<?php
/**
 * Global search results column.
 *
 * @var string $searchQuery
 * @var list<array<string, mixed>> $messages
 * @var int $totalMessages
 * @var int $page
 * @var int $totalPages
 * @var bool $imapConnected
 * @var string $imapError
 * @var int|null $perPage
 * @var bool $showFolder
 */
?>
<div class="mail-list-column mail-list-column--search">
<section class="page-header page-header--compact page-header--mail">
    <div class="page-title-row">
        <h2>Search results</h2>
        <?php if (!empty($searchQuery)): ?>
            <span class="page-header-subtitle" title="<?= e($searchQuery) ?>">“<?= e($searchQuery) ?>”</span>
        <?php endif; ?>
        <a class="search-clear-link" href="<?= e(url('folder/' . encode_folder_path(default_mail_folder()))) ?>" title="Clear search and return to your mailbox">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            <span>Clear search</span>
        </a>
    </div>
    <?php if ($imapConnected): ?>
        <p class="search-result-count">
            <strong><?= (int) $totalMessages ?></strong> <?= (int) $totalMessages === 1 ? 'result' : 'results' ?>
            <span class="search-result-scope">· searched senders, recipients, subjects and message text</span>
        </p>
    <?php endif; ?>
</section>

<?php if (!$imapConnected): ?>
<section class="card">
    <p class="status status-error">IMAP connection failed</p>
    <?php if (!empty($imapError)): ?>
        <p class="text-muted error-detail"><?= e($imapError) ?></p>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="card card-flush mail-list-card mail-list-card--search"
    data-mail-sync="0"
    data-global-search="1"
    data-total-messages="<?= (int) $totalMessages ?>">

    <?php $folderPath = ''; require base_path('views/partials/mail-toolbar.php'); ?>

    <?php if (empty($messages)): ?>
    <div id="mail-list-empty" class="empty-state empty-state--search">
        <div class="empty-icon" aria-hidden="true">🔍</div>
        <p>No results for “<?= e($searchQuery) ?>”</p>
        <p class="empty-state-hint">Try different keywords — you can search by sender, recipient, subject, or words inside the message.</p>
        <a class="btn btn-outline btn-sm" href="<?= e(url('folder/' . encode_folder_path(default_mail_folder()))) ?>">Back to Inbox</a>
    </div>
    <?php endif; ?>

    <div class="mail-list-scroller"<?= empty($messages) ? ' hidden' : '' ?> id="mail-list-scroller">
    <div class="mail-list-desktop mail-list-rows" id="mail-list-body" role="listbox" aria-label="Search results" tabindex="0">
        <?php foreach ($messages as $msg): ?>
            <?php
            $folderPath = (string) ($msg['_folder_path'] ?? '');
            require base_path('views/partials/mail-list-row.php');
            ?>
        <?php endforeach; ?>
    </div>
    </div>

    <div class="mail-list-mobile" id="mail-list-mobile"<?= empty($messages) ? ' hidden' : '' ?> role="listbox" aria-label="Search results">
        <?php foreach ($messages as $msg): ?>
            <?php
            $folderPath = (string) ($msg['_folder_path'] ?? '');
            $rowDisplay = mail_list_row_display($msg, $folderPath);
            $fromDisplay = $rowDisplay['list_from'];
            $snippet = $rowDisplay['snippet'];
            ?>
            <div class="mail-card<?= !$msg['seen'] ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?><?= is_draft_folder($folderPath) ? ' mail-card--draft' : '' ?>"
               role="option" tabindex="0" aria-selected="false"
               data-uid="<?= (int) $msg['uid'] ?>"
               data-seen="<?= $msg['seen'] ? '1' : '0' ?>"
               data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
               data-href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>"
               data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>">
                <div class="mail-card-check mail-row-check" onclick="event.stopPropagation()">
                    <input type="checkbox" class="mail-check" value="<?= (int) $msg['uid'] ?>" aria-label="Select message">
                </div>
                <div class="mail-card-body">
                    <div class="mail-card-line1">
                        <?php if (!empty($showFolder) && !empty($msg['_folder_label'])): ?>
                            <span class="mail-row-folder"><?= e($msg['_folder_label']) ?></span>
                        <?php endif; ?>
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
                    <div class="mail-card-subject" title="<?= e($msg['subject'] ?? '(no subject)') ?>"><?= !empty($msg['_hl_subject']) ? $msg['_hl_subject'] : e($msg['subject']) ?></div>
                    <div class="mail-row-snippet"<?= $snippet !== '' ? ' title="' . e($snippet) . '"' : ' aria-hidden="true"' ?>><?= !empty($msg['_hl_snippet']) ? $msg['_hl_snippet'] : ($snippet !== '' ? e($snippet) : '') ?></div>
                </div>
                <button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">&#8942;</button>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($imapConnected && ($totalMessages > 0 || ($totalPages ?? 1) > 1)): ?>
        <?php
        $pageBase = url('search') . '?q=' . urlencode($searchQuery) . '&';
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
