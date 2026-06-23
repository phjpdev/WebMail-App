<?php
$activeFolder = '__status__';
ob_start();
?>

<section class="page-header">
    <h2>Connection status</h2>
    <p class="text-muted">IMAP/SMTP diagnostics (admin)</p>
</section>

<section class="card" id="status-card" data-auto-check="1">
    <div id="status-result">
        <?php if ($imapConnected): ?>
            <p class="status status-ok" id="status-imap-line">IMAP connected successfully</p>
            <p class="text-muted" id="status-folder-line"><?= (int) $folderCount ?> folders found on mail server.</p>
        <?php else: ?>
            <p class="status status-error" id="status-imap-line">IMAP connection failed</p>
            <p class="text-muted error-detail" id="status-error-line"<?= empty($imapError) ? ' hidden' : '' ?>><?= e($imapError ?? '') ?></p>
            <p class="text-muted" id="status-folder-line" hidden></p>
        <?php endif; ?>
    </div>

    <p class="text-muted status-meta" id="status-checked-line">
        <?php if (!empty($lastCheckedAt)): ?>
            Last live check: <?= e(format_app_datetime((string) $lastCheckedAt, 'g:i:s A')) ?>
            <?php if (!empty($lastCheckMs)): ?>(<?= (int) $lastCheckMs ?> ms)<?php endif; ?>
        <?php else: ?>
            Running live connection test…
        <?php endif; ?>
    </p>

    <div class="status-actions">
        <button type="button" class="btn btn-secondary" id="status-refresh-btn">Test connection now</button>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <form method="post" action="<?= e(url('test-email')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline">Send test email</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
