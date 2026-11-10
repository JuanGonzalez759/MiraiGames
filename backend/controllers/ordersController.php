<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/jsonRequest.php';
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/stripe.php';
require_once __DIR__ . '/../models/ordersModel.php';

function createCheckoutSession(): array
{
    $userId = requireOrderUserId();
    requireStripePaymentsEnabled();
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';

    if (
        !is_string($idempotencyKey)
        || preg_match('/^[A-Za-z0-9._:-]{16,255}$/', $idempotencyKey) !== 1
    ) {
        throw new HttpException(422, 'A valid Idempotency-Key header is required.');
    }

    $connection = databaseConnection();
    $order = createOrFindReservedOrder($connection, $userId, $idempotencyKey);

    if ((int) $order['user_id'] !== $userId) {
        throw new HttpException(409, 'This checkout key is already in use.');
    }

    $checkoutUrl = createOrRetrieveStripeSession($connection, $order);

    return [
        'order_id' => (int) $order['id'],
        'checkout_url' => $checkoutUrl,
    ];
}

function resumeOrderCheckout(array $parameters): array
{
    $userId = requireOrderUserId();
    requireStripePaymentsEnabled();
    $orderId = validateOrderId($parameters['id'] ?? null);
    $connection = databaseConnection();
    $order = findCheckoutOrderById($connection, $orderId, $userId);

    if ($order === null) {
        throw new HttpException(404, 'Order not found.');
    }

    return [
        'order_id' => $orderId,
        'checkout_url' => createOrRetrieveStripeSession($connection, $order),
    ];
}

function getOrderHistory(): array
{
    $userId = requireOrderUserId();

    return ['orders' => findOrdersForUser(databaseConnection(), $userId)];
}

function getOrderDetails(array $parameters): array
{
    $userId = requireOrderUserId();
    $orderId = validateOrderId($parameters['id'] ?? null);
    $order = findOrderById(databaseConnection(), $orderId, $userId);

    if ($order === null) {
        throw new HttpException(404, 'Order not found.');
    }

    return ['order' => $order];
}

function requireOrderUserId(): int
{
    startAppSession();
    $userId = currentSessionUserId();

    if ($userId === null) {
        throw new HttpException(401, 'Authentication is required.');
    }

    return $userId;
}

function validateOrderId(mixed $value): int
{
    $orderId = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($orderId === false) {
        throw new HttpException(422, 'Order id must be a positive integer.');
    }

    return $orderId;
}

function findCheckoutOrderById(PDO $connection, int $orderId, int $userId): ?array
{
    $statement = $connection->prepare(
        'SELECT id, user_id, stripe_session_id, payment_status, reservation_expires_at,
                inventory_status
         FROM orders
         WHERE id = :order_id AND user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        'order_id' => $orderId,
        'user_id' => $userId,
    ]);
    $order = $statement->fetch();

    return $order === false ? null : $order;
}

function handleStripeWebhook(): array
{
    if (!class_exists(\Stripe\Webhook::class)) {
        throw new HttpException(503, 'Stripe SDK is not installed on this server.');
    }

    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    if (!is_string($signature) || $signature === '') {
        throw new HttpException(400, 'Stripe signature is required.');
    }

    $payload = file_get_contents('php://input');

    if (!is_string($payload) || $payload === '') {
        throw new HttpException(400, 'Stripe event body is required.');
    }

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $signature, stripeWebhookSecret());
    } catch (\Stripe\Exception\SignatureVerificationException | UnexpectedValueException) {
        throw new HttpException(400, 'Stripe signature or event body is invalid.');
    }

    if (!empty($event->livemode)) {
        throw new HttpException(400, 'Live Stripe events are not accepted.');
    }

    $connection = databaseConnection();
    $result = applyStripeEvent($connection, $event);

    return [
        'received' => true,
        'result' => $result,
    ];
}

