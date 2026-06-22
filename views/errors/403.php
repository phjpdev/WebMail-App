<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Access denied') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=32">
</head>
<body class="login-body">
    <header class="login-header">
        <span class="login-brand"><?= e(config('app')['name']) ?></span>
    </header>

    <div class="login-page">
        <div class="login-card error-card">
            <div class="error-code">403</div>
            <h2><?= e($title ?? 'Access denied') ?></h2>
            <p class="text-muted"><?= e($message ?? 'You do not have permission to view this page.') ?></p>
            <a href="<?= e(url('')) ?>" class="btn btn-primary btn-block">Go to inbox</a>
        </div>
    </div>
</body>
</html>
