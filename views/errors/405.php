<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Method not allowed') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=19">
</head>
<body class="error-body">
    <div class="error-page">
        <div class="error-card">
            <div class="error-code">405</div>
            <h1><?= e($title ?? 'Method not allowed') ?></h1>
            <p class="text-muted"><?= e($message ?? '') ?></p>
            <a href="<?= e(url('')) ?>" class="btn btn-primary">Go to inbox</a>
        </div>
    </div>
</body>
</html>
