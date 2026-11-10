<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Result: STOP\nReason: this preflight can only run from PHP CLI.\n");
    exit(1);
}

try {
    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): void {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    );
    require_once __DIR__ . '/../config/environment.php';
} catch (Throwable $exception) {
    restore_error_handler();
    fwrite(STDERR, "Result: STOP\nReason: Required environment configuration could not be loaded.\n");
    exit(1);
}
restore_error_handler();

$processEnvironment = getenv('APP_ENV');
$processDatabase = getenv('MYSQL_DATABASE');

if ($processEnvironment !== 'test' || $processDatabase !== 'mirai_games_test') {
    fwrite(
        STDERR,
        "Result: STOP\nReason: set process APP_ENV=test and MYSQL_DATABASE=mirai_games_test before running.\n"
    );
    exit(1);
}

try {
    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): void {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    );
    loadEnvironmentFile(__DIR__ . '/../.env');
} catch (Throwable $exception) {
    restore_error_handler();
    fwrite(STDERR, "Result: STOP\nReason: Environment configuration could not be loaded.\n");
    exit(1);
}
restore_error_handler();

if (getenv('APP_ENV') !== 'test' || getenv('MYSQL_DATABASE') !== 'mirai_games_test') {
    fwrite(STDERR, "Result: STOP\nReason: the effective test database configuration is invalid.\n");
    exit(1);
}

try {
    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): void {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    );
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $exception) {
    restore_error_handler();
    fwrite(STDERR, "Result: STOP\nReason: Required database configuration could not be loaded.\n");
    exit(1);
}
restore_error_handler();

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

const PREFLIGHT_DATABASE = 'mirai_games_test';

$expectedTables = [
    'cart_details',
    'carts',
    'categories',
    'game_categories',
    'game_platforms',
    'games',
    'order_details',
    'orders',
    'platforms',
    'stripe_webhook_events',
    'users',
];

$columnDefinitions = [
    'users' => [
        ['id', 'bigint unsigned', 'NO', null, 'auto_increment'],
        ['full_name', 'varchar(100)', 'NO', null, ''],
        ['email', 'varchar(254)', 'NO', null, ''],
        ['password_hash', 'varchar(255)', 'NO', null, ''],
        ['role', "enum('customer','admin')", 'NO', 'customer', ''],
        ['created_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', ''],
        ['updated_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', 'on update CURRENT_TIMESTAMP'],
    ],
    'categories' => [
        ['id', 'int unsigned', 'NO', null, 'auto_increment'],
        ['name', 'varchar(80)', 'NO', null, ''],
    ],
    'platforms' => [
        ['id', 'int unsigned', 'NO', null, 'auto_increment'],
        ['name', 'varchar(50)', 'NO', null, ''],
    ],
    'games' => [
        ['id', 'bigint unsigned', 'NO', null, 'auto_increment'],
        ['title', 'varchar(150)', 'NO', null, ''],
        ['description', 'text', 'YES', null, ''],
        ['price', 'decimal(10,2)', 'NO', null, ''],
        ['stock', 'int unsigned', 'NO', '0', ''],
        ['release_date', 'date', 'YES', null, ''],
        ['image_url', 'varchar(2048)', 'YES', null, ''],
        ['is_active', 'tinyint(1)', 'NO', '1', ''],
        ['created_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', ''],
        ['updated_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', 'on update CURRENT_TIMESTAMP'],
    ],
    'game_categories' => [
        ['game_id', 'bigint unsigned', 'NO', null, ''],
        ['category_id', 'int unsigned', 'NO', null, ''],
    ],
    'game_platforms' => [
        ['game_id', 'bigint unsigned', 'NO', null, ''],
        ['platform_id', 'int unsigned', 'NO', null, ''],
    ],
    'carts' => [
        ['id', 'bigint unsigned', 'NO', null, 'auto_increment'],
        ['user_id', 'bigint unsigned', 'NO', null, ''],
        ['created_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', ''],
        ['updated_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', 'on update CURRENT_TIMESTAMP'],
    ],
    'cart_details' => [
        ['cart_id', 'bigint unsigned', 'NO', null, ''],
        ['game_id', 'bigint unsigned', 'NO', null, ''],
        ['quantity', 'int unsigned', 'NO', null, ''],
    ],
    'orders' => [
        ['id', 'bigint unsigned', 'NO', null, 'auto_increment'],
        ['user_id', 'bigint unsigned', 'NO', null, ''],
        ['stripe_session_id', 'varchar(255)', 'YES', null, ''],
        ['checkout_idempotency_key', 'varchar(255)', 'YES', null, ''],
        ['payment_status', "enum('pending','paid','failed','expired')", 'NO', 'pending', ''],
        ['created_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', ''],
        ['updated_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', 'on update CURRENT_TIMESTAMP'],
        ['paid_at', 'datetime', 'YES', null, ''],
        ['reservation_expires_at', 'datetime', 'YES', null, ''],
        ['inventory_status', "enum('not_reserved','reserved','committed','released')", 'NO', 'not_reserved', ''],
    ],
    'order_details' => [
        ['order_id', 'bigint unsigned', 'NO', null, ''],
        ['game_id', 'bigint unsigned', 'NO', null, ''],
        ['game_title', 'varchar(150)', 'NO', null, ''],
        ['quantity', 'int unsigned', 'NO', null, ''],
        ['unit_price', 'decimal(10,2)', 'NO', null, ''],
    ],
    'stripe_webhook_events' => [
        ['event_id', 'varchar(255)', 'NO', null, ''],
        ['event_type', 'varchar(100)', 'NO', null, ''],
        ['order_id', 'bigint unsigned', 'YES', null, ''],
        ['processed_at', 'datetime', 'NO', 'CURRENT_TIMESTAMP', ''],
    ],
];

$expectedTableCollations = array_fill_keys($expectedTables, 'utf8mb4_unicode_ci');

$expectedColumnCharsets = [
    'stripe_webhook_events.event_id' => ['ascii', 'ascii_bin'],
];

