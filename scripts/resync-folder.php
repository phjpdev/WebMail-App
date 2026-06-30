<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));

$folder = $argv[1] ?? 'INBOX.Jean.Inbox';
$folder = mail_resolve_move_target_path($folder);

$imap = new App\Services\ImapService();
if (!$imap->connect()) {
    fwrite(STDERR, "IMAP connect failed: " . $imap->getLastError() . PHP_EOL);
    exit(1);
}

$count = App\Services\MailCacheService::resyncFolderAfterMove($imap, $folder, [], 50);
$indexed = App\Services\MailCacheService::countListableMessagesInIndex(
    App\Services\MailCacheService::indexFolderPath($folder)
);

echo "Resynced {$folder}: imap_headers={$count}, index_rows={$indexed}" . PHP_EOL;
