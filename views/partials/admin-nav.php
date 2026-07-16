<?php /** @var string $adminSection */ ?>
<nav class="admin-nav">
    <a class="admin-nav-link<?= ($adminSection ?? '') === 'dashboard' ? ' active' : '' ?>" href="<?= e(url('admin')) ?>">Dashboard</a>
    <a class="admin-nav-link<?= ($adminSection ?? '') === 'users' ? ' active' : '' ?>" href="<?= e(url('admin/users')) ?>">Users</a>
    <a class="admin-nav-link<?= ($adminSection ?? '') === 'aliases' ? ' active' : '' ?>" href="<?= e(url('admin/aliases')) ?>">Aliases</a>
    <a class="admin-nav-link<?= ($adminSection ?? '') === 'folders' ? ' active' : '' ?>" href="<?= e(url('admin/folders')) ?>">Folders</a>
    <a class="admin-nav-link<?= ($adminSection ?? '') === 'audit' ? ' active' : '' ?>" href="<?= e(url('admin/audit')) ?>">Audit log</a>
</nav>
