<!DOCTYPE html>
<html lang="en" data-theme="<?= e(($prefs['theme'] ?? 'light') === 'auto' ? '' : ($prefs['theme'] ?? 'light')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=19">
    <script>
        (function () {
            // The saved account preference is authoritative; keep localStorage in
            // sync with it so the theme can't drift between the DB and this device.
            var serverTheme = <?= json_encode($prefs['theme'] ?? null) ?>;
            try {
                if (serverTheme && serverTheme !== 'auto') {
                    document.documentElement.setAttribute('data-theme', serverTheme);
                    localStorage.setItem('dj_theme', serverTheme);
                    return;
                }
                if (serverTheme === 'auto') {
                    localStorage.removeItem('dj_theme');
                }
                var t = localStorage.getItem('dj_theme');
                if (t) {
                    document.documentElement.setAttribute('data-theme', t);
                } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) { /* storage blocked */ }
        })();
    </script>
</head>
    <body data-poll-interval="<?= (int) ($prefs['poll_interval'] ?? config('app')['mail_poll_interval']) ?>"
      data-sound-enabled="<?= !empty($prefs['sound_enabled']) ? '1' : '0' ?>"
      data-notify-enabled="<?= !empty($prefs['notify_enabled']) ? '1' : '0' ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-base-url="<?= e(url('')) ?>"
      data-filter-pending="<?= !empty($filterPending) ? '1' : '0' ?>">
    <?php $sessionUser = $sessionUser ?? $authUser ?? null; ?>
    <div id="nav-progress" class="nav-progress" role="status" aria-live="polite" aria-hidden="true">
        <span class="nav-progress-bar"></span>
    </div>

    <div id="filter-progress" class="filter-progress" hidden aria-live="polite">
        <div class="filter-progress-card">
            <span class="loading-ring"></span>
            <p>Organizing mail…</p>
        </div>
    </div>

    <div id="shortcuts-modal" class="shortcuts-modal" hidden>
        <div class="shortcuts-modal-inner">
            <h3>Keyboard shortcuts</h3>
            <ul class="shortcut-list">
                <li><kbd>c</kbd> Compose</li>
                <li><kbd>/</kbd> Focus search</li>
                <li><kbd>j</kbd> / <kbd>k</kbd> Next / previous</li>
                <li><kbd>r</kbd> Reply · <kbd>a</kbd> Reply all</li>
                <li><kbd>e</kbd> Delete · <kbd>?</kbd> This help</li>
            </ul>
            <button type="button" class="btn btn-secondary" id="shortcuts-close">Close</button>
        </div>
    </div>

    <header class="site-header">
        <div class="header-inner">
            <div class="header-left">
                <?php if (!empty($sessionUser)): ?>
                    <button type="button" id="menu-toggle" class="menu-toggle" aria-label="Toggle menu">
                        <span></span><span></span><span></span>
                    </button>
                <?php endif; ?>
                <h1 class="site-title">
                    <a href="<?= e(url('')) ?>" class="site-title-link"><?= e(config('app')['name']) ?></a>
                </h1>
            </div>
            <?php if (!empty($sessionUser)): ?>
                <div class="header-user">
                    <div class="header-user-info">
                        <span class="user-avatar" aria-hidden="true"><?= e(strtoupper(substr($sessionUser['name'], 0, 1))) ?></span>
                        <span class="user-name"><?= e($sessionUser['name']) ?></span>
                        <span class="role-badge role-<?= e($sessionUser['role']) ?>"><?= e($sessionUser['role']) ?></span>
                    </div>
                    <span class="header-divider" aria-hidden="true"></span>
                    <nav class="header-nav" aria-label="Account">
                        <a class="header-nav-link" href="<?= e(url('settings')) ?>">Settings</a>
                        <form method="post" action="<?= e(url('logout')) ?>" class="header-nav-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="header-nav-link header-nav-button">Logout</button>
                        </form>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div id="sidebar-backdrop" class="sidebar-backdrop" hidden></div>

    <div class="app-shell">
        <?php if (!empty($sessionUser)): ?>
            <aside id="sidebar" class="sidebar">
                <?php require base_path('views/partials/folder-sidebar.php'); ?>
            </aside>
        <?php endif; ?>

        <main class="main-content">
            <?php require base_path('views/partials/flash.php'); ?>
            <?= $content ?? '' ?>
        </main>
    </div>

    <script src="<?= e(url('assets/js/app.js')) ?>?v=19" defer></script>
</body>
</html>
