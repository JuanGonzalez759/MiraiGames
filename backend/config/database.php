<?php

declare(strict_types=1);

function databaseConnection(): PDO
{
    $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('MYSQL_PORT') ?: '3306';
    $database = getenv('MYSQL_DATABASE') ?: 'mirai_games';
    $username = getenv('MYSQL_USER');
    $password = getenv('MYSQL_PASSWORD');

    if ($username === false || $password === false) {
        throw new PDOException(
            'Set the MYSQL_USER and MYSQL_PASSWORD environment variables.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}
