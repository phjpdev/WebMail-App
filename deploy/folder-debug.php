<?php

declare(strict_types=1);

/**
 * folder-debug.php — shows exactly which folders the mail server reports vs
 * which are registered in the app's database, so we can see why an import
 * found fewer folders than expected.
 *
 * USE: copy to the app ROOT (next to index.php), open in a browser:
 *   http://localhost/dj_email/folder-debug.php
 * Send the whole output back. DELETE the file afterwards.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';
bootstrapEnv(__DIR__);

$mail = config('mail');
$imapCfg = $mail['imap'];

$flags = '/imap';
if (($imapCfg['encryption'] ?? '') === 'ssl') {
    $flags .= '/ssl';
}
$flags .= !empty($imapCfg['validate_cert']) ? '/validate-cert' : '/novalidate-cert';
$ref = sprintf('{%s:%d%s}', $imapCfg['host'], (int) $imapCfg['port'], $flags);

echo "=== Connection ===\n";
echo "reference: {$ref}\n";
echo "login:     {$mail['mailbox_email']}\n\n";

$conn = @imap_open($ref, $mail['mailbox_email'], $mail['mailbox_password'], 0, 1);
if (!$conn) {
    echo "IMAP OPEN FAILED: " . imap_last_error() . "\n";
    exit;
}

// --- What the server reports (three different listing commands) -------------
echo "=== 1. imap_getmailboxes (LIST *) — what the app's import uses ===\n";
$boxes = imap_getmailboxes($conn, $ref, '*') ?: [];
foreach ($boxes as $b) {
    $name = $b->name ?? '';
    // Strip whatever reference prefix the server echoed back
    $stripped = preg_replace('/^\{[^}]*\}/', '', $name);
    $attrs = [];
    if (($b->attributes ?? 0) & LATT_NOSELECT) { $attrs[] = 'NOSELECT'; }
    if (($b->attributes ?? 0) & LATT_HASCHILDREN) { $attrs[] = 'HASCHILDREN'; }
    echo "  " . imap_utf7_decode($stripped)
        . "   [delim=" . ($b->delimiter ?? '?') . ($attrs ? ' ' . implode(',', $attrs) : '') . "]\n";
}
echo "  (total: " . count($boxes) . ")\n\n";

echo "=== 2. imap_list (LIST *) ===\n";
$list = imap_list($conn, $ref, '*') ?: [];
foreach ($list as $name) {
    echo "  " . imap_utf7_decode(preg_replace('/^\{[^}]*\}/', '', $name)) . "\n";
}
echo "  (total: " . count($list) . ")\n\n";

echo "=== 3. imap_getsubscribed (LSUB *) — subscribed-only view ===\n";
$subs = imap_getsubscribed($conn, $ref, '*') ?: [];
foreach ($subs as $b) {
    echo "  " . imap_utf7_decode(preg_replace('/^\{[^}]*\}/', '', $b->name ?? '')) . "\n";
}
echo "  (total: " . count($subs) . ")\n\n";

imap_close($conn);

// --- What the app has registered --------------------------------------------
echo "=== 4. Folders registered in the app database ===\n";
try {
    $pdo = \App\Database::connection();
    $rows = $pdo->query('SELECT imap_path, display_name, folder_type, active FROM folders ORDER BY imap_path')->fetchAll();
    foreach ($rows as $r) {
        echo "  {$r['imap_path']}   (name: {$r['display_name']}, type: {$r['folder_type']}, active: {$r['active']})\n";
    }
    echo "  (total: " . count($rows) . ")\n";
} catch (\Throwable $e) {
    echo "  DB error: " . $e->getMessage() . "\n";
}

echo "\n=== Done. Delete this file from the server. ===\n";
