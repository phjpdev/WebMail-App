<?php

declare(strict_types=1);

/**
 * Background post-send IMAP sync (CLI). Frees the Apache worker on single-process hosts.
 *
 * Usage: php scripts/run-post-send.php <32-char-hex-token>
 */

$token = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    fwrite(STDERR, "Usage: php scripts/run-post-send.php <token>\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));
bootstrapAppTimezone();

$jobPath = base_path('storage/post_send/' . $token . '.json');
if (is_file($jobPath)) {
    $peek = json_decode((string) file_get_contents($jobPath), true);
    $sessionId = is_array($peek) ? trim((string) ($peek['session_id'] ?? '')) : '';
    if ($sessionId !== '') {
        session_name(post_send_session_name());
        session_id($sessionId);
        session_start();
    }
}

ignore_user_abort(true);
// Generous ceiling for a detached background job: the send already returned to the
// user, and the folder-sync loop is separately time-budgeted, so this only guards
// against a genuinely hung IMAP connection on a slow/remote server.
@set_time_limit(300);

(new App\Controllers\ComposeController())->runPostSendJobByToken($token);
