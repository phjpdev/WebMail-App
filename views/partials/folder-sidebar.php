<?php
/**
 * @var list<array{path: string, name: string}> $sidebarFolders
 * @var list<array{path: string, name: string}> $folders
 * @var string|null $activeFolder
 * @var array<string, int> $unreadCounts
 */

perf_mark('sidebar_render_start');

$unreadCounts = $unreadCounts ?? [];
$primaryOrder = sidebar_primary_folder_order();
$grouped = array_fill_keys(array_merge($primaryOrder, ['other']), []);

foreach (($sidebarFolders ?? $folders ?? []) as $folder) {
    $grouped[sidebar_folder_bucket($folder['path'])][] = $folder;
}

foreach ($primaryOrder as $bucket) {
    if ($bucket === 'inbox' || $grouped[$bucket] === []) {
        continue;
    }
    $grouped[$bucket] = sidebar_dedupe_primary_bucket($grouped[$bucket], $bucket);
}

$composeHref = url('compose');
$composeActive = $activeFolder ?? '';
if ($composeActive !== '' && !str_starts_with($composeActive, '__')) {
    $composeHref = url('compose') . '?return_folder=' . rawurlencode(encode_folder_path($composeActive));
}

$employeeRoots = sidebar_employee_root_path_set();

// O(1) active-folder matching: the active folder's identity keys, computed
// once. At ~1000 folders the per-node sidebar_folder_matches_active chain
// (several resolvePath calls each) was a large share of the sidebar render.
$activeMatchKeys = sidebar_active_match_keys($activeFolder ?? '');

$renderTreeLink = static function (
    array $folder,
    string $bucket,
    ?string $leafLabel = null
) use ($activeMatchKeys, $unreadCounts, $employeeRoots): void {
    $displayName = $leafLabel ?? sidebar_folder_label($folder, $bucket);
    $navPath = sidebar_folder_nav_path($folder['path']);
    $isActive = isset($activeMatchKeys[strtolower($navPath)]);
    $icon = folder_icon_type($folder['path']);
    $isEmployee = isset($employeeRoots[strtolower((string) ($folder['path'] ?? ''))]);
    $unread = folder_shows_unread_badge($navPath)
        ? (int) ($unreadCounts[$navPath] ?? $unreadCounts[$folder['path']] ?? 0)
        : 0;
    ?>
    <a class="sidebar-link sidebar-tree-link<?= $isActive ? ' active' : '' ?><?= $isEmployee ? ' sidebar-link--employee' : '' ?>"
       href="<?= e(folder_url($navPath)) ?>"
       data-folder-path="<?= e($navPath) ?>"
       data-folder-b64="<?= e(encode_folder_path($navPath)) ?>"
       data-ajax-folder="1">
        <span class="folder-icon folder-icon-<?= e($icon) ?>" aria-hidden="true"></span>
        <span class="sidebar-link-text"><?= e($displayName) ?></span>
        <?php if ($unread > 0): ?>
            <span class="folder-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
        <?php endif; ?>
    </a>
    <?php
};

$renderTreeRow = static function (
    array $folder,
    string $bucket,
    int $depth,
    ?string $leafLabel,
    bool $hasChildren,
    bool $branchOpen,
    ?string $branchKey
) use ($renderTreeLink): void {
    if ($hasChildren) {
        ?>
        <div class="sidebar-folder-branch<?= $branchOpen ? ' is-open' : '' ?>"
             data-sidebar-branch="<?= e((string) $branchKey) ?>">
            <div class="sidebar-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
                <button type="button"
                        class="sidebar-tree-toggle"
                        aria-expanded="<?= $branchOpen ? 'true' : 'false' ?>"
                        aria-label="Expand or collapse folder">
                    <svg class="sidebar-tree-chevron" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
                <?php $renderTreeLink($folder, $bucket, $leafLabel); ?>
            </div>
            <div class="sidebar-folder-branch-children"<?= $branchOpen ? '' : ' hidden' ?>>
        <?php
        return;
    }
    ?>
    <div class="sidebar-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
        <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
        <?php $renderTreeLink($folder, $bucket, $leafLabel); ?>
    </div>
    <?php
};

