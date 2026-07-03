<?php
/**
 * Shared rich-text formatting ribbon for the compose editor.
 *
 * Rendered at the top of every compose form (new message, reply, reply-all,
 * forward, draft) and revealed once the message body gains focus — see
 * initRichEditor() in app.js and .compose-format-toolbar in app.css.
 */
?>
<div class="compose-format-toolbar" id="rich-toolbar" role="toolbar" aria-label="Text formatting">
    <select class="compose-format-select compose-format-font" data-cmd="fontName" title="Font" aria-label="Font family">
        <option value="">Font</option>
        <option value="Arial, Helvetica, sans-serif">Arial</option>
        <option value="'Arial Black', Gadget, sans-serif">Arial Black</option>
        <option value="Calibri, sans-serif">Calibri</option>
        <option value="Cambria, Georgia, serif">Cambria</option>
        <option value="'Century Gothic', sans-serif">Century Gothic</option>
        <option value="'Comic Sans MS', cursive">Comic Sans MS</option>
        <option value="Consolas, monospace">Consolas</option>
        <option value="'Courier New', Courier, monospace">Courier New</option>
        <option value="'Franklin Gothic Medium', sans-serif">Franklin Gothic</option>
        <option value="Garamond, serif">Garamond</option>
        <option value="Georgia, serif">Georgia</option>
        <option value="Helvetica, Arial, sans-serif">Helvetica</option>
        <option value="Impact, Charcoal, sans-serif">Impact</option>
        <option value="'Lucida Console', Monaco, monospace">Lucida Console</option>
        <option value="'Lucida Sans Unicode', 'Lucida Grande', sans-serif">Lucida Sans</option>
        <option value="'Palatino Linotype', 'Book Antiqua', Palatino, serif">Palatino</option>
        <option value="'Segoe UI', Roboto, sans-serif">Segoe UI</option>
        <option value="Tahoma, Geneva, sans-serif">Tahoma</option>
        <option value="'Times New Roman', Times, serif">Times New Roman</option>
        <option value="'Trebuchet MS', Helvetica, sans-serif">Trebuchet MS</option>
        <option value="Verdana, Geneva, sans-serif">Verdana</option>
    </select>
    <select class="compose-format-select compose-format-size" data-cmd="fontSize" title="Font size" aria-label="Font size">
        <option value="">Size</option>
        <option value="8pt">8</option>
        <option value="9pt">9</option>
        <option value="10pt">10</option>
        <option value="11pt">11</option>
        <option value="12pt">12</option>
        <option value="14pt">14</option>
        <option value="16pt">16</option>
        <option value="18pt">18</option>
        <option value="20pt">20</option>
        <option value="24pt">24</option>
        <option value="28pt">28</option>
        <option value="36pt">36</option>
        <option value="48pt">48</option>
        <option value="72pt">72</option>
    </select>

    <span class="compose-format-sep" aria-hidden="true"></span>

    <button type="button" class="compose-format-btn" data-cmd="bold" title="Bold" aria-label="Bold"><b>B</b></button>
    <button type="button" class="compose-format-btn" data-cmd="italic" title="Italic" aria-label="Italic"><i>I</i></button>
    <button type="button" class="compose-format-btn" data-cmd="underline" title="Underline" aria-label="Underline"><u>U</u></button>
    <button type="button" class="compose-format-btn" data-cmd="strikeThrough" title="Strikethrough" aria-label="Strikethrough"><s>S</s></button>
    <input type="color" class="compose-format-color" data-cmd="foreColor" value="#111827" title="Text color" aria-label="Text color">

    <span class="compose-format-sep" aria-hidden="true"></span>

    <button type="button" class="compose-format-btn" data-cmd="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/></svg>
    </button>
    <button type="button" class="compose-format-btn" data-cmd="insertOrderedList" title="Numbered list" aria-label="Numbered list">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="10" y1="6" x2="20" y2="6"/><line x1="10" y1="12" x2="20" y2="12"/><line x1="10" y1="18" x2="20" y2="18"/><path d="M4 10V5L2.5 6"/><path d="M3 14h2.2L3 18h2.5"/></svg>
    </button>
    <button type="button" class="compose-format-btn" data-cmd="outdent" title="Decrease indent" aria-label="Decrease indent">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="7 8 3 12 7 16"/><line x1="21" y1="6" x2="11" y2="6"/><line x1="21" y1="12" x2="11" y2="12"/><line x1="21" y1="18" x2="11" y2="18"/></svg>
    </button>
    <button type="button" class="compose-format-btn" data-cmd="indent" title="Increase indent" aria-label="Increase indent">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 8 7 12 3 16"/><line x1="21" y1="6" x2="11" y2="6"/><line x1="21" y1="12" x2="11" y2="12"/><line x1="21" y1="18" x2="11" y2="18"/></svg>
    </button>

    <span class="compose-format-sep" aria-hidden="true"></span>

    <div class="compose-format-menu" data-menu="align">
        <button type="button" class="compose-format-btn compose-format-menu-btn" data-align="justifyLeft" title="Alignment" aria-haspopup="true" aria-expanded="false" aria-label="Alignment">
            <span class="compose-format-menu-icon icon-justifyLeft"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="15" y1="12" x2="3" y2="12"/><line x1="17" y1="18" x2="3" y2="18"/></svg></span>
            <span class="compose-format-menu-icon icon-justifyCenter"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="17" y1="12" x2="7" y2="12"/><line x1="19" y1="18" x2="5" y2="18"/></svg></span>
            <span class="compose-format-menu-icon icon-justifyRight"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="9" y2="12"/><line x1="21" y1="18" x2="7" y2="18"/></svg></span>
            <span class="compose-format-menu-icon icon-justifyFull"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="3" y2="18"/></svg></span>
            <svg class="compose-format-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="compose-format-menu-pop compose-format-menu-pop--align" role="menu" hidden>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="justifyLeft" title="Align left" aria-label="Align left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="15" y1="12" x2="3" y2="12"/><line x1="17" y1="18" x2="3" y2="18"/></svg></button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="justifyCenter" title="Align center" aria-label="Align center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="17" y1="12" x2="7" y2="12"/><line x1="19" y1="18" x2="5" y2="18"/></svg></button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="justifyRight" title="Align right" aria-label="Align right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="9" y2="12"/><line x1="21" y1="18" x2="7" y2="18"/></svg></button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="justifyFull" title="Justify" aria-label="Justify"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="3" y2="18"/></svg></button>
        </div>
    </div>
    <div class="compose-format-menu" data-menu="lineHeight">
        <button type="button" class="compose-format-btn compose-format-menu-btn" title="Line spacing" aria-haspopup="true" aria-expanded="false" aria-label="Line spacing">
            <span class="compose-format-menu-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M6 4v16"/><path d="M3 7l3-3 3 3"/><path d="M3 17l3 3 3-3"/></svg></span>
            <svg class="compose-format-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="compose-format-menu-pop compose-format-menu-pop--spacing" role="menu" hidden>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="1">1.0</button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="1.15">1.15</button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="1.5">1.5</button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="2">2.0</button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="2.5">2.5</button>
            <button type="button" class="compose-format-menu-item" role="menuitem" data-value="3">3.0</button>
        </div>
    </div>

    <span class="compose-format-sep" aria-hidden="true"></span>

    <button type="button" class="compose-format-btn" data-cmd="createLink" title="Insert link" aria-label="Insert link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
    </button>
</div>
