<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/environment.php';

$stripeAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($stripeAutoload)) {
    require_once $stripeAutoload;
}

function requireStripePaymentsEnabled(): void
{
    if (getenv('APP_ENV') === 'test') {
        throw new HttpException(503, 'Stripe payments are disabled in the test environment.');
    }
}

function stripeClient(): \Stripe\StripeClient
{
    requireStripePaymentsEnabled();

    if (!class_exists(\Stripe\StripeClient::class) || !extension_loaded('curl')) {
        throw new HttpException(503, 'Stripe payments are not available on this server.');
    }

    $secretKey = readEnvironmentFileValue(__DIR__ . '/../.env', 'STRIPE_SECRET_KEY');

    if (!is_string($secretKey) || !str_starts_with($secretKey, 'sk_test_')) {
        throw new HttpException(503, 'Stripe test configuration is unavailable.');
    }

    return new \Stripe\StripeClient($secretKey);
}

function stripeWebhookSecret(): string
{
    $secret = readEnvironmentFileValue(__DIR__ . '/../.env', 'STRIPE_WEBHOOK_SECRET');

    if (!is_string($secret) || !str_starts_with($secret, 'whsec_')) {
        throw new HttpException(503, 'Stripe webhook configuration is unavailable.');
    }

    return $secret;
}

function stripeReturnOrigin(): string
{
    $origin = getenv('APP_ORIGIN');

    if (!is_string($origin) || $origin === '') {
        throw new HttpException(503, 'Application origin is not configured.');
    }

    $parts = parse_url($origin);

    if (
        $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
    ) {
        throw new HttpException(503, 'Application origin is not configured.');
    }

    return rtrim($origin, '/');
}

function createOrRetrieveStripeSession(
    PDO $connection,
    array $order,
    bool $allowExpiredReservationForRecovery = false
): string
{
    if (
        $order['payment_status'] !== 'pending'
        || $order['inventory_status'] !== 'reserved'
    ) {
        throw new HttpException(409, 'This order is no longer available for payment.');
    }

    $client = stripeClient();
    $sessionId = $order['stripe_session_id'];

    if (is_string($sessionId) && $sessionId !== '') {
        try {
            $session = $client->checkout->sessions->retrieve($sessionId);
        } catch (Throwable) {
            error_log('Stripe checkout session lookup failed for order ' . $order['id'] . '.');
            throw new HttpException(502, 'The checkout session could not be retrieved. Please retry.');
        }

        if ($session->status === 'open' && is_string($session->url) && $session->url !== '') {
            return $session->url;
        }

        throw new HttpException(409, 'This checkout session is no longer open.');
    }

    $reservationExpiry = new DateTimeImmutable(
        (string) $order['reservation_expires_at'],
        new DateTimeZone('UTC')
    );

    if (!$allowExpiredReservationForRecovery && $reservationExpiry->getTimestamp() <= time()) {
        throw new HttpException(409, 'The stock reservation has expired and is being reconciled.');
    }

    $items = findOrderItems($connection, (int) $order['id']);
    $lineItems = [];

    foreach ($items as $item) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => ['name' => $item['game_title']],
                'unit_amount' => decimalToCents((string) $item['unit_price']),
            ],
            'quantity' => (int) $item['quantity'],
        ];
    }

    if ($lineItems === []) {
        throw new RuntimeException('A reserved order has no order details.');
    }

    $orderId = (int) $order['id'];
    $returnUrl = stripeReturnOrigin() . '/checkout/return?order_id=' . $orderId;

    try {
        $session = $client->checkout->sessions->create(
            [
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'client_reference_id' => (string) $orderId,
                'metadata' => ['order_id' => (string) $orderId],
                'success_url' => $returnUrl,
                'cancel_url' => $returnUrl,
            ],
            ['idempotency_key' => 'mirai-order-' . $orderId]
        );
    } catch (Throwable) {
        error_log('Stripe checkout session creation failed for order ' . $orderId . '.');
        throw new HttpException(502, 'Checkout could not be started. You can retry from order history.');
    }

    if (
        !is_string($session->id)
        || !is_string($session->url)
        || $session->url === ''
    ) {
        error_log('Stripe returned an incomplete checkout session for order ' . $orderId . '.');
        throw new HttpException(502, 'Checkout could not be started. Please retry.');
    }

    saveStripeSessionId($connection, $orderId, $session->id);

    return $session->url;
}
