<?php ob_start(); ?>

<section class="page-header page-header-row">
    <div><h2><?= e($title ?? 'Filter rules') ?></h2></div>
    <div class="page-header-actions">
        <form method="post" action="<?= e(url('admin/sync')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Run filter now
            </button>
        </form>
        <a class="btn btn-primary" href="<?= e(url('admin/rules/create')) ?>">Add rule</a>
    </div>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<nav class="rule-type-tabs">
    <a href="<?= e(url('admin/rules')) ?>" class="<?= empty($ruleType) ? 'active' : '' ?>">All</a>
    <a href="<?= e(url('admin/rules?type=spam')) ?>" class="<?= ($ruleType ?? '') === 'spam' ? 'active' : '' ?>">Spam</a>
    <a href="<?= e(url('admin/rules?type=company')) ?>" class="<?= ($ruleType ?? '') === 'company' ? 'active' : '' ?>">Company</a>
    <a href="<?= e(url('admin/rules?type=employee')) ?>" class="<?= ($ruleType ?? '') === 'employee' ? 'active' : '' ?>">Employee</a>
    <a href="<?= e(url('admin/rules?type=client')) ?>" class="<?= ($ruleType ?? '') === 'client' ? 'active' : '' ?>">Client</a>
</nav>

<section class="card card-flush">
    <p class="text-muted form-hint">Drag rows to reorder priority. Lower numbers run first.</p>
    <form method="post" action="<?= e(url('admin/rules/reorder')) ?>" id="rules-reorder-form">
        <?= csrf_field() ?>
        <input type="hidden" name="order" id="rules-order" value="">
    </form>
    <div class="table-wrap">
        <table class="data-table" id="rules-table">
            <thead>
                <tr><th></th><th>Priority</th><th>Name</th><th>Type</th><th>Condition</th><th>Target</th><th>Status</th><th></th></tr>
            </thead>
            <tbody id="rules-sortable">
                <?php foreach ($rules as $r): ?>
                <tr draggable="true" data-id="<?= (int) $r['id'] ?>" data-priority="<?= (int) $r['priority'] ?>">
                    <td class="drag-handle" aria-hidden="true">⋮⋮</td>
                    <td class="rule-priority"><?= (int) $r['priority'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><span class="badge badge-<?= e($r['rule_type']) ?>"><?= e($r['rule_type']) ?></span></td>
                    <td><code><?= e($r['condition_field'] . ' ' . $r['condition_operator'] . ' ' . $r['condition_value']) ?></code></td>
                    <td><?= e($r['folder_name']) ?></td>
                    <td><span class="badge badge-<?= (int) $r['active'] ? 'active' : 'inactive' ?>"><?= (int) $r['active'] ? 'Active' : 'Off' ?></span></td>
                    <td class="admin-actions">
                        <a class="admin-action-link" href="<?= e(url('admin/rules/' . $r['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('admin/rules/' . $r['id'] . '/toggle')) ?>" class="admin-action-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="admin-action-link admin-action-link-muted">Toggle</button>
                        </form>
                        <form method="post" action="<?= e(url('admin/rules/' . $r['id'] . '/delete')) ?>" class="admin-action-form"
                              data-confirm-title="Delete rule?"
                              data-confirm-message="Remove this filter rule? This cannot be undone."
                              data-confirm-danger="1"
                              data-confirm-label="Delete">
                            <?= csrf_field() ?>
                            <button type="submit" class="admin-action-link btn-link-danger">Delete</button>
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
