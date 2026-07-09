<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';
bootstrapEnv(__DIR__);
if (($_GET['token'] ?? '') !== 'dj7Qx2mLpv91') { http_response_code(403); exit('forbidden'); }
ini_set('display_errors', '1'); error_reporting(E_ALL); @set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');
use App\Database;
use App\Services\ImapService;

echo "== ops 13+ ==\n";
foreach (Database::query("SELECT id, op_type, source_json, target_folder, status, attempts, detail, created_at, updated_at FROM mail_pending_ops WHERE id >= 13 ORDER BY id")->fetchAll() as $r) {
    echo '  ' . json_encode($r) . "\n";
}

$imap = new ImapService();
echo "\n== connect: " . ($imap->connect() ? 'OK' : ('FAIL ' . $imap->getLastError())) . " ==\n";
$jeanMids = [];
foreach (['INBOX.Jean.Inbox', 'INBOX.Trash'] as $f) {
    $l = $imap->listMessages($f, 1, 40);
    echo "{$f}: total={$l['total']}\n";
    foreach ($l['messages'] as $m) {
        $mid = mail_normalize_thread_id((string) ($m['message_id'] ?? ''));
        if ($f === 'INBOX.Jean.Inbox') { $jeanMids[$mid] = (int) $m['uid']; }
        echo "  uid={$m['uid']} mid=" . substr($mid, 0, 25) . " subj=" . substr((string) $m['subject'], 0, 30) . "\n";
    }
}
// overlap: jean messages whose mid is also in trash (half-move leftovers)
$l = $imap->listMessages('INBOX.Trash', 1, 40);
$dupes = 0;
foreach ($l['messages'] as $m) {
    $mid = mail_normalize_thread_id((string) ($m['message_id'] ?? ''));
    if ($mid !== '' && isset($jeanMids[$mid])) { $dupes++; }
}
echo "\nJean messages ALSO in Trash (half-move leftovers): {$dupes}\n";

echo "\n== recent app log ==\n";
$log = base_path('storage/logs/app.log');
if (is_file($log)) {
    $lines = file($log);
    foreach (array_slice($lines, -25) as $ln) { echo '  ' . rtrim($ln) . "\n"; }
} else {
    foreach (glob(base_path('storage/logs/*')) as $f) { echo "  logfile: {$f}\n"; }
}
