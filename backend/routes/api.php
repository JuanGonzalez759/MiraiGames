<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/gamesController.php';
require_once __DIR__ . '/../controllers/authController.php';
require_once __DIR__ . '/../controllers/cartController.php';
require_once __DIR__ . '/../controllers/ordersController.php';
require_once __DIR__ . '/../controllers/adminController.php';

return [
    'GET /api/admin/games' => static function (): array {
        return getAdminGames();
    },
    'POST /api/admin/games' => static function (): ApiResponse {
        return createAdminGameEndpoint();
    },
    'PATCH /api/admin/games/{id}' => static function (array $parameters): array {
        return updateAdminGameEndpoint($parameters);
    },
    'DELETE /api/admin/games/{id}' => static function (array $parameters): array {
        return deactivateAdminGameEndpoint($parameters);
    },
    'POST /api/admin/games/{id}/stock' => static function (array $parameters): array {
        return adjustAdminGameStockEndpoint($parameters);
    },
    'GET /api/admin/orders' => static function (): array {
        return getAdminOrders();
    },
    'GET /api/admin/orders/{id}' => static function (array $parameters): array {
        return getAdminOrderDetails($parameters);
    },
    'GET /api/auth/csrf' => static function (): array {
        return getCsrfToken();
    },
    'POST /api/auth/register' => static function (): ApiResponse {
        return registerAccount();
    },
    'POST /api/auth/login' => static function (): array {
        return loginAccount();
    },
    'GET /api/auth/me' => static function (): array {
        return getAuthenticatedProfile();
    },
    'POST /api/auth/logout' => static function (): array {
        return logoutAccount();
    },
    'GET /api/cart' => static function (): array {
        return getAuthenticatedCart();
    },
    'POST /api/cart/items' => static function (): array {
        return addCartItem();
    },
    'PATCH /api/cart/items/{id}' => static function (array $parameters): array {
        return updateCartItem($parameters);
    },
    'DELETE /api/cart/items/{id}' => static function (array $parameters): array {
        return removeCartItem($parameters);
    },
    'POST /api/checkout/session' => static function (): array {
        return createCheckoutSession();
    },
    'POST /api/orders/{id}/checkout-session' => static function (array $parameters): array {
        return resumeOrderCheckout($parameters);
    },
    'GET /api/orders' => static function (): array {
        return getOrderHistory();
    },
    'GET /api/orders/{id}' => static function (array $parameters): array {
        return getOrderDetails($parameters);
    },
    'POST /api/webhooks/stripe' => static function (): array {
        return handleStripeWebhook();
    },
    'GET /api/health' => static function (): array {
        databaseConnection();

        return [
            'status' => 'ok',
            'api' => 'up',
            'database' => 'connected',
        ];
    },
    'GET /api/games' => static function (): array {
        return [
            'games' => getActiveGames($_GET),
        ];
    },
    'GET /api/games/{id}' => static function (array $parameters): array {
        return getActiveGame($parameters);
    },
    'GET /api/categories' => static function (): array {
        return getAvailableCategories();
    },
    'GET /api/platforms' => static function (): array {
        return getAvailablePlatforms();
    },
];