$expectedIndexes = [
    'users' => [
        'PRIMARY' => [0, ['id']],
        'uq_users_email' => [0, ['email']],
    ],
    'categories' => [
        'PRIMARY' => [0, ['id']],
        'uq_categories_name' => [0, ['name']],
    ],
    'platforms' => [
        'PRIMARY' => [0, ['id']],
        'uq_platforms_name' => [0, ['name']],
    ],
    'games' => [
        'PRIMARY' => [0, ['id']],
        'idx_games_title' => [1, ['title']],
        'idx_games_price' => [1, ['price']],
        'idx_games_active_price' => [1, ['is_active', 'price']],
    ],
    'game_categories' => [
        'PRIMARY' => [0, ['game_id', 'category_id']],
        'idx_game_categories_category_game' => [1, ['category_id', 'game_id']],
    ],
    'game_platforms' => [
        'PRIMARY' => [0, ['game_id', 'platform_id']],
        'idx_game_platforms_platform_game' => [1, ['platform_id', 'game_id']],
    ],
    'carts' => [
        'PRIMARY' => [0, ['id']],
        'uq_carts_user_id' => [0, ['user_id']],
    ],
    'cart_details' => [
        'PRIMARY' => [0, ['cart_id', 'game_id']],
        'idx_cart_details_game' => [1, ['game_id']],
    ],
    'orders' => [
        'PRIMARY' => [0, ['id']],
        'uq_orders_stripe_session_id' => [0, ['stripe_session_id']],
        'uq_orders_checkout_idempotency_key' => [0, ['checkout_idempotency_key']],
        'idx_orders_user_created' => [1, ['user_id', 'created_at']],
        'idx_orders_reservation_cleanup' => [1, ['inventory_status', 'reservation_expires_at']],
    ],
    'order_details' => [
        'PRIMARY' => [0, ['order_id', 'game_id']],
        'idx_order_details_game' => [1, ['game_id']],
    ],
    'stripe_webhook_events' => [
        'PRIMARY' => [0, ['event_id']],
        'idx_stripe_webhook_events_order' => [1, ['order_id', 'processed_at']],
    ],
];

$expectedForeignKeys = [
    'fk_game_categories_game' => ['game_categories', ['game_id'], 'games', ['id'], 'CASCADE', 'CASCADE'],
    'fk_game_categories_category' => ['game_categories', ['category_id'], 'categories', ['id'], 'CASCADE', 'CASCADE'],
    'fk_game_platforms_game' => ['game_platforms', ['game_id'], 'games', ['id'], 'CASCADE', 'CASCADE'],
    'fk_game_platforms_platform' => ['game_platforms', ['platform_id'], 'platforms', ['id'], 'CASCADE', 'CASCADE'],
    'fk_carts_user' => ['carts', ['user_id'], 'users', ['id'], 'CASCADE', 'CASCADE'],
    'fk_cart_details_cart' => ['cart_details', ['cart_id'], 'carts', ['id'], 'CASCADE', 'CASCADE'],
    'fk_cart_details_game' => ['cart_details', ['game_id'], 'games', ['id'], 'CASCADE', 'CASCADE'],
    'fk_orders_user' => ['orders', ['user_id'], 'users', ['id'], 'RESTRICT', 'CASCADE'],
    'fk_order_details_order' => ['order_details', ['order_id'], 'orders', ['id'], 'RESTRICT', 'CASCADE'],
    'fk_order_details_game' => ['order_details', ['game_id'], 'games', ['id'], 'RESTRICT', 'CASCADE'],
    'fk_stripe_webhook_events_order' => ['stripe_webhook_events', ['order_id'], 'orders', ['id'], 'SET NULL', 'CASCADE'],
];

$expectedChecks = [
    'games.chk_games_price_nonnegative' => 'price >= 0',
    'games.chk_games_stock_nonnegative' => 'stock >= 0',
    'cart_details.chk_cart_details_quantity_positive' => 'quantity > 0',
    'orders.chk_orders_paid_at_matches_status' =>
        "(payment_status = 'paid' AND paid_at IS NOT NULL) OR (payment_status <> 'paid' AND paid_at IS NULL)",
    'order_details.chk_order_details_quantity_positive' => 'quantity > 0',
    'order_details.chk_order_details_unit_price_nonnegative' => 'unit_price >= 0',
];

$newMigrationColumns = [
    'games.rawg_id',
    'game_platforms.price',
    'game_platforms.stock',
    'cart_details.platform_id',
    'cart_details.id',
    'order_details.platform_id',
    'order_details.id',
];

$newMigrationObjects = [
    'uq_games_rawg_id',
    'chk_game_platforms_price_nonnegative',
    'chk_game_platforms_stock_nonnegative',
    'uq_cart_details_cart_game_platform',
    'idx_cart_details_game_platform',
    'fk_cart_details_game_platform',
    'uq_order_details_order_game_platform',
    'idx_order_details_game_platform',
    'fk_order_details_game_platform',
];

