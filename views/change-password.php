<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Change password') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=19">
</head>
<body class="login-body">
    <div class="login-page">
        <div class="login-card">
            <h2><?= !empty($required) ? 'Set a new password' : 'Change password' ?></h2>
            <?php if (!empty($required)): ?>
                <p class="text-muted">You must change your password before continuing.</p>
            <?php endif; ?>

            <?php require base_path('views/partials/flash.php'); ?>

            <form method="post" action="<?= e(url('change-password')) ?>" class="login-form">
                <?= csrf_field() ?>

                <?php if (empty($required)): ?>
                <div class="form-group">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Save password</button>
            </form>
        </div>
    </div>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=19" defer></script>
</body>
</html>

