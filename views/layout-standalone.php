<!DOCTYPE html>

<html lang="en" data-theme="<?= e(($prefs['theme'] ?? 'light') === 'auto' ? '' : ($prefs['theme'] ?? 'light')) ?>">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>

    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=32">

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

        <a href="<?= e(url('')) ?>" class="standalone-header-brand"><?= e(config('app')['name']) ?></a>

        <div class="standalone-header-right">

            <a href="<?= e(url('')) ?>" class="standalone-back">← Mail</a>

            <form method="post" action="<?= e(url('logout')) ?>">

                <?= csrf_field() ?>

                <button type="submit" class="btn-ghost-standalone">Logout</button>

            </form>

        </div>

    </header>



    <main class="standalone-main">

        <?php require base_path('views/partials/flash.php'); ?>

        <?= $content ?? '' ?>

    </main>



    <script src="<?= e(url('assets/js/app.js')) ?>?v=46" defer></script>

</body>

</html>


