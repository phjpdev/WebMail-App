<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <h1 class="site-title"><?= e(config('app')['name']) ?></h1>
            <?php if (!empty($user)): ?>
                <div class="header-user">
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <span class="role-badge role-<?= e($user['role']) ?>"><?= e($user['role']) ?></span>
                    <a class="btn btn-link" href="<?= e(url('logout')) ?>">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="layout">
        <?php if (!empty($user)): ?>
            <aside class="sidebar">
                <nav class="sidebar-nav">
                    <p class="sidebar-label">Navigation</p>
                    <a class="sidebar-link active" href="<?= e(url('')) ?>">Dashboard</a>
                    <p class="sidebar-note">Folder tree — Milestone 2</p>
                </nav>
            </aside>
        <?php endif; ?>

        <main class="main-content">
            <?php require base_path('views/partials/flash.php'); ?>
            <?= $content ?? '' ?>
        </main>
    </div>
</body>
</html>
