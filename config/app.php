<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'D&J Webmail'),
    'url' => app_base_url(),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'session_lifetime' => (int) env('SESSION_LIFETIME', 28800),
    'log_path' => dirname(__DIR__) . '/storage/logs/app.log',
    'filter_batch_limit' => (int) env('FILTER_BATCH_LIMIT', 500),
    'filter_source_folder' => env('FILTER_SOURCE_FOLDER', 'INBOX'),
    // Minimum seconds between automatic filter passes (shared across all users).
    'filter_min_interval' => (int) env('FILTER_MIN_INTERVAL', 60),
    // Max seconds of filtering during a normal web page request.
    'filter_max_runtime' => (int) env('FILTER_MAX_RUNTIME', 20),
    'mail_poll_interval' => (int) env('MAIL_POLL_INTERVAL', 30),
    'mail_per_page' => (int) env('MAIL_PER_PAGE', 15),
];
