<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=2">
</head>
<body>
    <div id="loading-overlay" class="loading-overlay" hidden aria-live="polite" aria-busy="false">
        <div class="loading-spinner" role="status">
            <span class="loading-ring"></span>
            <span class="loading-text">Loading…</span>
        </div>
    </div>

    <header class="site-header">
        <div class="header-inner">
            <div class="header-left">
                <?php if (!empty($user)): ?>
                    <button type="button" id="menu-toggle" class="menu-toggle" aria-label="Toggle menu">
                        <span></span><span></span><span></span>
                    </button>
                <?php endif; ?>
                <h1 class="site-title">
                    <a href="<?= e(url('')) ?>" class="site-title-link"><?= e(config('app')['name']) ?></a>
                </h1>
            </div>
            <?php if (!empty($user)): ?>
                <div class="header-user">
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <span class="role-badge role-<?= e($user['role']) ?>"><?= e($user['role']) ?></span>
                    <a class="btn btn-ghost" href="<?= e(url('logout')) ?>">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div id="sidebar-backdrop" class="sidebar-backdrop" hidden></div>

    <div class="app-shell">
        <?php if (!empty($user)): ?>
            <aside id="sidebar" class="sidebar">
                <?php require base_path('views/partials/folder-sidebar.php'); ?>
            </aside>
        <?php endif; ?>

        <main class="main-content">
            <?php require base_path('views/partials/flash.php'); ?>
            <?= $content ?? '' ?>
        </main>
    </div>

    <script src="<?= e(url('assets/js/app.js')) ?>?v=2" defer></script>
</body>
</html>
