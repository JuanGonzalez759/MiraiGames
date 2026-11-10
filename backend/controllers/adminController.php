<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/jsonRequest.php';
require_once __DIR__ . '/../helpers/adminAuthorization.php';
require_once __DIR__ . '/../models/adminModel.php';

function getAdminGames(): array
{
    $connection = requireAdminConnection();

    return ['games' => findAdminGames($connection)];
}

function createAdminGameEndpoint(): ApiResponse
{
    $connection = requireAdminConnection();
    $payload = readJsonRequest();
    $fields = validateAdminGameFields($payload, true);
    $game = createAdminGame($connection, $fields);

    return new ApiResponse(201, ['game' => $game]);
}

function updateAdminGameEndpoint(array $parameters): array
{
    $connection = requireAdminConnection();
    $gameId = validateAdminGameId($parameters['id'] ?? null);
    $fields = validateAdminGameFields(readJsonRequest(), false);

    return ['game' => updateAdminGame($connection, $gameId, $fields)];
}

function deactivateAdminGameEndpoint(array $parameters): array
{
    $connection = requireAdminConnection();
    $gameId = validateAdminGameId($parameters['id'] ?? null);

    return ['game' => deactivateAdminGame($connection, $gameId)];
}

function adjustAdminGameStockEndpoint(array $parameters): array
{
    $connection = requireAdminConnection();
    $gameId = validateAdminGameId($parameters['id'] ?? null);
    $payload = readJsonRequest();

    if (array_diff(array_keys($payload), ['operation', 'quantity']) !== []) {
        throw new HttpException(422, 'Request contains unsupported stock fields.');
    }

    $operation = $payload['operation'] ?? null;

    if (!is_string($operation) || !in_array($operation, ['add', 'remove'], true)) {
        throw new HttpException(422, 'Stock operation must be add or remove.');
    }

    $quantity = validateAdminInteger(
        $payload['quantity'] ?? null,
        'quantity',
        1,
        4294967295
    );

    return adjustAdminGameStock($connection, $gameId, $operation, $quantity);
}

function getAdminOrders(): array
{
    $connection = requireAdminConnection();
    [$page, $perPage] = validateAdminPagination($_GET);

    return findAdminOrders($connection, $page, $perPage);
}

function getAdminOrderDetails(array $parameters): array
{
    $connection = requireAdminConnection();
    $orderId = validateAdminGameId($parameters['id'] ?? null);
    $order = findAdminOrderById($connection, $orderId);

    if ($order === null) {
        throw new HttpException(404, 'Order not found.');
    }

    return ['order' => $order];
}

function validateAdminGameFields(array $payload, bool $creating): array
{
    $allowedFields = [
        'title',
        'description',
        'price',
        'stock',
        'release_date',
        'image_url',
        'is_active',
        'category_ids',
        'platform_ids',
    ];

    if (array_diff(array_keys($payload), $allowedFields) !== []) {
        throw new HttpException(422, 'Request contains unsupported game fields.');
    }

    if ($creating && (!array_key_exists('title', $payload) || !array_key_exists('price', $payload))) {
        throw new HttpException(422, 'Title and price are required to create a game.');
    }

    if (!$creating && $payload === []) {
        throw new HttpException(422, 'At least one game field must be provided.');
    }

    $fields = [];

    if ($creating || array_key_exists('title', $payload)) {
        $fields['title'] = validateAdminText($payload['title'] ?? null, 'title', 150, false);
    }
    if ($creating || array_key_exists('description', $payload)) {
        $fields['description'] = validateAdminText($payload['description'] ?? null, 'description', 65535, true);
    }
    if ($creating || array_key_exists('price', $payload)) {
        $fields['price'] = validateAdminPrice($payload['price'] ?? null);
    }
    if ($creating || array_key_exists('stock', $payload)) {
        $stock = array_key_exists('stock', $payload)
            ? $payload['stock']
            : ($creating ? 0 : null);
        $fields['stock'] = validateAdminInteger(
            $stock,
            'stock',
            0,
            4294967295
        );
    }
    if ($creating || array_key_exists('release_date', $payload)) {
        $fields['release_date'] = validateAdminDate($payload['release_date'] ?? null);
    }
    if ($creating || array_key_exists('image_url', $payload)) {
        $fields['image_url'] = validateAdminImageUrl($payload['image_url'] ?? null);
    }
    if ($creating || array_key_exists('is_active', $payload)) {
        $active = array_key_exists('is_active', $payload)
            ? $payload['is_active']
            : ($creating ? true : null);

        if (!is_bool($active)) {
            throw new HttpException(422, 'is_active must be a boolean.');
        }

        $fields['is_active'] = $active;
    }
    if ($creating || array_key_exists('category_ids', $payload)) {
        $fields['category_ids'] = validateAdminIdList(
            array_key_exists('category_ids', $payload) ? $payload['category_ids'] : [],
            'category_ids'
        );
    }
    if ($creating || array_key_exists('platform_ids', $payload)) {
        $fields['platform_ids'] = validateAdminIdList(
            array_key_exists('platform_ids', $payload) ? $payload['platform_ids'] : [],
            'platform_ids'
        );
    }

    return $fields;
}