function applyStripeEvent(PDO $connection, \Stripe\Event $event): string
{
    $supportedEvents = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.expired',
    ];
    $isSupported = in_array($event->type, $supportedEvents, true);
    $session = $isSupported ? $event->data->object : null;
    $orderId = $isSupported
        ? filter_var($session->metadata->order_id ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($isSupported && ($orderId === false || $session->object !== 'checkout.session')) {
        throw new HttpException(400, 'Stripe checkout event is invalid.');
    }

    $connection->beginTransaction();

    try {
        $order = $isSupported ? lockOrderById($connection, (int) $orderId) : null;
        $eventOrderId = $order === null ? null : (int) $orderId;
        $insertEvent = $connection->prepare(
            'INSERT INTO stripe_webhook_events (event_id, event_type, order_id)
             VALUES (:event_id, :event_type, :order_id)'
        );

        try {
            $insertEvent->execute([
                'event_id' => $event->id,
                'event_type' => $event->type,
                'order_id' => $eventOrderId,
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $connection->rollBack();
                return 'duplicate';
            }

            throw $exception;
        }

        if (!$isSupported || $order === null) {
            $connection->commit();
            return 'ignored';
        }

        validateStripeSessionForOrder($connection, $session, $order, (int) $orderId);

        if ($event->type === 'checkout.session.expired') {
            if ($session->status !== 'expired') {
                throw new HttpException(400, 'Stripe did not confirm the session expiration.');
            }

            if ($order['payment_status'] === 'paid') {
                $connection->commit();
                return 'already_paid';
            }

            if ($order['payment_status'] === 'pending' && $order['inventory_status'] === 'reserved') {
                restoreOrderStockInsideTransaction($connection, (int) $orderId);
                $updateOrder = $connection->prepare(
                    "UPDATE orders
                     SET inventory_status = 'released', payment_status = 'expired'
                     WHERE id = :order_id
                       AND payment_status = 'pending'
                       AND inventory_status = 'reserved'"
                );
                $updateOrder->execute(['order_id' => (int) $orderId]);
            }

            $connection->commit();
            return 'expired';
        }

        if ($session->status !== 'complete' || $session->payment_status !== 'paid') {
            $connection->commit();
            return 'payment_not_confirmed';
        }

        if ($order['payment_status'] === 'paid' && $order['inventory_status'] === 'committed') {
            $connection->commit();
            return 'already_paid';
        }

        if ($order['payment_status'] !== 'pending' || $order['inventory_status'] !== 'reserved') {
            throw new RuntimeException('A paid Stripe session has no active inventory reservation.');
        }

        $expectedAmount = orderTotalCents($connection, (int) $orderId);

        if (
            strtolower((string) $session->currency) !== 'eur'
            || !is_int($session->amount_total)
            || $session->amount_total !== $expectedAmount
        ) {
            throw new HttpException(400, 'Stripe amount or currency does not match the order.');
        }

        $markPaid = $connection->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 inventory_status = 'committed',
                 paid_at = UTC_TIMESTAMP()
             WHERE id = :order_id
               AND payment_status = 'pending'
               AND inventory_status = 'reserved'"
        );
        $markPaid->execute(['order_id' => (int) $orderId]);

        if ($markPaid->rowCount() !== 1) {
            throw new RuntimeException('Paid order state could not be updated.');
        }

        $connection->commit();

        return 'paid';
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $exception;
    }
}

function validateStripeSessionForOrder(
    PDO $connection,
    \Stripe\StripeObject $session,
    array $order,
    int $orderId
): void {
    if (
        $session->mode !== 'payment'
        || !empty($session->livemode)
        || (string) ($session->metadata->order_id ?? '') !== (string) $orderId
        || (string) ($session->client_reference_id ?? '') !== (string) $orderId
        || !is_string($order['stripe_session_id'])
        || $order['stripe_session_id'] !== $session->id
    ) {
        throw new HttpException(400, 'Stripe session does not match the order.');
    }

    if ($session->payment_status === 'paid') {
        $expectedAmount = orderTotalCents($connection, $orderId);

        if (
            strtolower((string) $session->currency) !== 'eur'
            || !is_int($session->amount_total)
            || $session->amount_total !== $expectedAmount
        ) {
            throw new HttpException(400, 'Stripe amount or currency does not match the order.');
        }
    }
}

function restoreOrderStockInsideTransaction(PDO $connection, int $orderId): void
{
    $items = findOrderItems($connection, $orderId);
    $restoreStock = $connection->prepare(
        'UPDATE games SET stock = stock + :quantity WHERE id = :game_id'
    );

    foreach ($items as $item) {
        $restoreStock->execute([
            'quantity' => (int) $item['quantity'],
            'game_id' => (int) $item['game_id'],
        ]);

        if ($restoreStock->rowCount() !== 1) {
            throw new RuntimeException('Reserved game stock could not be restored.');
        }
    }
}
