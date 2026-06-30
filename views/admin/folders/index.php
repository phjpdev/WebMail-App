<?php ob_start(); ?>
<?php
$folderTree = build_folder_path_tree($folders, 'imap_path');
?>

<section class="page-header page-header-row">
    <div><h2>Folders</h2></div>
    <div class="page-header-actions">
        <form method="post" action="<?= e(url('admin/folders/purge-orphans')) ?>" class="inline-form"
              data-confirm-title="Remove orphaned mailboxes?"
              data-confirm-message="Delete employee folders that no longer have an active user from the mail server and folder registry. This cannot be undone."
              data-confirm-danger="1"
              data-confirm-label="Remove orphaned">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Remove orphaned mailboxes</button>
        </form>
        <a class="btn btn-primary" href="<?= e(url('admin/folders/create')) ?>">Add folder</a>
    </div>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="admin-folder-tree-toolbar">
        <label class="sr-only" for="admin-folder-search">Search folders</label>
        <input type="search" id="admin-folder-search" class="admin-folder-search"
               placeholder="Search folders…" autocomplete="off">
        <div class="admin-folder-tree-actions">
            <button type="button" class="btn btn-secondary btn-sm" id="admin-folder-expand-all">Expand all</button>
            <button type="button" class="btn btn-secondary btn-sm" id="admin-folder-collapse-all">Collapse all</button>
        </div>
    </div>

    <div class="admin-folder-tree-head" aria-hidden="true">
        <span class="admin-folder-tree-col-name">Folder</span>
        <span class="admin-folder-tree-col-type">Type</span>
        <span class="admin-folder-tree-col-user">Linked user</span>
        <span class="admin-folder-tree-col-status">Status</span>
        <span class="admin-folder-tree-col-actions"></span>
    </div>

    <div class="admin-folder-tree" id="admin-folder-tree" role="tree">
        <?php if ($folderTree === []): ?>
            <p class="admin-folder-tree-empty">No folders yet. <a href="<?= e(url('admin/folders/create')) ?>">Add a folder</a>.</p>
        <?php else: ?>
            <?php foreach ($folderTree as $node): ?>
                <?php require base_path('views/admin/folders/_tree-node.php'); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