$checksPassed = [];
$stage = 'Connecting to MySQL';
try {
    $stage = 'Connecting to MySQL';
    $connection = databaseConnection();

    $stage = 'Identifying the active database';
    $identity = $connection->query(
        'SELECT DATABASE() AS active_schema, VERSION() AS mysql_version,
                @@version_comment AS version_comment'
    )->fetch();

    if (
        !is_array($identity)
        || ($identity['active_schema'] ?? null) !== PREFLIGHT_DATABASE
    ) {
        stopPreflight('The connected schema is not mirai_games_test.');
    }
    passCheck('Target schema is exactly mirai_games_test.');

    $stage = 'Checking the MySQL version';
    $version = (string) ($identity['mysql_version'] ?? '');
    $versionComment = (string) ($identity['version_comment'] ?? '');
    if (
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $versionParts) !== 1
        || stripos($version, 'mariadb') !== false
        || stripos($versionComment, 'mariadb') !== false
        || version_compare(
            $versionParts[1] . '.' . $versionParts[2] . '.' . $versionParts[3],
            '8.0.16',
            '<'
        )
    ) {
        stopPreflight('MySQL 8.0.16 or later could not be confirmed.');
    }
    passCheck('MySQL version is compatible.');

    $stage = 'Checking tables and engines';
    verifyTables($connection, $expectedTables, $expectedTableCollations);

    $stage = 'Checking columns';
    verifyColumns($connection, $columnDefinitions, $expectedColumnCharsets);

    $stage = 'Checking indexes';
    verifyIndexes($connection, $expectedIndexes);

    $stage = 'Checking foreign keys';
    verifyForeignKeys($connection, $expectedForeignKeys);

    $stage = 'Checking CHECK constraints';
    verifyChecks($connection, $expectedChecks);
    passCheck('The complete source schema and previous migration match expectations.');

    $stage = 'Checking MySQL permissions and roles';
    verifyReadOnlyPrivileges($connection);
    passCheck('Local read-only privileges are verified; global metadata visibility has not been certified.');

    $stage = 'Checking for objects from the new migration';
    verifyNewMigrationObjectsAbsent(
        $connection,
        $newMigrationColumns,
        $newMigrationObjects
    );
    passCheck('No object from the new migration already exists.');

    $stage = 'Checking games without platforms';
    $gamesWithoutPlatforms = (int) $connection->query(
        'SELECT COUNT(*)
         FROM games g
         LEFT JOIN game_platforms gp ON gp.game_id = g.id
         WHERE gp.game_id IS NULL'
    )->fetchColumn();
    if ($gamesWithoutPlatforms !== 0) {
        stopPreflight('One or more games have no platform relationship.');
    }
    passCheck('Every game has at least one platform relationship.');

    $stage = 'Checking active reservations';
    $activeReservations = (int) $connection->query(
        "SELECT COUNT(*)
         FROM orders
         WHERE payment_status = 'pending'
           AND inventory_status = 'reserved'"
    )->fetchColumn();
    if ($activeReservations !== 0) {
        stopPreflight('Active pending/reserved orders must be drained before migration.');
    }
    passCheck('There are no active pending/reserved orders.');

    $stage = 'Reading table counts';
    $counts = readTableCounts($connection, $expectedTables);

    $stage = 'Checking historical order details';
    $historicalSnapshot = hashHistoricalOrderDetails($connection);

    fwrite(STDOUT, "MiraiGames platform migration preflight\n");
    foreach ($checksPassed as $check) {
        fwrite(STDOUT, "PASS: " . $check . "\n");
    }
    fwrite(STDOUT, "Table row counts:\n");
    foreach ($counts as $table => $count) {
        fwrite(STDOUT, "  " . $table . ": " . $count . "\n");
    }
    fwrite(
        STDOUT,
        'Historical order_details: '
        . $historicalSnapshot['rows']
        . ' rows; SHA-256 '
        . $historicalSnapshot['sha256']
        . "\n"
    );
    fwrite(STDOUT, "Result: LOCAL_CHECKS_PASSED\n");
    fwrite(STDOUT, "Administrative result: ADMIN_FK_REVIEW_REQUIRED\n");
    fwrite(
        STDERR,
        "Result: STOP\nReason: incoming foreign keys require an independent administrative review; local checks cannot certify global metadata visibility.\nNo migration was run.\n"
    );
    exit(1);
} catch (Throwable $exception) {
    if ($exception instanceof PreflightFailure) {
        $message = $exception->getMessage();
    } else {
        $exceptionCode = (string) $exception->getCode();
        $message = 'Stage "' . $stage . '" failed with '
            . get_class($exception)
            . ' (code ' . $exceptionCode . ')';

        if ($exception instanceof PDOException) {
            $sqlState = $exception->errorInfo[0] ?? null;
            if (is_string($sqlState) && preg_match('/^[A-Z0-9]{5}$/i', $sqlState) === 1) {
                $message .= ', SQLSTATE ' . strtoupper($sqlState);
            }
        }
        $message .= '.';
    }

    fwrite(STDERR, "Result: STOP\nReason: " . $message . "\nNo migration was run.\n");
    exit(1);
}

function verifyTables(PDO $connection, array $expectedTables, array $expectedCollations): void
{
    $rows = $connection->query(
        "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME"
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        if ($row['TABLE_TYPE'] !== 'BASE TABLE') {
            stopPreflight('The target schema contains an unexpected non-table object.');
        }
        $actual[$row['TABLE_NAME']] = $row;
    }

    $expectedSorted = $expectedTables;
    $actualNames = array_keys($actual);
    sort($expectedSorted);
    sort($actualNames);
    if ($actualNames !== $expectedSorted) {
        stopPreflight('The target schema does not contain exactly the 11 expected tables.');
    }

    foreach ($expectedTables as $table) {
        if (
            $actual[$table]['ENGINE'] !== 'InnoDB'
            || $actual[$table]['TABLE_COLLATION'] !== $expectedCollations[$table]
        ) {
            stopPreflight('A table has an unexpected engine or collation.');
        }
    }
    passCheck('All 11 expected tables use InnoDB and the expected collation.');
}

