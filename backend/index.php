<?php

declare(strict_types=1);

require_once __DIR__ . '/config/environment.php';
loadEnvironmentFile(__DIR__ . '/.env');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/httpException.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/session.php';
require_once __DIR__ . '/helpers/adminAuthorization.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/';
$path = $path === '' ? '/' : $path;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = require __DIR__ . '/routes/api.php';
$routeHandler = null;
$routeParameters = [];
$pathMatchesRoute = false;

foreach ($routes as $routeKey => $handler) {
    [$registeredMethod, $registeredPath] = explode(' ', $routeKey, 2);
    $parameters = matchRoutePath($registeredPath, $path);

    if ($parameters === null) {
        continue;
    }

    $pathMatchesRoute = true;

    if ($registeredMethod === $method) {
        $routeHandler = $handler;
        $routeParameters = $parameters;
        break;
    }
}

if ($routeHandler === null) {
    if ($pathMatchesRoute) {
        $allowedMethods = [];

        foreach ($routes as $routeKey => $_handler) {
            [$registeredMethod, $registeredPath] = explode(' ', $routeKey, 2);

            if (matchRoutePath($registeredPath, $path) !== null) {
                $allowedMethods[] = $registeredMethod;
            }
        }

        header('Allow: ' . implode(', ', array_unique($allowedMethods)));
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
    $isStripeWebhook = $method === 'POST' && $path === '/api/webhooks/stripe';

    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$isStripeWebhook) {
        requireSameOriginRequest();
        startAppSession();

        if (str_starts_with($path, '/api/admin/')) {
            requireAuthenticatedSessionUserId();
        }

        requireValidCsrfToken();
    }

    $result = $routeHandler($routeParameters);

    if ($result instanceof ApiResponse) {
        jsonResponse($result->statusCode, $result->payload);
    }

    jsonResponse(200, $result);
} catch (HttpException $exception) {
    jsonResponse($exception->statusCode, [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);
} catch (PDOException $exception) {
    error_log('Database operation failed: ' . $exception->getMessage());

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

function matchRoutePath(string $routePath, string $requestPath): ?array
{
    $routeSegments = explode('/', trim($routePath, '/'));
    $requestSegments = explode('/', trim($requestPath, '/'));

    if (count($routeSegments) !== count($requestSegments)) {
        return null;
    }

    $parameters = [];

    foreach ($routeSegments as $index => $routeSegment) {
        if (
            preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\}$/', $routeSegment, $matches) === 1
        ) {
            $parameters[$matches[1]] = $requestSegments[$index];
            continue;
        }

        if ($routeSegment !== $requestSegments[$index]) {
            return null;
        }
    }

    return $parameters;
}
