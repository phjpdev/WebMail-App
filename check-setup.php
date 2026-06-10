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
$checks['db_host_env'] = env('DB_HOST', '(empty)');
$checks['db_name'] = env('DB_NAME', '(empty)');
$checks['db_user'] = env('DB_USER', '(empty)');
$checks['db_password_mode'] = env('DB_PASSWORD_B64', '') !== '' ? 'base64' : 'plain';

$dbPass = (string) config('database')['password'];
$checks['db_password_length'] = (string) strlen($dbPass);
if ($dbPass !== '') {
    $checks['db_password_hint'] = 'starts with "' . $dbPass[0] . '", ends with "' . $dbPass[strlen($dbPass) - 1] . '"';
}

$b64Raw = env('DB_PASSWORD_B64', '');
if ($b64Raw !== '') {
    $checks['b64_value_length'] = (string) strlen($b64Raw);
    $checks['b64_decode_ok'] = base64_decode($b64Raw, true) !== false ? 'yes' : 'NO';
}

$checks['app_url'] = env('APP_URL', '(empty)');
$checks['pdo_mysql'] = extension_loaded('pdo_mysql') ? 'yes' : 'NO';
$checks['imap'] = extension_loaded('imap') ? 'yes' : 'NO';

$logDir = __DIR__ . '/storage/logs';
$checks['storage_writable'] = is_dir($logDir) && is_writable($logDir) ? 'yes' : 'NO';

$config = config('database');
$dbOk = false;
$hostResults = [];

foreach (['localhost', '127.0.0.1'] as $host) {
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
        $hostResults[$host] = "OK ({$count} users)";
        $checks['database'] = "connected via {$host}";
        $checks['users_table'] = "{$count} users";
        $checks['use_db_host'] = $host;
        $dbOk = true;
        break;
    } catch (Throwable $e) {
        $hostResults[$host] = $e->getMessage();
    }
}

$checks['test_localhost'] = $hostResults['localhost'] ?? 'not tested';
$checks['test_127.0.0.1'] = $hostResults['127.0.0.1'] ?? 'not tested';

if (!$dbOk) {
    $checks['database'] = 'FAILED on all hosts (see test_localhost / test_127.0.0.1)';
}

foreach ($checks as $key => $value) {
    echo str_pad($key, 22) . ': ' . $value . PHP_EOL;
}

echo PHP_EOL;

if ($dbOk) {
    echo "All good. Update .env: DB_HOST=" . $checks['use_db_host'] . PHP_EOL;
    echo 'Delete check-setup.php, then login at /login' . PHP_EOL;
    exit;
}

echo "WHAT THIS MEANS\n";
echo "---------------\n";
if ($checks['db_password_mode'] === 'base64' && (int) $checks['db_password_length'] > 0) {
    echo "- Your .env file is parsed correctly (base64 → {$checks['db_password_length']} chars).\n";
    echo "- MySQL still rejects the username/password pair.\n";
    echo "- This is NOT a code bug — hPanel has a different password than DB_PASSWORD_B64.\n";
} else {
    echo "- Fix .env first (use DB_PASSWORD_B64).\n";
}

echo PHP_EOL;
echo "VERIFY IN HPANEL (most important)\n";
echo "--------------------------------\n";
echo "1. hPanel → Websites → Databases → MySQL Databases\n";
echo "2. Under 'List of Current MySQL Databases And Users' copy EXACTLY:\n";
echo "   - Database name (must be: u321724939_mailbox)\n";
echo "   - Username     (must be: u321724939_mailboxUser — check capitals)\n";
echo "3. Click ⋮ next to the USER → 'Change password'\n";
echo "4. Type your new password → SAVE → copy it immediately\n";
echo "5. On your PC: php deploy/encode-secret.php \"paste-password-here\"\n";
echo "6. Put result in server .env as DB_PASSWORD_B64=... only (remove DB_PASSWORD line)\n";
echo "   TIP: uppercase I and lowercase l look identical — verify each character!\n";
echo PHP_EOL;
echo "PHPMyADMIN TRAP\n";
echo "---------------\n";
echo "Clicking 'Enter phpMyAdmin' in hPanel logs you in automatically.\n";
echo "That does NOT prove your DB user password works.\n";
echo "To test: open https://auth-db1149.hstgr.io/ and log in manually with:\n";
echo "  User: u321724939_mailboxUser\n";
echo "  Pass: (same password you put in .env)\n";
echo "If manual login fails → fix password in hPanel first, then re-encode.\n";
echo PHP_EOL;
echo "USER NOT LINKED TO DATABASE?\n";
echo "----------------------------\n";
echo "In hPanel, user must be assigned to database u321724939_mailbox.\n";
echo "If needed: remove user from DB, re-add with ALL PRIVILEGES.\n";
echo PHP_EOL;
