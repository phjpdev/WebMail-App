<?php
/**
 * Outlook-style inline reply / forward compose (reading pane).
 *
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
 * @var string $returnFolder
 */
$recipientAutocomplete = compose_recipient_autocomplete_data();
$composeAvatarColor = mail_avatar_color($from_email);
$composeAvatarInitial = mail_user_initials();
$showSubject = $mode === 'forward' || $mode === 'edit-draft';
$isReplyMode = in_array($mode, ['reply', 'reply-all'], true);

$toValue = trim($to);
$ccValue = trim((string) ($cc ?? ''));

$bodyParts = compose_split_reply_body($body ?? '');
$composeBody = $bodyParts['compose'];
$quotedBody = $bodyParts['quoted'];
$editorHtml = $composeBody !== '' ? nl2br(e($composeBody)) : '<p><br></p>';
?>
<form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form compose-form--outlook-inline" id="compose-form" enctype="multipart/form-data" novalidate
      data-recipient-domains="<?= e(json_encode($recipientAutocomplete['domains'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-recipient-contacts="<?= e(json_encode($recipientAutocomplete['contacts'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"
      data-send-as-email="<?= e($from_email) ?>"
      data-compose-mode="<?= e($mode) ?>">
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

    <div class="compose-outlook-card<?= $isReplyMode ? ' compose-outlook-card--reply' : '' ?>">
        <div class="compose-outlook-header">
            <div class="compose-outlook-header-row">
                <div class="compose-outlook-avatar" style="background-color: <?= e($composeAvatarColor) ?>" aria-hidden="true"><?= e($composeAvatarInitial) ?></div>
                <div class="compose-outlook-to-summary" data-to-summary-toggle role="button" tabindex="0" aria-expanded="false" aria-label="Edit recipients">
                    <span class="compose-outlook-recipients-summary" data-recipients-summary-inline></span>
                </div>
            </div>

            <div class="compose-outlook-recipients-panel" data-recipients-panel hidden>
                <div class="compose-recipients compose-recipients--outlook-expanded">
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

                    <div class="compose-recipient-row" id="bcc-row" data-field="bcc" hidden>
                        <label class="compose-recipient-label" for="bcc-input">Bcc</label>
                        <div class="recipient-field">
                            <div class="recipient-chips" id="bcc-chips" aria-live="polite"></div>
                            <input type="text" class="recipient-input" id="bcc-input" autocomplete="off" spellcheck="false">
                        </div>
                        <input type="hidden" name="bcc" id="bcc" value="">
                    </div>
                </div>
            </div>
        </div>

        <?php if ($showSubject): ?>
        <div class="compose-outlook-subject-row">
            <span class="compose-outlook-to-prefix">Subject</span>
            <input type="text" id="subject" name="subject" class="compose-outlook-subject-input" value="<?= e($subject) ?>" required>
        </div>
        <?php else: ?>
            <input type="hidden" id="subject" name="subject" value="<?= e($subject) ?>">
        <?php endif; ?>

        <?php if (!empty($send_as_fixed)): ?>
            <input type="hidden" id="from_email" name="from_email" value="<?= e($from_email) ?>">
        <?php else: ?>
        <div class="form-group compose-outlook-from-wrap">
            <label for="from_email" class="sr-only">Send as</label>
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
        <?php endif; ?>

        <div class="compose-outlook-editor-wrap">
            <?php if (!$isReplyMode): ?>
            <div class="rich-toolbar rich-toolbar--minimal" id="rich-toolbar">
                <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
                <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
                <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" data-cmd="insertUnorderedList" title="Bullet list">•</button>
            </div>
            <?php endif; ?>
            <div id="body-editor" class="rich-editor compose-outlook-editor" contenteditable="true"><?= $editorHtml ?></div>
            <?php if ($quotedBody !== ''): ?>
            <textarea class="compose-quoted-source sr-only" readonly aria-hidden="true"><?= e($quotedBody) ?></textarea>
            <?php if (!$isReplyMode): ?>
            <div class="compose-quoted-wrap">
                <button type="button" class="compose-quoted-toggle" aria-expanded="false" aria-label="Show quoted message">
                    <span class="compose-quoted-toggle-dots" aria-hidden="true">⋯</span>
                </button>
                <div class="compose-quoted-body" hidden>
                    <pre class="compose-quoted-text"><?= e(ltrim($quotedBody, "\n")) ?></pre>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <textarea id="body" name="body" rows="14" class="sr-only"><?= e($body) ?></textarea>
        </div>

        <div class="compose-outlook-attach">
            <div class="file-upload file-upload--compact" id="file-upload">
                <input type="file" id="attachments" name="attachments[]" multiple class="file-upload-input">
                <label for="attachments" class="file-upload-label file-upload-label--compact">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Attach</span>
                </label>
                <ul class="file-upload-list" id="file-upload-list" hidden></ul>
            </div>
        </div>

        <?php if (!empty($forwardedAttachments)): ?>
        <div class="compose-outlook-forwarded">
            <strong>Forwarded attachments</strong>
            <ul class="forwarded-attachments">
                <?php foreach ($forwardedAttachments as $fa): ?>
                    <li><?= e($fa['filename'] ?? 'attachment') ?></li>
                <?php endforeach; ?>
            </ul>
            <input type="hidden" name="forward_folder" value="<?= e(encode_folder_path((string) ($folderPath ?? ''))) ?>">
            <input type="hidden" name="forward_uid" value="<?= (int) ($uid ?? 0) ?>">
            <input type="hidden" name="forward_parts" value="<?= e(json_encode($forwardedAttachments, JSON_UNESCAPED_UNICODE) ?: '[]') ?>">
        </div>
        <?php endif; ?>

        <div class="compose-outlook-actions">
            <button type="submit" class="compose-outlook-send">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9 22 2z"/></svg>
                <span>Send</span>
            </button>
            <button type="button" class="compose-outlook-discard" data-compose-cancel>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3"/></svg>
                <span>Discard</span>
            </button>
        </div>
    </div>
</form>
