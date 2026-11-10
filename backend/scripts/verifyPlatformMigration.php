<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Result: STOP\nReason: this verifier can only run from PHP CLI.\n");
    exit(1);
}

$stage = 'Loading test configuration';

try {
    require_once __DIR__ . '/../config/environment.php';

    if (getenv('APP_ENV') !== 'test' || getenv('MYSQL_DATABASE') !== 'mirai_games_test') {
        throw new RuntimeException(
            'Set process APP_ENV=test and MYSQL_DATABASE=mirai_games_test before running.'
        );
    }

    loadEnvironmentFile(__DIR__ . '/../.env');
    require_once __DIR__ . '/../config/database.php';

    if (getenv('APP_ENV') !== 'test' || getenv('MYSQL_DATABASE') !== 'mirai_games_test') {
        throw new RuntimeException('The effective test database configuration is invalid.');
    }

    $stage = 'Connecting to the configured test database';
    $connection = databaseConnection();
    $identity = $connection->query(
        'SELECT DATABASE() AS active_schema, VERSION() AS mysql_version,
                @@version_comment AS version_comment'
    )->fetch();

    if (
        !is_array($identity)
        || ($identity['active_schema'] ?? null) !== 'mirai_games_test'
    ) {
        throw new RuntimeException('The connected schema is not mirai_games_test.');
    }

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
        throw new RuntimeException('MySQL 8.0.16 or later could not be confirmed.');
    }
    platformMigrationPass('Connected to mirai_games_test on a compatible MySQL version.');

    $stage = 'Checking migrated columns';
    platformMigrationVerifyColumns($connection);
    platformMigrationPass('All required migration columns and definitions are present.');

    $stage = 'Checking primary keys and indexes';
    platformMigrationVerifyIndexes($connection);
    platformMigrationPass('Expected primary keys and indexes are present.');

    $stage = 'Checking foreign keys';
    platformMigrationVerifyForeignKeys($connection);
    platformMigrationPass('Both platform foreign keys match their expected definitions.');

    $stage = 'Checking CHECK constraints';
    platformMigrationVerifyChecks($connection);
    platformMigrationPass('Variant price, stock and availability CHECK constraints are enforced.');

    $stage = 'Checking variant data';
    $invalidVariants = (int) $connection->query(
        'SELECT COUNT(*)
         FROM game_platforms
         WHERE price IS NULL
            OR price < 0
            OR stock IS NULL
            OR is_available IS NULL
            OR is_available NOT IN (0, 1)'
    )->fetchColumn();
    if ($invalidVariants !== 0) {
        throw new RuntimeException('One or more variants have invalid price, stock or availability data.');
    }
    $variantCount = (int) $connection->query(
        'SELECT COUNT(*) FROM game_platforms'
    )->fetchColumn();
    platformMigrationPass($variantCount . ' platform variants have valid price, stock and availability.');

    $stage = 'Checking immediate post-migration NULL preservation';
    $cartRows = (int) $connection->query(
        'SELECT COUNT(*) FROM cart_details'
    )->fetchColumn();
    $cartRowsWithPlatform = (int) $connection->query(
        'SELECT COUNT(*) FROM cart_details WHERE platform_id IS NOT NULL'
    )->fetchColumn();
    if ($cartRowsWithPlatform !== 0) {
        throw new RuntimeException(
            'Cart lines contain a selected platform; legacy NULL preservation cannot be confirmed.'
        );
    }

    platformMigrationPass(
        $cartRows . ' current cart lines have platform_id NULL.'
    );

    fwrite(
        STDOUT,
        "NOT CONCLUSIVE: this verifier has no pre-migration row-level baseline for order_details, so it cannot prove that historical NULL platform_id values were preserved. It does not require the order_details table to be empty or all its rows to have platform_id NULL.\n"
    );
    fwrite(
        STDOUT,
        "LIMITATION: the cart NULL check is conclusive only when this verifier runs immediately after migration, while application processes are stopped and before writes resume. If run later, it cannot distinguish historical rows from new rows.\n"
    );
    fwrite(STDOUT, "Result: PLATFORM_MIGRATION_VERIFIED\n");
    exit(0);
} catch (Throwable $exception) {
    $reason = $exception instanceof PDOException
        ? 'A read-only database check failed.'
        : $exception->getMessage();
    fwrite(
        STDERR,
        'Result: STOP' . "\n"
        . 'Stage: ' . $stage . "\n"
        . 'Reason: ' . $reason . "\n"
    );
    exit(1);
}

