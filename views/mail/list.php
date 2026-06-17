<?php ob_start(); ?>

<section class="page-header">
    <div class="page-header-row">
        <div class="page-header-left">
            <div class="page-title-row">
                <h2><?= e($title ?? 'Mail') ?></h2>
                <span class="page-header-count" id="mail-count-label" title="<?= (int) $totalMessages ?> message<?= $totalMessages === 1 ? '' : 's' ?>"><?= (int) $totalMessages ?></span>
            </div>
        </div>
        <form method="get" action="<?= e(folder_url($folderPath)) ?>" class="search-field mail-search-form" id="mail-search-form">
            <?php if (!empty($perPage)): ?>
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
            <?php endif; ?>
            <span class="search-field-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            </span>
            <input type="search" name="q" id="mail-search" class="search-field-input" placeholder="Search subject or from…"
                   value="<?= e($searchQuery ?? '') ?>" autocomplete="off">
            <button type="submit" class="btn btn-primary btn-sm search-field-btn">Search</button>
            <?php if (!empty($searchQuery)): ?>
                <a class="btn btn-outline btn-sm" href="<?= e(folder_url($folderPath) . (!empty($perPage) ? '?per_page=' . (int) $perPage : '')) ?>">Clear</a>
            <?php endif; ?>
        </form>
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
    data-folder-path="<?= e(encode_folder_path($folderPath)) ?>">

    <?php if (!empty($messages)): ?>
    <div class="bulk-toolbar" id="bulk-toolbar" hidden>
        <span id="bulk-count">0 selected</span>
        <form method="post" action="<?= e(url('message/bulk-mark-read')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <div id="bulk-read-uids"></div>
            <button type="submit" class="btn btn-outline btn-sm">Mark read</button>
        </form>
        <form method="post" action="<?= e(url('message/bulk-mark-unread')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <div id="bulk-unread-uids"></div>
            <button type="submit" class="btn btn-outline btn-sm">Mark unread</button>
        </form>
        <form method="post" action="<?= e(url('message/bulk-trash')) ?>" class="inline-form"
              onsubmit="return confirm('Move selected messages to Trash?');">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <div id="bulk-trash-uids"></div>
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
        <form method="post" action="<?= e(url('message/bulk-move')) ?>" class="inline-form move-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <div id="bulk-move-uids"></div>
            <div class="select-field select-field-inline">
            <select name="target_folder" required>
                <option value="">Move to…</option>
                <?php foreach ($folders as $f): ?>
                    <?php if ($f['path'] !== $folderPath): ?>
                    <option value="<?= e($f['path']) ?>"><?= e($f['name']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Move</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($messages)): ?>
    <div id="mail-list-empty" class="empty-state">
        <div class="empty-icon" aria-hidden="true">📭</div>
        <p><?= !empty($searchQuery) ? 'No messages match your search' : 'No messages in this folder' ?></p>
    </div>
    <?php endif; ?>

    <div class="mail-list-desktop table-wrap"<?= empty($messages) ? ' hidden' : '' ?>>
        <table class="data-table mail-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                    <th class="col-status"></th>
                    <th>From</th>
                    <th>Subject</th>
                    <th class="col-date">Date</th>
                </tr>
            </thead>
            <tbody id="mail-list-body">
                <?php foreach ($messages as $msg): ?>
                    <tr class="mail-row<?= !$msg['seen'] ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?>"
                        data-uid="<?= (int) $msg['uid'] ?>"
                        data-seen="<?= $msg['seen'] ? '1' : '0' ?>"
                        data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
                        data-href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>"
                        data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
                        data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
                        data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>">
                        <td class="col-check" onclick="event.stopPropagation()">
                            <input type="checkbox" class="mail-check" value="<?= (int) $msg['uid'] ?>" aria-label="Select message">
                        </td>
                        <td class="col-status"><?= !$msg['seen'] ? '<span class="unread-dot"></span>' : '' ?><?= !empty($msg['flagged']) ? '<span class="flag-dot" title="Important">&#9733;</span>' : '' ?></td>
                        <td class="col-from"><?= e(format_mail_from($msg['from'])) ?></td>
                        <td class="col-subject"><?= e($msg['subject']) ?></td>
                        <td class="col-date">
                            <span class="col-date-text"><?= e(format_mail_date($msg['date'])) ?></span>
                            <button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">&#8942;</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mail-list-mobile" id="mail-list-mobile"<?= empty($messages) ? ' hidden' : '' ?>>
        <?php foreach ($messages as $msg): ?>
            <div class="mail-card<?= !$msg['seen'] ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?>"
               role="link" tabindex="0"
               data-uid="<?= (int) $msg['uid'] ?>"
               data-seen="<?= $msg['seen'] ? '1' : '0' ?>"
               data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
               data-href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>"
               data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>"
               data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $msg['uid'])) ?>">
                <div class="mail-card-top">
                    <span class="mail-card-from"><?= !empty($msg['flagged']) ? '<span class="flag-dot" title="Important">&#9733;</span> ' : '' ?><?= e(format_mail_from($msg['from'])) ?></span>
                    <span class="mail-card-date"><?= e(format_mail_date($msg['date'])) ?></span>
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

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
