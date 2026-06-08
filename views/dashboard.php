<?php ob_start(); ?>

<section class="page-header">
    <h2>Dashboard</h2>
    <p class="text-muted">Mail connection status and folder overview</p>
</section>

<section class="card">
    <h3>Connection status</h3>
    <?php if ($imapConnected): ?>
        <p class="status status-ok">IMAP connected successfully</p>
    <?php else: ?>
        <p class="status status-error">IMAP connection failed</p>
        <?php if (!empty($imapError)): ?>
            <p class="text-muted error-detail"><?= e($imapError) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($imapConnected && !empty($sampleHeaders)): ?>
<section class="card">
    <h3>Latest INBOX message (header test)</h3>
    <dl class="detail-list">
        <dt>From</dt><dd><?= e($sampleHeaders['from'] ?? '—') ?></dd>
        <dt>To</dt><dd><?= e($sampleHeaders['to'] ?? '—') ?></dd>
        <dt>Delivered-To</dt><dd><?= e($sampleHeaders['delivered_to'] ?? '—') ?></dd>
        <dt>Subject</dt><dd><?= e($sampleHeaders['subject'] ?? '—') ?></dd>
        <dt>Date</dt><dd><?= e($sampleHeaders['date'] ?? '—') ?></dd>
    </dl>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header-row">
        <h3>Mail folders (<?= count($folders) ?>)</h3>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <form method="post" action="<?= e(url('test-email')) ?>">
                <button type="submit" class="btn btn-secondary">Send test email</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($folders)): ?>
        <p class="text-muted">No folders found or IMAP is not connected.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Folder name</th>
                        <th>IMAP path</th>
                        <th>Messages</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($folders as $folder): ?>
                        <tr>
                            <td><?= e($folder['name']) ?></td>
                            <td><code><?= e($folder['path']) ?></code></td>
                            <td><?= (int) ($folder['count'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card card-muted">
    <h3>Coming in Milestone 2</h3>
    <p class="text-muted">Read mail, compose, reply, send-as alias, manual move, and trash.</p>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