function platformMigrationVerifyColumns(PDO $connection): void
{
    $rows = $connection->query(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               \'games\', \'game_platforms\', \'cart_details\', \'order_details\'
           )'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $actual[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }

    $expected = [
        'games' => [
            'id' => ['bigint unsigned', 'NO', null, false],
            'rawg_id' => ['bigint unsigned', 'YES', null, false],
        ],
        'game_platforms' => [
            'game_id' => ['bigint unsigned', 'NO', null, false],
            'platform_id' => ['int unsigned', 'NO', null, false],
            'price' => ['decimal(10,2)', 'NO', null, false],
            'stock' => ['int unsigned', 'NO', '10', false],
            'is_available' => ['tinyint(1)', 'NO', '1', false],
        ],
        'cart_details' => [
            'cart_id' => ['bigint unsigned', 'NO', null, false],
            'game_id' => ['bigint unsigned', 'NO', null, false],
            'id' => ['bigint unsigned', 'NO', null, true],
            'platform_id' => ['int unsigned', 'YES', null, false],
        ],
        'order_details' => [
            'order_id' => ['bigint unsigned', 'NO', null, false],
            'game_id' => ['bigint unsigned', 'NO', null, false],
            'id' => ['bigint unsigned', 'NO', null, true],
            'platform_id' => ['int unsigned', 'YES', null, false],
        ],
    ];

    foreach ($expected as $table => $columns) {
        foreach ($columns as $column => [$type, $nullable, $default, $autoIncrement]) {
            $row = $actual[$table][$column] ?? null;
            if (
                $row === null
                || strtolower((string) $row['COLUMN_TYPE']) !== $type
                || $row['IS_NULLABLE'] !== $nullable
                || ($row['COLUMN_DEFAULT'] === null
                    ? null
                    : (string) $row['COLUMN_DEFAULT']) !== $default
                || (
                    $autoIncrement
                    && stripos((string) $row['EXTRA'], 'auto_increment') === false
                )
                || (
                    !$autoIncrement
                    && stripos((string) $row['EXTRA'], 'auto_increment') !== false
                )
            ) {
                throw new RuntimeException(
                    'Column definition mismatch or missing column: ' . $table . '.' . $column . '.'
                );
            }
        }
    }
}

function platformMigrationVerifyIndexes(PDO $connection): void
{
    $rows = $connection->query(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               \'games\', \'game_platforms\', \'cart_details\', \'order_details\'
           )
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $table = $row['TABLE_NAME'];
        $index = $row['INDEX_NAME'];
        $actual[$table][$index]['unique'] = (int) $row['NON_UNIQUE'] === 0;
        $actual[$table][$index]['columns'][] = $row['COLUMN_NAME'];
    }

    $expected = [
        'games' => [
            'uq_games_rawg_id' => [true, ['rawg_id']],
        ],
        'game_platforms' => [
            'PRIMARY' => [true, ['game_id', 'platform_id']],
        ],
        'cart_details' => [
            'PRIMARY' => [true, ['id']],
            'uq_cart_details_cart_game_platform' => [
                true,
                ['cart_id', 'game_id', 'platform_id'],
            ],
            'idx_cart_details_game_platform' => [
                false,
                ['game_id', 'platform_id'],
            ],
        ],
        'order_details' => [
            'PRIMARY' => [true, ['id']],
            'uq_order_details_order_game_platform' => [
                true,
                ['order_id', 'game_id', 'platform_id'],
            ],
            'idx_order_details_game_platform' => [
                false,
                ['game_id', 'platform_id'],
            ],
        ],
    ];

    foreach ($expected as $table => $indexes) {
        foreach ($indexes as $index => [$unique, $columns]) {
            $definition = $actual[$table][$index] ?? null;
            if (
                $definition === null
                || $definition['unique'] !== $unique
                || $definition['columns'] !== $columns
            ) {
                throw new RuntimeException(
                    'Index definition mismatch or missing index: ' . $table . '.' . $index . '.'
                );
            }
        }
    }
}

