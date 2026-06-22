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

<div class="mail-actions no-print">
    <a class="btn btn-primary compose-panel-link" id="reply-btn" href="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply">Reply</a>
    <a class="btn btn-outline compose-panel-link" id="reply-all-btn" href="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply all">Reply all</a>
    <a class="btn btn-outline compose-panel-link" href="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Forward">Forward</a>
    <?php if (folder_icon_type($folderPath) === 'draft'): ?>
        <a class="btn btn-outline compose-panel-link" href="<?= e(url('compose/edit-draft?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Edit draft">Edit draft</a>
    <?php endif; ?>
    <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>

    <?php if (!empty($message['seen'])): ?>
        <button type="button" class="btn btn-outline" data-mail-action="mark-unread">Mark unread</button>
    <?php else: ?>
        <button type="button" class="btn btn-outline" data-mail-action="mark-read">Mark read</button>
    <?php endif; ?>
    <button type="button" class="btn btn-outline" data-mail-action="flag">Mark important</button>
    <button type="button" class="btn btn-outline" data-mail-action="unflag">Remove importance</button>
    <button type="button" class="btn btn-outline" data-mail-action="spam">Spam</button>
    <button type="button" class="btn btn-danger"<?= $isPane ? '' : ' id="delete-form"' ?> data-mail-action="trash">Delete</button>

    <?php if (!empty($moveTargets)): ?>
    <form class="inline-form move-form" onsubmit="return false;">
        <select name="target_folder" required>
            <option value="">Move to…</option>
            <?php foreach ($moveTargets as $target): ?>
                <option value="<?= e($target['path']) ?>"><?= e($target['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-outline" data-mail-action="move">Move</button>
    </form>
    <?php endif; ?>
</div>

<dl class="detail-list mail-headers">
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
