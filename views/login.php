<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Login') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=7">
</head>
<body class="login-body">
    <div id="loading-overlay" class="loading-overlay" hidden aria-live="polite" aria-busy="false">
        <div class="loading-spinner" role="status">
            <span class="loading-ring"></span>
            <span class="loading-text">Signing in…</span>
        </div>
    </div>
    <div class="login-page">
        <div class="login-card">
            <h2>Sign in</h2>
            <p class="text-muted">Access the shared team mailbox</p>

            <?php require base_path('views/partials/flash.php'); ?>

            <form method="post" action="<?= e(url('login')) ?>" class="login-form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password or access code</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Login</button>
            </form>
        </div>
    </div>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=7" defer></script>
    <script>
        document.querySelector('.login-form')?.addEventListener('submit', function () {
            var o = document.getElementById('loading-overlay');
            if (o) o.hidden = false;
        });
    </script>
</body>
</html>
