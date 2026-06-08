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
            <h1 class="site-title"><a href="<?= e(url('')) ?>" class="site-title-link"><?= e(config('app')['name']) ?></a></h1>
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
                <?php require base_path('views/partials/folder-sidebar.php'); ?>
            </aside>
        <?php endif; ?>

        <main class="main-content">
            <?php require base_path('views/partials/flash.php'); ?>
            <?= $content ?? '' ?>
        </main>
    </div>
</body>
</html>
