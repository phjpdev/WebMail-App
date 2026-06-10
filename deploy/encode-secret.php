<?php

declare(strict_types=1);

/**
 * Encode a password for .env (run locally, never upload this file to production).
 *
 * Usage: php deploy/encode-secret.php "your-password-here"
 */

$secret = $argv[1] ?? null;
if ($secret === null || $secret === '') {
    fwrite(STDERR, "Usage: php deploy/encode-secret.php \"your-password\"\n");
    exit(1);
}

echo 'DB_PASSWORD_B64=' . base64_encode($secret) . PHP_EOL;
