<?php
/**
 * Mail list column fragment (full page or AJAX folder switch).
 *
 * @var string $folderPath
 * @var string $folderB64
 * @var list<array<string, mixed>> $messages
 * @var int $totalMessages
 * @var int $unreadCount
 * @var int $page
 * @var int $totalPages
 * @var string $searchQuery
 * @var bool $imapConnected
 * @var string $imapError
 * @var int $pollInterval
 * @var int|null $perPage
 * @var string|null $title
 */
?>
<div class="mail-list-column">
<section class="page-header page-header--compact page-header--mail">
    <div class="page-title-row">
        <h2><?= e($title ?? 'Mail') ?></h2>
        <?php
        $headerUnread = folder_shows_unread_badge($folderPath)
            ? (int) ($unreadCount ?? 0)
            : 0;
        $headerTitle = folder_uses_draft_badge($folderPath)
            ? ($headerUnread === 1 ? '1 draft' : $headerUnread . ' drafts')
            : ($headerUnread > 0
                ? $headerUnread . ' unread'
                : (int) $totalMessages . ' message' . ($totalMessages === 1 ? '' : 's'));
        ?>
        <?php if ($headerUnread > 0): ?>
        <span class="page-header-count page-header-count--unread" id="mail-count-label" data-total="<?= (int) $totalMessages ?>" data-unread="<?= $headerUnread ?>" title="<?= e($headerTitle) ?>"><?= $headerUnread ?></span>
        <?php else: ?>
        <span class="page-header-count page-header-count--hidden" id="mail-count-label" data-total="<?= (int) $totalMessages ?>" data-unread="0" title="<?= e($headerTitle) ?>" hidden aria-hidden="true"></span>
        <?php endif; ?>
    </div>
</section>

<?php if (!$imapConnected): ?>
<section class="card">
    <p class="status status-error">IMAP connection failed</p>
    <?php if (!empty($imapError)): ?>
        <p class="text-muted error-detail"><?= e($imapError) ?></p>
    <?php endif; ?>
