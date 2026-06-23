<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div><h2>Aliases</h2></div>
    <a class="btn btn-primary" href="<?= e(url('admin/aliases/create')) ?>">Add alias</a>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Email</th><th>Display name</th><th>User</th><th>Folder</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($aliases as $a): ?>
                <tr>
                    <td><?= e($a['email']) ?></td>
                    <td><?= e($a['display_name']) ?></td>
                    <td><?= e($a['user_name'] ?? '—') ?></td>
                    <td><?= e($a['folder_name'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= (int) $a['active'] ? 'active' : 'inactive' ?>"><?= (int) $a['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="table-actions">
                        <a href="<?= e(url('admin/aliases/' . $a['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('admin/aliases/' . $a['id'] . '/delete')) ?>" class="admin-action-form"
                              data-confirm-title="Delete alias?"
                              data-confirm-message="Remove this send-as alias? This cannot be undone."
                              data-confirm-danger="1"
                              data-confirm-label="Delete">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-link-danger">Delete</button>
                        </form>
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
