<?php
/**
 * @param array{folder: array<string, mixed>, children: list<array{folder: array<string, mixed>, children: list}>} $node
 */
function render_admin_folder_tree_node(array $node, int $depth = 0): void
{
    $folder = $node['folder'];
    $children = $node['children'] ?? [];
    $hasChildren = $children !== [];
    $folderId = (int) ($folder['id'] ?? 0);
    $displayName = (string) ($folder['display_name'] ?? '');
    $imapPath = (string) ($folder['imap_path'] ?? '');
    $folderType = (string) ($folder['folder_type'] ?? '');
    $linkedUser = (string) ($folder['linked_user_name'] ?? '—');
    $searchText = strtolower($displayName . ' ' . $imapPath . ' ' . $folderType . ' ' . $linkedUser);
    $branchId = 'admin-folder-branch-' . $folderId;
    $subtreeId = $branchId . '-subtree';

    if ($hasChildren): ?>
<div class="admin-folder-branch has-children"
     role="treeitem"
     aria-expanded="false"
     data-folder-search="<?= e($searchText) ?>"
     data-branch-id="<?= e($branchId) ?>">
    <?php require base_path('views/admin/folders/_row.php'); ?>
    <div class="admin-folder-branch-children" id="<?= e($subtreeId) ?>" role="group" hidden>
        <?php foreach ($children as $childNode):
            render_admin_folder_tree_node($childNode, $depth + 1);
        endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="admin-folder-branch admin-folder-branch--leaf"
     role="treeitem"
     data-folder-search="<?= e($searchText) ?>"
     data-branch-id="<?= e($branchId) ?>">
    <?php require base_path('views/admin/folders/_row.php'); ?>
</div>
<?php endif;
}
