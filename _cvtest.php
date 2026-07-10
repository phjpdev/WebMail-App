<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';
bootstrapEnv(__DIR__);
if (($_GET['token'] ?? '') !== 'dj7Qx2mLpv91') { http_response_code(403); exit('forbidden'); }
ini_set('display_errors','1'); @set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');
use App\Services\ImapService;
use App\Services\MailCacheService;
$imap = new ImapService();
if (!$imap->connect()) { exit('imap fail'); }
$do = $_GET['do'] ?? 'state';
$mk = function(string $subj, string $mid, string $inReplyTo, bool $seen) : string {
    $h = "From: CV <cv@ex.test>\r\nTo: test@bebenailsmd.com\r\nSubject: {$subj}\r\n"
       . "Message-ID: <{$mid}@cv.test>\r\nDate: " . date('r') . "\r\n";
    if ($inReplyTo !== '') { $h .= "In-Reply-To: <{$inReplyTo}@cv.test>\r\nReferences: <{$inReplyTo}@cv.test>\r\n"; }
    return $h . "\r\nbody {$subj}\r\n";
};
if ($do === 'seed') {
    // 3-message chain in INBOX (proper In-Reply-To) + 1 stranger same subject, no refs
    $imap->appendMessage('INBOX', $mk('CVThread', 'cv1', '', true), '\Seen');
    $imap->appendMessage('INBOX', $mk('Re: CVThread', 'cv2', 'cv1', true), '\Seen');
    $imap->appendMessage('INBOX', $mk('Re: CVThread', 'cv3', 'cv2', false)); // unread
    $imap->appendMessage('INBOX', $mk('CVThread', 'cvX', '', true), '\Seen'); // stranger, same subject, no ref
    MailCacheService::syncFolderHeaders($imap, 'INBOX');
    MailCacheService::reconcileBadgeFromIndex('INBOX');
    echo "seeded\n";
    foreach ($imap->listMessages('INBOX',1,40)['messages'] as $m) if (stripos((string)$m['subject'],'CVThread')!==false) echo "  uid={$m['uid']} {$m['subject']}\n";
    exit;
}
if ($do === 'cleanup') {
    foreach (['INBOX','INBOX.Trash','INBOX.Jean.Inbox'] as $f) {
        foreach ($imap->listMessages($f,1,80)['messages'] as $m) if (stripos((string)($m['subject']??''),'CVThread')!==false) $imap->deleteMessages($f,[(int)$m['uid']]);
        MailCacheService::syncFolderHeaders($imap,$f); MailCacheService::reconcileBadgeFromIndex($f);
    }
    echo "cleaned\n"; exit;
}