function verifyColumns(
    PDO $connection,
    array $definitions,
    array $specialCharsets
): void {
    $rows = $connection->query(
        'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
                IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $table = $row['TABLE_NAME'];
        $actual[$table][] = $row;
    }

    if (array_keysSorted($actual) !== array_keysSorted($definitions)) {
        stopPreflight('The set of tables with columns does not match the expected schema.');
    }

    foreach ($definitions as $table => $columns) {
        if (count($actual[$table] ?? []) !== count($columns)) {
            stopPreflight('A table has a missing or unexpected column.');
        }

        foreach ($columns as $position => $expected) {
            $row = $actual[$table][$position];
            [$name, $type, $nullable, $default, $extra] = $expected;
            $ordinalPosition = $position + 1;
            if ($row['COLUMN_NAME'] !== $name) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property COLUMN_NAME; expected ' . diagnosticValue($name)
                    . ', found ' . diagnosticValue($row['COLUMN_NAME']) . '.'
                );
            }
            if ((int) $row['ORDINAL_POSITION'] !== $ordinalPosition) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property ORDINAL_POSITION; expected ' . $ordinalPosition
                    . ', found ' . diagnosticValue($row['ORDINAL_POSITION']) . '.'
                );
            }
            if (strtolower((string) $row['COLUMN_TYPE']) !== strtolower($type)) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property COLUMN_TYPE; expected ' . diagnosticValue($type)
                    . ', found ' . diagnosticValue($row['COLUMN_TYPE']) . '.'
                );
            }
            if ($row['IS_NULLABLE'] !== $nullable) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property IS_NULLABLE; expected ' . diagnosticValue($nullable)
                    . ', found ' . diagnosticValue($row['IS_NULLABLE']) . '.'
                );
            }

            $actualDefault = normalizeDefault($row['COLUMN_DEFAULT']);
            $expectedDefault = normalizeDefault($default);
            if ($actualDefault !== $expectedDefault) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property COLUMN_DEFAULT; expected ' . diagnosticValue($default)
                    . ', found ' . diagnosticValue($row['COLUMN_DEFAULT']) . '.'
                );
            }

            if (!columnExtraMatches((string) $row['EXTRA'], $extra, $default)) {
                stopPreflight(
                    'Column mismatch: ' . $table . '.' . $name
                    . '; property EXTRA; expected ' . diagnosticValue($extra)
                    . ' (optionally DEFAULT_GENERATED for CURRENT_TIMESTAMP defaults), found '
                    . diagnosticValue($row['EXTRA']) . '.'
                );
            }

            $key = $table . '.' . $name;
            if (isset($specialCharsets[$key])) {
                [$charset, $collation] = $specialCharsets[$key];
                if ($row['CHARACTER_SET_NAME'] !== $charset) {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property CHARACTER_SET_NAME; expected ' . diagnosticValue($charset)
                        . ', found ' . diagnosticValue($row['CHARACTER_SET_NAME']) . '.'
                    );
                }
                if ($row['COLLATION_NAME'] !== $collation) {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property COLLATION_NAME; expected ' . diagnosticValue($collation)
                        . ', found ' . diagnosticValue($row['COLLATION_NAME']) . '.'
                    );
                }
            } elseif (isStringColumn($type)) {
                if ($row['CHARACTER_SET_NAME'] !== 'utf8mb4') {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property CHARACTER_SET_NAME; expected "utf8mb4", found '
                        . diagnosticValue($row['CHARACTER_SET_NAME']) . '.'
                    );
                }
                if ($row['COLLATION_NAME'] !== 'utf8mb4_unicode_ci') {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property COLLATION_NAME; expected "utf8mb4_unicode_ci", found '
                        . diagnosticValue($row['COLLATION_NAME']) . '.'
                    );
                }
            } else {
                if ($row['CHARACTER_SET_NAME'] !== null) {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property CHARACTER_SET_NAME; expected NULL, found '
                        . diagnosticValue($row['CHARACTER_SET_NAME']) . '.'
                    );
                }
                if ($row['COLLATION_NAME'] !== null) {
                    stopPreflight(
                        'Column mismatch: ' . $table . '.' . $name
                        . '; property COLLATION_NAME; expected NULL, found '
                        . diagnosticValue($row['COLLATION_NAME']) . '.'
                    );
                }
            }
        }
    }
    passCheck('All table columns, types, defaults, nullability and character metadata match.');
}

