<?php ob_start(); ?>

<section class="page-header">
    <h2><?= e(ucfirst(str_replace('-', ' ', $mode))) ?></h2>
</section>

<section class="card">
    <form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form" id="compose-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="body_html" id="body_html" value="<?= e($body_html ?? '') ?>">
        <?php if (!empty($folderPath) && !empty($uid)): ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="from_email">Send as</label>
            <div class="select-field">
            <select id="from_email" name="from_email" required>
                <?php foreach ($aliases as $alias): ?>
                    <option value="<?= e($alias['email']) ?>"<?= ($from_email === $alias['email']) ? ' selected' : '' ?>>
                        <?= e($alias['display_name']) ?> &lt;<?= e($alias['email']) ?>&gt;
                    </option>
                <?php endforeach; ?>
            </select>
            </div>
        </div>

        <div class="form-group">
            <label for="to">To</label>
            <input type="text" id="to" name="to" value="<?= e($to) ?>" required
                   placeholder="email@example.com, other@example.com" autocomplete="email">
        </div>

        <p class="compose-cc-toggle">
            <button type="button" class="btn-link-muted" id="toggle-cc-bcc" aria-expanded="<?= (!empty($cc) || !empty($bcc)) ? 'true' : 'false' ?>">
                Cc / Bcc
            </button>
        </p>

        <div id="cc-bcc-fields" class="cc-bcc-fields"<?= (empty($cc) && empty($bcc)) ? ' hidden' : '' ?>>
            <div class="form-group">
                <label for="cc">Cc</label>
                <input type="text" id="cc" name="cc" value="<?= e($cc ?? '') ?>"
                       placeholder="email@example.com" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bcc">Bcc</label>
                <input type="text" id="bcc" name="bcc" value="<?= e($bcc ?? '') ?>"
                       placeholder="email@example.com" autocomplete="off">
            </div>
        </div>

        <div class="form-group">
            <label for="subject">Subject</label>
            <input type="text" id="subject" name="subject" value="<?= e($subject) ?>" required>
        </div>

        <div class="form-group">
            <label>Message</label>
            <div class="rich-toolbar" id="rich-toolbar">
                <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
                <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
                <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" data-cmd="insertUnorderedList" title="Bullet list">•</button>
                <button type="button" data-cmd="createLink" title="Link">Link</button>
            </div>
            <div id="body-editor" class="rich-editor" contenteditable="true"><?= !empty($body_html) ? $body_html : nl2br(e($body)) ?></div>
            <textarea id="body" name="body" rows="14" class="sr-only"><?= e($body) ?></textarea>
        </div>

        <div class="form-group">
            <label>Attachments</label>
            <div class="file-upload" id="file-upload">
                <input type="file" id="attachments" name="attachments[]" multiple class="file-upload-input">
                <label for="attachments" class="file-upload-label">
                    <span class="file-upload-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </span>
                    <span class="file-upload-text">Choose files or drag here</span>
                    <span class="file-upload-hint">Up to 5 files, 10 MB each</span>
                </label>
                <ul class="file-upload-list" id="file-upload-list" hidden></ul>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send</button>
            <button type="submit" formaction="<?= e(url('compose/draft')) ?>" class="btn btn-outline">Save draft</button>
            <a class="btn btn-outline" href="<?= e(url('')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
