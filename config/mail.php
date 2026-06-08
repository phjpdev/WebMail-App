<?php

declare(strict_types=1);

return [
    'imap' => [
        'host' => env('IMAP_HOST', ''),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => filter_var(env('IMAP_VALIDATE_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
    'smtp' => [
        'host' => env('SMTP_HOST', ''),
        'port' => (int) env('SMTP_PORT', 465),
        'encryption' => env('SMTP_ENCRYPTION', 'ssl'),
        'validate_cert' => filter_var(env('SMTP_VALIDATE_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
    'mailbox_email' => env('MAILBOX_EMAIL', ''),
    'mailbox_password' => env('MAILBOX_PASSWORD', ''),
    'test_email_to' => env('TEST_EMAIL_TO', ''),
];
