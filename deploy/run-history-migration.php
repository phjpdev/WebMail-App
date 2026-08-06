<?php

declare(strict_types=1);

/**
 * run-history-migration.php — applies the full-history schema changes using
 * the app's own .env DB settings (no mysql CLI needed). Idempotent: checks
 * information_schema before each change.
 *
 * USE: copy to the app ROOT (next to index.php), open in a browser:
 *   http(s)://<your-app>/run-history-migration.php?k=history2026
 * Then DELETE the file from the server.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['k'] ?? '') !== 'history2026') {
    http_response_code(403);
    echo "Missing/invalid key. Open with ?k=history2026\n";
    exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';
bootstrapEnv(__DIR__);

use App\Database;

$db = (string) env('DB_NAME', '');
echo "Database: {$db}\n\n";

$columnExists = static function (string $table, string $column) use ($db): bool {
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$db, $table, $column]
    );

    return (int) ($row['c'] ?? 0) > 0;
};
$indexExists = static function (string $table, string $index) use ($db): bool {
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$db, $table, $index]
    );

    return (int) ($row['c'] ?? 0) > 0;
};

$steps = [
    ['mail_sync_state', 'server_total', 'column',
        'ALTER TABLE mail_sync_state ADD COLUMN server_total INT UNSIGNED NULL DEFAULT NULL AFTER imap_total'],
    ['mail_sync_state', 'oldest_uid', 'column',
        'ALTER TABLE mail_sync_state ADD COLUMN oldest_uid INT UNSIGNED NULL DEFAULT NULL AFTER server_total'],
    ['mail_sync_state', 'backfill_done', 'column',
        'ALTER TABLE mail_sync_state ADD COLUMN backfill_done TINYINT(1) NOT NULL DEFAULT 0 AFTER oldest_uid'],
    ['mail_index', 'backfilled', 'column',
        'ALTER TABLE mail_index ADD COLUMN backfilled TINYINT(1) NOT NULL DEFAULT 0 AFTER seen'],
    ['mail_index', 'idx_mail_index_badge', 'index',
        'ALTER TABLE mail_index ADD INDEX idx_mail_index_badge (backfilled, seen, folder_path)'],
];

$applied = 0;
foreach ($steps as [$table, $name, $kind, $sql]) {
    $exists = $kind === 'column' ? $columnExists($table, $name) : $indexExists($table, $name);
    if ($exists) {
        echo "SKIP  {$table}.{$name} already present\n";
        continue;
    }
    try {
        Database::query($sql);
        echo "OK    added {$kind} {$table}.{$name}\n";
        $applied++;
    } catch (\Throwable $e) {
        echo "FAIL  {$table}.{$name}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone — {$applied} change(s) applied.\n";
echo "DELETE this file from the server now.\n";
