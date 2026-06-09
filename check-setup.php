<?php

declare(strict_types=1);

/**
 * Deployment diagnostic — visit once, then delete from the server.
 * https://mailbox.djgroupllc.net/check-setup.php
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';

bootstrapEnv(__DIR__);

header('Content-Type: text/plain; charset=utf-8');

$checks = [];

$envFile = null;
foreach (['.env', '.env.production', '.env-production'] as $file) {
    if (is_file(__DIR__ . '/' . $file)) {
        $envFile = $file;
        break;
    }
}
$checks['env_file'] = $envFile ?? 'NOT FOUND';
$checks['db_host'] = env('DB_HOST', '(empty)');
$checks['db_name'] = env('DB_NAME', '(empty)');
$checks['db_user'] = env('DB_USER', '(empty)');
$checks['db_password_length'] = (string) strlen((string) env('DB_PASSWORD', ''));
$checks['app_url'] = env('APP_URL', '(empty)');

$checks['pdo_mysql'] = extension_loaded('pdo_mysql') ? 'yes' : 'NO';
$checks['imap'] = extension_loaded('imap') ? 'yes' : 'NO';

$logDir = __DIR__ . '/storage/logs';
$checks['storage_writable'] = is_dir($logDir) && is_writable($logDir) ? 'yes' : 'NO';

$dbOk = false;
$dbError = '';

foreach (['localhost', '127.0.0.1'] as $host) {
    $config = config('database');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $config['port'],
        $config['name'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $checks['database'] = "connected OK via {$host}";
        $checks['users_table'] = "{$count} users";
        $dbOk = true;
        break;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if (!$dbOk) {
    $checks['database'] = 'FAILED: ' . $dbError;
}

foreach ($checks as $key => $value) {
    echo str_pad($key, 22) . ': ' . $value . PHP_EOL;
}

echo PHP_EOL;

if (!$dbOk) {
    echo "FIX (Hostinger hPanel):\n";
    echo "1. Websites → Databases → MySQL Databases\n";
    echo "2. Confirm user u321724939_mailboxUser is assigned to database u321724939_mailbox\n";
    echo "3. Click 'Change password' on the DB user — set a NEW simple password (letters+numbers only)\n";
    echo "4. Update .env: DB_PASSWORD=YourNewPassword  (no quotes needed if no special chars)\n";
    echo "5. db_password_length should be 12 for the original password — if wrong, .env parsing failed\n";
    echo PHP_EOL;
}

echo 'Delete check-setup.php after all checks pass.' . PHP_EOL;
