<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div>
        <h2>Users</h2>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-primary" href="<?= e(url('admin/users/create')) ?>">Add user</a>
    </div>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Email</th><th>Folder</th><th>Role</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php
                $actingId = (int) (\App\Auth::user()['id'] ?? 0);
                $activeAdmins = 0;
                foreach ($users as $uu) {
                    if (($uu['role'] ?? '') === 'admin' && (int) ($uu['active'] ?? 0)) {
                        $activeAdmins++;
                    }
                }
                ?>
                <?php foreach ($users as $u): ?>
                <?php
                $isSelf = (int) $u['id'] === $actingId;
                $isLastActiveAdmin = ($u['role'] === 'admin') && (int) ($u['active'] ?? 0) && $activeAdmins <= 1;
                $canRemove = !$isSelf && !$isLastActiveAdmin;
                ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['alias_email'] ?? '') ?: '—' ?></td>
                    <td><?= e($u['folder_name'] ?? '') ?: '—' ?></td>
                    <td><span class="badge badge-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
                    <td><span class="badge badge-<?= (int) $u['active'] ? 'active' : 'inactive' ?>"><?= (int) $u['active'] ? 'Active' : 'Disabled' ?></span></td>
                    <td class="admin-actions">
                        <a class="admin-action-link" href="<?= e(url('admin/users/' . $u['id'] . '/edit')) ?>">Edit</a>
                        <?php if ((int) $u['active'] && $canRemove): ?>
                            <form method="post" action="<?= e(url('admin/users/' . $u['id'] . '/disable')) ?>" class="admin-action-form"
                                  data-confirm-title="Disable user?"
                                  data-confirm-message="This user will no longer be able to sign in."
                                  data-confirm-danger="1"
                                  data-confirm-label="Disable">
                                <?= csrf_field() ?>
                                <button type="submit" class="admin-action-link admin-action-link-danger">Disable</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canRemove): ?>
                            <form method="post" action="<?= e(url('admin/users/' . $u['id'] . '/delete')) ?>" class="admin-action-form"
                                  data-confirm-title="Delete user?"
                                  data-confirm-message="Permanently delete this user, their personal IMAP folder, alias, and routing rules. This cannot be undone."
                                  data-confirm-danger="1"
                                  data-confirm-label="Delete"
                                  data-confirm-loading="Deleting user…">
                                <?= csrf_field() ?>
                                <button type="submit" class="admin-action-link admin-action-link-danger">Delete</button>
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
