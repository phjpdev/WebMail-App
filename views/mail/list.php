<?php ob_start(); ?>

<section class="page-header">
    <div class="page-header-row">
        <div>
            <h2><?= e($title ?? 'Mail') ?></h2>
            <p class="text-muted" id="mail-count-label"><?= (int) $totalMessages ?> message<?= $totalMessages === 1 ? '' : 's' ?></p>
            <p class="mail-sync-status" id="mail-sync-status" hidden aria-live="polite"></p>
        </div>
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
<section class="card card-flush mail-list-card"
    data-mail-sync="1"
    data-folder-b64="<?= e($folderB64 ?? encode_folder_path($folderPath)) ?>"
    data-page="<?= (int) $page ?>"
    data-poll-url="<?= e(url('folder/' . ($folderB64 ?? encode_folder_path($folderPath)) . '/sync')) ?>"
    data-poll-interval="<?= (int) ($pollInterval ?? 30) ?>">

    <?php if (empty($messages)): ?>
    <div id="mail-list-empty" class="empty-state">
        <div class="empty-icon" aria-hidden="true">📭</div>
        <p>No messages in this folder</p>
    </div>
    <?php endif; ?>

    <div class="mail-list-desktop table-wrap"<?= empty($messages) ? ' hidden' : '' ?>>
        <table class="data-table mail-table">
            <thead>
                <tr>
                    <th class="col-status"></th>
                    <th>From</th>
                    <th>Subject</th>
                    <th class="col-date">Date</th>
                </tr>
            </thead>
            <tbody id="mail-list-body">
                <?php foreach ($messages as $msg): ?>
                    <tr class="mail-row<?= !$msg['seen'] ? ' mail-unread' : '' ?>"
                        data-uid="<?= (int) $msg['uid'] ?>"
                        data-href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>">
                        <td class="col-status"><?= !$msg['seen'] ? '<span class="unread-dot"></span>' : '' ?></td>
                        <td class="col-from"><?= e(format_mail_from($msg['from'])) ?></td>
                        <td class="col-subject"><?= e($msg['subject']) ?></td>
                        <td class="col-date"><?= e(format_mail_date($msg['date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mail-list-mobile" id="mail-list-mobile"<?= empty($messages) ? ' hidden' : '' ?>>
        <?php foreach ($messages as $msg): ?>
            <a class="mail-card<?= !$msg['seen'] ? ' mail-unread' : '' ?>"
               data-uid="<?= (int) $msg['uid'] ?>"
               href="<?= e(message_url($folderPath, (int) $msg['uid'])) ?>">
                <div class="mail-card-top">
                    <span class="mail-card-from"><?= e(format_mail_from($msg['from'])) ?></span>
                    <span class="mail-card-date"><?= e(format_mail_date($msg['date'])) ?></span>
                </div>
                <div class="mail-card-subject"><?= e($msg['subject']) ?></div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(folder_url($folderPath) . '?page=' . ($page - 1)) ?>">← Prev</a>
        <?php endif; ?>
        <span class="pagination-info"><?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(folder_url($folderPath) . '?page=' . ($page + 1)) ?>">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
