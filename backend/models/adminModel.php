<?php

declare(strict_types=1);

require_once __DIR__ . '/gamesModel.php';
require_once __DIR__ . '/ordersModel.php';

function findAdminGames(PDO $connection): array
{
    $games = $connection
        ->query(
            'SELECT id, title, description, price, stock, release_date, image_url,
                    is_active, created_at, updated_at
             FROM games
             ORDER BY id ASC'
        )
        ->fetchAll();

    $gameIds = [];
    $gamesById = [];

    foreach ($games as $index => &$game) {
        $game['id'] = (int) $game['id'];
        $game['stock'] = (int) $game['stock'];
        $game['is_active'] = (bool) $game['is_active'];
        $game['categories'] = [];
        $game['platforms'] = [];
        $gameIds[] = $game['id'];
        $gamesById[$game['id']] = $index;
    }
    unset($game);

    if ($gameIds === []) {
        return $games;
    }

    $placeholders = implode(', ', array_fill(0, count($gameIds), '?'));
    $categories = $connection->prepare(
        'SELECT gc.game_id, c.id, c.name
         FROM game_categories gc
         INNER JOIN categories c ON c.id = gc.category_id
         WHERE gc.game_id IN (' . $placeholders . ')
         ORDER BY c.name ASC'
    );
    $categories->execute($gameIds);

    foreach ($categories->fetchAll() as $category) {
        $index = $gamesById[(int) $category['game_id']];
        $games[$index]['categories'][] = [
            'id' => (int) $category['id'],
            'name' => $category['name'],
        ];
    }

    $platforms = $connection->prepare(
        'SELECT gp.game_id, p.id, p.name
         FROM game_platforms gp
         INNER JOIN platforms p ON p.id = gp.platform_id
         WHERE gp.game_id IN (' . $placeholders . ')
         ORDER BY p.name ASC'
    );
    $platforms->execute($gameIds);

    foreach ($platforms->fetchAll() as $platform) {
        $index = $gamesById[(int) $platform['game_id']];
        $games[$index]['platforms'][] = [
            'id' => (int) $platform['id'],
            'name' => $platform['name'],
        ];
    }

    return $games;
}

function findAdminGameById(PDO $connection, int $gameId): ?array
{
    $statement = $connection->prepare(
        'SELECT id, title, description, price, stock, release_date, image_url,
                is_active, created_at, updated_at
         FROM games
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $gameId]);
    $game = $statement->fetch();

    if ($game === false) {
        return null;
    }

    $game['id'] = (int) $game['id'];
    $game['stock'] = (int) $game['stock'];
    $game['is_active'] = (bool) $game['is_active'];
    $game['categories'] = findGameTaxonomy(
        $connection,
        'categories',
        'game_categories',
        'category_id',
        $gameId
    );
    $game['platforms'] = findGameTaxonomy(
        $connection,
        'platforms',
        'game_platforms',
        'platform_id',
        $gameId
    );

    return $game;
}

function createAdminGame(PDO $connection, array $fields): array
{
    return withAdminTransaction($connection, function () use ($connection, $fields): array {
        validateAdminTaxonomyIds($connection, 'categories', $fields['category_ids']);
        validateAdminTaxonomyIds($connection, 'platforms', $fields['platform_ids']);

        $statement = $connection->prepare(
            'INSERT INTO games
                (title, description, price, stock, release_date, image_url, is_active)
             VALUES
                (:title, :description, :price, :stock, :release_date, :image_url, :is_active)'
        );
        $statement->execute([
            'title' => $fields['title'],
            'description' => $fields['description'],
            'price' => $fields['price'],
            'stock' => $fields['stock'],
            'release_date' => $fields['release_date'],
            'image_url' => $fields['image_url'],
            'is_active' => $fields['is_active'] ? 1 : 0,
        ]);
        $gameId = (int) $connection->lastInsertId();

        replaceAdminGameTaxonomy(
            $connection,
            'game_categories',
            'category_id',
            $gameId,
            $fields['category_ids']
        );
        replaceAdminGameTaxonomy(
            $connection,
            'game_platforms',
            'platform_id',
            $gameId,
            $fields['platform_ids']
        );

        return findAdminGameById($connection, $gameId)
            ?? throw new RuntimeException('Created game could not be reloaded.');
    });
}

function updateAdminGame(PDO $connection, int $gameId, array $fields): array
{
    return withAdminTransaction($connection, function () use (
        $connection,
        $gameId,
        $fields
    ): array {
        lockAdminGame($connection, $gameId);
        $columns = [
            'title' => 'title',
            'description' => 'description',
            'price' => 'price',
            'stock' => 'stock',
            'release_date' => 'release_date',
            'image_url' => 'image_url',
            'is_active' => 'is_active',
        ];
        $updates = [];
        $parameters = ['id' => $gameId];

        foreach ($columns as $field => $column) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $placeholder = 'field_' . $field;
            $updates[] = $column . ' = :' . $placeholder;
            $parameters[$placeholder] = $field === 'is_active'
                ? ($fields[$field] ? 1 : 0)
                : $fields[$field];
        }

        if ($updates !== []) {
            $statement = $connection->prepare(
                'UPDATE games SET ' . implode(', ', $updates) . ' WHERE id = :id'
            );
            $statement->execute($parameters);
        }

        if (array_key_exists('category_ids', $fields)) {
            validateAdminTaxonomyIds($connection, 'categories', $fields['category_ids']);
            replaceAdminGameTaxonomy(
                $connection,
                'game_categories',
                'category_id',
                $gameId,
                $fields['category_ids']
            );
        }

        if (array_key_exists('platform_ids', $fields)) {
            validateAdminTaxonomyIds($connection, 'platforms', $fields['platform_ids']);
            replaceAdminGameTaxonomy(
                $connection,
                'game_platforms',
                'platform_id',
                $gameId,
                $fields['platform_ids']
            );
        }

        return findAdminGameById($connection, $gameId)
            ?? throw new RuntimeException('Updated game could not be reloaded.');
    });
}

