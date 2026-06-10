<?php

declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'name' => env('DB_NAME', 'dj_webmail'),
    'user' => env('DB_USER', 'root'),
    'password' => env_secret('DB_PASSWORD'),
    'charset' => 'utf8mb4',
];
