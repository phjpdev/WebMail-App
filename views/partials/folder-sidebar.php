<?php
/**
 * @var list<array{path: string, name: string}> $folders
 * @var string|null $activeFolder
 * @var array<string, int> $unreadCounts
 */

$groupOrder = ['inbox', 'sent', 'drafts', 'other', 'trash'];
$grouped = [
    'inbox' => [],
    'sent' => [],
    'drafts' => [],
    'trash' => [],
    'other' => [],
];
$unreadCounts = $unreadCounts ?? [];
$fixedGroups = ['inbox', 'sent', 'drafts', 'trash'];

foreach ($folders as $folder) {
    $path = $folder['path'];
    $lower = strtolower($path);

    if ($path === 'INBOX') {
        $grouped['inbox'][] = $folder;
    } elseif (str_contains($lower, 'sent')) {
        $grouped['sent'][] = $folder;
    } elseif (str_contains($lower, 'draft')) {
        $grouped['drafts'][] = $folder;
    } elseif (str_contains($lower, 'trash')) {
        $grouped['trash'][] = $folder;
    } else {
        $grouped['other'][] = $folder;
    }
}

$labels = [
    'inbox' => 'Inbox',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'other' => 'Folders',
    'trash' => 'Trash',
];

?>

<nav class="sidebar-nav">
    <a class="btn btn-primary btn-compose" href="<?= e(url('compose')) ?>" id="compose-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Compose
    </a>

    <div class="sidebar-groups" id="folder-sidebar-list">
        <?php foreach ($groupOrder as $group): ?>
            <?php if (empty($grouped[$group])) continue; ?>
            <?php
            $groupUnread = 0;
            foreach ($grouped[$group] as $folder) {
                $groupUnread += (int) ($unreadCounts[$folder['path']] ?? 0);
            }
            $isCollapsible = !in_array($group, $fixedGroups, true);
            $isOpen = $isCollapsible ? false : true;
            if ($isCollapsible) {
                foreach ($grouped[$group] as $folder) {
                    if (($activeFolder ?? '') === $folder['path']) {
                        $isOpen = true;
                        break;
                    }
                }
            }
            ?>
            <div class="sidebar-group<?= $isOpen ? ' is-open' : '' ?><?= $isCollapsible ? ' is-collapsible' : ' is-fixed' ?>" data-group="<?= e($group) ?>">
                <?php if ($isCollapsible): ?>
                    <button type="button" class="sidebar-group-toggle" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                        <span class="sidebar-group-chevron" aria-hidden="true"></span>
                        <span class="sidebar-group-title"><?= e($labels[$group]) ?></span>
                        <?php if ($groupUnread > 0): ?>
                            <span class="folder-badge folder-badge-sm"><?= $groupUnread > 99 ? '99+' : $groupUnread ?></span>
                        <?php endif; ?>
                    </button>
                <?php else: ?>
                    <p class="sidebar-label">
                        <?= e($labels[$group]) ?>
                        <?php if ($groupUnread > 0): ?>
                            <span class="folder-badge folder-badge-sm"><?= $groupUnread > 99 ? '99+' : $groupUnread ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="sidebar-group-items">
                    <?php foreach ($grouped[$group] as $folder): ?>
                        <?php
                        $displayName = $folder['path'] === 'INBOX' ? 'Inbox' : preg_replace('/^INBOX\./', '', $folder['name']);
                        $isActive = ($activeFolder ?? '') === $folder['path'];
                        $icon = folder_icon_type($folder['path']);
                        $unread = (int) ($unreadCounts[$folder['path']] ?? 0);
                        ?>
                        <a class="sidebar-link<?= $isActive ? ' active' : '' ?>"
                           href="<?= e(folder_url($folder['path'])) ?>"
                           data-folder-path="<?= e($folder['path']) ?>">
                            <span class="folder-icon folder-icon-<?= e($icon) ?>" aria-hidden="true"></span>
                            <span class="sidebar-link-text"><?= e($displayName) ?></span>
                            <?php if ($unread > 0): ?>
                                <span class="folder-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (($sessionUser['role'] ?? '') === 'admin'): ?>
            <div class="sidebar-group is-open is-collapsible" data-group="admin">
                <button type="button" class="sidebar-group-toggle" aria-expanded="true">
                    <span class="sidebar-group-chevron" aria-hidden="true"></span>
                    <span class="sidebar-group-title">Admin</span>
                </button>
                <div class="sidebar-group-items">
                    <a class="sidebar-link<?= ($activeFolder ?? '') === '__admin__' ? ' active' : '' ?>"
                       href="<?= e(url('admin')) ?>">
                        <span class="folder-icon folder-icon-status" aria-hidden="true"></span>
                        <span class="sidebar-link-text">Admin panel</span>
                    </a>
                    <a class="sidebar-link<?= ($activeFolder ?? '') === '__status__' ? ' active' : '' ?>"
                       href="<?= e(url('status')) ?>">
                        <span class="folder-icon folder-icon-status" aria-hidden="true"></span>
                        <span class="sidebar-link-text">Connection status</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>
