<?php
$activeFolder = '__status__';
ob_start();
?>

<section class="page-header">
    <h2>Connection status</h2>
    <p class="text-muted">IMAP/SMTP diagnostics (admin)</p>
</section>

<section class="card">
    <?php if ($imapConnected): ?>
        <p class="status status-ok">IMAP connected successfully</p>
        <p class="text-muted"><?= (int) $folderCount ?> folders found on mail server.</p>
    <?php else: ?>
        <p class="status status-error">IMAP connection failed</p>
        <?php if (!empty($imapError)): ?>
            <p class="text-muted error-detail"><?= e($imapError) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
        <form method="post" action="<?= e(url('test-email')) ?>" style="margin-top: 1rem;">
            <button type="submit" class="btn btn-secondary">Send test email</button>
        </form>
    <?php endif; ?>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