// One post-order pass marking every node whose CHILD subtree contains the
// active folder ('_childActive') — replaces the per-branch recursive subtree
// scan (sidebar_folder_branch_should_open), which was O(N²) at ~1000 folders.
// Works for both the IMAP-prefix tree and the display forest (whose children
// are NOT path-prefixed, so a prefix test would be wrong).
$annotateActiveBranches = static function (array $node) use (&$annotateActiveBranches, $activeMatchKeys): array {
    $childActive = false;
    $children = [];
    foreach ($node['children'] ?? [] as $child) {
        $child = $annotateActiveBranches($child);
        $nav = sidebar_folder_nav_path((string) ($child['folder']['path'] ?? ''));
        if (($nav !== '' && isset($activeMatchKeys[strtolower($nav)])) || $child['_childActive']) {
            $childActive = true;
        }
        $children[] = $child;
    }
    $node['children'] = $children;
    $node['_childActive'] = $childActive;

    return $node;
};

$renderSidebarFolderBranch = static function (array $node, int $depth = 0) use (
    &$renderSidebarFolderBranch,
    $renderTreeRow
): void {
    $folder = $node['folder'];
    $children = $node['children'] ?? [];
    $hasChildren = $children !== [];
    $navPath = sidebar_folder_nav_path($folder['path']);
    $displayName = sidebar_folder_tree_label($folder);
    $branchKey = strtolower($navPath);
    $branchOpen = $hasChildren && !empty($node['_childActive']);

    if ($hasChildren) {
        $renderTreeRow($folder, 'other', $depth, $displayName, true, $branchOpen, $branchKey);
        foreach ($children as $childNode) {
            $renderSidebarFolderBranch($childNode, $depth + 1);
        }
        echo '</div></div>';
        return;
    }

    $renderTreeRow($folder, 'other', $depth, $displayName, false, false, null);
};

// Manual, display-only sidebar grouping (arbitrary depth). A folder's
// display_parent_id nests it under another folder in the sidebar; chains like
// Employees → New-Employees → Jean are supported. The IMAP mailboxes, their
// paths, addresses and routing are NOT changed — this only affects how the
// "other" folders are laid out here. Keys are normalized with
// employee_mailbox_root_prefix() so an employee mailbox matches whether the
// sidebar lists it as INBOX.Jean or INBOX.Jean.Inbox.
$displayParentKey = []; // childKey => parentKey
try {
    foreach (App\Database::query(
        "SELECT c.imap_path AS child_path, p.imap_path AS parent_path
         FROM folders c
         JOIN folders p ON p.id = c.display_parent_id
         WHERE c.active = 1 AND p.active = 1 AND c.display_parent_id IS NOT NULL"
    )->fetchAll() as $row) {
        $childKey = strtolower(employee_mailbox_root_prefix((string) ($row['child_path'] ?? '')));
        $parentKey = strtolower(employee_mailbox_root_prefix((string) ($row['parent_path'] ?? '')));
        if ($childKey !== '' && $parentKey !== '' && $childKey !== $parentKey) {
            $displayParentKey[$childKey] = $parentKey;
        }
    }
} catch (\Throwable) {
}

