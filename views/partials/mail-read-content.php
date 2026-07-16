<?php
/**
 * Shared read view body (actions, headers, attachments, message body).
 *
 * @var array<string, mixed> $message
 * @var string $folderPath
 * @var string|null $folderB64
 * @var string $replyFrom
 * @var list<array{path: string, name: string}> $moveTargets
 * @var string $sanitizedHtml
 * @var bool $isPane
 */
$isPane = !empty($isPane);
$folderB64 = $folderB64 ?? encode_folder_path($folderPath);
$uid = (int) ($message['uid'] ?? 0);
$isFlagged = !empty($message['flagged']);
$thread = is_array($conversationThread ?? null) && ($conversationThread ?? []) !== []
    ? $conversationThread
    : mail_build_conversation_thread($message, $sanitizedHtml, $replyFrom, $folderPath, $uid);
$threadDisplay = $thread;
$threadCount = count($threadDisplay);
$threadSubject = mail_display_subject($message, $folderPath);
$threadKey = mail_normalize_thread_subject((string) ($message['subject'] ?? ''));
$expandAllThread = $threadCount > 1;
$messageAttachments = $message['attachments'] ?? [];
?>

<div class="mail-actions no-print" role="toolbar" aria-label="Message actions">
    <a class="mail-action-btn mail-action-btn--primary compose-panel-link" id="reply-btn" href="<?= e(url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply" title="Reply" aria-label="Reply">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10h10a4 4 0 0 1 4 4v1"/><path d="M3 10l5-5M3 10l5 5"/></svg>
        <span class="mail-action-label">Reply</span>
    </a>
    <a class="mail-action-btn compose-panel-link" id="reply-all-btn" href="<?= e(url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Reply all" title="Reply all" aria-label="Reply all">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10h6a4 4 0 0 1 4 4v1"/><path d="M3 10l5-5M3 10l5 5"/><path d="M11 10h6a4 4 0 0 1 4 4v1"/><path d="M11 10l5-5M11 10l5 5"/></svg>
        <span class="mail-action-label">Reply all</span>
    </a>
    <a class="mail-action-btn compose-panel-link" href="<?= e(url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Forward" title="Forward" aria-label="Forward">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 10H11a4 4 0 0 0-4 4v1"/><path d="M21 10l-5-5M21 10l-5 5"/></svg>
        <span class="mail-action-label">Forward</span>
    </a>
    <?php if (folder_icon_type($folderPath) === 'draft'): ?>
        <a class="mail-action-btn compose-panel-link" href="<?= e(url('compose/edit-draft?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid)) ?>" data-compose-title="Edit draft" title="Edit draft" aria-label="Edit draft">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            <span class="mail-action-label">Edit draft</span>
        </a>
    <?php endif; ?>
    <button type="button" class="mail-action-btn" data-mail-action="print" title="Print" aria-label="Print">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <span class="mail-action-label">Print</span>
    </button>

    <span class="mail-action-divider" aria-hidden="true"></span>

    <?php if (!empty($message['seen'])): ?>
        <button type="button" class="mail-action-btn" data-mail-action="mark-unread" title="Mark unread" aria-label="Mark unread">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            <span class="mail-action-label">Unread</span>
        </button>
    <?php else: ?>
        <button type="button" class="mail-action-btn" data-mail-action="mark-read" title="Mark read" aria-label="Mark read">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 9l9-6 9 6v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 9l9 6 9-6"/></svg>
            <span class="mail-action-label">Read</span>
        </button>
    <?php endif; ?>
    <button type="button" class="mail-action-btn mail-action-btn--flag" data-mail-action="flag-toggle" title="<?= $isFlagged ? 'Remove importance' : 'Mark important' ?>" aria-label="<?= $isFlagged ? 'Remove importance' : 'Mark important' ?>" aria-pressed="<?= $isFlagged ? 'true' : 'false' ?>">
        <svg class="mail-action-icon mail-action-icon--flag" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/></svg>
        <svg class="mail-action-icon mail-action-icon--unflag" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/><path d="M4 4l16 16"/></svg>
        <span class="mail-action-label">Flag</span>
    </button>
    <button type="button" class="mail-action-btn" data-mail-action="spam" title="Spam" aria-label="Report spam">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
        <span class="mail-action-label">Spam</span>
    </button>
    <button type="button" class="mail-action-btn mail-action-btn--danger"<?= $isPane ? '' : ' id="delete-form"' ?> data-mail-action="trash" title="Delete" aria-label="Delete">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3"/></svg>
        <span class="mail-action-label">Delete</span>
    </button>

    <?php if (!empty($moveTargets)): ?>
    <div class="mail-action-move" id="read-mail-move-<?= $uid ?>">
        <label class="sr-only" for="read-move-target-<?= $uid ?>">Move to folder</label>
        <div class="mail-action-move-select-wrap" aria-hidden="true">
            <select id="read-move-target-<?= $uid ?>" name="target_folder" class="mail-action-move-select" aria-label="Move to folder" tabindex="-1">
                <option value="">Move to…</option>
                <?php foreach ($moveTargets as $target): ?>
                    <option value="<?= e($target['path']) ?>" data-depth="<?= (int) ($target['depth'] ?? 0) ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', (int) ($target['depth'] ?? 0)) ?><?= e($target['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="mail-action-btn mail-action-btn--move" data-mail-action="move" title="Move to folder" aria-label="Move to folder">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9l-.81-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/><path d="M12 10.5v4.5"/><path d="m9.75 12.75 2.25 2.25 2.25-2.25"/></svg>
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="mail-read-content">
    <div class="mail-read-subject-bar">
        <h1 class="mail-read-subject"><?= e($threadSubject) ?></h1>
    </div>

    <div class="mail-thread" data-mail-thread>
        <?php foreach ($threadDisplay as $i => $segment): ?>
            <?php
            $isLatest = ($i === $threadCount - 1);
            $threadHistorySegments = $i > 0
                ? array_reverse(array_slice($threadDisplay, 0, $i))
                : [];
            $showThreadHistoryToggle = $threadHistorySegments !== [];
            $display = mail_thread_segment_display($segment, $replyFrom, $folderPath);
            $attachments = is_array($segment['attachments'] ?? null) ? $segment['attachments'] : [];
            // Fall back to the opened message's attachments only for the genuine
            // opened message — NOT a pending reply preview, which defaults its uid
            // to the opened message's and would otherwise wrongly show the
            // original's attachment on the reply card (reply carries only its own
            // uploaded files).
            if ($attachments === [] && !empty($segment['is_current']) && empty($segment['is_pending_reply'])) {
                $attachments = $messageAttachments;
            }
            $cardFolder = (string) ($segment['folder_path'] ?? $folderPath);
            $cardUid = (int) ($segment['imap_uid'] ?? 0);
            if ($cardUid <= 0 && !empty($segment['is_current'])) {
                $cardUid = $uid;
                $cardFolder = $folderPath;
            }
            require base_path('views/partials/mail-thread-card.php');
            ?>
        <?php endforeach; ?>
    </div>
</div>
