<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/jsonRequest.php';
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../models/cartModel.php';

function getAuthenticatedCart(): array
{
    $userId = requireCartUserId();
    $connection = databaseConnection();

    return withCartTransaction($connection, function () use ($connection, $userId): array {
        $cartId = findCartIdByUserId($connection, $userId);

        return [
            'cart' => $cartId === null
                ? ['items' => [], 'total' => '0.00']
                : findCartContents($connection, $cartId),
        ];
    });
}

function addCartItem(): array
{
    $userId = requireCartUserId();
    $payload = readJsonRequest();
    if (array_diff(array_keys($payload), ['game_id', 'platform_id', 'quantity']) !== []) {
        throw new HttpException(422, 'Request contains unsupported cart fields.');
    }

    $gameId = validateCartInteger($payload['game_id'] ?? null, 'game_id');
    $platformId = validateCartInteger(
        $payload['platform_id'] ?? null,
        'platform_id',
        4294967295
    );
    $quantity = validateCartInteger(
        $payload['quantity'] ?? null,
        'quantity',
        min(4294967295, PHP_INT_MAX)
    );
    $connection = databaseConnection();

    return withCartTransaction($connection, function () use (
        $connection,
        $userId,
        $gameId,
        $platformId,
        $quantity
    ): array {
        $cartId = createOrLockCartForUser($connection, $userId);
        $variant = findCartVariantForUpdate($connection, $gameId, $platformId);
        requireAvailableCartVariant($variant);

        $existing = findCartItemByVariantForUpdate(
            $connection,
            $cartId,
            $gameId,
            $platformId
        );
        $currentQuantity = $existing === null ? 0 : (int) $existing['quantity'];
        $newQuantity = checkedCartQuantitySum($currentQuantity, $quantity);

        if ($newQuantity > (int) $variant['stock']) {
            throw new HttpException(409, 'Requested quantity exceeds available variant stock.');
        }

        if ($existing === null) {
            insertCartItem($connection, $cartId, $gameId, $platformId, $newQuantity);
        } else {
            updateCartItemQuantityById(
                $connection,
                $cartId,
                (int) $existing['id'],
                $newQuantity
            );
        }

        return ['cart' => findCartContents($connection, $cartId)];
    });
}

function updateCartItem(array $parameters): array
{
    $userId = requireCartUserId();
    $itemId = validateRouteCartItemId($parameters['id'] ?? null);
    $payload = readJsonRequest();
    if (
        $payload === []
        || array_diff(array_keys($payload), ['platform_id', 'quantity']) !== []
    ) {
        throw new HttpException(422, 'Provide a platform_id, a quantity, or both.');
    }

    $platformId = array_key_exists('platform_id', $payload)
        ? validateCartInteger($payload['platform_id'], 'platform_id', 4294967295)
        : null;
    $quantity = array_key_exists('quantity', $payload)
        ? validateCartInteger(
            $payload['quantity'],
            'quantity',
            min(4294967295, PHP_INT_MAX)
        )
        : null;
    $connection = databaseConnection();

    return withCartTransaction($connection, function () use (
        $connection,
        $userId,
        $itemId,
        $platformId,
        $quantity,
        $payload
    ): array {
        $cartId = lockCartIdByUserId($connection, $userId);

        if ($cartId === null) {
            throw new HttpException(404, 'Cart item not found.');
        }

        $item = findCartItemByIdForUpdate($connection, $cartId, $itemId);
        if ($item === null) {
            throw new HttpException(404, 'Cart item not found.');
        }

        $gameId = (int) $item['game_id'];
        $currentPlatformId = $item['platform_id'] === null
            ? null
            : (int) $item['platform_id'];
        $targetPlatformId = array_key_exists('platform_id', $payload)
            ? $platformId
            : $currentPlatformId;
        $targetQuantity = $quantity ?? (int) $item['quantity'];

        if ($targetPlatformId === null) {
            updateCartItemById($connection, $cartId, $itemId, null, $targetQuantity);

            return ['cart' => findCartContents($connection, $cartId)];
        }

        $variant = findCartVariantForUpdate($connection, $gameId, $targetPlatformId);
        requireAvailableCartVariant($variant);

        $otherItem = findOtherCartItemByVariantForUpdate(
            $connection,
            $cartId,
            $gameId,
            $targetPlatformId,
            $itemId
        );

        if ($otherItem !== null) {
            $mergedQuantity = checkedCartQuantitySum(
                (int) $otherItem['quantity'],
                $targetQuantity
            );
            if ($mergedQuantity > (int) $variant['stock']) {
                throw new HttpException(409, 'Requested quantity exceeds available variant stock.');
            }

            updateCartItemQuantityById(
                $connection,
                $cartId,
                (int) $otherItem['id'],
                $mergedQuantity
            );
            if (deleteCartItemById($connection, $cartId, $itemId) !== 1) {
                throw new RuntimeException('The merged cart line could not be removed.');
            }
        } else {
            if ($targetQuantity > (int) $variant['stock']) {
                throw new HttpException(409, 'Requested quantity exceeds available variant stock.');
            }

            updateCartItemById(
                $connection,
                $cartId,
                $itemId,
                $targetPlatformId,
                $targetQuantity
            );
        }

        return ['cart' => findCartContents($connection, $cartId)];
    });
}

function removeCartItem(array $parameters): array
{
    $userId = requireCartUserId();
    $itemId = validateRouteCartItemId($parameters['id'] ?? null);
    $connection = databaseConnection();

    return withCartTransaction($connection, function () use (
        $connection,
        $userId,
        $itemId
    ): array {
        $cartId = lockCartIdByUserId($connection, $userId);

        if ($cartId === null || deleteCartItemById($connection, $cartId, $itemId) !== 1) {
            throw new HttpException(404, 'Cart item not found.');
        }

        return ['cart' => findCartContents($connection, $cartId)];
    });
}

function requireCartUserId(): int
{
    startAppSession();
    $userId = currentSessionUserId();

    if ($userId === null) {
        throw new HttpException(401, 'Authentication is required.');
    }

    return $userId;
}

function validateRouteCartItemId(mixed $value): int
{
    $itemId = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($itemId === false) {
        throw new HttpException(422, 'Cart item id must be a positive integer.');
    }

    return $itemId;
}

function validateCartInteger(
    mixed $value,
    string $field,
    int $maximum = PHP_INT_MAX
): int {
    if (!is_int($value) || $value < 1 || $value > $maximum) {
        throw new HttpException(422, $field . ' must be a positive integer.');
    }

    return $value;
}

function checkedCartQuantitySum(int $currentQuantity, int $addedQuantity): int
{
    $maximumQuantity = min(4294967295, PHP_INT_MAX);
    if ($addedQuantity > $maximumQuantity - $currentQuantity) {
        throw new HttpException(422, 'The resulting quantity exceeds the supported limit.');
    }

    return $currentQuantity + $addedQuantity;
}

function requireAvailableCartVariant(?array $variant): void
{
    if ($variant === null) {
        throw new HttpException(422, 'The selected platform does not belong to this game.');
    }

    if (!(bool) $variant['is_active']) {
        throw new HttpException(409, 'Game is not available.');
    }

    if (!(bool) $variant['is_available'] || (int) $variant['stock'] < 1) {
        throw new HttpException(409, 'The selected platform variant is not available.');
    }
}

function withCartTransaction(PDO $connection, callable $operation): array
{
    if (!$connection->beginTransaction()) {
        throw new RuntimeException('Cart transaction could not be started.');
    }

    try {
        $result = $operation();
        $connection->commit();

        return $result;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $exception;
    }
}
