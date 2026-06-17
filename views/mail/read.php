<?php ob_start(); ?>

<section class="page-header">
    <p class="breadcrumb">
        <a href="<?= e(folder_url($folderPath)) ?>">← Back to folder</a>
    </p>
    <h2><?= e($message['subject'] ?: '(no subject)') ?></h2>
</section>

<section class="card mail-read-card print-area"
    data-message-sync="1"
    data-sync-url="<?= e(url('folder/' . ($folderB64 ?? encode_folder_path($folderPath)) . '/message/' . (int) $message['uid'] . '/sync')) ?>"
    data-folder-url="<?= e(folder_url($folderPath)) ?>"
    data-poll-interval="<?= (int) ($pollInterval ?? 30) ?>">

    <div class="mail-actions no-print">
        <a class="btn btn-primary" id="reply-btn" href="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Reply</a>
        <a class="btn btn-outline" id="reply-all-btn" href="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Reply all</a>
        <a class="btn btn-outline" href="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Forward</a>
        <?php if (folder_icon_type($folderPath) === 'draft'): ?>
            <a class="btn btn-outline" href="<?= e(url('compose/edit-draft?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Edit draft</a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>

        <form method="post" action="<?= e(url('message/mark-unread')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <input type="hidden" name="redirect" value="<?= e('folder/' . encode_folder_path($folderPath)) ?>">
            <button type="submit" class="btn btn-outline">Mark unread</button>
        </form>

        <form method="post" action="<?= e(url('message/flag')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <input type="hidden" name="redirect" value="<?= e('folder/' . encode_folder_path($folderPath) . '/message/' . (int) $message['uid']) ?>">
            <button type="submit" class="btn btn-outline">Mark important</button>
        </form>

        <form method="post" action="<?= e(url('message/unflag')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <input type="hidden" name="redirect" value="<?= e('folder/' . encode_folder_path($folderPath) . '/message/' . (int) $message['uid']) ?>">
            <button type="submit" class="btn btn-outline">Remove importance</button>
        </form>

        <form method="post" action="<?= e(url('message/spam')) ?>" class="inline-form"
              onsubmit="return confirm('Move this message to Spam?');">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <button type="submit" class="btn btn-outline">Spam</button>
        </form>

        <form method="post" action="<?= e(url('message/trash')) ?>" class="inline-form" id="delete-form"
              onsubmit="return confirm('Move this message to Trash?');">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>

        <?php if (!empty($moveTargets)): ?>
        <form method="post" action="<?= e(url('message/move')) ?>" class="inline-form move-form">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <select name="target_folder" required>
                <option value="">Move to…</option>
                <?php foreach ($moveTargets as $target): ?>
                    <option value="<?= e($target['path']) ?>"><?= e($target['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline">Move</button>
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
                $baseUrl = url('attachment?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'] . '&part=' . urlencode($att['id']));
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
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
