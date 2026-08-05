<?php ob_start(); ?>

<?php

require_once base_path('views/admin/folders/_tree-node.php');

$adminFoldersView = partition_admin_folders_for_display($folders);

$primaryFolders = $adminFoldersView['primary'];

$folderTree = $adminFoldersView['custom_tree'];

$hasFolders = $primaryFolders !== [] || $folderTree !== [];

?>



<section class="page-header page-header-row">

    <div><h2>Folders</h2></div>

    <div class="page-header-actions">

        <form method="post" action="<?= e(url('admin/folders/import')) ?>" class="admin-action-form"
              data-confirm-title="Import folders from the mail server?"
              data-confirm-message="Scans the mail server and adds every folder that is not registered here yet. Existing folders are not changed, so it is safe to run again."
              data-confirm-label="Import"
              data-confirm-loading="Importing folders…">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Import from mail server</button>
        </form>

        <a class="btn btn-primary" href="<?= e(url('admin/folders/create')) ?>">Add folder</a>

    </div>

</section>



<div class="admin-folders-page">

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

        <span class="admin-folder-tree-col-user">Linked user</span>

        <span class="admin-folder-tree-col-status">Status</span>

        <span class="admin-folder-tree-col-action">Edit</span>

        <span class="admin-folder-tree-col-action">Delete</span>

        <span class="admin-folder-tree-col-action">Subfolder</span>

    </div>



    <div class="admin-folder-tree" id="admin-folder-tree" role="tree">

        <?php if (!$hasFolders): ?>

            <p class="admin-folder-tree-empty">No folders yet. <a href="<?= e(url('admin/folders/create')) ?>">Add a folder</a>.</p>

        <?php else: ?>

            <?php foreach ($primaryFolders as $folder): ?>

                <?php

                $depth = 0;

                $hasChildren = false;

                $searchText = strtolower(

                    (string) ($folder['display_name'] ?? '') . ' '

                    . (string) ($folder['imap_path'] ?? '') . ' '

                    . (string) ($folder['folder_type'] ?? '') . ' '

                    . (string) ($folder['linked_user_name'] ?? '')

                );

                ?>

                <div class="admin-folder-branch admin-folder-branch-primary"

                     role="treeitem"

                     data-folder-search="<?= e($searchText) ?>">

                    <?php require base_path('views/admin/folders/_row.php'); ?>

                </div>

            <?php endforeach; ?>



            <?php if ($primaryFolders !== [] && $folderTree !== []): ?>

                <div class="admin-folder-tree-divider" aria-hidden="true"></div>

            <?php endif; ?>



            <?php foreach ($folderTree as $node): ?>

                <?php render_admin_folder_tree_node($node, 0); ?>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</section>

</div>

<?php

$content = ob_get_clean();

require base_path('views/layout.php');

