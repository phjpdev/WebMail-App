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
                <tr><th>Display name</th><th>IMAP path</th><th>Type</th><th>Linked user</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($folders as $f): ?>
                <?php $canDelete = in_array($f['folder_type'] ?? '', ['employee', 'client'], true); ?>
                <tr>
                    <td><?= e($f['display_name']) ?></td>
                    <td><code><?= e($f['imap_path']) ?></code></td>
                    <td><span class="badge badge-<?= e($f['folder_type']) ?>"><?= e($f['folder_type']) ?></span></td>
                    <td><?= e($f['linked_user_name'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= (int) $f['active'] ? 'active' : 'inactive' ?>"><?= (int) $f['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="table-actions">
                        <a href="<?= e(url('admin/folders/' . $f['id'] . '/edit')) ?>">Edit</a>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= e(url('admin/folders/' . $f['id'] . '/delete')) ?>"
                                  onsubmit="return confirm(<?= json_encode(
                                      'Delete folder "' . ($f['display_name'] ?? '') . '"? Messages in this folder will be removed from the mail server. This cannot be undone.',
                                      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                  ) ?>);">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-link-danger">Delete</button>
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