$displayForest = []; // list of ['folder' => array, 'children' => list<self>]
if ($displayParentKey !== [] && $grouped['other'] !== []) {
    $folderByKey = [];
    foreach ($grouped['other'] as $folder) {
        $key = strtolower(employee_mailbox_root_prefix((string) ($folder['path'] ?? '')));
        if ($key !== '' && !isset($folderByKey[$key])) {
            $folderByKey[$key] = $folder;
        }
    }

    // Effective parent of a grouped folder = its display_parent (if present); a
    // grouped folder with no display_parent falls back to its nearest present
    // IMAP ancestor, so a group's real subfolder (e.g. INBOX.Employee.New-Employees
    // under Employees) nests under the group just like it does in the admin table.
    $effectiveParent = [];
    $involved = [];
    foreach ($displayParentKey as $childKey => $parentKey) {
        if (isset($folderByKey[$childKey], $folderByKey[$parentKey])) {
            $effectiveParent[$childKey] = $parentKey;
            $involved[$childKey] = true;
            $involved[$parentKey] = true;
        }
    }

    if ($involved !== []) {
        // Memoized: the convergence loop below re-asks for the same paths every
        // pass, and each uncached lookup walks every ancestor segment — at
        // ~1000 folders that multiplied into seconds of render time.
        $imapAncestorMemo = [];
        $findImapAncestorKey = static function (string $path) use ($folderByKey, &$imapAncestorMemo): ?string {
            if (array_key_exists($path, $imapAncestorMemo)) {
                return $imapAncestorMemo[$path];
            }
            $walk = \App\Services\FolderCache::resolvePath($path);
            $found = null;
            while (($pos = strrpos($walk, '.')) !== false) {
                $walk = substr($walk, 0, $pos);
                $key = strtolower(employee_mailbox_root_prefix($walk));
                if ($key !== '' && isset($folderByKey[$key])) {
                    $found = $key;
                    break;
                }
            }

            return $imapAncestorMemo[$path] = $found;
        };

        $queue = array_keys($involved);
        while ($queue !== []) {
            $key = array_pop($queue);
            if (isset($effectiveParent[$key])) {
                continue;
            }
            $ancestorKey = $findImapAncestorKey((string) ($folderByKey[$key]['path'] ?? ''));
            if ($ancestorKey !== null && $ancestorKey !== $key) {
                $effectiveParent[$key] = $ancestorKey;
                if (!isset($involved[$ancestorKey])) {
                    $involved[$ancestorKey] = true;
                    $queue[] = $ancestorKey;
                }
            }
        }

        // Also nest a group's real IMAP subfolders (e.g. INBOX.Employee.New-Employees
        // under Employees) even when they carry no display_parent of their own — so
        // the sidebar tree matches the admin table, which nests purely by IMAP path.
        // A folder whose nearest present IMAP ancestor is already part of a group
        // joins that group; that can in turn pull in the folder's own children.
        $pullChanged = true;
        while ($pullChanged) {
            $pullChanged = false;
            foreach ($folderByKey as $key => $folder) {
                if (isset($effectiveParent[$key])) {
                    continue;
                }
                $ancestorKey = $findImapAncestorKey((string) ($folder['path'] ?? ''));
                if ($ancestorKey !== null && $ancestorKey !== $key && isset($involved[$ancestorKey])) {
                    $effectiveParent[$key] = $ancestorKey;
                    $involved[$key] = true;
                    $pullChanged = true;
                }
            }
        }

        $childrenByParent = [];
        foreach ($effectiveParent as $childKey => $parentKey) {
            $childrenByParent[$parentKey][] = $childKey;
        }

        $placedKeys = [];
        $buildDisplayNode = function (string $key, array $seen = []) use (&$buildDisplayNode, $folderByKey, $childrenByParent, &$placedKeys): array {
            $seen[$key] = true;
            $placedKeys[$key] = true;
            $children = [];
            foreach ($childrenByParent[$key] ?? [] as $childKey) {
                if (isset($seen[$childKey])) {
                    continue;
                }
                $children[] = $buildDisplayNode($childKey, $seen);
            }

            return ['folder' => $folderByKey[$key], 'children' => $children];
        };

        // Roots = involved folders with no effective parent of their own.
        foreach ($folderByKey as $key => $folder) {
            if (isset($involved[$key]) && !isset($effectiveParent[$key])) {
                $displayForest[] = $buildDisplayNode($key);
            }
        }

        // Pull only the folders actually placed in the forest out of the flat list.
        $remainingOther = [];
        foreach ($grouped['other'] as $folder) {
            $key = strtolower(employee_mailbox_root_prefix((string) ($folder['path'] ?? '')));
            if ($key !== '' && isset($placedKeys[$key])) {
                continue;
            }
            $remainingOther[] = $folder;
        }
        $grouped['other'] = $remainingOther;
    }
}

