<?php
/**
 * Draft editor — full compose fields without read-preview duplication.
 *
 * @var string $to
 * @var string|null $cc
 * @var string|null $bcc
 * @var string $subject
 * @var string $body
 * @var string|null $body_html
 * @var string $from_email
 * @var list<array{email: string, display_name: string}> $aliases
 * @var string $draftFolder
 * @var int $draftUid
 * @var string $returnFolder
 * @var bool $send_as_fixed
 */
$recipientAutocomplete = compose_recipient_autocomplete_data();
$toValue = trim($to);
$ccValue = trim((string) ($cc ?? ''));
$bccValue = trim((string) ($bcc ?? ''));
$subjectValue = trim($subject);
$compose_send_as_variant = 'draft';

if (!empty($body_html)) {
    $editorHtml = \App\HtmlSanitizer::sanitize($body_html);
} else {
    $bodyText = trim($body);
    $editorHtml = $bodyText !== '' ? nl2br(e($bodyText)) : '<p><br></p>';
}
?>
<form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form compose-form--draft" id="compose-form" enctype="multipart/form-data" novalidate
      data-recipient-domains="<?= e(json_encode($recipientAutocomplete['domains'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-recipient-contacts="<?= e(json_encode($recipientAutocomplete['contacts'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-send-as-email="<?= e($from_email) ?>"
      data-compose-mode="edit-draft">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="edit-draft">
    <input type="hidden" name="body_html" id="body_html" value="<?= e($body_html ?? '') ?>">
    <input type="hidden" name="return_folder" id="return_folder" value="<?= e($returnFolder ?? '') ?>">
    <?php if (!empty($draftFolder) && !empty($draftUid)): ?>
        <input type="hidden" name="draft_folder" value="<?= e(encode_folder_path($draftFolder)) ?>">
        <input type="hidden" name="draft_uid" value="<?= (int) $draftUid ?>">
    <?php endif; ?>

    <?php require base_path('views/partials/compose-format-toolbar.php'); ?>

    <div class="compose-draft-card">
        <header class="compose-draft-header">
            <div class="compose-draft-header-main">
                <span class="compose-draft-badge">Draft</span>
                <h2 class="compose-draft-title"><?= e($subjectValue !== '' ? $subjectValue : '(no subject)') ?></h2>
            </div>
            <p class="compose-draft-hint">Finish your message below, then send or save.</p>
        </header>

        <div class="compose-draft-fields">
            <?php require base_path('views/partials/compose-send-as.php'); ?>

            <div class="compose-draft-recipients-box">
                <div class="compose-recipients compose-recipients--draft">
                    <div class="compose-recipient-row" data-field="to">
                        <label class="compose-recipient-label" for="to-input">To</label>
                        <div class="recipient-field">
                            <div class="recipient-chips" id="to-chips" aria-live="polite"></div>
                            <input type="text" class="recipient-input" id="to-input" autocomplete="off" spellcheck="false" placeholder="Add recipients">
                        </div>
                        <div class="compose-recipient-meta">
                            <button type="button" class="recipient-toggle-cc" id="toggle-cc" aria-controls="cc-row">Cc</button>
                            <button type="button" class="recipient-toggle-bcc" id="toggle-bcc" aria-controls="bcc-row">Bcc</button>
                        </div>
                        <input type="hidden" name="to" id="to" value="<?= e($toValue) ?>">
                    </div>

                    <div class="compose-recipient-row" id="cc-row" data-field="cc"<?= $ccValue === '' ? ' hidden' : '' ?>>
                        <label class="compose-recipient-label" for="cc-input">Cc</label>
                        <div class="recipient-field">
                            <div class="recipient-chips" id="cc-chips" aria-live="polite"></div>
                            <input type="text" class="recipient-input" id="cc-input" autocomplete="off" spellcheck="false" placeholder="Add Cc">
                        </div>
                        <input type="hidden" name="cc" id="cc" value="<?= e($ccValue) ?>">
                    </div>

                    <div class="compose-recipient-row" id="bcc-row" data-field="bcc"<?= $bccValue === '' ? ' hidden' : '' ?>>
                        <label class="compose-recipient-label" for="bcc-input">Bcc</label>
                        <div class="recipient-field">
                            <div class="recipient-chips" id="bcc-chips" aria-live="polite"></div>
                            <input type="text" class="recipient-input" id="bcc-input" autocomplete="off" spellcheck="false" placeholder="Add Bcc">
                        </div>
                        <input type="hidden" name="bcc" id="bcc" value="<?= e($bccValue) ?>">
                    </div>
                </div>
            </div>

            <div class="compose-draft-subject-row">
                <label class="compose-recipient-label" for="subject">Subject</label>
                <input type="text" id="subject" name="subject" class="compose-draft-subject-input" value="<?= e($subjectValue) ?>" required placeholder="Add a subject">
            </div>
        </div>

        <div class="compose-draft-editor-wrap">
            <div id="body-editor" class="rich-editor compose-draft-editor" contenteditable="true" aria-label="Message body"><?= $editorHtml ?></div>
            <textarea id="body" name="body" rows="14" class="sr-only"><?= e($body) ?></textarea>
        </div>

        <footer class="compose-draft-toolbar">
            <div class="compose-draft-toolbar-attach file-upload file-upload--draft-bar" id="file-upload">
                <input type="file" id="attachments" name="attachments[]" multiple class="file-upload-input">
                <label for="attachments" class="compose-draft-attach-btn" title="Attach files" aria-label="Attach files">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </label>
                <ul class="compose-draft-attach-list file-upload-list" id="file-upload-list" hidden></ul>
            </div>

            <div class="compose-draft-toolbar-actions">
                <button type="button" class="compose-draft-action compose-draft-action--discard" data-compose-cancel>Discard</button>
                <button type="submit" formaction="<?= e(url('compose/draft')) ?>" class="compose-draft-action compose-draft-action--save">Save draft</button>
                <button type="submit" class="compose-draft-action compose-draft-action--send">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9 22 2z"/></svg>
                    <span>Send</span>
                </button>
            </div>
        </footer>
    </div>
</form>
