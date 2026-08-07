<?php ob_start(); ?>

<section class="page-header">
    <h2>Admin dashboard</h2>
    <p class="text-muted">Manage users, folders, aliases, and filter rules</p>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<div class="admin-stats">
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $userCount ?></span>
        <span class="admin-stat-label">Active users</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $ruleCount ?></span>
        <span class="admin-stat-label">Active rules</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value"><?= (int) $folderCount ?></span>
        <span class="admin-stat-label">Folders</span>
    </div>
</div>

<section class="card">
    <h3>Mail sync</h3>
    <p class="text-muted">Check the Inbox now and sort new mail into folders by your rules.</p>
    <?php if (!empty($filterStats)): ?>
        <p class="text-muted">Last run: <?= (int) $filterStats['processed'] ?> processed, <?= (int) $filterStats['moved'] ?> moved (<?= (int) $filterStats['duration_ms'] ?>ms)</p>
    <?php endif; ?>
    <div class="admin-action-row">
        <form method="post" action="<?= e(url('admin/sync')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Sync now</button>
        </form>
    </div>
</section>

<section class="card" id="history-import-card">
    <h3>Import old emails</h3>
    <p class="text-muted">
        Index the full message history of every folder so all old mail is browsable and searchable.
        <strong>Runs on the server in the background</strong> — you can close this window, log out,
        or shut your computer; the import keeps going. Progress is saved continuously and it is
        safe to pause and resume any time.
    </p>
    <div class="admin-action-row">
        <button type="button" class="btn btn-primary" id="history-import-toggle" data-running="0">Start import</button>
        <span class="text-muted" id="history-import-status" aria-live="polite">Checking status…</span>
    </div>
</section>

<script>
(function () {
    var btn = document.getElementById('history-import-toggle');
    var status = document.getElementById('history-import-status');
    if (!btn || !status) return;
    var csrf = document.querySelector('input[name="_csrf"]');
    var startUrl = <?= json_encode(url('admin/history/start')) ?>;
    var stopUrl = <?= json_encode(url('admin/history/stop')) ?>;
    var statusUrl = <?= json_encode(url('admin/history/status')) ?>;
    var pollTimer = null;

    function setStatus(text) { status.textContent = text; }
    function setButton(running) {
        btn.dataset.running = running ? '1' : '0';
        btn.textContent = running ? 'Pause import' : 'Start import';
    }

    function renderStatus(d) {
        setButton(!!d.running);
        if (d.complete && !d.running) {
            setStatus('All old emails imported — ' + d.folders_done + ' folders complete ✓ ('
                + (d.rows_total || 0).toLocaleString() + ' emails indexed)');
            return;
        }
        if (!d.running) {
            setStatus(d.rows_total > 0
                ? 'Paused — ' + d.folders_done + ' of ' + d.folders_total + ' folders complete, '
                    + (d.rows_total || 0).toLocaleString() + ' emails indexed so far. Click Start to resume.'
                : 'Not running.');
            return;
        }
        var line = 'Importing in background: ' + d.folders_done + ' of ' + d.folders_total
            + ' folders complete · ' + (d.rows_total || 0).toLocaleString() + ' emails indexed'
            + (d.current_folder ? ' · working on: ' + d.current_folder : '');
        if (d.heartbeat_age !== null && d.heartbeat_age > 90) {
            line += ' · (restarting worker…)';
        }
        setStatus(line + ' — you can close this window.');
    }

    function poll() {
        fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok) renderStatus(d); })
            .catch(function () { /* transient */ })
            .finally(function () { pollTimer = setTimeout(poll, 4000); });
    }

    btn.addEventListener('click', function () {
        var running = btn.dataset.running === '1';
        var body = new URLSearchParams();
        if (csrf) body.set('_csrf', csrf.value);
        fetch(running ? stopUrl : startUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.ok) setButton(!!d.running);
            setStatus(running ? 'Pausing…' : 'Started — running on the server. You can close this window.');
        }).catch(function () { setStatus('Request failed — try again.'); });
    });

    poll();
})();
</script>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
