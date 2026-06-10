<!DOCTYPE html>
<html lang="en" data-theme="<?= e(($prefs['theme'] ?? 'light') === 'auto' ? '' : ($prefs['theme'] ?? 'light')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=8">
    <script>
        (function () {
            var t = localStorage.getItem('dj_theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
            else if (!document.documentElement.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
</head>
<body class="standalone-body" data-csrf="<?= e(csrf_token()) ?>">
    <header class="standalone-header">
        <a href="<?= e(url('')) ?>" class="standalone-back">← Back to mail</a>
        <div class="standalone-header-right">
            <a href="<?= e(url('logout')) ?>" class="btn btn-ghost-standalone">Logout</a>
        </div>
    </header>

    <main class="standalone-main">
        <?php require base_path('views/partials/flash.php'); ?>
        <?= $content ?? '' ?>
    </main>

    <script src="<?= e(url('assets/js/app.js')) ?>?v=8" defer></script>
</body>
</html>
