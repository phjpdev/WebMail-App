<?php ob_start(); ?>

<section class="page-header">
    <p class="breadcrumb">
        <a href="<?= e(folder_url($folderPath)) ?>">← Back to folder</a>
    </p>
    <h2><?= e($message['subject'] ?: '(no subject)') ?></h2>
</section>

<section class="card">
    <div class="mail-actions">
        <a class="btn btn-primary" href="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Reply</a>
        <a class="btn btn-secondary" href="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'])) ?>">Forward</a>

        <form method="post" action="<?= e(url('message/trash')) ?>" class="inline-form"
              onsubmit="return confirm('Move this message to Trash?');">
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>

        <?php if (!empty($moveTargets)): ?>
        <form method="post" action="<?= e(url('message/move')) ?>" class="inline-form move-form">
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $message['uid'] ?>">
            <select name="target_folder" required>
                <option value="">Move to…</option>
                <?php foreach ($moveTargets as $target): ?>
                    <option value="<?= e($target['path']) ?>"><?= e($target['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Move</button>
        </form>
        <?php endif; ?>
    </div>

    <dl class="detail-list mail-headers">
        <dt>From</dt><dd><?= e($message['from'] ?? '—') ?></dd>
        <dt>To</dt><dd><?= e($message['to'] ?? '—') ?></dd>
        <dt>Delivered-To</dt><dd><?= e($message['delivered_to'] ?? '—') ?></dd>
        <dt>Date</dt><dd><?= e($message['date'] ?? '—') ?></dd>
        <dt>Reply as</dt><dd><code><?= e($replyFrom) ?></code></dd>
    </dl>

    <?php if (!empty($message['attachments'])): ?>
    <div class="attachments">
        <strong>Attachments:</strong>
        <ul>
            <?php foreach ($message['attachments'] as $att): ?>
                <li>
                    <a href="<?= e(url('attachment?folder=' . encode_folder_path($folderPath) . '&uid=' . (int) $message['uid'] . '&part=' . urlencode($att['id']))) ?>">
                        <?= e($att['filename']) ?> (<?= number_format($att['size'] / 1024, 1) ?> KB)
                    </a>
                </li>
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
