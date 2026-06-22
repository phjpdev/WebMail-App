<?php ob_start(); ?>

<div class="mail-workspace" id="mail-workspace">
<?php require base_path('views/mail/list-column.php'); ?>

<aside class="reading-pane" id="reading-pane" aria-label="Message preview">
    <div class="reading-pane-viewport" id="reading-pane-viewport">
        <div class="reading-pane-empty" id="reading-pane-empty">
            <div class="reading-pane-empty-inner">
                <svg class="reading-pane-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M3 8.5l9 5.5 9-5.5"/><path d="M5 6h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/>
                </svg>
                <p>Select a message to read</p>
            </div>
        </div>
        <div class="reading-pane-body" id="reading-pane-body" hidden aria-live="polite"></div>
        <div class="reading-pane-skeleton" id="reading-pane-skeleton" hidden aria-hidden="true">
            <div class="pane-skeleton-toolbar">
                <span class="pane-skeleton-block pane-skeleton-block--btn"></span>
                <span class="pane-skeleton-block pane-skeleton-block--btn"></span>
                <span class="pane-skeleton-block pane-skeleton-block--btn"></span>
            </div>
            <div class="pane-skeleton-subject"></div>
            <div class="pane-skeleton-meta"></div>
            <div class="pane-skeleton-lines">
                <span class="pane-skeleton-line"></span>
                <span class="pane-skeleton-line"></span>
                <span class="pane-skeleton-line pane-skeleton-line--short"></span>
            </div>
        </div>
    </div>
    <div id="mail-live-region" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
    <div class="compose-panel" id="compose-panel" hidden aria-label="Compose message">
        <div class="compose-panel-header">
            <h3 class="compose-panel-title" id="compose-panel-title">New message</h3>
            <button type="button" class="compose-panel-close" id="compose-panel-close" aria-label="Close compose">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="compose-panel-body" id="compose-panel-body" aria-live="polite"></div>
    </div>
</aside>
</div>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
