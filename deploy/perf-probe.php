<?php

declare(strict_types=1);

/**
 * perf-probe.php v2 — full diagnostic: verifies the build, times every suspect
 * operation, and shows recent errors. One paste of this output pinpoints the
 * bottleneck — no more guessing.
 *
 * USE: copy to the app ROOT (next to index.php), open in a browser:
 *   http://localhost/dj_email/perf-probe.php
 * Copy ALL output back. DELETE the file afterwards.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$t0 = microtime(true);
$ms = static fn (float $since): string => str_pad(number_format((microtime(true) - $since) * 1000, 1) . ' ms', 12);
$section = static function (string $name): void {
    echo "\n========== {$name} ==========\n";
};

echo "perf-probe v2  " . date('Y-m-d H:i:s') . "\n";

// ---------------------------------------------------------------------------
$section('1. PHP environment');
echo "php version:      " . PHP_VERSION . "\n";
echo "memory_limit:     " . ini_get('memory_limit') . "\n";
$opEnabled = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));
echo "OPCACHE:          " . ($opEnabled ? 'ENABLED' : 'DISABLED  <<< CRITICAL if disabled: PHP re-parses ~1MB of code EVERY request = high CPU on every click') . "\n";
echo "sapi:             " . PHP_SAPI . "\n";

// ---------------------------------------------------------------------------
$section('2. Build check (are the performance fixes actually installed?)');
$t = microtime(true);
try {
    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/src/helpers.php';
    bootstrapEnv(__DIR__);
    echo "bootstrap:        " . $ms($t) . "\n";
} catch (\Throwable $e) {
    echo "BOOTSTRAP FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "VERSION.txt:      " . (is_file(__DIR__ . '/VERSION.txt') ? trim((string) file_get_contents(__DIR__ . '/VERSION.txt')) : 'MISSING  <<< the new build was NOT copied') . "\n";

$checks = [
    'MailCacheService::syncedFolderPathSet' => method_exists(\App\Services\MailCacheService::class, 'syncedFolderPathSet'),
    'MailCacheService::hasFolderDataInSet' => method_exists(\App\Services\MailCacheService::class, 'hasFolderDataInSet'),
    'FolderCache::statusBudgetPaths' => (new \ReflectionClass(\App\Services\FolderCache::class))->hasMethod('statusBudgetPaths'),
];
foreach ($checks as $what => $ok) {
    echo str_pad($what . ':', 42) . ($ok ? 'present' : 'MISSING  <<< OLD FILE — fix not installed') . "\n";
}

// ---------------------------------------------------------------------------
$section('3. Database timings');
try {
    $t = microtime(true);
    $pdo = \App\Database::connection();
    echo "db connect:       " . $ms($t) . "\n";

    foreach (['folders', 'mail_sync_state', 'mail_index', 'users'] as $table) {
        $t = microtime(true);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        echo str_pad("count {$table}:", 26) . $ms($t) . " ({$n} rows)\n";
    }

    $t = microtime(true);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM mail_index WHERE seen = 0")->fetchColumn();
    echo str_pad("unseen scan:", 26) . $ms($t) . " ({$n} unseen)  <- slow here = missing DB index\n";

    if (method_exists(\App\Services\MailCacheService::class, 'syncedFolderPathSet')) {
        $t = microtime(true);
        $set = \App\Services\MailCacheService::syncedFolderPathSet();
        echo str_pad("syncedFolderPathSet:", 26) . $ms($t) . " (" . count($set) . " indexed folders)\n";
    }
} catch (\Throwable $e) {
    echo "DB FAILED: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
$section('4. Registry / admin-page cost (the "Admin panel takes minutes" suspect)');
try {
    $t = microtime(true);
    $svc = new \App\Services\AdminFolderService();
    $all = $svc->listAll();
    echo "AdminFolderService::listAll:              " . $ms($t) . " (" . count($all) . " folders)\n";

    $t = microtime(true);
    $view = partition_admin_folders_for_display($all);
    echo "partition_admin_folders_for_display:      " . $ms($t) . "  <- if this is seconds, admin Folders page is quadratic\n";

    $t = microtime(true);
    $choices = $svc->listGroupParentChoices();
    echo "listGroupParentChoices:                   " . $ms($t) . " (" . count($choices) . " choices)\n";
} catch (\Throwable $e) {
    echo "ADMIN COST FAILED: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
$section('5. Sidebar helper cost per request (runs on EVERY page/poll)');
try {
    $paths = array_column((new \App\Services\AdminFolderService())->listAll(), 'imap_path');
    $t = microtime(true);
    $badgeable = 0;
    foreach ($paths as $p) {
        if (folder_shows_unread_badge((string) $p)) {
            $badgeable++;
        }
    }
    echo "folder_shows_unread_badge x" . count($paths) . ":  " . $ms($t) . " ({$badgeable} badgeable)\n";

    $t = microtime(true);
    foreach ($paths as $p) {
        folder_icon_type((string) $p);
    }
    echo "folder_icon_type x" . count($paths) . ":          " . $ms($t) . "\n";

    $t = microtime(true);
    $tree = build_sidebar_other_folder_tree(array_map(static fn ($p) => ['path' => (string) $p, 'name' => (string) $p], $paths), 'path', '.');
    echo "build_sidebar_other_folder_tree:   " . $ms($t) . "  <- sidebar tree build\n";
} catch (\Throwable $e) {
    echo "SIDEBAR COST FAILED: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
$section('6. Mail server (IMAP) timings');
try {
    $t = microtime(true);
    $imap = new \App\Services\ImapService();
    if (!$imap->connect()) {
        echo "IMAP connect FAILED: " . $imap->getLastError() . "\n";
    } else {
        echo "imap connect:     " . $ms($t) . "\n";

        $t = microtime(true);
        $folders = $imap->listFolders(true);
        echo "imap LIST all:    " . $ms($t) . " (" . count($folders) . " folders on server)\n";

        $sample = array_slice(array_column($folders, 'path'), 0, 5);
        $t = microtime(true);
        $imap->getFolderBadgeCounts($sample);
        $per = (microtime(true) - $t) * 1000 / max(1, count($sample));
        echo "imap STATUS x" . count($sample) . ":   " . $ms($t) . " (avg " . number_format($per, 0) . " ms/folder; 25-budget ≈ "
            . number_format($per * 25 / 1000, 1) . " s; all " . count($folders) . " ≈ " . number_format($per * count($folders) / 1000, 0) . " s)\n";

        // First-open cost estimate: biggest folder message count via STATUS
        $t = microtime(true);
        $inboxTotal = $imap->getFolderMessageCounts(['INBOX']);
        echo "INBOX total msgs: " . $ms($t) . " (" . (int) ($inboxTotal['INBOX'] ?? 0) . " messages)"
            . "  <- first-ever click on a big folder indexes headers once; that is the one-time 'first open is slow'\n";
    }
} catch (\Throwable $e) {
    echo "IMAP FAILED: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
$section('7. Recent application errors (storage/logs)');
$logDir = __DIR__ . '/storage/logs';
$logFiles = is_dir($logDir) ? glob($logDir . '/*.log') : [];
if (!$logFiles) {
    echo "(no log files)\n";
} else {
    foreach ($logFiles as $lf) {
        $lines = @file($lf, FILE_IGNORE_NEW_LINES) ?: [];
        $tail = array_slice($lines, -15);
        echo "--- " . basename($lf) . " (last " . count($tail) . " of " . count($lines) . " lines) ---\n";
        foreach ($tail as $ln) {
            echo $ln . "\n";
        }
    }
}

// PHP error log if configured
$phpLog = ini_get('error_log');
if ($phpLog && is_file($phpLog)) {
    $lines = @file($phpLog, FILE_IGNORE_NEW_LINES) ?: [];
    $tail = array_slice($lines, -10);
    echo "--- php error_log (last " . count($tail) . ") ---\n";
    foreach ($tail as $ln) {
        echo $ln . "\n";
    }
}

echo "\n========== done in " . number_format((microtime(true) - $t0), 1) . " s — send ALL of this output. Then DELETE this file. ==========\n";
