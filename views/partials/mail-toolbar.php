<?php
/**
 * Outlook-style command bar above the message list.
 *
 * @var string $folderPath
 * @var list<array{path: string, name: string}> $folders
 */
?>
<div class="mail-command-bar" id="mail-command-bar" role="toolbar" aria-label="Message actions">
    <div class="mail-command-bar-row mail-command-bar-row--main">
        <div class="mail-command-bar-primary">
            <a class="btn btn-primary btn-sm mail-cmd-compose" href="<?= e(url('compose')) ?>" data-cmd="compose">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                <span>New mail</span>
            </a>
        </div>

        <div class="mail-command-bar-main-end">
            <button type="button" class="mail-cmd-btn mail-cmd-btn--main-row" data-cmd="refresh" title="Refresh list">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                <span class="mail-cmd-label">Refresh</span>
            </button>

            <div class="mail-command-bar-meta">
                <label class="mail-list-select-all mail-list-select-all--toolbar">
                    <input type="checkbox" id="select-all" aria-label="Select all messages on this page">
                    <span>All</span>
                </label>
                <span class="mail-cmd-selection-count" id="cmd-selection-count" hidden></span>
            </div>
        </div>
    </div>

    <div class="mail-command-bar-row mail-command-bar-row--selection">
        <div class="mail-command-bar-actions">
            <button type="button" class="mail-cmd-btn mail-cmd-btn--compose-action" data-cmd="reply" disabled hidden title="Reply" aria-label="Reply">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 15L4 10l5-5"/><path d="M4 10h9a7 7 0 0 1 7 7v2"/></svg>
                <span class="mail-cmd-label">Reply</span>
            </button>
            <button type="button" class="mail-cmd-btn mail-cmd-btn--compose-action" data-cmd="reply-all" disabled hidden title="Reply all" aria-label="Reply all">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 15l-5-5 5-5"/><path d="M12 15l-5-5 5-5"/><path d="M7 10h8a6 6 0 0 1 6 6v2"/></svg>
                <span class="mail-cmd-label">Reply all</span>
            </button>
            <button type="button" class="mail-cmd-btn mail-cmd-btn--compose-action" data-cmd="forward" disabled hidden title="Forward" aria-label="Forward">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M15 15l5-5-5-5"/><path d="M20 10h-9a7 7 0 0 0-7 7v2"/></svg>
                <span class="mail-cmd-label">Forward</span>
            </button>

            <button type="button" class="mail-cmd-btn" data-cmd="mark-read" disabled title="Mark as read">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 9l9-6 9 6v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 9l9 6 9-6"/></svg>
                <span class="mail-cmd-label">Read</span>
            </button>
            <button type="button" class="mail-cmd-btn" data-cmd="mark-unread" disabled title="Mark as unread">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                <span class="mail-cmd-label">Unread</span>
            </button>
            <button type="button" class="mail-cmd-btn mail-cmd-btn--flag" data-cmd="flag-toggle" disabled title="Mark as important" aria-pressed="false">
                <svg class="mail-cmd-icon mail-cmd-icon--flag" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/></svg>
                <svg class="mail-cmd-icon mail-cmd-icon--unflag" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/><path d="M4 4l16 16"/></svg>
                <span class="mail-cmd-label">Flag</span>
            </button>

            <div class="mail-cmd-move" id="mail-cmd-move">
                <label class="sr-only" for="cmd-move-target">Move to folder</label>
                <div class="mail-cmd-move-select-wrap" aria-hidden="true">
                    <select id="cmd-move-target" class="mail-cmd-move-select" disabled aria-label="Move to folder" tabindex="-1">
                        <option value="">Move to…</option>
                        <?php foreach ($folders as $f): ?>
                            <?php if ($f['path'] !== $folderPath): ?>
                                <option value="<?= e($f['path']) ?>"><?= e($f['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="mail-cmd-btn mail-cmd-btn--move" data-cmd="move" disabled title="Move to folder" aria-label="Move to folder">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h5l2 3h11v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7z"/><path d="M9 12h7"/><path d="M13 9l3 3-3 3"/></svg>
                </button>
            </div>

            <button type="button" class="mail-cmd-btn mail-cmd-btn--danger" data-cmd="delete" disabled title="Delete">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4h6v3"/></svg>
                <span class="mail-cmd-label">Delete</span>
            </button>
        </div>
    </div>
</div>