function platformMigrationVerifyForeignKeys(PDO $connection): void
{
    $rows = $connection->query(
        'SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                kcu.ORDINAL_POSITION, kcu.REFERENCED_TABLE_SCHEMA,
                kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                rc.DELETE_RULE, rc.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          AND rc.TABLE_NAME = kcu.TABLE_NAME
         WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
           AND kcu.CONSTRAINT_NAME IN (
               \'fk_cart_details_game_platform\',
               \'fk_order_details_game_platform\'
           )
         ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $name = $row['CONSTRAINT_NAME'];
        $actual[$name]['table'] = $row['TABLE_NAME'];
        $actual[$name]['columns'][] = $row['COLUMN_NAME'];
        $actual[$name]['referenced_schema'] = $row['REFERENCED_TABLE_SCHEMA'];
        $actual[$name]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
        $actual[$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        $actual[$name]['delete'] = strtoupper($row['DELETE_RULE']);
        $actual[$name]['update'] = strtoupper($row['UPDATE_RULE']);
    }

    $expected = [
        'fk_cart_details_game_platform' => [
            'table' => 'cart_details',
            'columns' => ['game_id', 'platform_id'],
            'referenced_schema' => 'mirai_games_test',
            'referenced_table' => 'game_platforms',
            'referenced_columns' => ['game_id', 'platform_id'],
            'delete' => 'RESTRICT',
            'update' => 'CASCADE',
        ],
        'fk_order_details_game_platform' => [
            'table' => 'order_details',
            'columns' => ['game_id', 'platform_id'],
            'referenced_schema' => 'mirai_games_test',
            'referenced_table' => 'game_platforms',
            'referenced_columns' => ['game_id', 'platform_id'],
            'delete' => 'RESTRICT',
            'update' => 'CASCADE',
        ],
    ];

    foreach ($expected as $name => $definition) {
        if (($actual[$name] ?? null) !== $definition) {
            throw new RuntimeException('Foreign key definition mismatch or missing constraint: ' . $name . '.');
        }
    }
}

function platformMigrationVerifyChecks(PDO $connection): void
{
    $rows = $connection->query(
        "SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED
         FROM information_schema.TABLE_CONSTRAINTS tc
         INNER JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
          AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
           AND tc.CONSTRAINT_TYPE = 'CHECK'
           AND tc.CONSTRAINT_NAME IN (
               'chk_game_platforms_price_nonnegative',
               'chk_game_platforms_stock_nonnegative',
               'chk_game_platforms_is_available_boolean'
           )"
    )->fetchAll();

    $actual = [];
    foreach ($rows as $row) {
        $actual[$row['CONSTRAINT_NAME']] = [
            'table' => $row['TABLE_NAME'],
            'expression' => platformMigrationNormalizeCheck((string) $row['CHECK_CLAUSE']),
            'enforced' => $row['ENFORCED'],
        ];
    }

    $expected = [
        'chk_game_platforms_price_nonnegative' => [
            'table' => 'game_platforms',
            'expression' => 'price>=0',
            'enforced' => 'YES',
        ],
        'chk_game_platforms_stock_nonnegative' => [
            'table' => 'game_platforms',
            'expression' => 'stock>=0',
            'enforced' => 'YES',
        ],
        'chk_game_platforms_is_available_boolean' => [
            'table' => 'game_platforms',
            'expression' => 'is_availablein0,1',
            'enforced' => 'YES',
        ],
    ];

    foreach ($expected as $name => $definition) {
        if (($actual[$name] ?? null) !== $definition) {
            throw new RuntimeException('CHECK definition mismatch, missing or unenforced: ' . $name . '.');
        }
    }
}

function platformMigrationNormalizeCheck(string $expression): string
{
    $normalized = preg_replace('/[\x60\s()]/', '', strtolower($expression));
    if (!is_string($normalized) || $normalized === '') {
        throw new RuntimeException('A CHECK expression could not be normalized.');
    }

    return $normalized;
}

function platformMigrationPass(string $message): void
{
    fwrite(STDOUT, 'PASS: ' . $message . "\n");
}