function verifyIndexes(PDO $connection, array $expectedIndexes): void
{
    $rows = $connection->query(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
                SUB_PART, INDEX_TYPE, IS_VISIBLE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        if (
            $row['SUB_PART'] !== null
            || $row['INDEX_TYPE'] !== 'BTREE'
            || !in_array($row['IS_VISIBLE'], ['YES', 'NO'], true)
        ) {
            stopPreflight('An index uses an unexpected prefix or index type.');
        }
        $actual[$row['TABLE_NAME']][$row['INDEX_NAME']]['unique'] = (int) $row['NON_UNIQUE'];
        $actual[$row['TABLE_NAME']][$row['INDEX_NAME']]['visible'] = $row['IS_VISIBLE'];
        $actual[$row['TABLE_NAME']][$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
    }

    if (canonicalizeIndexes($actual) !== canonicalizeIndexes($expectedIndexes)) {
        stopPreflight('The indexes or ordered index columns differ from the expected schema.');
    }
    passCheck('Primary, unique and secondary indexes match the expected definitions.');
}

function verifyForeignKeys(PDO $connection, array $expectedForeignKeys): void
{
    $rows = $connection->query(
        'SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                kcu.ORDINAL_POSITION, kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          AND rc.TABLE_NAME = kcu.TABLE_NAME
         WHERE kcu.TABLE_SCHEMA = DATABASE()
           AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $name = $row['CONSTRAINT_NAME'];
        $actual[$name]['table'] = $row['TABLE_NAME'];
        $actual[$name]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
        $actual[$name]['delete'] = strtoupper($row['DELETE_RULE']);
        $actual[$name]['update'] = strtoupper($row['UPDATE_RULE']);
        $actual[$name]['columns'][] = $row['COLUMN_NAME'];
        $actual[$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
    }

    ksort($actual);
    ksort($expectedForeignKeys);
    if ($actual !== canonicalizeForeignKeys($expectedForeignKeys)) {
        stopPreflight('Foreign keys or their referential actions differ from the expected schema.');
    }
    passCheck('All foreign keys and referential actions match the expected schema.');
}

function verifyChecks(PDO $connection, array $expectedChecks): void
{
    $rows = $connection->query(
        "SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED
         FROM information_schema.TABLE_CONSTRAINTS tc
         INNER JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
          AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
           AND tc.CONSTRAINT_TYPE = 'CHECK'
         ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME"
    )->fetchAll();

    $actual = [];
    $actualExpressions = [];
    foreach ($rows as $row) {
        if ($row['ENFORCED'] !== 'YES') {
            stopPreflight('A CHECK constraint is not enforced.');
        }
        $checkName = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
        $actualExpressions[$checkName] = $row['CHECK_CLAUSE'];
        $actual[$checkName] =
            normalizeCheckExpression(
                $row['CHECK_CLAUSE'],
                'MySQL CHECK_CLAUSE',
                $row['TABLE_NAME'],
                $row['CONSTRAINT_NAME']
            );
    }

    $expected = [];
    foreach ($expectedChecks as $name => $expression) {
        [$table, $constraint] = explode('.', $name, 2);
        $expected[$name] = normalizeCheckExpression(
            $expression,
            'expected expression',
            $table,
            $constraint
        );
    }
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) {
        $differences = [];
        $allNames = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        sort($allNames);

        foreach ($allNames as $checkName) {
            $hasExpected = array_key_exists($checkName, $expected);
            $hasActual = array_key_exists($checkName, $actual);
            if ($hasExpected && $hasActual && $expected[$checkName] === $actual[$checkName]) {
                continue;
            }

            if (!$hasExpected) {
                $differences[] = $checkName . ' (unexpected in database)';
                continue;
            }
            if (!$hasActual) {
                $differences[] = $checkName . ' (missing from database)';
                continue;
            }

            $expectedExpression = $expectedChecks[$checkName];
            $actualExpression = $actualExpressions[$checkName];
            $differences[] = $checkName
                . ' (normalized signatures differ; expected '
                . safeCheckExpressionSignature($expectedExpression)
                . '; MySQL '
                . safeCheckExpressionSignature($actualExpression)
                . '; expected SHA-256 '
                . hash('sha256', $expected[$checkName])
                . '; MySQL SHA-256 '
                . hash('sha256', $actual[$checkName])
                . ')';

            if ($checkName === 'orders.chk_orders_paid_at_matches_status') {
                $differences[] = detailedCheckMismatchDiagnostic(
                    $expectedExpression,
                    $actualExpression,
                    $expected[$checkName],
                    $actual[$checkName]
                );
            }
        }

        stopPreflight(
            'CHECK constraints differ after normalization: '
            . implode('; ', $differences)
            . '.'
        );
    }
    passCheck('All CHECK constraints exist, match and are enforced.');
}

function verifyNewMigrationObjectsAbsent(
    PDO $connection,
    array $columns,
    array $objects
): void {
    $columnStatement = $connection->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    foreach ($columns as $qualifiedName) {
        [$table, $column] = explode('.', $qualifiedName, 2);
        $columnStatement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        if ((int) $columnStatement->fetchColumn() !== 0) {
            stopPreflight('An object from the new migration already exists; possible partial migration.');
        }
    }

    $objectStatement = $connection->prepare(
        'SELECT
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = :index_name)
          + (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :constraint_name)'
    );
    foreach ($objects as $object) {
        $objectStatement->execute([
            'index_name' => $object,
            'constraint_name' => $object,
        ]);
        if ((int) $objectStatement->fetchColumn() !== 0) {
            stopPreflight('An index or constraint from the new migration already exists; possible partial migration.');
        }
    }
}

function readTableCounts(PDO $connection, array $tables): array
{
    $counts = [];
    foreach ($tables as $table) {
        $statement = $connection->query(
            'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`'
        );
        $counts[$table] = (int) $statement->fetchColumn();
    }
    return $counts;
}

function hashHistoricalOrderDetails(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT order_id, game_id, game_title, quantity, unit_price
         FROM order_details
         ORDER BY order_id ASC, game_id ASC'
    );
    $context = hash_init('sha256');
    $rows = 0;

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        hash_update($context, "R");
        foreach (['order_id', 'game_id', 'game_title', 'quantity', 'unit_price'] as $field) {
            $value = $row[$field];
            if ($value === null) {
                hash_update($context, "N;");
                continue;
            }

            $canonical = canonicalHistoricalValue($field, (string) $value);
            hash_update($context, 'V' . strlen($canonical) . ':' . $canonical . ';');
        }
        ++$rows;
    }

    return [
        'rows' => $rows,
        'sha256' => hash_final($context),
    ];
}

function canonicalHistoricalValue(string $field, string $value): string
{
    if (in_array($field, ['order_id', 'game_id', 'quantity'], true)) {
        if (preg_match('/^\d+$/', $value) !== 1) {
            stopPreflight('Historical numeric data could not be canonicalized.');
        }
        $canonical = ltrim($value, '0');
        return $canonical === '' ? '0' : $canonical;
    }

    if ($field === 'unit_price') {
        if (preg_match('/^(\d+)\.(\d{2})$/', $value, $matches) !== 1) {
            stopPreflight('Historical decimal data could not be canonicalized.');
        }
        $whole = ltrim($matches[1], '0');
        return ($whole === '' ? '0' : $whole) . '.' . $matches[2];
    }

    return $value;
}