// Recursive helpers for rendering the display forest.
$displayGroupUnread = function (array $node) use (&$displayGroupUnread, $unreadCounts): int {
    $total = 0;
    foreach ($node['children'] as $child) {
        if ($child['children'] === []) {
            $nav = sidebar_folder_nav_path($child['folder']['path']);
            if (folder_shows_unread_badge($nav)) {
                $total += (int) ($unreadCounts[$nav] ?? $unreadCounts[$child['folder']['path']] ?? 0);
            }
        } else {
            $total += $displayGroupUnread($child);
        }
    }

    return $total;
};

$displayGroupHasActive = function (array $node) use (&$displayGroupHasActive, $activeFolder): bool {
    foreach ($node['children'] as $child) {
        $nav = sidebar_folder_nav_path($child['folder']['path']);
        if (sidebar_folder_matches_active($activeFolder ?? '', $nav)) {
            return true;
        }
        if ($child['children'] !== [] && $displayGroupHasActive($child)) {
            return true;
        }
    }

    return false;
};

$renderDisplayGroup = function (array $node, int $depth) use (
    &$renderDisplayGroup,
    $renderTreeRow,
    $displayGroupUnread,
    $displayGroupHasActive,
    $activeFolder
): void {
    $folder = $node['folder'];
    $nav = sidebar_folder_nav_path($folder['path']);
    $label = sidebar_folder_tree_label($folder);
    $unread = $displayGroupUnread($node);
    $open = sidebar_folder_matches_active($activeFolder ?? '', $nav) || $displayGroupHasActive($node);
    ?>
    <div class="sidebar-group is-collapsible sidebar-group--folder<?= $open ? ' is-open' : '' ?>" data-group="folder:<?= e(strtolower($nav)) ?>">
        <div class="sidebar-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
            <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
            <button type="button" class="sidebar-group-toggle sidebar-link sidebar-tree-link" aria-expanded="<?= $open ? 'true' : 'false' ?>" title="<?= e($label) ?>">
                <span class="folder-icon folder-icon-folder" aria-hidden="true"></span>
                <span class="sidebar-link-text"><?= e($label) ?></span>
                <?php if ($unread > 0): ?>
                    <span class="folder-badge folder-badge-sm"><?= $unread > 99 ? '99+' : $unread ?></span>
                <?php endif; ?>
                <svg class="sidebar-group-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
        </div>
        <div class="sidebar-group-items">
            <?php foreach ($node['children'] as $child): ?>
                <?php if ($child['children'] !== []): ?>
                    <?php $renderDisplayGroup($child, $depth + 1); ?>
                <?php else: ?>
                    <?php $renderTreeRow($child['folder'], 'employee', $depth + 1, null, false, false, null); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
};

$otherFolderTree = [];
if ($grouped['other'] !== []) {
    $otherFolders = sidebar_dedupe_other_folders($grouped['other']);
    $delimiter = $otherFolders[0]['delimiter'] ?? '.';
    $otherFolderTree = build_sidebar_other_folder_tree($otherFolders, 'path', $delimiter);
}

// Mark active-containing branches once (O(N)) for the branch-open state.
$otherFolderTree = array_map($annotateActiveBranches, $otherFolderTree);
$displayForest = array_map($annotateActiveBranches, $displayForest);

