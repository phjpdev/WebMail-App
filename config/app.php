<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'D&J Webmail'),
    'brand' => env('APP_BRAND', 'DJ Group'),
    'brand_suffix' => env('APP_BRAND_SUFFIX', 'Webmail'),
    'timezone' => env('APP_TIMEZONE', 'America/New_York'),
    'url' => app_base_url(),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    // Idle window before a session is considered stale. Also drives the client
    // auto sign-out (data-idle-timeout) so the browser and server agree. 3 hours.
    'session_lifetime' => (int) env('SESSION_LIFETIME', 10800),
    'log_path' => dirname(__DIR__) . '/storage/logs/app.log',
    // Auto-create the "Support" folder (named after MAILBOX_EMAIL's local part) so
    // mail addressed to the main mailbox is filed separately. Disabled by default —
    // set PROVISION_SUPPORT_MAILBOX=true to bring it back.
    'provision_support_mailbox' => filter_var(env('PROVISION_SUPPORT_MAILBOX', 'false'), FILTER_VALIDATE_BOOLEAN),
    'filter_batch_limit' => (int) env('FILTER_BATCH_LIMIT', 500),
    'filter_source_folder' => env('FILTER_SOURCE_FOLDER', 'INBOX'),
    // Minimum seconds between automatic filter passes (shared across all users).
    // Lower = mail routed into folders faster. 30s suits a dedicated server; raise
    // it (e.g. 60) on a constrained shared host.
    'filter_min_interval' => (int) env('FILTER_MIN_INTERVAL', 30),
    // Max seconds of filtering during a normal web page request.
    'filter_max_runtime' => (int) env('FILTER_MAX_RUNTIME', 20),
    'mail_poll_interval' => (int) env('MAIL_POLL_INTERVAL', 30),
    // Seconds between the gentle background new-mail check (live-sync). 60s suits a
    // dedicated server; a constrained shared host may want 120. Floored at 15s.
    'live_sync_interval' => max(15, (int) env('LIVE_SYNC_INTERVAL', 60)),
    'mail_per_page' => (int) env('MAIL_PER_PAGE', 15),
    // Local MySQL cache (no cron — synced on login, folder open, poll).
    'mail_cache_header_limit' => (int) env('MAIL_CACHE_HEADER_LIMIT', 200),
    // Full-history browsing: messages fetched per chunk when a deep page
    // reaches past the indexed window, and the max chunks per request.
    'deep_page_chunk' => (int) env('MAIL_DEEP_PAGE_CHUNK', 200),
    'deep_page_max_chunks' => (int) env('MAIL_DEEP_PAGE_MAX_CHUNKS', 3),
    // Bulk "select all in folder" safety cap (server-enforced).
    'mail_bulk_all_max' => (int) env('MAIL_BULK_ALL_MAX', 500),
    'mail_cache_ttl' => (int) env('MAIL_CACHE_TTL', 120),
    'mail_cache_bootstrap_limit' => (int) env('MAIL_CACHE_BOOTSTRAP_LIMIT', 150),
];