function validateAdminText(mixed $value, string $field, int $maximumLength, bool $nullable): ?string
{
    if ($nullable && ($value === null || $value === '')) {
        return null;
    }

    if (!is_string($value)) {
        throw new HttpException(422, $field . ' must be a string.');
    }

    $value = trim($value);
    $length = preg_match_all('/./us', $value);

    if (
        $length === false
        || ($field === 'title' && $length < 1)
        || $length > $maximumLength
        || ($field === 'description' && strlen($value) > $maximumLength)
    ) {
        throw new HttpException(422, $field . ' has an invalid length.');
    }

    return $value;
}

function validateAdminPrice(mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new HttpException(422, 'price must be a non-negative amount with up to two decimals.');
    }

    if (is_float($value) && !is_finite($value)) {
        throw new HttpException(422, 'price must be a finite amount.');
    }

    $price = (string) $value;

    if (preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $price, $matches) !== 1) {
        throw new HttpException(422, 'price must be a non-negative amount with up to two decimals.');
    }

    return $matches[1] . '.' . str_pad($matches[2] ?? '', 2, '0');
}

function validateAdminInteger(mixed $value, string $field, int $minimum, int $maximum): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new HttpException(422, $field . ' must be an integer.');
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT);

    if ($integer === false || $integer < $minimum || $integer > $maximum) {
        throw new HttpException(422, $field . ' is outside the allowed range.');
    }

    return $integer;
}

function validateAdminDate(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new HttpException(422, 'release_date must use YYYY-MM-DD format.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new HttpException(422, 'release_date must use a valid YYYY-MM-DD date.');
    }

    return $value;
}

function validateAdminImageUrl(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value) || strlen($value) > 2048) {
        throw new HttpException(422, 'image_url must be a valid HTTP or HTTPS URL.');
    }

    $parts = parse_url($value);

    if (
        filter_var($value, FILTER_VALIDATE_URL) === false
        || $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new HttpException(422, 'image_url must be a valid HTTP or HTTPS URL.');
    }

    return $value;
}

function validateAdminIdList(mixed $value, string $field): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
        throw new HttpException(422, $field . ' must be a list of at most 50 ids.');
    }

    $ids = [];

    foreach ($value as $id) {
        $ids[] = validateAdminInteger($id, $field, 1, PHP_INT_MAX);
    }

    if (count(array_unique($ids)) !== count($ids)) {
        throw new HttpException(422, $field . ' must not contain duplicate ids.');
    }

    return $ids;
}

function validateAdminGameId(mixed $value): int
{
    return validateAdminInteger($value, 'id', 1, PHP_INT_MAX);
}

function validateAdminPagination(array $query): array
{
    $page = array_key_exists('page', $query)
        ? validateAdminInteger($query['page'], 'page', 1, PHP_INT_MAX)
        : 1;
    $perPage = array_key_exists('per_page', $query)
        ? validateAdminInteger($query['per_page'], 'per_page', 1, 100)
        : 20;

    if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
        throw new HttpException(422, 'Requested page is outside the supported range.');
    }

    return [$page, $perPage];
}