function verifyReadOnlyPrivileges(PDO $connection): array
{
    $currentRole = (string) $connection->query('SELECT CURRENT_ROLE()')->fetchColumn();
    if ($currentRole !== 'NONE') {
        stopPreflight('An active MySQL role is present; inherited privileges cannot be certified by this preflight.');
    }

    $roles = $connection->query(
        'SELECT ROLE_NAME FROM information_schema.APPLICABLE_ROLES'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($roles !== []) {
        stopPreflight('Applicable MySQL roles were found; inherited privileges cannot be certified by this preflight.');
    }

    $grants = $connection->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    if ($grants === []) {
        stopPreflight('MySQL grants could not be inspected.');
    }

    $verified = 0;
    $targetSelectVerified = false;
    foreach ($grants as $grant) {
        if (!is_string($grant)) {
            stopPreflight('A MySQL grant could not be interpreted.');
        }

        $grant = trim($grant);
        if (stripos($grant, 'WITH GRANT OPTION') !== false
            || stripos($grant, 'WITH ADMIN OPTION') !== false
        ) {
            stopPreflight('A MySQL grant permits granting privileges or roles.');
        }

        if (
            preg_match(
                '/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+(.+)$/i',
                $grant,
                $matches
            ) !== 1
        ) {
            stopPreflight('A grant or role assignment could not be safely interpreted.');
        }
        if (
            preg_match(
                "/^'.*'@'.*'$/",
                trim($matches[3])
            ) !== 1
            && preg_match('/^`.*`@`.*`$/', trim($matches[3])) !== 1
        ) {
            stopPreflight('A MySQL grant target uses an unrecognized format.');
        }

        $privileges = array_map(
            static fn (string $privilege): string => strtoupper(trim($privilege)),
            explode(',', $matches[1])
        );
        $scope = strtolower(str_replace('`', '', trim($matches[2])));
        $allowedPrivileges = ['SELECT'];
        $isUsage = $privileges === ['USAGE'] && $scope === '*.*';
        $isTargetSelect = $privileges === ['SELECT']
            && $scope === 'mirai_games_test.*';

        if (!$isUsage && !$isTargetSelect) {
            stopPreflight('MySQL grants exceed the permitted read-only scope.');
        }
        if ($isTargetSelect) {
            $targetSelectVerified = true;
        }
        ++$verified;
    }

    if ($verified === 0) {
        stopPreflight('No verifiable read-only grants were found.');
    }
    if (!$targetSelectVerified) {
        stopPreflight('SELECT on mirai_games_test.* is required for local preflight checks.');
    }

    return [
        'grant_entries_verified' => $verified,
        'applicable_roles' => 0,
        'target_schema_select_verified' => true,
        'global_metadata_visibility' => false,
    ];
}

function canonicalizeIndexes(array $indexes): array
{
    $result = [];
    foreach ($indexes as $table => $tableIndexes) {
        foreach ($tableIndexes as $name => $definition) {
            $result[$table][$name] = [
                'unique' => (int) ($definition['unique'] ?? $definition[0]),
                'columns' => array_values($definition['columns'] ?? $definition[1]),
                'visible' => $definition['visible'] ?? 'YES',
            ];
        }
    }
    ksort($result);
    foreach ($result as &$tableIndexes) {
        ksort($tableIndexes);
    }
    unset($tableIndexes);
    return $result;
}

function canonicalizeForeignKeys(array $foreignKeys): array
{
    $result = [];
    foreach ($foreignKeys as $name => $definition) {
        [
            $table,
            $columns,
            $referencedTable,
            $referencedColumns,
            $deleteRule,
            $updateRule,
        ] = $definition;
        $result[$name] = [
            'table' => $table,
            'referenced_table' => $referencedTable,
            'delete' => $deleteRule,
            'update' => $updateRule,
            'columns' => $columns,
            'referenced_columns' => $referencedColumns,
        ];
    }
    ksort($result);
    return $result;
}

function normalizeDefault(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    return strtoupper(trim((string) $value));
}

function columnExtraMatches(string $actual, string $expected, ?string $default): bool
{
    $normalize = static fn (string $value): string => strtolower(trim($value));
    $actual = $normalize($actual);
    $expected = $normalize($expected);

    if ($actual === $expected) {
        return true;
    }

    if (normalizeDefault($default) !== 'CURRENT_TIMESTAMP') {
        return false;
    }

    if ($expected === '') {
        return $actual === 'default_generated';
    }

    if ($expected === 'on update current_timestamp') {
        return $actual === 'default_generated on update current_timestamp';
    }

    return false;
}

function diagnosticValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if ($value === '') {
        return '""';
    }

    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '"[unprintable]"';
}

function normalizeCheckExpression(
    string $expression,
    string $source,
    string $table,
    string $constraint
): string
{
    $tokens = [];
    $quote = null;
    $quoteStartPosition = null;
    $length = strlen($expression);
    $mysqlClause = $source === 'MySQL CHECK_CLAUSE';

    for ($index = 0; $index < $length; ++$index) {
        $character = $expression[$index];

        if ($quote === null && ctype_space($character)) {
            continue;
        }

        if ($quote !== null) {
            $quoteBuffer = &$tokens[count($tokens) - 1];
            if ($character === '\\') {
                $nextCharacter = $expression[$index + 1] ?? null;
                if ($mysqlClause && $nextCharacter === $quote) {
                    $quoteBuffer .= $quote;
                    ++$index;
                    $quote = null;
                    $quoteStartPosition = null;
                    unset($quoteBuffer);
                    continue;
                }

                unset($quoteBuffer);
                stopPreflight(
                    'Unrecognized or ambiguous backslash escape in CHECK expression from '
                    . $source . ' for ' . $table . '.' . $constraint
                    . ' at byte position ' . ($index + 1) . '.'
                );
            }

            $quoteBuffer .= $character;
            if ($character === $quote) {
                if ($index + 1 < $length && $expression[$index + 1] === $quote) {
                    $quoteBuffer .= $expression[++$index];
                    continue;
                }
                $quote = null;
                $quoteStartPosition = null;
            }
            unset($quoteBuffer);
            continue;
        }

        if ($character === '\\') {
            $nextCharacter = $expression[$index + 1] ?? null;
            if (
                $mysqlClause
                && ($nextCharacter === "'" || $nextCharacter === '"')
            ) {
                $quote = $nextCharacter;
                $quoteStartPosition = $index + 1;
                $tokens[] = $quote;
                ++$index;
                continue;
            }

            stopPreflight(
                'Unrecognized or ambiguous backslash escape in CHECK expression from '
                . $source . ' for ' . $table . '.' . $constraint
                . ' at byte position ' . ($index + 1) . '.'
            );
        }

        if ($character === "'" || $character === '"') {
            $quote = $character;
            $quoteStartPosition = $index;
            $tokens[] = $character;
            continue;
        }

        if ($character === '`') {
            $quoteStartPosition = $index;
            $identifier = '';
            $closed = false;
            for (++$index; $index < $length; ++$index) {
                if ($expression[$index] !== '`') {
                    $identifier .= $expression[$index];
                    continue;
                }
                if ($index + 1 < $length && $expression[$index + 1] === '`') {
                    $identifier .= '`';
                    ++$index;
                    continue;
                }
                $closed = true;
                break;
            }
            if (!$closed) {
                stopPreflight(
                    checkExpressionQuoteDiagnostic(
                        $source,
                        $table,
                        $constraint,
                        $expression,
                        $quoteStartPosition,
                        '`',
                        'identifier'
                    )
                );
            }
            $tokens[] = 'I' . strtolower($identifier);
            $quoteStartPosition = null;
            continue;
        }

        if (ctype_alnum($character) || $character === '_' || $character === '$') {
            $atom = $character;
            while (
                $index + 1 < $length
                && (ctype_alnum($expression[$index + 1])
                    || $expression[$index + 1] === '_'
                    || $expression[$index + 1] === '$')
            ) {
                $atom .= $expression[++$index];
            }

            if (
                $mysqlClause
                && strtolower($atom) === '_utf8mb4'
                && (
                    in_array($expression[$index + 1] ?? null, ["'", '"'], true)
                    || (
                        ($expression[$index + 1] ?? null) === '\\'
                        && in_array($expression[$index + 2] ?? null, ["'", '"'], true)
                    )
                )
            ) {
                continue;
            }

            $tokens[] = in_array(strtolower($atom), ['and', 'or'], true)
                ? 'A' . strtolower($atom)
                : 'I' . strtolower($atom);
            continue;
        }

        $operator = $character;
        if (
            in_array($character, ['>', '<', '!', '&', '|'], true)
            && $index + 1 < $length
            && in_array($expression[$index + 1], ['=', '>', '<', '&', '|'], true)
        ) {
            $operator .= $expression[++$index];
        }
        $tokens[] = 'P' . $operator;
    }

    if ($quote !== null) {
        stopPreflight(
            checkExpressionQuoteDiagnostic(
                $source,
                $table,
                $constraint,
                $expression,
                $quoteStartPosition ?? 0,
                $quote,
                'value'
            )
        );
    }

    if ($tokens === []) {
        stopPreflight('A CHECK expression is empty.');
    }
    validateCheckExpressionTokens($tokens);

    $tokenIndex = 0;
    $expressionTree = parseCheckOrExpression($tokens, $tokenIndex);
    if ($tokenIndex !== count($tokens)) {
        stopPreflight('A CHECK expression contains unsupported or unbalanced syntax.');
    }

    return serializeCheckExpressionTree($expressionTree);
}

