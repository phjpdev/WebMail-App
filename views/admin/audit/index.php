<?php ob_start(); ?>



<section class="page-header page-header-row">

    <div><h2>Audit log</h2></div>

</section>



<?php require base_path('views/partials/admin-nav.php'); ?>



<section class="card card-flush">

    <form method="get" class="filter-bar filter-bar-modern">

        <label for="action" class="filter-bar-label">Filter by action</label>

        <div class="select-field select-field-inline">

            <select id="action" name="action" onchange="this.form.submit()">

                <option value="">All actions</option>

                <option value="login"<?= ($filterAction ?? '') === 'login' ? ' selected' : '' ?>>Login</option>

                <option value="logout"<?= ($filterAction ?? '') === 'logout' ? ' selected' : '' ?>>Logout</option>

                <option value="filter_move"<?= ($filterAction ?? '') === 'filter_move' ? ' selected' : '' ?>>Filter move</option>

            </select>

        </div>

    </form>



    <div class="table-wrap">

        <table class="data-table">

            <thead>

                <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr>

            </thead>

            <tbody>

                <?php foreach ($entries as $entry): ?>

                <tr>

                    <td><?= e($entry['created_at']) ?></td>

                    <td><?= e($entry['user_name'] ?? $entry['username'] ?? '—') ?></td>

                    <td><code><?= e($entry['action']) ?></code></td>

                    <td><?= e($entry['details'] ?? '') ?></td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>



    <?php if (($totalMessages ?? 0) > 0): ?>
        <?php
        $baseUrl = '?action=' . urlencode($filterAction ?? '') . '&';
        require base_path('views/partials/pagination.php');
        ?>
    <?php endif; ?>

</section>



<?php

$content = ob_get_clean();

require base_path('views/layout.php');