</section>
<?php else: ?>
<?php
$syncQueryParts = [];
if (!empty($searchQuery)) {
    $syncQueryParts[] = 'q=' . urlencode($searchQuery);
}
if (!empty($perPage)) {
    $syncQueryParts[] = 'per_page=' . (int) $perPage;
}
$syncQuery = $syncQueryParts ? '?' . implode('&', $syncQueryParts) : '';
?>
<section class="card card-flush mail-list-card"
    data-mail-sync="<?= empty($searchQuery) ? '1' : '0' ?>"
    data-folder-b64="<?= e($folderB64 ?? encode_folder_path($folderPath)) ?>"
    data-page="<?= (int) $page ?>"
    data-poll-url="<?= e(url('folder/' . ($folderB64 ?? encode_folder_path($folderPath)) . '/sync' . $syncQuery)) ?>"
    data-poll-interval="<?= (int) ($pollInterval ?? 30) ?>"
    data-folder-path="<?= e(encode_folder_path($folderPath)) ?>"
    data-folder-plain="<?= e($folderPath) ?>"
    data-folder-url="<?= e(folder_url($folderPath)) ?>"
    data-folder-kind="<?= e(folder_icon_type($folderPath)) ?>"
    data-total-messages="<?= (int) $totalMessages ?>"
    data-cache-stale="<?= !empty($listCacheStale) ? '1' : '0' ?>">

    <?php require base_path('views/partials/mail-toolbar.php'); ?>

    <div class="mail-select-all-banner" id="select-all-folder-banner" hidden></div>

    <?php if (!empty($listAwaitingSync) && empty($messages)): ?>
    <div id="mail-list-loading" class="mail-list-loading" aria-live="polite">
        <span class="reading-pane-spinner" aria-hidden="true"></span>
        <span>Loading messages…</span>
    </div>
    <?php endif; ?>

    <?php if (empty($messages) && empty($listAwaitingSync)): ?>
    <div id="mail-list-empty" class="empty-state">
        <div class="empty-icon" aria-hidden="true">📭</div>
        <p><?= !empty($searchQuery) ? 'No messages match your search' : 'No messages in this folder' ?></p>
    </div>
    <?php endif; ?>

    <div class="mail-list-scroller"<?= empty($messages) ? ' hidden' : '' ?> id="mail-list-scroller">
    <div class="mail-list-desktop mail-list-rows" id="mail-list-body" role="listbox" aria-label="Messages" tabindex="0">
                <?php foreach ($messages as $msg): ?>
                    <?php require base_path('views/partials/mail-list-row.php'); ?>
                <?php endforeach; ?>
    </div>
    </div>

    <div class="mail-list-mobile" id="mail-list-mobile"<?= empty($messages) ? ' hidden' : '' ?> role="listbox" aria-label="Messages">
        <?php foreach ($messages as $msg): ?>
            <?php
            $rowFolder = \App\Services\FolderCache::resolvePath(employee_messages_imap_path((string) ($msg['list_folder'] ?? $folderPath)));
            $rowFolderB64 = encode_folder_path($rowFolder);
            $msgUid = (int) $msg['uid'];
            ?>
            <div class="mail-card<?= !$msg['seen'] ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?><?= is_draft_folder($folderPath) ? ' mail-card--draft' : '' ?><?= !empty($msg['optimistic']) ? ' mail-row--optimistic' : '' ?>"
               role="option" tabindex="0" aria-selected="false"
               data-uid="<?= $msgUid ?>"
               data-seen="<?= $msg['seen'] ? '1' : '0' ?>"
               data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
               <?php if (empty($msg['optimistic'])): ?>
               data-href="<?= e(message_url($rowFolder, $msgUid)) ?>"
               data-folder-b64="<?= e($rowFolderB64) ?>"
               data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($rowFolder) . '&uid=' . $msgUid)) ?>"
               data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($rowFolder) . '&uid=' . $msgUid)) ?>"
               data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($rowFolder) . '&uid=' . $msgUid)) ?>"
               <?php else: ?>
               data-optimistic="1"
               <?php endif; ?>>
                <?php
                $rowDisplay = mail_list_row_display($msg, $folderPath);
                $fromDisplay = $rowDisplay['list_from'];
                $snippet = $rowDisplay['snippet'];
                ?>
                <div class="mail-card-check mail-row-check" onclick="event.stopPropagation()">
                    <input type="checkbox" class="mail-check" value="<?= (int) $msg['uid'] ?>" aria-label="Select message">
                </div>
                <div class="mail-card-body">
                    <div class="mail-card-line1">
                        <?php if ($rowDisplay['is_draft']): ?>
                            <span class="mail-row-draft-badge">[Draft]</span>
                        <?php endif; ?>
                        <span class="mail-card-from" title="<?= e($fromDisplay) ?>"><?= e($fromDisplay) ?></span>
                        <span class="mail-card-meta">
                            <?php if (!empty($msg['has_attachment'])): ?>
                                <span class="mail-row-attach" title="Has attachment" aria-label="Has attachment">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($msg['flagged'])): ?>
                                <span class="flag-dot mail-row-flag" title="Important">&#9733;</span>
                            <?php endif; ?>
                            <span class="mail-card-date"><?= e(format_mail_date($msg['date'])) ?></span>
                        </span>
                    </div>
                    <div class="mail-card-subject" title="<?= e($msg['subject'] ?? '(no subject)') ?>"><?= e($msg['subject']) ?></div>
                    <div class="mail-row-snippet"<?= $snippet !== '' ? ' title="' . e($snippet) . '"' : ' aria-hidden="true"' ?>><?= $snippet !== '' ? e($snippet) : '' ?></div>
                </div>
                <button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">&#8942;</button>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($imapConnected && ($totalMessages > 0 || ($totalPages ?? 1) > 1)): ?>
        <?php
        $pageBase = folder_url($folderPath) . '?';
        if (!empty($searchQuery)) {
            $pageBase .= 'q=' . urlencode($searchQuery) . '&';
        }
        if (!empty($perPage)) {
            $pageBase .= 'per_page=' . (int) $perPage . '&';
        }
        $baseUrl = $pageBase;
        $currentPerPage = (int) ($perPage ?? mail_per_page());
        require base_path('views/partials/pagination.php');
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