function validateCheckExpressionTokens(array $tokens): void
{
    $allowedPunctuation = ['P(', 'P)', 'P=', 'P>', 'P<', 'P>=', 'P<=', 'P<>', 'P!='];
    foreach ($tokens as $token) {
        if (
            in_array($token, $allowedPunctuation, true)
            || $token === 'Aand'
            || $token === 'Aor'
            || str_starts_with($token, 'I')
        ) {
            continue;
        }

        if (
            (str_starts_with($token, "'") && str_ends_with($token, "'"))
            || (str_starts_with($token, '"') && str_ends_with($token, '"'))
        ) {
            continue;
        }

        stopPreflight('A CHECK expression contains unsupported or unrecognized syntax.');
    }
}

function parseCheckOrExpression(array $tokens, int &$index): array
{
    $children = [parseCheckAndExpression($tokens, $index)];
    while (($tokens[$index] ?? null) === 'Aor') {
        ++$index;
        $children[] = parseCheckAndExpression($tokens, $index);
    }

    return combineCheckExpressionNodes('OR', $children);
}

function parseCheckAndExpression(array $tokens, int &$index): array
{
    $children = [parseCheckPrimaryExpression($tokens, $index)];
    while (($tokens[$index] ?? null) === 'Aand') {
        ++$index;
        $children[] = parseCheckPrimaryExpression($tokens, $index);
    }

    return combineCheckExpressionNodes('AND', $children);
}

function parseCheckPrimaryExpression(array $tokens, int &$index): array
{
    if (($tokens[$index] ?? null) === 'P(') {
        ++$index;
        $node = parseCheckOrExpression($tokens, $index);
        if (($tokens[$index] ?? null) !== 'P)') {
            stopPreflight('A CHECK expression has unbalanced or unsupported parentheses.');
        }
        ++$index;
        return $node;
    }

    $leaf = [];
    $nestedParentheses = 0;
    $tokenCount = count($tokens);
    while ($index < $tokenCount) {
        $token = $tokens[$index];
        if (
            $nestedParentheses === 0
            && ($token === 'Aand' || $token === 'Aor' || $token === 'P)')
        ) {
            break;
        }

        if ($token === 'P(') {
            ++$nestedParentheses;
        } elseif ($token === 'P)') {
            --$nestedParentheses;
            if ($nestedParentheses < 0) {
                break;
            }
        }

        $leaf[] = $token;
        ++$index;
    }

    if ($leaf === [] || $nestedParentheses !== 0) {
        stopPreflight('A CHECK expression contains an empty term or unsupported parentheses.');
    }

    return ['type' => 'leaf', 'tokens' => $leaf];
}

function combineCheckExpressionNodes(string $operator, array $children): array
{
    if (count($children) === 1) {
        return $children[0];
    }

    $flattened = [];
    foreach ($children as $child) {
        if (($child['type'] ?? null) === $operator) {
            array_push($flattened, ...$child['children']);
        } else {
            $flattened[] = $child;
        }
    }

    return ['type' => $operator, 'children' => $flattened];
}

function serializeCheckExpressionTree(array $node): string
{
    if ($node['type'] === 'leaf') {
        $serializedTokens = '';
        foreach ($node['tokens'] as $token) {
            $serializedTokens .= strlen($token) . ':' . $token . ';';
        }
        return 'L' . strlen($serializedTokens) . ':' . $serializedTokens . ';';
    }

    $serializedChildren = '';
    foreach ($node['children'] as $child) {
        $serializedChild = serializeCheckExpressionTree($child);
        $serializedChildren .= strlen($serializedChild) . ':' . $serializedChild . ';';
    }

    return $node['type'] . '[' . $serializedChildren . ']';
}

