<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'D&J Webmail'),
    'url' => rtrim(env('APP_URL', 'http://localhost/webmail'), '/'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'session_lifetime' => (int) env('SESSION_LIFETIME', 28800),
    'log_path' => dirname(__DIR__) . '/storage/logs/app.log',
];
