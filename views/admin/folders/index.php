<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div><h2>Folders</h2></div>
    <a class="btn btn-primary" href="<?= e(url('admin/folders/create')) ?>">Add folder</a>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Display name</th><th>IMAP path</th><th>Type</th><th>Linked user</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($folders as $f): ?>
                <tr>
                    <td><?= e($f['display_name']) ?></td>
                    <td><code><?= e($f['imap_path']) ?></code></td>
                    <td><span class="badge badge-<?= e($f['folder_type']) ?>"><?= e($f['folder_type']) ?></span></td>
                    <td><?= e($f['linked_user_name'] ?? '—') ?></td>
                    <td><?= (int) $f['active'] ? 'Active' : 'Inactive' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
