<?php

declare(strict_types=1);

/**
 * check-setup.php — deployment self-test.
 *
 * Visit https://<your-server>/check-setup.php in a browser after uploading the
 * app + importing the database + writing .env. Every row should be green.
 *
 * It boots the SAME config the app uses (vendor autoload + .env), so a pass
 * here means the real app will connect too.
 *
 * SECURITY: this reveals environment details. DELETE THIS FILE from the server
 * once all checks pass. It never prints passwords (only length / encoding mode).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$rows = [];
$fail = 0;
$warn = 0;

/** @param 'ok'|'warn'|'fail' $status */
function row(string $label, string $status, string $detail): void
{
    global $rows, $fail, $warn;
    if ($status === 'fail') {
        $fail++;
    } elseif ($status === 'warn') {
        $warn++;
    }
    $rows[] = [$label, $status, $detail];
}

// --- 1. PHP version ---------------------------------------------------------
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
row('PHP version', $phpOk ? 'ok' : 'fail', PHP_VERSION . ($phpOk ? '' : ' — need 8.0 or newer'));

// --- 2. Required extensions -------------------------------------------------
$required = ['imap', 'pdo_mysql', 'openssl', 'mbstring', 'curl', 'json', 'fileinfo'];
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    $note = $loaded ? 'loaded' : 'MISSING — enable php_' . $ext . ' and restart the web server';
    // imap and pdo_mysql are hard requirements; the rest are effectively required too.
    row('Extension: ' . $ext, $loaded ? 'ok' : 'fail', $note);
}

// --- 3. Bootstrap the app (.env + config) -----------------------------------
$booted = false;
try {
    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/src/helpers.php';
    bootstrapEnv(__DIR__);
    $booted = true;
    row('Bootstrap (.env + autoload)', 'ok', 'loaded');
} catch (\Throwable $e) {
    row('Bootstrap (.env + autoload)', 'fail', $e->getMessage());
}

// --- 4. .env core values ----------------------------------------------------
if ($booted) {
    $appUrl = (string) env('APP_URL', '');
    row('APP_URL', $appUrl !== '' ? 'ok' : 'warn', $appUrl !== '' ? $appUrl : 'not set');

    $debug = env('APP_DEBUG', 'false');
    $debugOn = filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    row('APP_DEBUG', $debugOn ? 'warn' : 'ok', $debugOn ? 'true — set to false in production' : 'false (good for production)');

    $dbName = (string) env('DB_NAME', '');
    row('DB_NAME', $dbName !== '' ? 'ok' : 'fail', $dbName !== '' ? $dbName : 'not set');

    // Report how the DB password was provided, and its length — never the value.
    $rawPlain = (string) env('DB_PASSWORD', '');
    $rawB64 = (string) env('DB_PASSWORD_B64', '');
    if ($rawB64 !== '') {
        $decoded = (string) base64_decode($rawB64, true);
        row('DB password', $decoded !== '' ? 'ok' : 'fail',
            'mode: base64, decoded length: ' . strlen($decoded) . ($decoded === '' ? ' — invalid base64' : ''));
    } elseif ($rawPlain !== '') {
        row('DB password', 'ok', 'mode: plain, length: ' . strlen($rawPlain));
    } else {
        row('DB password', 'warn', 'empty (only OK if your MySQL user truly has no password)');
    }
}

// --- 5. Database connection + sanity query ----------------------------------
if ($booted) {
    try {
        $pdo = \App\Database::connection();
        row('Database connection', 'ok', 'connected');

        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            row('Table: users', $n > 0 ? 'ok' : 'warn',
                $n . ' row(s)' . ($n === 0 ? ' — did you import the database export?' : ''));
        } catch (\Throwable $e) {
            row('Table: users', 'fail', 'query failed — was the database imported? (' . $e->getMessage() . ')');
        }
    } catch (\Throwable $e) {
        row('Database connection', 'fail', $e->getMessage()
            . ' — check DB_HOST/DB_NAME/DB_USER/DB_PASSWORD in .env and that the DB user is assigned to the database');
    }
}

// --- 6. Writable storage directories ----------------------------------------
$dirs = ['storage/logs', 'storage/post_send', 'storage/thread_replies'];
foreach ($dirs as $d) {
    $path = __DIR__ . '/' . $d;
    if (!is_dir($path)) {
        row('Writable: ' . $d, 'fail', 'does not exist — create this folder');
        continue;
    }
    $probe = $path . '/.write_test_' . getmypid();
    $ok = @file_put_contents($probe, 'x') !== false;
    if ($ok) {
        @unlink($probe);
    }
    row('Writable: ' . $d, $ok ? 'ok' : 'fail', $ok ? 'writable' : 'NOT writable — fix folder permissions');
}

// --- 7. URL rewriting (.htaccess active) ------------------------------------
$rewrite = null;
if (function_exists('apache_get_modules')) {
    $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
}
if ($rewrite === true) {
    row('mod_rewrite', 'ok', 'enabled');
} elseif ($rewrite === false) {
    row('mod_rewrite', 'fail', 'NOT enabled — turn on mod_rewrite (routing + .htaccess protection depend on it)');
} else {
    row('mod_rewrite', 'warn', 'cannot detect (non-Apache or CGI). Confirm clean URLs work: open the app homepage.');
}

