<?php

declare(strict_types=1);

/**
 * backfill-history.php — index the FULL message history of every IMAP folder
 * into mail_index, so all old mail is browsable/searchable in the webmail.
 *
 * Usage (run on the server, from the project root):
 *   php scripts/backfill-history.php [options]
 *
 * Options:
 *   --folder=PATH          only this folder (IMAP path, e.g. INBOX.Name.Inbox)
 *   --limit-per-folder=N   stop after adding N rows to a folder this run (0 = all)
 *   --chunk=N              messages per IMAP fetch (default 200)
 *   --sleep-ms=N           pause between chunks (default 150; be kind to the host)
 *   --max-runtime=SECONDS  exit cleanly after this long (re-run resumes where it left off)
 *   --force                re-walk folders already marked backfill_done
 *   --prune                also remove index rows for messages deleted on the server
 *
 * Safe to re-run any time: progress is tracked per folder (oldest_uid watermark)
 * and row writes are idempotent upserts. Run off-hours — a connect failure trips
 * the app's 20s IMAP circuit breaker for web users too.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));
set_time_limit(0);
ini_set('memory_limit', '512M');

use App\Services\ImapService;
use App\Services\MailCacheService;

// ---- options ---------------------------------------------------------------
$opts = [
    'folder' => '',
    'limit-per-folder' => 0,
    'chunk' => 200,
    'sleep-ms' => 150,
    'max-runtime' => 0,
    'force' => false,
    'prune' => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') { $opts['force'] = true; continue; }
    if ($arg === '--prune') { $opts['prune'] = true; continue; }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = is_int($opts[$m[1]]) ? (int) $m[2] : $m[2];
        continue;
    }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(1);
}
$chunk = max(25, (int) $opts['chunk']);
$sleepMs = max(0, (int) $opts['sleep-ms']);
$deadline = $opts['max-runtime'] > 0 ? time() + (int) $opts['max-runtime'] : 0;

$out = static function (string $line): void {
    echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
};

// ---- connect ----------------------------------------------------------------
$imap = new ImapService();
if (!$imap->connect()) {
    fwrite(STDERR, 'IMAP connect failed: ' . $imap->getLastError() . PHP_EOL);
    exit(1);
}

$folders = $imap->listFolders(true);
if ($folders === []) {
    fwrite(STDERR, "IMAP returned no folders.\n");
    exit(1);
}
$paths = array_column($folders, 'path');
if ($opts['folder'] !== '') {
    $want = strtolower(trim((string) $opts['folder']));
    $paths = array_values(array_filter($paths, static fn (string $p): bool => strtolower($p) === $want));
    if ($paths === []) {
        fwrite(STDERR, "Folder not found on server: {$opts['folder']}\n");
        exit(1);
    }
}

$out('Backfill starting: ' . count($paths) . ' folder(s), chunk=' . $chunk . ', sleep=' . $sleepMs . 'ms'
    . ($deadline ? ', max-runtime=' . $opts['max-runtime'] . 's' : ''));

// ---- walk -------------------------------------------------------------------
$summary = ['folders' => 0, 'rows' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0];
$stopped = false;

foreach ($paths as $path) {
    if ($deadline && time() >= $deadline) {
        $out('Max runtime reached — exiting cleanly. Re-run to resume.');
        $stopped = true;
        break;
    }

    $summary['folders']++;
    try {
        // Long-running process: don't let per-request memo maps go stale
        // across hundreds of folders.
        MailCacheService::resetRuntimeMaps();

        $indexPath = MailCacheService::indexFolderPath($path);
        $serverTotal = $imap->getMessageCount($path);
        if ($serverTotal <= 0) {
            MailCacheService::recordServerTotal($path, max(0, $serverTotal));
            $summary['skipped']++;
            continue;
        }
        MailCacheService::recordServerTotal($path, $serverTotal);

        $state = MailCacheService::getSyncState($path);
        if (!empty($state['backfill_done']) && !$opts['force'] && !$opts['prune']) {
            $summary['done']++;
            continue;
        }

        // Never-synced folder: pull the recent window first (badge-correct,
        // sets last_sync_at / imap_total with normal semantics).
        if ($state === null || empty($state['last_sync_at'])) {
            MailCacheService::syncFolderHeaders($imap, $path);
            $state = MailCacheService::getSyncState($path);
        }

        $oldest = (int) ($state['oldest_uid'] ?? 0);
        if ($oldest <= 0) {
            $row = App\Database::fetchOne(
                'SELECT MIN(imap_uid) AS u FROM mail_index WHERE folder_path = ?',
                [$indexPath]
            );
            $oldest = (int) ($row['u'] ?? 0);
        }
        if ($oldest <= 0) {
            $summary['skipped']++;
            continue;
        }

        $addedThisFolder = 0;
        $retried = false;
        while (true) {
            if ($deadline && time() >= $deadline) {
                $stopped = true;
                break;
            }
            if ($opts['limit-per-folder'] > 0 && $addedThisFolder >= (int) $opts['limit-per-folder']) {
                $out("{$path}: per-folder limit reached ({$addedThisFolder} rows) — resuming next run.");
                break;
            }

            $res = $imap->listMessagesBeforeUid($path, $oldest, $chunk);

            if (!empty($res['failed'])) {
                if ($retried) {
                    $out("{$path}: IMAP chunk failed twice — moving on (watermark saved, re-run resumes).");
                    $summary['failed']++;
                    break;
                }
                $retried = true;
                $out("{$path}: IMAP chunk failed — reconnecting once…");
                ImapService::closeShared();
                $imap = new ImapService();
                if (!$imap->connect()) {
                    $out('Reconnect failed: ' . $imap->getLastError() . ' — aborting run.');
                    $stopped = true;
                    break;
                }
                continue;
            }
            $retried = false;

            if (!empty($res['exhausted']) || $res['messages'] === []) {
                MailCacheService::setBackfillWatermark($path, $oldest, done: true);
                $indexed = MailCacheService::countMessagesInIndex($indexPath);
                $out("{$path}: DONE — {$indexed} / {$serverTotal} indexed.");
                $summary['done']++;
                break;
            }

            $written = MailCacheService::upsertIndexRowsBulk($path, $res['messages'], backfilled: true);
            $addedThisFolder += $written;
            $summary['rows'] += $written;

            $chunkMin = $oldest;
            foreach ($res['messages'] as $msg) {
                $uid = (int) ($msg['uid'] ?? 0);
                if ($uid > 0 && $uid < $chunkMin) {
                    $chunkMin = $uid;
                }
            }
            if ($chunkMin >= $oldest) {
                $out("{$path}: no progress in chunk — stopping folder to avoid a loop.");
                $summary['failed']++;
                break;
            }
            $oldest = $chunkMin;
            MailCacheService::setBackfillWatermark($path, $oldest);

            $indexed = MailCacheService::countMessagesInIndex($indexPath);
            $out("{$path}: {$indexed} / {$serverTotal} indexed (oldest uid {$oldest})");

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        // Optional reconcile: drop index rows whose messages are gone from the
        // server (only meaningful once a folder is fully walked).
        if ($opts['prune'] && !$stopped) {
            $serverUids = $imap->allMessageUids($path);
            if ($serverUids !== []) {
                MailCacheService::pruneIndexUidsNotIn($path, $serverUids);
                $out("{$path}: pruned index rows not on server (kept " . count($serverUids) . ').');
            }
        }
    } catch (\Throwable $e) {
        $summary['failed']++;
        $out("{$path}: ERROR — " . $e->getMessage());
    }

    if ($stopped) {
        break;
    }
}

// ---- summary ----------------------------------------------------------------
$out('----------------------------------------');
$out('Folders walked:   ' . $summary['folders']);
$out('Rows indexed:     ' . $summary['rows']);
$out('Folders complete: ' . $summary['done']);
$out('Folders skipped:  ' . $summary['skipped'] . ' (empty/never-synced)');
$out('Folders failed:   ' . $summary['failed']);
$out($stopped ? 'Run stopped early — RE-RUN to resume (progress is saved).' : 'Run complete.');
exit($summary['failed'] > 0 ? 2 : 0);