// Total unread across the BOTTOM folders only (the custom folders below the
// section divider — Erik, Emp and its members — not Inbox/Sent/Drafts/etc.).
$bottomUnread = 0;
$bottomSeen = [];
$sumBottomUnread = function (array $nodes) use (&$sumBottomUnread, &$bottomUnread, &$bottomSeen, $unreadCounts): void {
    foreach ($nodes as $node) {
        $p = (string) ($node['folder']['path'] ?? '');
        if ($p !== '') {
            $nav = sidebar_folder_nav_path($p);
            $k = strtolower($nav);
            if ($nav !== '' && !isset($bottomSeen[$k])) {
                $bottomSeen[$k] = true;
                if (folder_shows_unread_badge($nav)) {
                    $bottomUnread += (int) ($unreadCounts[$nav] ?? $unreadCounts[$p] ?? 0);
                }
            }
        }
        $sumBottomUnread($node['children'] ?? []);
    }
};
$sumBottomUnread($otherFolderTree);
$sumBottomUnread($displayForest);

?>

<nav class="sidebar-nav">
    <a class="btn btn-primary btn-compose" href="<?= e($composeHref) ?>" id="compose-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        Compose
    </a>

    <div class="sidebar-groups" id="folder-sidebar-list">
        <div class="sidebar-folder-tree sidebar-primary-folders--tree">
            <?php foreach ($primaryOrder as $bucket): ?>
                <?php foreach ($grouped[$bucket] as $folder): ?>
                    <?php $renderTreeRow($folder, $bucket, 0, null, false, false, null); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php if ($otherFolderTree !== [] || $displayForest !== []): ?>
            <div class="sidebar-section-divider" data-sidebar-section="folders">
                <span class="sidebar-section-divider-line" aria-hidden="true"></span>
                <span class="sidebar-section-badge" id="sidebar-section-badge" aria-label="Total unread in folders"<?= $bottomUnread > 0 ? '' : ' hidden' ?>><?= $bottomUnread > 99 ? '99+' : (int) $bottomUnread ?></span>
                <button type="button" class="sidebar-section-toggle"
                        aria-expanded="true"
                        aria-controls="sidebar-folder-groups"
                        aria-label="Collapse or expand all folders"
                        title="Collapse / expand all">
                    <svg class="sidebar-section-chevron" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </button>
            </div>
            <div class="sidebar-folder-tree sidebar-section-content" id="sidebar-folder-groups">
                <?php foreach ($otherFolderTree as $node): ?>
                    <?php $renderSidebarFolderBranch($node, 0); ?>
                <?php endforeach; ?>
                <?php foreach ($displayForest as $node): ?>
                    <?php // Render grouped folders as navigable branches: the chevron
                          // expands/collapses, clicking the folder name opens its messages. ?>
                    <?php $renderSidebarFolderBranch($node, 0); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (($sessionUser['role'] ?? '') === 'admin'): ?>
        <div class="sidebar-footer sidebar-folder-tree">
            <div class="sidebar-tree-row" style="--tree-depth: 0;">
                <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
                <a class="sidebar-link sidebar-tree-link<?= ($activeFolder ?? '') === '__admin__' ? ' active' : '' ?>"
                   href="<?= e(url('admin')) ?>">
                    <span class="folder-icon folder-icon-admin" aria-hidden="true"></span>
                    <span class="sidebar-link-text">Admin panel</span>
                </a>
            </div>
            <div class="sidebar-tree-row" style="--tree-depth: 0;">
                <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
                <a class="sidebar-link sidebar-tree-link<?= ($activeFolder ?? '') === '__status__' ? ' active' : '' ?>"
                   href="<?= e(url('status')) ?>">
                    <span class="folder-icon folder-icon-connection" aria-hidden="true"></span>
                    <span class="sidebar-link-text">Connection status</span>
                </a>
            </div>
        </div>
    <?php endif; ?>
</nav>
<?php perf_mark('sidebar_render_done'); ?>