function checkExpressionQuoteDiagnostic(
    string $source,
    string $table,
    string $constraint,
    string $expression,
    int $openingPosition,
    string $delimiter,
    string $kind
): string {
    return 'CHECK expression from ' . $source . ' for '
        . $table . '.' . $constraint
        . ' has an unterminated quoted ' . $kind
        . ' opened by ' . diagnosticValue($delimiter)
        . ' at byte position ' . ($openingPosition + 1)
        . ' (1-based); expression length is ' . strlen($expression)
        . ' bytes; safe signature: '
        . safeCheckExpressionSignature($expression) . '.';
}

function safeCheckExpressionSignature(string $expression): string
{
    $signature = '';
    $length = strlen($expression);
    $quote = null;

    for ($index = 0; $index < $length; ++$index) {
        $character = $expression[$index];

        if ($quote !== null) {
            if ($character === '\\' && $index + 1 < $length) {
                ++$index;
                continue;
            }
            if ($character === $quote) {
                if ($index + 1 < $length && $expression[$index + 1] === $quote) {
                    ++$index;
                    continue;
                }
                $signature .= $quote;
                $quote = null;
            }
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $signature .= $character . '[redacted]';
            continue;
        }

        if (ctype_alnum($character) || $character === '_' || $character === '$') {
            $signature .= 'T';
            while (
                $index + 1 < $length
                && (ctype_alnum($expression[$index + 1])
                    || $expression[$index + 1] === '_'
                    || $expression[$index + 1] === '$')
            ) {
                ++$index;
            }
            continue;
        }

        $signature .= ctype_print($character) ? $character : '?';
    }

    if (strlen($signature) > 160) {
        return substr($signature, 0, 157) . '...';
    }
    return $signature;
}

function detailedCheckMismatchDiagnostic(
    string $expectedExpression,
    string $mysqlExpression,
    string $expectedCanonical,
    string $mysqlCanonical
): string {
    $expectedTokens = decodeCanonicalCheckTokens($expectedCanonical);
    $mysqlTokens = decodeCanonicalCheckTokens($mysqlCanonical);
    $firstDifference = null;
    $maxTokens = max(count($expectedTokens), count($mysqlTokens));

    for ($index = 0; $index < $maxTokens; ++$index) {
        if (($expectedTokens[$index] ?? null) !== ($mysqlTokens[$index] ?? null)) {
            $firstDifference = $index;
            break;
        }
    }

    $expectedTokenJson = json_encode(
        $expectedTokens,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $mysqlTokenJson = json_encode(
        $mysqlTokens,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $mysqlJson = json_encode(
        $mysqlExpression,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return 'CHECK diagnostic for orders.chk_orders_paid_at_matches_status: '
        . 'expected_length_bytes=' . strlen($expectedExpression)
        . '; mysql_length_bytes=' . strlen($mysqlExpression)
        . '; mysql_bin2hex=' . bin2hex($mysqlExpression)
        . '; mysql_json=' . ($mysqlJson === false ? '"[json encoding failed]"' : $mysqlJson)
        . '; expected_tokens='
        . ($expectedTokenJson === false ? '"[token encoding failed]"' : $expectedTokenJson)
        . '; mysql_tokens='
        . ($mysqlTokenJson === false ? '"[token encoding failed]"' : $mysqlTokenJson)
        . '; first_token_divergence='
        . ($firstDifference === null
            ? 'none'
            : ($firstDifference + 1)
                . ' (1-based; expected='
                . diagnosticValue($expectedTokens[$firstDifference] ?? null)
                . ', mysql='
                . diagnosticValue($mysqlTokens[$firstDifference] ?? null)
                . ')')
        . '; mysql_special_byte_positions='
        . json_encode(
            checkExpressionSpecialBytePositions($mysqlExpression),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        )
        . '; token_stream_status=canonical streams decoded completely; normalization may omit whitespace, MySQL _utf8mb4 introducers, delimiter-encoding backslashes, and redundant outer parentheses.';
}

function decodeCanonicalCheckTokens(string $canonical): array
{
    $tokens = [];
    $offset = 0;
    $length = strlen($canonical);

    while ($offset < $length) {
        $separator = strpos($canonical, ':', $offset);
        if ($separator === false) {
            return ['[invalid canonical token stream]'];
        }

        $tokenLengthText = substr($canonical, $offset, $separator - $offset);
        if ($tokenLengthText === '' || preg_match('/^\d+$/', $tokenLengthText) !== 1) {
            return ['[invalid canonical token length]'];
        }

        $tokenLength = (int) $tokenLengthText;
        $tokenStart = $separator + 1;
        $token = substr($canonical, $tokenStart, $tokenLength);
        $terminatorOffset = $tokenStart + $tokenLength;
        if (
            strlen($token) !== $tokenLength
            || ($canonical[$terminatorOffset] ?? null) !== ';'
        ) {
            return ['[incomplete canonical token]'];
        }

        $tokens[] = $token;
        $offset = $terminatorOffset + 1;
    }

    return $tokens;
}

function checkExpressionSpecialBytePositions(string $expression): array
{
    $positions = [];
    $length = strlen($expression);
    for ($index = 0; $index < $length; ++$index) {
        $byte = $expression[$index];
        if ($byte === '\\' || $byte === "'" || $byte === '"' || $byte === '`') {
            $positions[] = [
                'position_1_based' => $index + 1,
                'character' => $byte,
                'hex' => bin2hex($byte),
            ];
        }
    }

    return $positions;
}

function isStringColumn(string $type): bool
{
    return str_starts_with($type, 'varchar(')
        || str_starts_with($type, 'enum(')
        || $type === 'text';
}

function array_keysSorted(array $array): array
{
    $keys = array_keys($array);
    sort($keys);
    return $keys;
}

function passCheck(string $message): void
{
    global $checksPassed;
    $checksPassed[] = $message;
}

function stopPreflight(string $message): void
{
    throw new PreflightFailure($message);
}

final class PreflightFailure extends RuntimeException
{
}
