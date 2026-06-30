<?php
/**
 * @var array{uid: int, from?: string, to?: string, list_from?: string, snippet?: string, subject?: string, date?: string, seen?: bool, flagged?: bool, has_attachment?: bool} $msg
 * @var string $folderPath
 */
$rowDisplay = mail_list_row_display($msg, $folderPath);
$fromDisplay = $rowDisplay['list_from'];
$snippet = $rowDisplay['snippet'];
$uid = (int) $msg['uid'];
?>
<div class="mail-row mail-row--outlook<?= empty($msg['seen']) ? ' mail-unread' : '' ?><?= !empty($msg['flagged']) ? ' mail-flagged' : '' ?><?= $rowDisplay['is_draft'] ? ' mail-row--draft' : '' ?><?= !empty($msg['optimistic']) ? ' mail-row--optimistic' : '' ?>"
    role="option"
    tabindex="-1"
    aria-selected="false"
    data-uid="<?= $uid ?>"
    data-seen="<?= !empty($msg['seen']) ? '1' : '0' ?>"
    data-flagged="<?= !empty($msg['flagged']) ? '1' : '0' ?>"
    <?php if (!empty($msg['optimistic'])): ?>
    data-optimistic="1"
    <?php else: ?>
    data-href="<?= e(message_url($folderPath, $uid)) ?>"
    data-reply-url="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>"
    data-reply-all-url="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>"
    data-forward-url="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>"
    <?php endif; ?>>
    <div class="mail-row-check" onclick="event.stopPropagation()">
        <input type="checkbox" class="mail-check" value="<?= $uid ?>" aria-label="Select message">
    </div>
    <div class="mail-row-body">
        <div class="mail-row-text">
            <div class="mail-row-line1">
                <?php if (!empty($showFolder) && !empty($msg['_folder_label'])): ?>
                    <span class="mail-row-folder"><?= e($msg['_folder_label']) ?></span>
                <?php endif; ?>
                <?php if ($rowDisplay['is_draft']): ?>
                    <span class="mail-row-draft-badge">[Draft]</span>
                <?php endif; ?>
                <span class="mail-row-from" title="<?= e($fromDisplay) ?>"><?= e($fromDisplay) ?></span>
            </div>
            <div class="mail-row-subject" title="<?= e($msg['subject'] ?? '(no subject)') ?>"><?= e($msg['subject'] ?? '(no subject)') ?></div>
            <div class="mail-row-snippet"<?= $snippet !== '' ? ' title="' . e($snippet) . '"' : ' aria-hidden="true"' ?>><?= $snippet !== '' ? e($snippet) : '' ?></div>
        </div>
        <span class="mail-row-meta">
            <?php if (!empty($msg['has_attachment'])): ?>
                <span class="mail-row-attach" title="Has attachment" aria-label="Has attachment">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </span>
            <?php endif; ?>
            <?php if (!empty($msg['flagged'])): ?>
                <span class="flag-dot mail-row-flag" title="Important">&#9733;</span>
            <?php endif; ?>
            <span class="mail-row-date"><?= e(format_mail_date($msg['date'] ?? '')) ?></span>
        </span>
    </div>
    <button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">&#8942;</button>
</div>
