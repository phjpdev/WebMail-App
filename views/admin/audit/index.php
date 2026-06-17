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

                <?php
                $auditActions = [
                    'login' => 'Login',
                    'logout' => 'Logout',
                    'filter_move' => 'Filter move',
                    'reprocess_inbox' => 'Reprocess inbox',
                    'user_create' => 'User create',
                    'user_update' => 'User update',
                    'user_disable' => 'User disable',
                    'alias_create' => 'Alias create',
                    'alias_update' => 'Alias update',
                    'alias_delete' => 'Alias delete',
                    'folder_create' => 'Folder create',
                    'folder_update' => 'Folder update',
                    'rule_create' => 'Rule create',
                    'rule_update' => 'Rule update',
                    'rule_toggle' => 'Rule toggle',
                    'rule_reorder' => 'Rule reorder',
                    'rule_delete' => 'Rule delete',
                ];
                ?>
                <option value="">All actions</option>
                <?php foreach ($auditActions as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= ($filterAction ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>

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