// --- 8. HTTPS ---------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
row('HTTPS', $https ? 'ok' : 'warn', $https ? 'yes' : 'no — install an SSL certificate before going live (login cookies need it)');

// --- 9. Mail (IMAP) config present ------------------------------------------
if ($booted) {
    $imapHost = (string) env('IMAP_HOST', '');
    $mailbox = (string) env('MAILBOX_EMAIL', '');
    $hasPass = env('MAILBOX_PASSWORD', '') !== '' || env('MAILBOX_PASSWORD_B64', '') !== '';
    $mailOk = $imapHost !== '' && $mailbox !== '' && $hasPass;
    row('Mail (IMAP) config', $mailOk ? 'ok' : 'fail',
        $mailOk
            ? ($mailbox . ' @ ' . $imapHost . ' (unchanged from old server — correct)')
            : 'IMAP_HOST / MAILBOX_EMAIL / MAILBOX_PASSWORD must be set (copy these unchanged from the old server)');

    // Optional live IMAP login test — only if ?imap=1 (can be slow / block).
    if (($_GET['imap'] ?? '') === '1' && $mailOk && extension_loaded('imap')) {
        $port = (int) env('IMAP_PORT', 993);
        $enc = (string) env('IMAP_ENCRYPTION', 'ssl');
        $flags = '/imap';
        $flags .= $enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '');
        if (!filter_var(env('IMAP_VALIDATE_CERT', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $flags .= '/novalidate-cert';
        }
        $mboxStr = '{' . $imapHost . ':' . $port . $flags . '}';
        $pass = env('MAILBOX_PASSWORD_B64', '') !== ''
            ? (string) base64_decode((string) env('MAILBOX_PASSWORD_B64'), true)
            : (string) env('MAILBOX_PASSWORD', '');
        $conn = @imap_open($mboxStr, $mailbox, $pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($conn) {
            @imap_close($conn);
            row('IMAP live login (?imap=1)', 'ok', 'connected to ' . $imapHost);
        } else {
            row('IMAP live login (?imap=1)', 'fail', trim((string) imap_last_error()) ?: 'connection failed');
        }
    }
}

// --- Render -----------------------------------------------------------------
$overall = $fail > 0 ? 'FAIL' : ($warn > 0 ? 'CHECK WARNINGS' : 'ALL GOOD');
$overallColor = $fail > 0 ? '#c62828' : ($warn > 0 ? '#b26a00' : '#1a7f37');
$colors = ['ok' => '#1a7f37', 'warn' => '#b26a00', 'fail' => '#c62828'];
$icons = ['ok' => '✓', 'warn' => '!', 'fail' => '✗'];
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deployment check</title>
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f5f6f8; color: #1f2328; }
  .wrap { max-width: 820px; margin: 2rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
  .sub { color: #57606a; margin: 0 0 1.25rem; font-size: 0.9rem; }
  .banner { padding: 0.75rem 1rem; border-radius: 8px; color: #fff; font-weight: 600; margin-bottom: 1.25rem; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
  td { padding: 0.6rem 0.9rem; border-bottom: 1px solid #eaecef; vertical-align: top; font-size: 0.9rem; }
  tr:last-child td { border-bottom: none; }
  .st { width: 1.5rem; font-weight: 700; text-align: center; }
  .lbl { width: 210px; font-weight: 600; }
  .det { color: #57606a; }
  .note { margin-top: 1.25rem; padding: 0.85rem 1rem; background: #fff8e1; border: 1px solid #ffe08a; border-radius: 8px; font-size: 0.88rem; }
  code { background: #eef1f4; padding: 0.1rem 0.35rem; border-radius: 4px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>D&amp;J Webmail — deployment check</h1>
  <p class="sub">Boots the real app config (.env + database). Every row should be green.</p>
  <div class="banner" style="background: <?= $overallColor ?>;">
    <?= htmlspecialchars($overall) ?>
    <?= $fail > 0 ? "($fail failed" . ($warn ? ", $warn warning" . ($warn > 1 ? 's' : '') : '') . ')' : ($warn > 0 ? "($warn warning" . ($warn > 1 ? 's' : '') . ')' : '') ?>
  </div>
  <table>
    <?php foreach ($rows as [$label, $status, $detail]): ?>
    <tr>
      <td class="st" style="color: <?= $colors[$status] ?>;"><?= $icons[$status] ?></td>
      <td class="lbl"><?= htmlspecialchars($label) ?></td>
      <td class="det"><?= htmlspecialchars($detail) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div class="note">
    <strong>After all checks pass, delete this file</strong> (<code>check-setup.php</code>) from the server — it exposes environment details.
    <br>To also test the live mailbox login, append <code>?imap=1</code> to the URL (may take a few seconds).
  </div>
</div>
</body>
</html>
