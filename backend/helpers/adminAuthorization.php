<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../models/usersModel.php';

function requireAuthenticatedSessionUserId(): int
{
    startAppSession();
    $userId = currentSessionUserId();

    if ($userId === null) {
        throw new HttpException(401, 'Authentication is required.');
    }

    return $userId;
}

function requireAdminUserId(PDO $connection): int
{
    $userId = requireAuthenticatedSessionUserId();
    $user = findPublicUserById($connection, $userId);

    if ($user === null) {
        destroyAppSession();
        throw new HttpException(401, 'Authentication is required.');
    }

    if ($user['role'] !== 'admin') {
        throw new HttpException(403, 'Administrator access is required.');
    }

    return $userId;
}

function requireAdminConnection(): PDO
{
    requireAuthenticatedSessionUserId();
    $connection = databaseConnection();
    requireAdminUserId($connection);

    return $connection;
}