function deactivateAdminGame(PDO $connection, int $gameId): array
{
    return withAdminTransaction($connection, function () use ($connection, $gameId): array {
        lockAdminGame($connection, $gameId);
        $statement = $connection->prepare(
            'UPDATE games SET is_active = 0 WHERE id = :id'
        );
        $statement->execute(['id' => $gameId]);

        return findAdminGameById($connection, $gameId)
            ?? throw new RuntimeException('Deactivated game could not be reloaded.');
    });
}

function adjustAdminGameStock(
    PDO $connection,
    int $gameId,
    string $operation,
    int $quantity
): array {
    return withAdminTransaction($connection, function () use (
        $connection,
        $gameId,
        $operation,
        $quantity
    ): array {
        $game = lockAdminGame($connection, $gameId);
        $currentStock = (int) $game['stock'];

        if ($operation === 'add') {
            if ($quantity > 4294967295 - $currentStock) {
                throw new HttpException(422, 'The resulting stock exceeds the supported limit.');
            }
            $newStock = $currentStock + $quantity;
        } elseif ($operation === 'remove') {
            if ($quantity > $currentStock) {
                throw new HttpException(409, 'Cannot remove more stock than is currently available.');
            }
            $newStock = $currentStock - $quantity;
        } else {
            throw new HttpException(422, 'Stock operation must be add or remove.');
        }

        $statement = $connection->prepare(
            'UPDATE games SET stock = :stock WHERE id = :id'
        );
        $statement->execute([
            'stock' => $newStock,
            'id' => $gameId,
        ]);

        return ['game_id' => $gameId, 'stock' => $newStock];
    });
}

function findAdminOrders(PDO $connection, int $page, int $perPage): array
{
    $total = (int) $connection->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $statement = $connection->prepare(
        'SELECT o.id, u.id AS customer_id, u.full_name AS customer_name,
                o.created_at, o.payment_status, o.inventory_status,
                CAST((
                    SELECT COALESCE(SUM(od.quantity * od.unit_price), 0.00)
                    FROM order_details od
                    WHERE od.order_id = o.id
                ) AS DECIMAL(12, 2)) AS total
         FROM orders o
         INNER JOIN users u ON u.id = o.user_id
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $statement->execute();
    $orders = $statement->fetchAll();

    foreach ($orders as &$order) {
        $order['id'] = (int) $order['id'];
        $order['customer_id'] = (int) $order['customer_id'];
    }
    unset($order);

    return [
        'orders' => $orders,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ],
    ];
}

function findAdminOrderById(PDO $connection, int $orderId): ?array
{
    $statement = $connection->prepare(
        'SELECT o.id, u.id AS customer_id, u.full_name AS customer_name,
                o.created_at, o.paid_at, o.payment_status, o.inventory_status,
                CAST((
                    SELECT COALESCE(SUM(od.quantity * od.unit_price), 0.00)
                    FROM order_details od
                    WHERE od.order_id = o.id
                ) AS DECIMAL(12, 2)) AS total
         FROM orders o
         INNER JOIN users u ON u.id = o.user_id
         WHERE o.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $orderId]);
    $order = $statement->fetch();

    if ($order === false) {
        return null;
    }

    $order['id'] = (int) $order['id'];
    $order['customer_id'] = (int) $order['customer_id'];
    $order['items'] = findOrderItems($connection, $orderId);

    return $order;
}

function withAdminTransaction(PDO $connection, callable $operation): mixed
{
    if (!$connection->beginTransaction()) {
        throw new RuntimeException('Administrative transaction could not be started.');
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

function lockAdminGame(PDO $connection, int $gameId): array
{
    $statement = $connection->prepare(
        'SELECT id, stock FROM games WHERE id = :id FOR UPDATE'
    );
    $statement->execute(['id' => $gameId]);
    $game = $statement->fetch();

    if ($game === false) {
        throw new HttpException(404, 'Game not found.');
    }

    return $game;
}

function validateAdminTaxonomyIds(PDO $connection, string $table, array $ids): void
{
    if (!in_array($table, ['categories', 'platforms'], true)) {
        throw new LogicException('Unsupported game taxonomy table.');
    }

    if ($ids === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = $connection->prepare(
        'SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')'
    );
    $statement->execute($ids);

    if (count($statement->fetchAll()) !== count($ids)) {
        throw new HttpException(422, 'One or more category or platform ids are invalid.');
    }
}

function replaceAdminGameTaxonomy(
    PDO $connection,
    string $linkTable,
    string $taxonomyIdColumn,
    int $gameId,
    array $ids
): void {
    $allowedLinks = [
        'game_categories' => 'category_id',
        'game_platforms' => 'platform_id',
    ];

    if (($allowedLinks[$linkTable] ?? null) !== $taxonomyIdColumn) {
        throw new LogicException('Unsupported game taxonomy relation.');
    }

    $delete = $connection->prepare(
        'DELETE FROM ' . $linkTable . ' WHERE game_id = :game_id'
    );
    $delete->execute(['game_id' => $gameId]);

    $insert = $connection->prepare(
        'INSERT INTO ' . $linkTable . ' (game_id, ' . $taxonomyIdColumn . ')
         VALUES (:game_id, :taxonomy_id)'
    );

    foreach ($ids as $taxonomyId) {
        $insert->execute([
            'game_id' => $gameId,
            'taxonomy_id' => $taxonomyId,
        ]);
    }
}
