<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div>
        <h2>Users</h2>
    </div>
    <div class="page-header-actions">
        <form method="post" action="<?= e(url('admin/users/backfill')) ?>" class="admin-action-form"
              onsubmit="return confirm('Provision missing folders, aliases and routing rules for all employees, then re-route existing inbox mail?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">Backfill onboarding</button>
        </form>
        <a class="btn btn-primary" href="<?= e(url('admin/users/create')) ?>">Add user</a>
    </div>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['username']) ?></td>
                    <td><span class="badge badge-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
                    <td><span class="badge badge-<?= (int) $u['active'] ? 'active' : 'inactive' ?>"><?= (int) $u['active'] ? 'Active' : 'Disabled' ?></span></td>
                    <td class="admin-actions">
                        <a class="admin-action-link" href="<?= e(url('admin/users/' . $u['id'] . '/edit')) ?>">Edit</a>
                        <?php if ((int) $u['active'] && $u['role'] !== 'admin'): ?>
                            <form method="post" action="<?= e(url('admin/users/' . $u['id'] . '/disable')) ?>" class="admin-action-form" onsubmit="return confirm('Disable this user?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="admin-action-link admin-action-link-danger">Disable</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
