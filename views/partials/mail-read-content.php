<?php
/**
 * Shared read view body (actions, headers, attachments, message body).
 *
 * @var array<string, mixed> $message
 * @var string $folderPath
 * @var string|null $folderB64
 * @var string $replyFrom
 * @var list<array{path: string, name: string}> $moveTargets
 * @var string $sanitizedHtml
 * @var bool $isPane
 */
$isPane = !empty($isPane);
$folderB64 = $folderB64 ?? encode_folder_path($folderPath);
$uid = (int) ($message['uid'] ?? 0);
?>

<div class="mail-actions no-print" role="toolbar" aria-label="Message actions">
    <a class="mail-action-btn mail-action-btn--primary compose-panel-link" id="reply-btn" href="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply" title="Reply" aria-label="Reply">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10h10a4 4 0 0 1 4 4v1"/><path d="M3 10l5-5M3 10l5 5"/></svg>
        <span class="mail-action-label">Reply</span>
    </a>
    <a class="mail-action-btn compose-panel-link" id="reply-all-btn" href="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply all" title="Reply all" aria-label="Reply all">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10h6a4 4 0 0 1 4 4v1"/><path d="M3 10l5-5M3 10l5 5"/><path d="M11 10h6a4 4 0 0 1 4 4v1"/><path d="M11 10l5-5M11 10l5 5"/></svg>
        <span class="mail-action-label">Reply all</span>
    </a>
    <a class="mail-action-btn compose-panel-link" href="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Forward" title="Forward" aria-label="Forward">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 10H11a4 4 0 0 0-4 4v1"/><path d="M21 10l-5-5M21 10l-5 5"/></svg>
        <span class="mail-action-label">Forward</span>
    </a>
    <?php if (folder_icon_type($folderPath) === 'draft'): ?>
        <a class="mail-action-btn compose-panel-link" href="<?= e(url('compose/edit-draft?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Edit draft" title="Edit draft" aria-label="Edit draft">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            <span class="mail-action-label">Edit draft</span>
        </a>
    <?php endif; ?>
    <button type="button" class="mail-action-btn" onclick="window.print()" title="Print" aria-label="Print">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <span class="mail-action-label">Print</span>
    </button>

    <span class="mail-action-divider" aria-hidden="true"></span>

    <?php if (!empty($message['seen'])): ?>
        <button type="button" class="mail-action-btn" data-mail-action="mark-unread" title="Mark unread" aria-label="Mark unread">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            <span class="mail-action-label">Unread</span>
        </button>
    <?php else: ?>
        <button type="button" class="mail-action-btn" data-mail-action="mark-read" title="Mark read" aria-label="Mark read">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 9l9-6 9 6v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 9l9 6 9-6"/></svg>
            <span class="mail-action-label">Read</span>
        </button>
    <?php endif; ?>
    <button type="button" class="mail-action-btn" data-mail-action="flag" title="Mark important" aria-label="Mark important">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/></svg>
        <span class="mail-action-label">Flag</span>
    </button>
    <button type="button" class="mail-action-btn" data-mail-action="unflag" title="Remove importance" aria-label="Remove importance">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/><path d="M4 4l16 16"/></svg>
        <span class="mail-action-label">Unflag</span>
    </button>
    <button type="button" class="mail-action-btn" data-mail-action="spam" title="Spam" aria-label="Report spam">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
        <span class="mail-action-label">Spam</span>
    </button>
    <button type="button" class="mail-action-btn mail-action-btn--danger"<?= $isPane ? '' : ' id="delete-form"' ?> data-mail-action="trash" title="Delete" aria-label="Delete">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3"/></svg>
        <span class="mail-action-label">Delete</span>
    </button>

    <?php if (!empty($moveTargets)): ?>
    <form class="mail-action-move move-form" onsubmit="return false;">
        <label class="sr-only" for="read-move-target-<?= $uid ?>">Move to folder</label>
        <select id="read-move-target-<?= $uid ?>" name="target_folder" class="mail-action-move-select" required aria-label="Move to folder">
            <option value="">Move to…</option>
            <?php foreach ($moveTargets as $target): ?>
                <option value="<?= e($target['path']) ?>"><?= e($target['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="mail-action-btn mail-action-btn--move" data-mail-action="move" title="Move" aria-label="Move to folder">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <span class="mail-action-label">Move</span>
        </button>
    </form>
    <?php endif; ?>
</div>

<dl class="detail-list mail-headers">
    <dt>Subject</dt><dd><?= e($message['subject'] ?: '(no subject)') ?></dd>
    <dt>From</dt><dd><?= e($message['from'] ?? '—') ?></dd>
    <dt>To</dt><dd><?= e($message['to'] ?? '—') ?></dd>
    <?php if (!empty($message['cc'])): ?>
    <dt>Cc</dt><dd><?= e($message['cc']) ?></dd>
    <?php endif; ?>
    <dt>Delivered-To</dt><dd><?= e($message['delivered_to'] ?? '—') ?></dd>
    <dt>Date</dt><dd><?= e($message['date'] ?? '—') ?></dd>
    <dt>Reply as</dt><dd><code><?= e($replyFrom) ?></code></dd>
</dl>

<?php if (!empty($message['attachments'])): ?>
<div class="attachments">
    <strong>Attachments:</strong>
    <ul>
        <?php foreach ($message['attachments'] as $att): ?>
            <?php
            $baseUrl = url('attachment?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . '&part=' . urlencode($att['id']));
            $isPreview = str_starts_with($att['mime'], 'image/') || $att['mime'] === 'application/pdf';
            ?>
            <li>
                <a href="<?= e($baseUrl) ?>"><?= e($att['filename']) ?> (<?= number_format($att['size'] / 1024, 1) ?> KB)</a>
                <?php if ($isPreview): ?>
                    · <a href="<?= e($baseUrl . '&disposition=inline') ?>" target="_blank" rel="noopener">Preview</a>
                <?php endif; ?>
            </li>
            <?php if (str_starts_with($att['mime'], 'image/')): ?>
            <li class="attachment-preview">
                <img src="<?= e($baseUrl . '&disposition=inline') ?>" alt="<?= e($att['filename']) ?>" loading="lazy">
            </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="mail-body">
    <?php if ($sanitizedHtml !== ''): ?>
        <div class="mail-body-html"><?= $sanitizedHtml ?></div>
    <?php elseif (!empty($message['plain'])): ?>
        <pre class="mail-body-plain"><?= e($message['plain']) ?></pre>
    <?php else: ?>
        <p class="text-muted">(No message body)</p>
    <?php endif; ?>
</div>
