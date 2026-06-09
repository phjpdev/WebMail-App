<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div><h2>Filter rules</h2></div>
    <a class="btn btn-primary" href="<?= e(url('admin/rules/create')) ?>">Add rule</a>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-flush">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Priority</th><th>Name</th><th>Type</th><th>Condition</th><th>Target</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $r): ?>
                <tr>
                    <td><?= (int) $r['priority'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><span class="badge badge-<?= e($r['rule_type']) ?>"><?= e($r['rule_type']) ?></span></td>
                    <td><code><?= e($r['condition_field'] . ' ' . $r['condition_operator'] . ' ' . $r['condition_value']) ?></code></td>
                    <td><?= e($r['folder_name']) ?></td>
                    <td><span class="badge badge-<?= (int) $r['active'] ? 'active' : 'inactive' ?>"><?= (int) $r['active'] ? 'Active' : 'Off' ?></span></td>
                    <td class="admin-actions">
                        <a href="<?= e(url('admin/rules/' . $r['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('admin/rules/' . $r['id'] . '/toggle')) ?>" class="inline-form">
                            <button type="submit" class="btn-link-muted">Toggle</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
