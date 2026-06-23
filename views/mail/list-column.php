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
        $headerUnread = (int) ($unreadCount ?? 0);
        $headerTitle = $headerUnread > 0
            ? $headerUnread . ' unread'
            : (int) $totalMessages . ' message' . ($totalMessages === 1 ? '' : 's');
        ?>
        <?php if ($headerUnread > 0): ?>
        <span class="page-header-count page-header-count--unread" id="mail-count-label" data-total="<?= (int) $totalMessages ?>" data-unread="<?= $headerUnread ?>" title="<?= e($headerTitle) ?>"><?= $headerUnread ?></span>
        <?php else: ?>
        <span class="page-header-count page-header-count--hidden" id="mail-count-label" data-total="<?= (int) $totalMessages ?>" data-unread="0" title="<?= e($headerTitle) ?>" hidden aria-hidden="true"></span>
        <?php endif; ?>
    </div>
    <form method="get" action="<?= e(folder_url($folderPath)) ?>" class="search-field search-field--mail mail-search-form" id="mail-search-form">
        <?php if (!empty($perPage)): ?>
            <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <?php endif; ?>
        <span class="search-field-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        </span>
        <input type="search" name="q" id="mail-search" class="search-field-input"
               placeholder="Search in <?= e($title ?? 'folder') ?>…"
               value="<?= e($searchQuery ?? '') ?>" autocomplete="off" enterkeyhint="search">
        <?php if (!empty($searchQuery)): ?>
            <a class="search-field-clear" href="<?= e(folder_url($folderPath) . (!empty($perPage) ? '?per_page=' . (int) $perPage : '')) ?>" aria-label="Clear search" title="Clear search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </a>
        <?php endif; ?>
    </form>
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
    data-total-messages="<?= (int) $totalMessages ?>">

    <?php require base_path('views/partials/mail-toolbar.php'); ?>

    <div class="mail-select-all-banner" id="select-all-folder-banner" hidden></div>

    <?php if (empty($messages)): ?>
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
            <div class="mail-card<?= !$msg['seen'] ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?>"
               role="option" tabindex="0" aria-selected="false"
               data-uid="<?= (int) $msg['uid'] ?>"
               data-seen="<?= $msg['seen'] ? '1' : '0' ?>"
               data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
               data-href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>"
               data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>">
                <div class="mail-card-top">
                    <span class="mail-card-from"><?= !empty($msg['flagged']) ? '<span class="flag-dot" title="Important">&#9733;</span> ' : '' ?><?= e(format_mail_from($msg['from'])) ?></span>
                    <span class="mail-card-meta">
                        <?php if (!empty($msg['has_attachment'])): ?>
                            <span class="mail-row-attach" title="Has attachment" aria-label="Has attachment">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            </span>
                        <?php endif; ?>
                        <span class="mail-card-date"><?= e(format_mail_date($msg['date'])) ?></span>
                    </span>
                    <button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">&#8942;</button>
                </div>
                <div class="mail-card-subject"><?= e($msg['subject']) ?></div>
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
