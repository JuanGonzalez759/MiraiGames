<?php

declare(strict_types=1);

return [
    'GET /api/health' => static function (): array {
        databaseConnection();

        return [
            'status' => 'ok',
            'api' => 'up',
            'database' => 'connected',
        ];
    },
];
