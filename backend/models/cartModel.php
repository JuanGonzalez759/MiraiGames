<?php

declare(strict_types=1);

function findCartIdByUserId(PDO $connection, int $userId): ?int
{
    $statement = $connection->prepare(
        'SELECT id FROM carts WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $cartId = $statement->fetchColumn();

    return $cartId === false ? null : (int) $cartId;
}

function lockCartIdByUserId(PDO $connection, int $userId): ?int
{
    $statement = $connection->prepare(
        'SELECT id FROM carts WHERE user_id = :user_id FOR UPDATE'
    );
    $statement->execute(['user_id' => $userId]);
    $cartId = $statement->fetchColumn();

    return $cartId === false ? null : (int) $cartId;
}

function createOrLockCartForUser(PDO $connection, int $userId): int
{
    $statement = $connection->prepare(
        'INSERT INTO carts (user_id) VALUES (:user_id)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );
    $statement->execute(['user_id' => $userId]);

    $statement = $connection->prepare(
        'SELECT id FROM carts WHERE user_id = :user_id FOR UPDATE'
    );
    $statement->execute(['user_id' => $userId]);
    $cartId = $statement->fetchColumn();

    if ($cartId === false) {
        throw new RuntimeException('Cart could not be loaded after creation.');
    }

    return (int) $cartId;
}

function findCartVariantForUpdate(PDO $connection, int $gameId, int $platformId): ?array
{
    $statement = $connection->prepare(
        'SELECT gp.game_id, gp.platform_id, gp.price, gp.stock, gp.is_available,
                g.title, g.image_url, g.is_active, p.name AS platform_name
         FROM game_platforms gp
         INNER JOIN games g ON g.id = gp.game_id
         INNER JOIN platforms p ON p.id = gp.platform_id
         WHERE gp.game_id = :game_id AND gp.platform_id = :platform_id
         FOR UPDATE'
    );
    $statement->execute([
        'game_id' => $gameId,
        'platform_id' => $platformId,
    ]);
    $variant = $statement->fetch();

    return $variant === false ? null : $variant;
}

function findCartItemByVariantForUpdate(
    PDO $connection,
    int $cartId,
    int $gameId,
    int $platformId
): ?array {
    $statement = $connection->prepare(
        'SELECT id, quantity
         FROM cart_details
         WHERE cart_id = :cart_id
           AND game_id = :game_id
           AND platform_id = :platform_id
         FOR UPDATE'
    );
    $statement->execute([
        'cart_id' => $cartId,
        'game_id' => $gameId,
        'platform_id' => $platformId,
    ]);
    $item = $statement->fetch();

    return $item === false ? null : $item;
}

function findCartItemByIdForUpdate(PDO $connection, int $cartId, int $itemId): ?array
{
    $statement = $connection->prepare(
        'SELECT id, game_id, platform_id, quantity
         FROM cart_details
         WHERE id = :id AND cart_id = :cart_id
         FOR UPDATE'
    );
    $statement->execute([
        'id' => $itemId,
        'cart_id' => $cartId,
    ]);
    $item = $statement->fetch();

    return $item === false ? null : $item;
}

function findOtherCartItemByVariantForUpdate(
    PDO $connection,
    int $cartId,
    int $gameId,
    int $platformId,
    int $excludeItemId
): ?array {
    $statement = $connection->prepare(
        'SELECT id, quantity
         FROM cart_details
         WHERE cart_id = :cart_id
           AND game_id = :game_id
           AND platform_id = :platform_id
           AND id <> :exclude_id
         FOR UPDATE'
    );
    $statement->execute([
        'cart_id' => $cartId,
        'game_id' => $gameId,
        'platform_id' => $platformId,
        'exclude_id' => $excludeItemId,
    ]);
    $item = $statement->fetch();

    return $item === false ? null : $item;
}

function insertCartItem(
    PDO $connection,
    int $cartId,
    int $gameId,
    int $platformId,
    int $quantity
): void {
    $statement = $connection->prepare(
        'INSERT INTO cart_details (cart_id, game_id, platform_id, quantity)
         VALUES (:cart_id, :game_id, :platform_id, :quantity)'
    );
    $statement->execute([
        'cart_id' => $cartId,
        'game_id' => $gameId,
        'platform_id' => $platformId,
        'quantity' => $quantity,
    ]);
}

function updateCartItemById(
    PDO $connection,
    int $cartId,
    int $itemId,
    ?int $platformId,
    int $quantity
): void {
    $statement = $connection->prepare(
        'UPDATE cart_details
         SET platform_id = :platform_id, quantity = :quantity
         WHERE id = :id AND cart_id = :cart_id'
    );
    $statement->execute([
        'platform_id' => $platformId,
        'quantity' => $quantity,
        'id' => $itemId,
        'cart_id' => $cartId,
    ]);

    if ($statement->rowCount() > 1) {
        throw new RuntimeException('More than one cart line was updated.');
    }
}

function updateCartItemQuantityById(
    PDO $connection,
    int $cartId,
    int $itemId,
    int $quantity
): void {
    $statement = $connection->prepare(
        'UPDATE cart_details
         SET quantity = :quantity
         WHERE id = :id AND cart_id = :cart_id'
    );
    $statement->execute([
        'quantity' => $quantity,
        'id' => $itemId,
        'cart_id' => $cartId,
    ]);
}

function deleteCartItemById(PDO $connection, int $cartId, int $itemId): int
{
    $statement = $connection->prepare(
        'DELETE FROM cart_details
         WHERE id = :id AND cart_id = :cart_id'
    );
    $statement->execute([
        'id' => $itemId,
        'cart_id' => $cartId,
    ]);

    return $statement->rowCount();
}

function findCartContents(PDO $connection, int $cartId): array
{
    $statement = $connection->prepare(
        'SELECT cd.id, cd.game_id, cd.platform_id, cd.quantity,
                g.title, p.name AS platform_name, gp.price AS unit_price,
                gp.stock, gp.is_available, g.image_url, g.is_active,
                CAST(gp.price * cd.quantity AS DECIMAL(20, 2)) AS subtotal
         FROM cart_details cd
         INNER JOIN games g ON g.id = cd.game_id
         LEFT JOIN game_platforms gp
           ON gp.game_id = cd.game_id AND gp.platform_id = cd.platform_id
         LEFT JOIN platforms p ON p.id = gp.platform_id
         WHERE cd.cart_id = :cart_id
         ORDER BY g.title ASC, p.name ASC, cd.id ASC'
    );
    $statement->execute(['cart_id' => $cartId]);
    $items = $statement->fetchAll();

    $statement = $connection->prepare(
        'SELECT CAST(
             COALESCE(SUM(gp.price * cd.quantity), 0.00)
             AS DECIMAL(20, 2)
         )
         FROM cart_details cd
         LEFT JOIN game_platforms gp
           ON gp.game_id = cd.game_id AND gp.platform_id = cd.platform_id
         WHERE cd.cart_id = :cart_id'
    );
    $statement->execute(['cart_id' => $cartId]);

    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['game_id'] = (int) $item['game_id'];
        $item['platform_id'] = $item['platform_id'] === null
            ? null
            : (int) $item['platform_id'];
        $item['quantity'] = (int) $item['quantity'];
        $item['unit_price'] = $item['unit_price'] === null
            ? null
            : (string) $item['unit_price'];
        $item['stock'] = $item['stock'] === null ? null : (int) $item['stock'];
        $item['is_available'] = $item['is_available'] === null
            ? null
            : (bool) $item['is_available'];
        $item['is_active'] = (bool) $item['is_active'];
        $item['subtotal'] = $item['subtotal'] === null
            ? null
            : (string) $item['subtotal'];
    }
    unset($item);

    return [
        'items' => $items,
        'total' => (string) $statement->fetchColumn(),
    ];
}
