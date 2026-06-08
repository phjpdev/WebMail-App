<?php ob_start(); ?>

<section class="page-header"><h2>Add folder</h2></section>
<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card">
    <form method="post" action="<?= e(url('admin/folders/store')) ?>" class="compose-form">
        <div class="form-group">
            <label for="display_name">Display name</label>
            <input type="text" id="display_name" name="display_name" required placeholder="Client ABC">
        </div>
        <div class="form-group">
            <label for="folder_type">Folder type</label>
            <select id="folder_type" name="folder_type">
                <option value="client">Client</option>
                <option value="employee">Employee</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="form-group">
            <label for="imap_path">IMAP path (optional)</label>
            <input type="text" id="imap_path" name="imap_path" placeholder="INBOX.ClientABC — auto-generated if empty">
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="create_rule" value="1"> Create filter rule</label>
        </div>
        <div class="form-group">
            <label for="rule_field">Rule field</label>
            <select id="rule_field" name="rule_field">
                <option value="subject">Subject</option>
                <option value="from">From</option>
                <option value="from_domain">From domain</option>
                <option value="to">To</option>
                <option value="body">Body</option>
            </select>
        </div>
        <div class="form-group">
            <label for="rule_operator">Operator</label>
            <select id="rule_operator" name="rule_operator">
                <option value="contains">Contains</option>
                <option value="equals">Equals</option>
                <option value="starts_with">Starts with</option>
                <option value="ends_with">Ends with</option>
            </select>
        </div>
        <div class="form-group">
            <label for="rule_value">Rule value</label>
            <input type="text" id="rule_value" name="rule_value" placeholder="e.g. K Nails">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create folder</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/folders')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
