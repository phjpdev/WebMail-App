<?php

declare(strict_types=1);

// TLS certificate validation defaults to ON. In production (APP_DEBUG off) a
// "false" setting is ignored unless ALLOW_INSECURE_TLS is explicitly enabled,
// so a stray novalidate-cert config can't silently ship insecure.
$appDebug = filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
$allowInsecureTls = filter_var(env('ALLOW_INSECURE_TLS', 'false'), FILTER_VALIDATE_BOOLEAN);
$resolveValidateCert = static function (string $key) use ($appDebug, $allowInsecureTls): bool {
    $configured = filter_var(env($key, 'true'), FILTER_VALIDATE_BOOLEAN);
    if (!$configured && !$appDebug && !$allowInsecureTls) {
        return true;
    }

    return $configured;
};

return [
    'imap' => [
        'host' => env('IMAP_HOST', ''),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => $resolveValidateCert('IMAP_VALIDATE_CERT'),
        // Bound how long a single IMAP connect / read may block so a throttled
        // or slow mail server can't tie up PHP workers (which makes the whole
        // app feel frozen, e.g. right after sending). Fail fast and retry.
        'open_timeout' => max(1, (int) env('IMAP_OPEN_TIMEOUT', 8)),
        'io_timeout' => max(1, (int) env('IMAP_IO_TIMEOUT', 12)),
    ],
    'smtp' => [
        'host' => env('SMTP_HOST', ''),
        'port' => (int) env('SMTP_PORT', 465),
        'encryption' => env('SMTP_ENCRYPTION', 'ssl'),
        'validate_cert' => $resolveValidateCert('SMTP_VALIDATE_CERT'),
    ],
    'mailbox_email' => env('MAILBOX_EMAIL', ''),
    'mailbox_password' => env_secret('MAILBOX_PASSWORD'),
    'test_email_to' => env('TEST_EMAIL_TO', ''),
];
