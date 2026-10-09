<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/response.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/';
$path = $path === '' ? '/' : $path;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = require __DIR__ . '/routes/api.php';
$routeKey = $method . ' ' . $path;

if (!isset($routes[$routeKey])) {
    $allowedMethods = [];

    foreach (array_keys($routes) as $registeredRoute) {
        [$registeredMethod, $registeredPath] = explode(' ', $registeredRoute, 2);

        if ($registeredPath === $path) {
            $allowedMethods[] = $registeredMethod;
        }
    }

    if ($allowedMethods !== []) {
        header('Allow: ' . implode(', ', $allowedMethods));
        jsonResponse(405, [
            'status' => 'error',
            'message' => 'Method not allowed.',
        ]);
    }

    jsonResponse(404, [
        'status' => 'error',
        'message' => 'Route not found.',
    ]);
}

try {
    $handler = $routes[$routeKey];
    jsonResponse(200, $handler());
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());

    jsonResponse(503, [
        'status' => 'error',
        'api' => 'up',
        'database' => 'disconnected',
        'message' => 'Database connection is unavailable.',
    ]);
} catch (Throwable $exception) {
    error_log('Unhandled API error: ' . $exception->getMessage());

    jsonResponse(500, [
        'status' => 'error',
        'message' => 'Internal server error.',
    ]);
}
