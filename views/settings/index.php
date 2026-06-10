<?php ob_start(); ?>

<div class="standalone-page">
    <section class="page-header standalone-page-header">
        <h2>Settings</h2>
        <p class="text-muted">Manage your profile and preferences</p>
    </section>

    <section class="card card-form standalone-card">
        <form method="post" action="<?= e(url('settings')) ?>" class="compose-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="name">Display name</label>
                <input type="text" id="name" name="name" value="<?= e($user['name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="signature">Email signature</label>
                <textarea id="signature" name="signature" rows="4" placeholder="Your signature (appended to outgoing mail)"><?= e($signature ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="poll_interval">Inbox refresh interval (seconds)</label>
                <input type="number" id="poll_interval" name="poll_interval" min="15" max="300"
                       value="<?= (int) ($prefs['poll_interval'] ?? 30) ?>">
            </div>

            <div class="form-group">
                <label for="theme">Theme</label>
                <div class="select-field">
                    <select id="theme" name="theme">
                        <option value="light"<?= ($prefs['theme'] ?? 'light') === 'light' ? ' selected' : '' ?>>Light</option>
                        <option value="dark"<?= ($prefs['theme'] ?? '') === 'dark' ? ' selected' : '' ?>>Dark</option>
                        <option value="auto"<?= ($prefs['theme'] ?? '') === 'auto' ? ' selected' : '' ?>>System</option>
                    </select>
                </div>
            </div>

            <div class="form-group form-check">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" name="sound_enabled" value="1"<?= !empty($prefs['sound_enabled']) ? ' checked' : '' ?>>
                    <span>Play sound for new mail</span>
                </label>
            </div>

            <div class="form-group form-check">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" name="notify_enabled" value="1"<?= !empty($prefs['notify_enabled']) ? ' checked' : '' ?>>
                    <span>Desktop notifications for new mail</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save settings</button>
                <a class="btn btn-outline" href="<?= e(url('change-password')) ?>">Change password</a>
            </div>
        </form>
    </section>

    <section class="card shortcuts-help standalone-card">
        <h3>Keyboard shortcuts</h3>
        <ul class="shortcut-list">
            <li><kbd>c</kbd> Compose</li>
            <li><kbd>/</kbd> Focus search</li>
            <li><kbd>j</kbd> / <kbd>k</kbd> Next / previous message</li>
            <li><kbd>r</kbd> Reply</li>
            <li><kbd>a</kbd> Reply all</li>
            <li><kbd>e</kbd> Delete</li>
            <li><kbd>?</kbd> Show shortcuts</li>
        </ul>
    </section>
</div>

<?php
$content = ob_get_clean();
require base_path('views/layout-standalone.php');
