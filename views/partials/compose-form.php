<?php
/**
 * @var string $mode
 * @var string $to
 * @var string|null $cc
 * @var string|null $bcc
 * @var string $subject
 * @var string $body
 * @var string|null $body_html
 * @var string $from_email
 * @var list<array{email: string, display_name: string}> $aliases
 * @var string $folderPath
 * @var int $uid
 * @var string $draftFolder
 * @var int $draftUid
 * @var list<array{filename?: string}> $forwardedAttachments
 * @var bool $embed
 * @var string $returnFolder
 */
$embed = !empty($embed);
$outlookInline = $embed && in_array($mode, ['reply', 'reply-all', 'forward', 'edit-draft'], true);
$recipientAutocomplete = compose_recipient_autocomplete_data();

if ($outlookInline) {
    require base_path('views/partials/compose-form-outlook-inline.php');
    return;
}
?>
<form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form" id="compose-form" enctype="multipart/form-data"
      data-recipient-domains="<?= e(json_encode($recipientAutocomplete['domains'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-recipient-contacts="<?= e(json_encode($recipientAutocomplete['contacts'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-send-as-email="<?= e($from_email) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <input type="hidden" name="body_html" id="body_html" value="<?= e($body_html ?? '') ?>">
    <input type="hidden" name="return_folder" id="return_folder" value="<?= e($returnFolder ?? '') ?>">
    <?php if (!empty($folderPath) && !empty($uid)): ?>
        <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
    <?php endif; ?>
    <?php if (!empty($draftFolder) && !empty($draftUid)): ?>
        <input type="hidden" name="draft_folder" value="<?= e(encode_folder_path($draftFolder)) ?>">
        <input type="hidden" name="draft_uid" value="<?= (int) $draftUid ?>">
    <?php endif; ?>

    <?php require base_path('views/partials/compose-send-as.php'); ?>

    <div class="compose-recipients">
        <div class="compose-recipient-row" data-field="to">
            <label class="compose-recipient-label" for="to-input">To</label>
            <div class="recipient-field">
                <div class="recipient-chips" id="to-chips" aria-live="polite"></div>
                <input type="text" class="recipient-input" id="to-input" autocomplete="off" spellcheck="false">
            </div>
            <div class="compose-recipient-meta">
                <button type="button" class="recipient-toggle-cc" id="toggle-cc" aria-controls="cc-row">Cc</button>
                <button type="button" class="recipient-toggle-bcc" id="toggle-bcc" aria-controls="bcc-row">Bcc</button>
            </div>
            <input type="hidden" name="to" id="to" value="<?= e($to) ?>">
        </div>

        <div class="compose-recipient-row" id="cc-row" data-field="cc"<?= empty($cc) ? ' hidden' : '' ?>>
            <label class="compose-recipient-label" for="cc-input">Cc</label>
            <div class="recipient-field">
                <div class="recipient-chips" id="cc-chips" aria-live="polite"></div>
                <input type="text" class="recipient-input" id="cc-input" autocomplete="off" spellcheck="false">
            </div>
            <input type="hidden" name="cc" id="cc" value="<?= e($cc ?? '') ?>">
        </div>

        <div class="compose-recipient-row" id="bcc-row" data-field="bcc"<?= empty($bcc) ? ' hidden' : '' ?>>
            <label class="compose-recipient-label" for="bcc-input">Bcc</label>
            <div class="recipient-field">
                <div class="recipient-chips" id="bcc-chips" aria-live="polite"></div>
                <input type="text" class="recipient-input" id="bcc-input" autocomplete="off" spellcheck="false">
            </div>
            <input type="hidden" name="bcc" id="bcc" value="<?= e($bcc ?? '') ?>">
        </div>
    </div>

    <div class="form-group compose-subject-group">
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
        <div id="body-editor" class="rich-editor" contenteditable="true"><?= !empty($body_html) ? \App\HtmlSanitizer::sanitize($body_html) : nl2br(e($body)) ?></div>
        <textarea id="body" name="body" rows="14" class="sr-only"><?= e($body) ?></textarea>
    </div>

    <?php if (!empty($forwardedAttachments)): ?>
        <div class="form-group">
            <label>Forwarded attachments</label>
            <ul class="forwarded-attachments">
                <?php foreach ($forwardedAttachments as $fa): ?>
                    <li><?= e($fa['filename'] ?? 'attachment') ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="file-upload-hint">These will be included with your forward.</p>
        </div>
    <?php endif; ?>

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

    <div class="form-actions compose-form-actions">
        <button type="submit" class="btn btn-primary">Send</button>
        <button type="submit" formaction="<?= e(url('compose/draft')) ?>" class="btn btn-outline">Save draft</button>
        <?php if ($embed): ?>
            <button type="button" class="btn btn-outline" data-compose-cancel>Discard</button>
        <?php else: ?>
            <a class="btn btn-outline" href="<?= e(url('')) ?>">Cancel</a>
        <?php endif; ?>
    </div>
</form>
