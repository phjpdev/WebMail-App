<!DOCTYPE html>
<html lang="en" data-theme="<?= e(($prefs['theme'] ?? 'light') === 'auto' ? '' : ($prefs['theme'] ?? 'light')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'D&J Webmail') ?> — <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=73">
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
    <body class="<?= e(trim($bodyClass ?? '')) ?>"
      data-poll-interval="<?= (int) ($prefs['poll_interval'] ?? config('app')['mail_poll_interval']) ?>"
      data-sound-enabled="<?= !empty($prefs['sound_enabled']) ? '1' : '0' ?>"
      data-notify-enabled="<?= !empty($prefs['notify_enabled']) ? '1' : '0' ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-base-url="<?= e(url('')) ?>">
    <?php $sessionUser = $sessionUser ?? $authUser ?? null; ?>

    <div id="confirm-modal" class="app-modal" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-confirm-dismiss tabindex="-1"></div>
        <div class="app-modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message">
            <div class="app-modal-body">
                <div class="app-modal-icon" id="confirm-modal-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                </div>
                <div class="app-modal-content">
                    <h2 id="confirm-modal-title" class="app-modal-title"></h2>
                    <p id="confirm-modal-message" class="app-modal-message"></p>
                </div>
            </div>
            <div class="app-modal-actions">
                <button type="button" class="btn btn-outline" id="confirm-modal-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-modal-ok">OK</button>
            </div>
        </div>
    </div>

    <div id="folder-picker-modal" class="app-modal folder-picker-modal" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-folder-picker-dismiss tabindex="-1"></div>
        <div class="app-modal-dialog app-modal-dialog--sheet" role="dialog" aria-modal="true" aria-labelledby="folder-picker-title">
            <div class="folder-picker-header">
                <h2 id="folder-picker-title" class="folder-picker-title">Choose folder</h2>
                <button type="button" class="folder-picker-close" id="folder-picker-cancel" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="folder-picker-search-wrap">
                <label class="sr-only" for="folder-picker-search">Search folders</label>
                <input type="search" id="folder-picker-search" class="folder-picker-search" placeholder="Search folders…" autocomplete="off">
            </div>
            <div class="folder-picker-list" id="folder-picker-list" role="listbox" aria-labelledby="folder-picker-title"></div>
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

    <script src="<?= e(url('assets/js/app.js')) ?>?v=68" defer></script>
</body>
</html>
