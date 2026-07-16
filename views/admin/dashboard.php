<?php ob_start(); ?>

<section class="page-header">
    <h2>Admin dashboard</h2>
    <p class="text-muted">Manage users, folders, aliases, and filter rules</p>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<div class="admin-stats">
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $userCount ?></span>
        <span class="admin-stat-label">Active users</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $ruleCount ?></span>
        <span class="admin-stat-label">Active rules</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $folderCount ?></span>
        <span class="admin-stat-label">Folders</span>
    </div>
</div>

<section class="card">
    <h3>Mail sync</h3>
    <p class="text-muted">Check the Inbox now and sort new mail into folders by your rules.</p>
    <?php if (!empty($filterStats)): ?>
        <p class="text-muted">Last run: <?= (int) $filterStats['processed'] ?> processed, <?= (int) $filterStats['moved'] ?> moved (<?= (int) $filterStats['duration_ms'] ?>ms)</p>
    <?php endif; ?>
    <div class="admin-action-row">
        <form method="post" action="<?= e(url('admin/sync')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Sync now</button>
        </form>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
