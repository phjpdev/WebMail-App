<?php ob_start(); ?>

<section class="page-header">
    <h2><?= e($title ?? 'Mail') ?></h2>
    <p class="text-muted"><?= (int) $totalMessages ?> message<?= $totalMessages === 1 ? '' : 's' ?></p>
</section>

<?php if (!$imapConnected): ?>
<section class="card">
    <p class="status status-error">IMAP connection failed</p>
    <?php if (!empty($imapError)): ?>
        <p class="text-muted error-detail"><?= e($imapError) ?></p>
    <?php endif; ?>
</section>
<?php elseif (empty($messages)): ?>
<section class="card">
    <p class="text-muted">No messages in this folder.</p>
</section>
<?php else: ?>
<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table mail-table">
            <thead>
                <tr>
                    <th class="col-status"></th>
                    <th>From</th>
                    <th>Subject</th>
                    <th class="col-date">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $msg): ?>
                    <tr class="mail-row<?= !$msg['seen'] ? ' mail-unread' : '' ?>"
                        onclick="window.location='<?= e(message_url($folderPath, (int) $msg['uid'])) ?>'">
                        <td class="col-status"><?= !$msg['seen'] ? '●' : '' ?></td>
                        <td class="col-from"><?= e($msg['from']) ?></td>
                        <td class="col-subject"><?= e($msg['subject']) ?></td>
                        <td class="col-date"><?= e($msg['date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a class="btn btn-secondary" href="<?= e(folder_url($folderPath) . '?page=' . ($page - 1)) ?>">← Previous</a>
        <?php endif; ?>
        <span class="pagination-info">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-secondary" href="<?= e(folder_url($folderPath) . '?page=' . ($page + 1)) ?>">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
