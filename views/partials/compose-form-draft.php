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

    <div class="compose-draft-card">
        <header class="compose-draft-header">
            <span class="compose-draft-badge">Draft</span>
            <h2 class="compose-draft-title"><?= e($subjectValue !== '' ? $subjectValue : '(no subject)') ?></h2>
        </header>

        <div class="compose-draft-fields">
            <?php require base_path('views/partials/compose-send-as.php'); ?>

            <div class="compose-recipients compose-recipients--draft">
                <div class="compose-recipient-row" data-field="to">
                    <label class="compose-recipient-label" for="to-input">To</label>
                    <div class="recipient-field">
                        <div class="recipient-chips" id="to-chips" aria-live="polite"></div>
                        <input type="text" class="recipient-input" id="to-input" autocomplete="off" spellcheck="false" placeholder="Recipients">
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
                        <input type="text" class="recipient-input" id="cc-input" autocomplete="off" spellcheck="false">
                    </div>
                    <input type="hidden" name="cc" id="cc" value="<?= e($ccValue) ?>">
                </div>

                <div class="compose-recipient-row" id="bcc-row" data-field="bcc"<?= $bccValue === '' ? ' hidden' : '' ?>>
                    <label class="compose-recipient-label" for="bcc-input">Bcc</label>
                    <div class="recipient-field">
                        <div class="recipient-chips" id="bcc-chips" aria-live="polite"></div>
                        <input type="text" class="recipient-input" id="bcc-input" autocomplete="off" spellcheck="false">
                    </div>
                    <input type="hidden" name="bcc" id="bcc" value="<?= e($bccValue) ?>">
                </div>
            </div>

            <div class="compose-draft-subject-row">
                <label class="compose-recipient-label" for="subject">Subject</label>
                <input type="text" id="subject" name="subject" class="compose-draft-subject-input" value="<?= e($subjectValue) ?>" required placeholder="Subject">
            </div>
        </div>

        <div class="compose-draft-editor-wrap">
            <div class="rich-toolbar rich-toolbar--minimal" id="rich-toolbar">
                <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
                <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
                <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" data-cmd="insertUnorderedList" title="Bullet list">•</button>
                <button type="button" data-cmd="createLink" title="Link">Link</button>
            </div>
            <div id="body-editor" class="rich-editor compose-draft-editor" contenteditable="true"><?= $editorHtml ?></div>
            <textarea id="body" name="body" rows="14" class="sr-only"><?= e($body) ?></textarea>
        </div>

        <div class="compose-draft-attach">
            <div class="file-upload file-upload--compact" id="file-upload">
                <input type="file" id="attachments" name="attachments[]" multiple class="file-upload-input">
                <label for="attachments" class="file-upload-label file-upload-label--compact">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Attach files</span>
                </label>
                <ul class="file-upload-list" id="file-upload-list" hidden></ul>
            </div>
        </div>

        <div class="compose-draft-actions">
            <button type="submit" class="btn btn-primary compose-draft-send">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9 22 2z"/></svg>
                <span>Send</span>
            </button>
            <button type="submit" formaction="<?= e(url('compose/draft')) ?>" class="btn btn-outline compose-draft-save">Save draft</button>
            <button type="button" class="btn btn-outline compose-draft-discard" data-compose-cancel>Discard</button>
        </div>
    </div>
</form>
