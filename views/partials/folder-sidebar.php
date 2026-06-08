<?php
/**
 * @var list<array{path: string, name: string}> $folders
 * @var string|null $activeFolder
 */

$groupOrder = ['inbox', 'sent', 'drafts', 'other', 'trash'];
$grouped = [
    'inbox' => [],
    'sent' => [],
    'drafts' => [],
    'trash' => [],
    'other' => [],
];

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
    <a class="btn btn-primary btn-compose" href="<?= e(url('compose')) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Compose
    </a>

    <div class="sidebar-scroll">
        <?php foreach ($groupOrder as $group): ?>
            <?php if (empty($grouped[$group])) continue; ?>
            <p class="sidebar-label"><?= e($labels[$group]) ?></p>
            <?php foreach ($grouped[$group] as $folder): ?>
                <?php
                $displayName = $folder['path'] === 'INBOX' ? 'Inbox' : preg_replace('/^INBOX\./', '', $folder['name']);
                $isActive = ($activeFolder ?? '') === $folder['path'];
                $icon = folder_icon_type($folder['path']);
                ?>
                <a class="sidebar-link<?= $isActive ? ' active' : '' ?>"
                   href="<?= e(folder_url($folder['path'])) ?>">
                    <span class="folder-icon folder-icon-<?= e($icon) ?>" aria-hidden="true"></span>
                    <span class="sidebar-link-text"><?= e($displayName) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
        <p class="sidebar-label">Admin</p>
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
    <?php endif; ?>
    </div>
</nav>
