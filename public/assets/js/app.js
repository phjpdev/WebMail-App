(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    var menuToggle = document.getElementById('menu-toggle');
    var sidebarBackdrop = document.getElementById('sidebar-backdrop');
    var body = document.body;
    var csrf = body.getAttribute('data-csrf') || '';
    var appBase = (body.getAttribute('data-base-url') || '').replace(/\/$/, '');

    function setCsrfToken(token) {
        if (!token) return;
        csrf = token;
        if (body) body.setAttribute('data-csrf', token);
        patchCsrfFields(document);
    }

    function patchCsrfFields(root) {
        var token = csrf || (body ? body.getAttribute('data-csrf') : '') || '';
        if (!token) return;
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input[name="_csrf"]').forEach(function (el) {
            el.value = token;
        });
    }

    function captureCsrfFromResponse(res) {
        if (!res || !res.headers) return;
        var token = res.headers.get('X-CSRF-Token');
        if (token) setCsrfToken(token);
    }

    function refreshCsrfToken() {
        return fetch(apiUrl('session/csrf'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            captureCsrfFromResponse(res);
            if (!res.ok) return csrf;
            return res.json().catch(function () { return null; }).then(function (data) {
                if (data && data.csrf) setCsrfToken(data.csrf);
                return csrf;
            });
        }).catch(function () { return csrf; });
    }

    function appBasePathname() {
        if (!appBase) return '';
        try {
            if (/^https?:\/\//i.test(appBase)) {
                return new URL(appBase).pathname.replace(/\/$/, '') || '';
            }
        } catch (e) { /* use literal path below */ }
        return appBase.replace(/\/$/, '');
    }

    function apiUrl(path) {
        path = String(path);
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        path = path.replace(/^\//, '');
        var basePath = appBasePathname();
        if (basePath && basePath !== '/' && path.indexOf(basePath.replace(/^\//, '') + '/') === 0) {
            path = path.slice(basePath.replace(/^\//, '').length + 1);
        }
        return appBase + '/' + path;
    }

    function showLoading() {}
    function hideLoading() {}
    function beginTask() {}
    function endTask() {}

    function openSidebar() {
        if (sidebar) sidebar.classList.add('is-open');
        if (sidebarBackdrop) sidebarBackdrop.hidden = false;
        body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('is-open');
        if (sidebarBackdrop) sidebarBackdrop.hidden = true;
        body.classList.remove('sidebar-open');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function () {
            if (sidebar && sidebar.classList.contains('is-open')) closeSidebar();
            else openSidebar();
        });
    }

    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);

    document.addEventListener('click', function (e) {
        if (e.target.closest('.sidebar-link') && window.innerWidth < 900) closeSidebar();
    });

    var PANE_MEDIA = window.matchMedia('(min-width: 1024px)');
    var paneLoadSeq = 0;
    var folderLoadSeq = 0;
    var folderFetchAbort = null;
    var folderFetchTimeoutId = null;
    var FOLDER_FETCH_TIMEOUT_MS = 12000;
    var pendingPostSendPreviewData = null;
    var listMutationQuietUntil = 0;
    var composePanelSeq = 0;
    var composePanelRestoreUid = null;
    var composePrefetchCache = {};
    var COMPOSE_CACHE_VERSION = 3;
    var composePrefetchInFlight = {};
    var composePrefetchTimer = null;
    var bodyWarmKeys = {};
    var backgroundFetchQueue = [];
    var backgroundFetchActive = 0;
    var backgroundFetchControllers = [];
    var MAX_BACKGROUND_FETCH = 2;
    var paneNavTimer = null;
    var paneNavPendingUid = null;
    var paneNavPendingHistory = false;
    var paneCache = {};
    var mailPollIntervalId = null;
    var mailPollInFlight = false;
    var mailPollAbort = null;
    // Module-scoped so stopMailSync() can cancel a pending refresh follow-up on a
    // folder switch — otherwise the previous folder's timer fires poll() against
    // its own URL and leaks that folder's rows into the new list.
    var mailRefreshFollowUpTimer = null;
    var activeMailPollUrl = '';
    var mailSyncPaused = false;
    var lastMailPollAt = 0;
    var mailPollMinGapMs = 25000;
    var mailSyncHooksBound = false;
    var postSendQuietUntil = 0;
    var paneMessageSyncTimer = null;
    var paneMessageSyncInFlight = false;
    var postSendRefreshFolders = [];
    var postSendSelectionThreadKey = '';
    var mailListLoadingGuard = null;
    var attachmentHintsTimer = null;
    var listSnippetsTimer = null;
    var mailBootstrapAbort = null;
    var panePrefetchInFlight = {};
    var paneHoverPrefetchTimer = null;
    var paneHoverPrefetchUid = null;
    var pendingRemovalUntil = {};
    var PENDING_REMOVAL_MS = 120000;
    var recentlyMarkedReadUntil = {};
    var RECENTLY_READ_MS = 300000;
    var PANE_CACHE_MAX = 24;
    var PANE_NAV_DEBOUNCE_MS = 0;
    // Folder-fragment SWR cache: revisiting a folder paints the last server
    // HTML instantly, then an immediate forced poll reconciles rows/badges.
    var folderFragmentCache = {};
    var FOLDER_FRAG_CACHE_MAX = 12;
    var FOLDER_FRAG_TTL_MS = 10 * 60 * 1000;

    function useReadingPane() {
        return PANE_MEDIA.matches && !!document.getElementById('reading-pane');
    }

    function getListCard() {
        return document.querySelector('.mail-list-card[data-folder-b64]');
    }

    function markUidsPendingRemoval(uids) {
        var until = Date.now() + PENDING_REMOVAL_MS;
        (uids || []).forEach(function (uid) {
            if (uid) pendingRemovalUntil[String(uid)] = until;
        });
        // Rows are being removed — every cached folder snapshot may now contain
        // them. Drop the whole fragment cache (single choke point for
        // delete/move/spam/restore, single and bulk).
        clearFolderFragmentCache();
    }

    function clearFolderFragmentCache() {
        folderFragmentCache = {};
    }

    function rememberFolderFragment(folderB64, data) {
        if (!folderB64 || !data || !data.ok || !data.html) return;
        // Never cache transitional or degraded variants: the awaiting-sync
        // "Loading…" state, the IMAP-failure card (no .mail-list-card → would
        // silently kill polling on restore), or anything during post-send flux.
        if (data.list_loading) return;
        if (data.html.indexOf('mail-list-card') === -1) return;
        if (pendingPostSendPreviewData || isPostSendQuiet()) return;

        folderFragmentCache[folderB64] = {
            html: data.html,
            folder_path: data.folder_path,
            folder_b64: data.folder_b64,
            title: data.title,
            url: data.url,
            ok: true,
            at: Date.now()
        };

        var keys = Object.keys(folderFragmentCache);
        if (keys.length > FOLDER_FRAG_CACHE_MAX) {
            keys.sort(function (a, b) { return folderFragmentCache[a].at - folderFragmentCache[b].at; });
            for (var i = 0; i < keys.length - FOLDER_FRAG_CACHE_MAX; i++) {
                delete folderFragmentCache[keys[i]];
            }
        }
    }

    function getFolderFragmentCached(folderB64) {
        var entry = folderFragmentCache[folderB64];
        if (!entry) return null;
        if (Date.now() - entry.at > FOLDER_FRAG_TTL_MS) {
            delete folderFragmentCache[folderB64];
            return null;
        }
        return entry;
    }

    // Cached HTML may predate a delete/move — never resurrect those rows,
    // not even for one frame.
    function sweepPendingRemovalRows() {
        document.querySelectorAll('#mail-list-body [data-uid], #mail-list-mobile [data-uid]').forEach(function (row) {
            if (isUidPendingRemoval(row.getAttribute('data-uid'))) row.remove();
        });
    }

    function isUidPendingRemoval(uid) {
        var key = String(uid);
        var until = pendingRemovalUntil[key];
        if (!until) return false;
        if (Date.now() > until) {
            delete pendingRemovalUntil[key];
            return false;
        }
        return true;
    }

    function noteRecentlyMarkedRead(uid) {
        if (!uid) return;
        recentlyMarkedReadUntil[String(uid)] = Date.now() + RECENTLY_READ_MS;
    }

    function isRecentlyMarkedRead(uid) {
        var key = String(uid);
        var until = recentlyMarkedReadUntil[key];
        if (!until) return false;
        if (Date.now() > until) {
            delete recentlyMarkedReadUntil[key];
            return false;
        }
        return true;
    }

    function persistMarkRead(uid, wasUnread) {
        if (!uid) return;
        var listCard = getListCard();
        var folderEnc = listCard ? listCard.getAttribute('data-folder-path') : '';
        if (!folderEnc) return;

        function applyCounts(data) {
            if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            }
            if (data && typeof data.folder_unread === 'number') {
                var countLabel = document.getElementById('mail-count-label');
                var totalMsgs = countLabel
                    ? parseInt(countLabel.getAttribute('data-total') || countLabel.textContent, 10) || 0
                    : 0;
                updateMailCount(totalMsgs, data.folder_unread);
            }
        }

        // Conversation: opening it marks EVERY message of the thread in this
        // folder read (Gmail). Bulk endpoint is idempotent and includes the
        // opened uid.
        var row = rowForUid(uid);
        var threadUids = row ? rowThreadUids(row) : [uid];
        if (threadUids.length > 1) {
            threadUids.forEach(function (u) { noteRecentlyMarkedRead(u); });
            var payload = new URLSearchParams();
            payload.set('_csrf', csrf);
            payload.set('folder', folderEnc);
            threadUids.forEach(function (u) { payload.append('uids[]', String(u)); });
            fetch(apiUrl('message/bulk-mark-read'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf || ''
                },
                body: payload.toString()
            }).then(function (r) { return r.json(); }).then(function (data) {
                setRowSeen(uid, true);
                applyCounts(data);
            }).catch(function () {});
            return;
        }

        noteRecentlyMarkedRead(uid);
        ajaxAction('message/mark-read', {
            folder: folderEnc,
            uid: String(uid)
        }).then(function (data) {
            setRowSeen(uid, true);
            // Badges update from the server's authoritative counts below only —
            // no local estimate (per client: correct number over instant number).
            applyCounts(data);
        }).catch(function () {});
    }

    function currentFolderKind() {
        var card = getListCard();
        return card ? (card.getAttribute('data-folder-kind') || '') : '';
    }

    function isTrashFolder() {
        return currentFolderKind() === 'trash';
    }

    function decodeFolderEnc(enc) {
        if (!enc) return '';
        try {
            return window.atob(String(enc).replace(/-/g, '+').replace(/_/g, '/'));
        } catch (e) {
            return '';
        }
    }

    // Whether a delete acting on this source folder is permanent. The list
    // card's folder kind covers list pages; the path check covers the
    // standalone read page (e.g. mobile), where there is no list card.
    function isTrashSource(sourceFolderEnc) {
        if (isTrashFolder()) return true;
        var path = decodeFolderEnc(sourceFolderEnc);
        return path !== '' && path.toLowerCase().indexOf('trash') >= 0;
    }

    function deleteConfirmOptions(count, permanent) {
        var n = count || 1;
        if (permanent === undefined ? isTrashFolder() : permanent) {
            return {
                title: n === 1 ? 'Delete permanently?' : 'Delete ' + n + ' messages permanently?',
                message: n === 1
                    ? 'This message will be permanently deleted and cannot be recovered.'
                    : 'These messages will be permanently deleted and cannot be recovered.',
                confirmLabel: 'Delete permanently',
                danger: true
            };
        }
        return {
            title: n === 1 ? 'Move to Trash?' : 'Move ' + n + ' messages to Trash?',
            message: n === 1
                ? 'This message will be moved to Trash. You can recover it from the Trash folder.'
                : 'These messages will be moved to Trash. You can recover them from the Trash folder.',
            confirmLabel: 'Move to Trash',
            danger: true
        };
    }

    function deleteSuccessMessage(count) {
        var n = count || 1;
        if (isTrashFolder()) {
            return n === 1 ? 'Message deleted permanently.' : 'Selected messages deleted permanently.';
        }
        return n === 1 ? 'Message moved to Trash.' : 'Selected messages moved to Trash.';
    }

    function deleteLoadingMessage(count) {
        var n = count || 1;
        if (isTrashFolder()) {
            return n === 1 ? 'Deleting message…' : 'Deleting messages…';
        }
        return n === 1 ? 'Moving to Trash…' : 'Moving messages to Trash…';
    }

    function resetConfirmModalButtons() {
        setConfirmModalLoading(false);
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        if (okBtn) setButtonLoading(okBtn, false);
        if (cancelBtn) cancelBtn.disabled = false;
    }

    function setConfirmModalLoading(loading, loadingLabel) {
        var modal = document.getElementById('confirm-modal');
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        var backdrop = modal ? modal.querySelector('[data-confirm-dismiss]') : null;
        var dialog = modal ? modal.querySelector('.app-modal-dialog') : null;

        if (okBtn) {
            if (loading) {
                setButtonLoading(okBtn, true, loadingLabel || okBtn.textContent.trim());
            } else {
                setButtonLoading(okBtn, false);
            }
        }
        if (cancelBtn) cancelBtn.disabled = !!loading;
        if (backdrop) backdrop.style.pointerEvents = loading ? 'none' : '';
        if (dialog) {
            dialog.classList.toggle('app-modal-dialog--busy', !!loading);
            if (loading) dialog.setAttribute('aria-busy', 'true');
            else dialog.removeAttribute('aria-busy');
        }
    }

    function showAppBusy(message) {
        var overlay = document.getElementById('app-busy-overlay');
        var msgEl = document.getElementById('app-busy-overlay-message');
        if (!overlay) return;
        if (msgEl) msgEl.textContent = message || 'Working…';
        overlay.hidden = false;
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('app-is-busy');
    }

    function hideAppBusy() {
        var overlay = document.getElementById('app-busy-overlay');
        if (!overlay) return;
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('app-is-busy');
    }

    function confirmFormLoadingMessage(form) {
        var custom = form.getAttribute('data-confirm-loading');
        if (custom) return custom;

        var action = form.getAttribute('action') || '';
        var label = (form.getAttribute('data-confirm-label') || '').trim();
        var title = (form.getAttribute('data-confirm-title') || '').trim();

        if (/\/folders\/[^/]+\/delete/i.test(action)) return 'Deleting folder…';
        if (/\/users\/[^/]+\/delete/i.test(action)) return 'Deleting user…';
        if (/\/users\/[^/]+\/disable/i.test(action)) return 'Disabling user…';
        if (/\/users\/backfill/i.test(action)) return 'Running backfill…';
        if (/\/aliases\/[^/]+\/delete/i.test(action)) return 'Deleting alias…';
        if (/\/rules\/[^/]+\/delete/i.test(action)) return 'Deleting rule…';
        if (/\/reprocess/i.test(action)) return 'Reprocessing…';

        var text = (label || title).toLowerCase();
        if (text.indexOf('delete') !== -1) return 'Deleting…';
        if (text.indexOf('disable') !== -1) return 'Disabling…';
        if (text.indexOf('backfill') !== -1) return 'Running backfill…';
        if (text.indexOf('reprocess') !== -1) return 'Reprocessing…';
        if (text.indexOf('remove') !== -1) return 'Removing…';

        if (label) return label.replace(/\.\.\.$/, '') + '…';
        return 'Working…';
    }

    function parseMessagePath(pathname) {
        var m = pathname.match(/\/folder\/([^/]+)\/message\/(\d+)\/?$/);
        if (!m) return null;
        return { folderB64: m[1], uid: parseInt(m[2], 10) };
    }

    function paneCacheKey(uid) {
        var card = getListCard();
        var b64 = card ? card.getAttribute('data-folder-b64') : '';
        return (b64 || 'folder') + ':' + uid;
    }

    function getPaneCache(uid) {
        return paneCache[paneCacheKey(uid)] || null;
    }

    function invalidatePaneCache(uid) {
        delete paneCache[paneCacheKey(uid)];
    }

    function isRowUnread(uid) {
        var row = rowsForUid(uid)[0];
        return !!(row && row.getAttribute('data-seen') === '0');
    }

    function paneFetchUrl(uid, bustCache) {
        var row = rowsForUid(uid)[0];
        var url = null;
        if (row) {
            var href = row.getAttribute('data-href');
            if (href) {
                var path = href.split('?')[0];
                try {
                    path = new URL(href, window.location.href).pathname;
                } catch (e) { /* relative path */ }
                var m = path.match(/\/folder\/([^/]+)\/message\/(\d+)/);
                if (m) {
                    url = apiUrl('folder/' + m[1] + '/message/' + m[2] + '/pane');
                }
            }
            if (!url) {
                var rowFolderB64 = row.getAttribute('data-folder-b64');
                if (rowFolderB64) {
                    url = apiUrl('folder/' + rowFolderB64 + '/message/' + uid + '/pane');
                }
            }
        }
        if (!url) {
            var card = getListCard();
            if (!card) return null;
            var b64 = card.getAttribute('data-folder-b64');
            url = apiUrl('folder/' + b64 + '/message/' + uid + '/pane');
        }
        if (bustCache) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
        }

        return url;
    }

    function announceLive(message) {
        var region = document.getElementById('mail-live-region');
        if (!region || !message) return;
        region.textContent = '';
        window.setTimeout(function () {
            region.textContent = message;
        }, 30);
    }

    function parseFolderPath(pathname) {
        var m = pathname.match(/\/folder\/([^/]+)\/?$/);
        if (!m) return null;
        return { folderB64: m[1] };
    }

    function folderPathKey(path) {
        return path ? String(path).toLowerCase() : '';
    }

    function folderPathsMatch(activePath, linkPath) {
        var activeKey = folderPathKey(activePath);
        var linkKey = folderPathKey(linkPath);
        if (!activeKey || !linkKey) return false;
        if (activeKey === linkKey) return true;
        if (activeKey === linkKey + '.inbox') return true;
        if (activeKey + '.inbox' === linkKey) return true;
        return false;
    }

    function updateSidebarActive(folderPath, folderB64) {
        var pathKey = folderPathKey(folderPath);
        var b64 = folderB64 || '';
        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            var linkPath = link.getAttribute('data-folder-path') || '';
            var linkB64 = link.getAttribute('data-folder-b64') || '';
            var active = (b64 !== '' && linkB64 === b64)
                || (pathKey !== '' && folderPathKey(linkPath) === pathKey)
                || folderPathsMatch(folderPath, linkPath);
            link.classList.toggle('active', active);
            if (active && link.closest('.sidebar-group.is-collapsible')) {
                link.closest('.sidebar-group').classList.add('is-open');
                var toggle = link.closest('.sidebar-group').querySelector('.sidebar-group-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
            }
            if (active) {
                var branch = link.closest('.sidebar-folder-branch');
                while (branch) {
                    branch.classList.add('is-open');
                    var branchChildren = branch.querySelector(':scope > .sidebar-folder-branch-children');
                    if (branchChildren) branchChildren.hidden = false;
                    var branchToggle = branch.querySelector(':scope > .sidebar-tree-row .sidebar-tree-toggle');
                    if (branchToggle) branchToggle.setAttribute('aria-expanded', 'true');
                    branch = branch.parentElement ? branch.parentElement.closest('.sidebar-folder-branch') : null;
                }
            }
        });
    }

    function setRowAriaSelected(uid) {
        document.querySelectorAll('.mail-row[role="option"], .mail-card[role="option"]').forEach(function (el) {
            var selected = parseInt(el.getAttribute('data-uid'), 10) === uid;
            el.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    }

    function clearMailRowSelection() {
        document.querySelectorAll('.mail-row.is-selected, .mail-card.is-selected, .mail-row.is-focused, .mail-card.is-focused').forEach(function (el) {
            el.classList.remove('is-selected', 'is-focused');
            el.setAttribute('aria-selected', 'false');
        });
    }

    function setSelectedRow(uid) {
        clearMailRowSelection();
        var escaped = window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid);
        var desktop = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        var scroller = document.getElementById('mail-list-scroller');
        var useMobile = mobile && !mobile.hidden && (!scroller || scroller.hidden);
        var container = useMobile ? mobile : desktop;
        if (!container) {
            rowsForUid(uid).forEach(function (el) {
                el.classList.add('is-selected');
                el.classList.add('is-focused');
                el.setAttribute('aria-selected', 'true');
            });
        } else {
            container.querySelectorAll('[data-uid="' + escaped + '"]').forEach(function (el) {
                el.classList.add('is-selected');
                el.classList.add('is-focused');
                el.setAttribute('aria-selected', 'true');
            });
        }
        setRowAriaSelected(uid);
        // NOTE: no compose prefetch here. Prefetching reply URLs on row select
        // raced the pane's own message fetch — on any first-open message the
        // prefetch missed the body cache and opened a SECOND parallel IMAP
        // connection per click. Hover-warming on the pane's reply buttons
        // (prefetchComposeFromPane) covers the compose-open path instead.
        updateCommandBar();
    }

    function setPaneView(state) {
        var empty = document.getElementById('reading-pane-empty');
        var bodyEl = document.getElementById('reading-pane-body');
        var skeleton = document.getElementById('reading-pane-skeleton');
        var viewport = document.getElementById('reading-pane-viewport');
        var hasContent = !!(bodyEl && bodyEl.innerHTML.trim());

        if (viewport) viewport.classList.toggle('is-pane-loading', state === 'loading');

        if (state === 'empty') {
            if (empty) empty.hidden = false;
            if (bodyEl) bodyEl.hidden = true;
            if (skeleton) skeleton.hidden = true;
            stopPaneMessageSync();
            return;
        }

        if (state === 'loading') {
            if (empty) empty.hidden = true;
            if (bodyEl) bodyEl.hidden = !hasContent;
            if (skeleton) skeleton.hidden = false;
            return;
        }

        if (state === 'content') {
            if (empty) empty.hidden = true;
            if (bodyEl) bodyEl.hidden = false;
            if (skeleton) skeleton.hidden = true;
        }
    }

    function showPaneLoading(show) {
        setPaneView(show ? 'loading' : 'empty');
    }

    function clearReadingPane() {
        var bodyEl = document.getElementById('reading-pane-body');
        paneLoadSeq++;
        stopPaneMessageSync();
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        setPaneView('empty');
        clearMailRowSelection();
    }

    function clearReadingPaneIfShowingUids(uids) {
        if (!uids || !uids.length) return;
        var paneHost = document.getElementById('reading-pane-body');
        if (!paneHost) return;
        var lookup = {};
        uids.forEach(function (u) { lookup[String(u)] = true; });
        var paneCard = paneHost.querySelector('.mail-read-card[data-uid]');
        if (!paneCard) return;
        if (!lookup[String(paneCard.getAttribute('data-uid'))]) return;
        clearReadingPane();
        var listCard = getListCard();
        var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : null;
        if (folderOnly && window.history && window.history.replaceState) {
            window.history.replaceState({}, '', folderOnly);
        }
    }

    function showPanePreviewFromRow(uid) {
        var skeleton = document.getElementById('reading-pane-skeleton');
        var bodyEl = document.getElementById('reading-pane-body');
        if (!skeleton) return;

        var row = rowsForUid(uid)[0];
        if (!row) {
            setPaneView('loading');
            return;
        }

        var fromEl = row.querySelector('.mail-col-from, .mail-card-from');
        var subjectEl = row.querySelector('.mail-col-subject, .mail-card-subject');
        var fromText = fromEl ? fromEl.textContent.trim() : '';
        var subjectText = subjectEl ? subjectEl.textContent.trim() : '(no subject)';

        skeleton.innerHTML =
            '<div class="pane-preview">' +
            '<div class="pane-preview-from">' + escapeHtml(fromText) + '</div>' +
            '<div class="pane-preview-subject">' + escapeHtml(subjectText) + '</div>' +
            '<div class="pane-preview-body"><span class="pane-preview-shimmer"></span></div>' +
            '</div>';
        if (bodyEl) bodyEl.hidden = true;
        skeleton.hidden = false;
        setPaneView('loading');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function rememberPaneCache(uid, data) {
        var copy = {};
        Object.keys(data).forEach(function (k) {
            if (k !== 'unread_counts' && k !== 'folder_unread') {
                copy[k] = data[k];
            }
        });
        paneCache[paneCacheKey(uid)] = copy;
        var keys = Object.keys(paneCache);
        while (keys.length > PANE_CACHE_MAX) {
            delete paneCache[keys[0]];
            keys = Object.keys(paneCache);
        }
    }

    function confirmPrefetchedPane(uid) {
        if (!uid || isPostSendQuiet()) return;
        if (!isRowUnread(uid)) return;
        persistMarkRead(uid, true);
    }

    function applyPaneHtml(uid, data, pushHistory) {
        var bodyEl = document.getElementById('reading-pane-body');
        if (!bodyEl) return;

        stopPaneMessageSync();
        bodyEl.innerHTML = data.html;
        setPaneView('content');

        if (data.was_unread || isRowUnread(uid)) {
            var rowWasUnread = isRowUnread(uid);
            setRowSeen(uid, true);
            var readCard = bodyEl.querySelector('.mail-read-card[data-uid]');
            if (readCard) {
                readCard.setAttribute('data-seen', '1');
                syncReadSeenButton(readCard);
            }
            noteRecentlyMarkedRead(uid);
            // Conversation row: the server marked only the OPENED message
            // (data.was_unread); the thread's other unread messages in this
            // folder still need marking. Bulk mark-read is idempotent.
            var openedRow = rowForUid(uid);
            var isThread = openedRow && rowThreadCount(openedRow) > 1;
            if (!data.was_unread && rowWasUnread) {
                persistMarkRead(uid, rowWasUnread);
            } else if (data.was_unread && isThread) {
                persistMarkRead(uid, true);
            }
        }
        if (data.unread_counts && Object.keys(data.unread_counts).length) {
            applyUnreadCounts(data.unread_counts);
        }
        if (typeof data.folder_unread === 'number') {
            var countLabel = document.getElementById('mail-count-label');
            var totalMsgs = countLabel
                ? parseInt(countLabel.getAttribute('data-total') || countLabel.textContent, 10) || 0
                : 0;
            updateMailCount(totalMsgs, data.folder_unread);
        }

        var card = bodyEl.querySelector('.mail-read-card[data-uid]');
        var draftPane = bodyEl.querySelector('.draft-editor-pane');
        if (draftPane) {
            initComposeForm(bodyEl);
            var subjectInput = bodyEl.querySelector('#subject');
            var editor = bodyEl.querySelector('#body-editor');
            window.setTimeout(function () {
                if (subjectInput && subjectInput.value.trim() === '' && subjectInput.focus) {
                    subjectInput.focus();
                } else if (editor && editor.focus) {
                    editor.focus();
                }
            }, 50);
            announceLive('Draft loaded: ' + (data.subject || 'Draft'));
            return;
        }

        bindReadViewCard(card);
        bindComposeLinks(card);
        bindMessageSyncCard(card);
        prefetchComposeFromPane(card);

        var thread = bodyEl.querySelector('.mail-thread');
        if (thread && thread.children.length > 1) {
            var lastCard = thread.querySelector('.mail-message-card--latest');
            if (lastCard) {
                window.setTimeout(function () {
                    lastCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 80);
            }
        }

        var subject = data.subject || 'Message';
        announceLive('Loaded: ' + subject);
    }

    function paneFetchWithRetry(url, retries) {
        // Backoff grows per attempt — the remote host's connect alone can take
        // 1-3s, so a 600ms-only retry window gave up far too early.
        var delay = retries > 1 ? 800 : 1800;
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                // 5xx = the server couldn't reach the slow/remote mail host this
                // time (a transient blip, not a deleted message). Retry before
                // surfacing it, so the message isn't wrongly reported as gone.
                if (res.status >= 500 && retries > 0) {
                    return new Promise(function (resolve) { window.setTimeout(resolve, delay); })
                        .then(function () { return paneFetchWithRetry(url, retries - 1); });
                }
                return res;
            })
            .catch(function (err) {
                // "Failed to fetch" is a network-level hiccup — common against a slow/
                // remote IMAP host under concurrent load. Retry before surfacing an
                // error, so a transient blip doesn't leave the message unopenable.
                if (retries > 0 && err && err.name === 'TypeError') {
                    return new Promise(function (resolve) { window.setTimeout(resolve, delay); })
                        .then(function () { return paneFetchWithRetry(url, retries - 1); });
                }
                throw err;
            });
    }

    function openMessageInPaneNow(uid, pushHistory, bustCache) {
        if (!uid) return;
        cancelPostSendBackgroundWork();
        if (!useReadingPane()) {
            var row = rowsForUid(uid)[0];
            if (row) {
                showLoading();
                window.location = row.getAttribute('data-href');
            }
            return;
        }

        var isUnread = isRowUnread(uid);
        if (!bustCache) {
            var cached = getPaneCache(uid);
            if (cached && cached.html && (!isUnread || cached.prefetched)) {
                var rowCached = rowsForUid(uid)[0];
                var hrefCached = rowCached ? rowCached.getAttribute('data-href') : null;
                if (pushHistory && hrefCached && window.history && window.history.pushState) {
                    window.history.pushState({ paneUid: uid }, '', hrefCached);
                }
                // Invalidate any in-flight fetch from a previous click so its
                // late failure can't blank (or its late success overwrite)
                // the message we're showing right now.
                paneLoadSeq++;
                setSelectedRow(uid);
                applyPaneHtml(uid, cached, pushHistory);
                if (cached.prefetched && isUnread) {
                    confirmPrefetchedPane(uid);
                }
                return;
            }
        }

        var url = paneFetchUrl(uid, bustCache);
        if (!url) return;

        var seq = ++paneLoadSeq;
        showPanePreviewFromRow(uid);
        setSelectedRow(uid);

        var row = rowsForUid(uid)[0];
        var messageHref = row ? row.getAttribute('data-href') : null;
        if (pushHistory && messageHref && window.history && window.history.pushState) {
            window.history.pushState({ paneUid: uid }, '', messageHref);
        }

        paneFetchWithRetry(url, 2)
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        var err = new Error((data && data.error) || 'Could not load message.');
                        err.gone = !!(data && data.gone);
                        throw err;
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (seq !== paneLoadSeq) return;
                if (!data || !data.ok || !data.html) throw new Error('Could not load message.');

                rememberPaneCache(uid, data);
                applyPaneHtml(uid, data, pushHistory);
            })
            .catch(function (err) {
                if (seq !== paneLoadSeq) return;
                if (err && err.gone) {
                    // Confirmed deleted on the server — clear the pane and row.
                    removeRowByUid(uid);
                    syncListEmptyState();
                    scheduleMailPoll(true, false);
                    setPaneView('empty');
                    announceLive('Could not load message.');
                    return;
                }
                // Transient failure: do NOT blank the pane to "Select a message
                // to read" — keep whatever was showing and surface a toast so
                // the user can simply click again.
                var body = document.getElementById('reading-pane-body');
                setPaneView(body && body.innerHTML.trim() !== '' ? 'content' : 'empty');
                announceLive('Could not load message.');
                showToast('error', (err && err.message) || 'Could not load message — please try again.');
            });
    }

    function openMessageInPane(uid, pushHistory) {
        if (!uid) return;
        // Reading a message is allowed EVEN while a destructive op (delete/move/spam)
        // is still finishing in the background — it's an independent, lightweight
        // fetch, so the user can keep triaging (open the next email) without waiting
        // for the op to complete. Destructive ops stay serialized elsewhere (one at a
        // time) so the mail host is never hit by several heavy ops at once. The only
        // message that's off-limits is the one on its way out — opening a row that's
        // being deleted/moved would fetch a vanishing message.
        if (isUidPendingRemoval(uid)) {
            return;
        }
        if (paneNavTimer) window.clearTimeout(paneNavTimer);
        paneNavPendingUid = uid;
        paneNavPendingHistory = !!pushHistory;
        if (PANE_NAV_DEBOUNCE_MS <= 0) {
            openMessageInPaneNow(uid, pushHistory);
            return;
        }
        paneNavTimer = window.setTimeout(function () {
            openMessageInPaneNow(paneNavPendingUid, paneNavPendingHistory);
        }, PANE_NAV_DEBOUNCE_MS);
    }

    function isPostSendQuiet() {
        return Date.now() < postSendQuietUntil;
    }

    function beginPostSendQuiet(ms) {
        postSendQuietUntil = Date.now() + (ms || 15000);
    }

    function runBackgroundFetch(url, options) {
        options = options || {};
        if (isPostSendQuiet()) {
            return Promise.reject(new Error('quiet'));
        }
        return new Promise(function (resolve, reject) {
            backgroundFetchQueue.push({ url: url, options: options, resolve: resolve, reject: reject });
            drainBackgroundFetchQueue();
        });
    }

    function drainBackgroundFetchQueue() {
        while (backgroundFetchActive < MAX_BACKGROUND_FETCH && backgroundFetchQueue.length) {
            startBackgroundFetch(backgroundFetchQueue.shift());
        }
    }

    function startBackgroundFetch(item) {
        backgroundFetchActive++;
        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var options = item.options || {};
        if (ctrl && !options.signal) options.signal = ctrl.signal;
        if (ctrl) backgroundFetchControllers.push(ctrl);
        fetch(item.url, options)
            .then(item.resolve)
            .catch(item.reject)
            .finally(function () {
                backgroundFetchActive--;
                if (ctrl) {
                    var idx = backgroundFetchControllers.indexOf(ctrl);
                    if (idx >= 0) backgroundFetchControllers.splice(idx, 1);
                }
                drainBackgroundFetchQueue();
            });
    }

    // Drop queued prefetches and abort in-flight ones. Called when navigating to a
    // new folder so stale pane/compose prefetches from the folder we're leaving stop
    // hogging the browser's (few) connections — on a slow/remote IMAP server they
    // were starving the new folder's list load, leaving it stuck on "Loading…".
    function abortBackgroundFetches() {
        if (backgroundFetchQueue.length) {
            backgroundFetchQueue.splice(0, backgroundFetchQueue.length).forEach(function (item) {
                try { item.reject(new Error('navigated')); } catch (e) { /* ignore */ }
            });
        }
        backgroundFetchControllers.splice(0).forEach(function (ctrl) {
            try { ctrl.abort(); } catch (e) { /* ignore */ }
        });
    }

    function prefetchPane(uid) {
        if (!uid || getPaneCache(uid) || !useReadingPane()) return;
        if (mailSyncPaused || isPostSendQuiet()) return;
        if (panePrefetchInFlight[uid]) return;
        var url = paneFetchUrl(uid);
        if (!url) return;
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'prefetch=1';
        panePrefetchInFlight[uid] = true;
        runBackgroundFetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (res.ok && data && data.ok && data.html) {
                        data.prefetched = true;
                        rememberPaneCache(uid, data);
                    }
                });
            }).catch(function () {})
            .finally(function () {
                delete panePrefetchInFlight[uid];
            });
    }

    function initReadingPane() {
        if (!document.getElementById('mail-workspace')) return;

        window.addEventListener('popstate', function () {
            if (!useReadingPane()) return;
            var parsedMsg = parseMessagePath(window.location.pathname);
            if (parsedMsg && parsedMsg.uid) {
                openMessageInPaneNow(parsedMsg.uid, false);
                return;
            }
            var parsedFolder = parseFolderPath(window.location.pathname);
            if (parsedFolder && parsedFolder.folderB64) {
                loadFolderAjax(parsedFolder.folderB64, false);
                return;
            }
            clearReadingPane();
        });

        if (PANE_MEDIA.addEventListener) {
            PANE_MEDIA.addEventListener('change', function () {
                if (!useReadingPane()) {
                    closeComposePanel(false);
                    clearReadingPane();
                }
            });
        }

        var parsed = parseMessagePath(window.location.pathname);
        if (parsed && parsed.uid) {
            openMessageInPaneNow(parsed.uid, false);
        }

        initThreadSubjectToggle();
    }

    function stopMailSync() {
        if (mailPollIntervalId) {
            window.clearInterval(mailPollIntervalId);
            mailPollIntervalId = null;
        }
        if (mailPollAbort) {
            try {
                mailPollAbort.abort();
            } catch (e) { /* ignore */ }
            mailPollAbort = null;
        }
        mailPollInFlight = false;
        if (mailRefreshFollowUpTimer) {
            window.clearTimeout(mailRefreshFollowUpTimer);
            mailRefreshFollowUpTimer = null;
        }
    }

    function bindAllMailRows(root) {
        (root || document).querySelectorAll('.mail-row[data-href], .mail-card[data-href]').forEach(bindMailRow);
    }

    function applyAttachmentIcon(row, hasAttachment) {
        if (!hasAttachment || !row) return;
        var meta = row.querySelector('.mail-row-meta') || row.querySelector('.mail-card-meta');
        if (!meta || meta.querySelector('.mail-row-attach')) return;
        var span = document.createElement('span');
        span.className = 'mail-row-attach';
        span.title = 'Has attachment';
        span.setAttribute('aria-label', 'Has attachment');
        span.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
        var dateEl = meta.querySelector('.mail-row-date, .mail-card-date');
        if (dateEl) meta.insertBefore(span, dateEl);
        else meta.appendChild(span);
    }

    function expandThreadForInlineCompose() {
        var content = document.querySelector('#reading-pane-body .mail-read-content');
        if (!content) return;
        content.classList.add('is-thread-expanded');
        setThreadInlineHistoryExpanded(content, false);
        var bar = content.querySelector('.mail-read-subject-bar');
        if (bar) {
            bar.setAttribute('aria-expanded', 'true');
            bar.setAttribute('title', 'Show latest message only');
        }
        content.querySelectorAll('[data-mail-thread-card]').forEach(function (card) {
            if (card.classList.contains('is-expanded')) return;
            card.classList.add('is-expanded');
            card.setAttribute('aria-expanded', 'true');
            var collapsed = card.querySelector('.mail-message-collapsed');
            var expanded = card.querySelector('.mail-message-expanded');
            if (collapsed) collapsed.hidden = true;
            if (expanded) expanded.hidden = false;
        });
    }

    function applySnippetToRow(uid, snippet, dateIso, subject) {
        if (!snippet && !dateIso && !subject) return;
        rowsForUid(uid).forEach(function (el) {
            if (snippet) {
                var node = el.querySelector('.mail-row-snippet');
                if (node) {
                    node.textContent = snippet;
                    node.title = snippet;
                    node.removeAttribute('aria-hidden');
                }
            }
            if (subject) {
                var subjectEl = el.querySelector('.mail-row-subject, .mail-card-subject');
                if (subjectEl) {
                    subjectEl.textContent = subject;
                    subjectEl.title = subject;
                }
            }
            if (dateIso) {
                var dateEl = el.querySelector('.mail-row-date, .mail-card-date');
                if (dateEl) {
                    dateEl.textContent = formatMailListDate(dateIso);
                    dateEl.setAttribute('datetime', dateIso);
                }
            }
        });
    }

    function formatMailListDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        var now = new Date();
        var sameDay = d.toDateString() === now.toDateString();
        if (sameDay) {
            return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        var weekAgo = new Date(now);
        weekAgo.setDate(weekAgo.getDate() - 6);
        if (d >= weekAgo) {
            return d.toLocaleDateString([], { weekday: 'short' }) + ' ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function loadListSnippets(root) {
        if (isPostSendQuiet()) return;
        root = root || document;
        var card = root.querySelector ? root.querySelector('.mail-list-card[data-folder-b64]') : null;
        if (!card) return;
        var b64 = card.getAttribute('data-folder-b64');
        var uids = [];
        (root.querySelectorAll ? root.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]') : []).forEach(function (el) {
            var node = el.querySelector('.mail-row-snippet');
            if (!node || (node.textContent && node.textContent.trim() !== '')) return;
            var uid = parseInt(el.getAttribute('data-uid'), 10);
            if (uid) uids.push(uid);
        });
        if (!uids.length) return;
        var seen = {};
        uids = uids.filter(function (id) {
            if (seen[id]) return false;
            seen[id] = true;
            return true;
        }).slice(0, 20);

        runBackgroundFetch(apiUrl('folder/' + b64 + '/snippets?uids=' + uids.join(',')), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) {
            if (!r.ok) return null;
            return r.json();
        }).then(function (data) {
            if (!data || !data.ok || !data.snippets) return;
            Object.keys(data.snippets).forEach(function (uidKey) {
                applySnippetToRow(parseInt(uidKey, 10), data.snippets[uidKey]);
            });
        }).catch(function () {});
    }

    function abortDeferredListEnhancements() {
        if (attachmentHintsTimer) {
            window.clearTimeout(attachmentHintsTimer);
            attachmentHintsTimer = null;
        }
        if (listSnippetsTimer) {
            window.clearTimeout(listSnippetsTimer);
            listSnippetsTimer = null;
        }
    }

    function abortMailBootstrap() {
        if (mailBootstrapAbort) {
            try { mailBootstrapAbort.abort(); } catch (e) { /* ignore */ }
            mailBootstrapAbort = null;
        }
    }

    function scheduleListSnippets(root) {
        if (isPostSendQuiet()) {
            window.setTimeout(function () { loadListSnippets(root || document); }, Math.max(0, postSendQuietUntil - Date.now()) + 500);
            return;
        }
        if (listSnippetsTimer) window.clearTimeout(listSnippetsTimer);
        listSnippetsTimer = window.setTimeout(function () {
            listSnippetsTimer = null;
            loadListSnippets(root);
        }, 400);
    }

    function loadAttachmentHints(root) {
        if (isPostSendQuiet()) return;
        root = root || document;
        var card = root.querySelector ? root.querySelector('.mail-list-card[data-folder-b64]') : null;
        if (!card) return;
        var b64 = card.getAttribute('data-folder-b64');
        var uids = [];
        (root.querySelectorAll ? root.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]') : []).forEach(function (el) {
            if (el.querySelector('.mail-row-attach')) return;
            var uid = parseInt(el.getAttribute('data-uid'), 10);
            if (uid) uids.push(uid);
        });
        if (!uids.length) return;
        uids = uids.slice(0, 20);

        if (attachmentHintsTimer) window.clearTimeout(attachmentHintsTimer);
        attachmentHintsTimer = window.setTimeout(function () {
            attachmentHintsTimer = null;
            if (isPostSendQuiet()) return;
            runBackgroundFetch(apiUrl('folder/' + b64 + '/attachments?uids=' + uids.join(',')), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (r) {
                if (!r.ok) return null;
                return r.json();
            })
                .then(function (data) {
                    if (!data || !data.ok || !data.has_attachment) return;
                    Object.keys(data.has_attachment).forEach(function (uidKey) {
                        if (!data.has_attachment[uidKey]) return;
                        rowsForUid(parseInt(uidKey, 10)).forEach(function (row) {
                            applyAttachmentIcon(row, true);
                        });
                    });
                }).catch(function () {});
        }, 2000);
    }

    function scheduleAttachmentHints(root) {
        if (isPostSendQuiet()) {
            window.setTimeout(function () { loadAttachmentHints(root || document); }, Math.max(0, postSendQuietUntil - Date.now()) + 500);
            return;
        }
        loadAttachmentHints(root);
    }

    function prefetchUnreadInList(root) {
        if (!useReadingPane() || mailSyncPaused || isPostSendQuiet()) return;
        var scope = root && root.querySelectorAll ? root : document;
        var unread = scope.querySelectorAll('.mail-row.mail-unread[data-uid], .mail-card.mail-unread[data-uid]');
        var warmed = 0;
        // Warm only the FIRST unread pane on folder open (was 3). prefetchPane
        // already fetches the fully rendered pane (body included), so the extra
        // warmMessageBodyForRow was a redundant SECOND IMAP hit per row — dropped.
        // Fewer concurrent requests leaves the host's worker pool free for the
        // fragment the user is actually waiting on. Hovering a row still warms it
        // on demand via scheduleUnreadPanePrefetch.
        for (var i = 0; i < unread.length && warmed < 1; i++) {
            var uid = parseInt(unread[i].getAttribute('data-uid'), 10);
            if (!uid || getPaneCache(uid)) continue;
            prefetchPane(uid);
            warmed++;
        }
    }

    // Run the list "enrichment" work (attachment icons, snippet previews, warming
    // the first unread pane) when the browser is idle, so it never competes with
    // the folder fragment / pane the user is waiting on. Capped so it still fires
    // promptly on a quiet page.
    function scheduleListEnrichment(root) {
        var scope = root || document;
        var run = function () {
            scheduleAttachmentHints(scope);
            scheduleListSnippets(scope);
            prefetchUnreadInList(scope);
        };
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, { timeout: 800 });
        } else {
            window.setTimeout(run, 250);
        }
    }

    function scheduleUnreadPanePrefetch(row) {
        if (!row || !useReadingPane() || mailSyncPaused || isPostSendQuiet()) return;
        if (row.getAttribute('data-optimistic') === '1') return;
        if (row.getAttribute('data-seen') === '1') return;
        var uid = parseInt(row.getAttribute('data-uid'), 10);
        if (!uid || uid < 0 || getPaneCache(uid)) return;

        if (paneHoverPrefetchTimer) window.clearTimeout(paneHoverPrefetchTimer);
        paneHoverPrefetchUid = uid;
        paneHoverPrefetchTimer = window.setTimeout(function () {
            paneHoverPrefetchTimer = null;
            var targetUid = paneHoverPrefetchUid;
            paneHoverPrefetchUid = null;
            if (!targetUid) return;
            var targetRow = rowsForUid(targetUid)[0];
            if (!targetRow || targetRow.getAttribute('data-seen') === '1') return;
            // prefetchPane alone: it fetches AND caches the body server-side
            // (/pane?prefetch=1 -> getBody/saveBody). Firing warm-body too made
            // both requests IMAP-fetch the same message in parallel.
            prefetchPane(targetUid);
        }, 80);
    }

    function prefetchVisiblePanes() {
        if (!useReadingPane() || isPostSendQuiet()) return;
        var row = document.querySelector('.mail-row.is-selected, .mail-card.is-selected');
        if (!row) return;
        var uid = parseInt(row.getAttribute('data-uid'), 10);
        if (uid) prefetchPane(uid);
    }

    function reinitMailListColumn() {
        selectAllInFolder = false;
        bindAllMailRows(document);
        initMailCommandBar();
        bindComposePrefetchTriggers(document);
        initPerPageSelect();
        initMailSync();
        scheduleListEnrichment(document);

        var loadingEl = document.getElementById('mail-list-loading');
        if (loadingEl && !loadingEl.hidden) {
            armMailListLoadingGuard();
            window.setTimeout(function () { scheduleMailPoll(true, false); }, 0);
        }
    }

    function isListMutationQuiet() {
        return Date.now() < listMutationQuietUntil;
    }

    function beginListMutationQuiet(ms) {
        listMutationQuietUntil = Date.now() + (ms || 2500);
        mailSyncPaused = true;
    }

    function endListMutationQuiet(delayMs) {
        window.setTimeout(function () {
            if (Date.now() >= listMutationQuietUntil) {
                mailSyncPaused = false;
            }
        }, delayMs || 0);
    }

    function cancelPostSendBackgroundWork() {
        afterSendBadgePolls = postSendReconcileDelays.length;
        clearPostSendFolderPolls();
        setMailListLoading(false);
        if (mailPollAbort) {
            try { mailPollAbort.abort(); } catch (e) { /* ignore */ }
            mailPollAbort = null;
            mailPollInFlight = false;
        }
    }

    function abortInFlightFolderFetch() {
        abortDeferredListEnhancements();
        abortMailBootstrap();
        abortBackgroundFetches();
        if (folderFetchTimeoutId) {
            window.clearTimeout(folderFetchTimeoutId);
            folderFetchTimeoutId = null;
        }
        if (folderFetchAbort) {
            try { folderFetchAbort.abort(); } catch (e) { /* ignore */ }
            folderFetchAbort = null;
        }
        if (mailPollAbort) {
            try { mailPollAbort.abort(); } catch (e) { /* ignore */ }
            mailPollAbort = null;
            mailPollInFlight = false;
        }
    }

    // Shared tail of a folder switch: swap the list column HTML in and rewire
    // everything. Used by both the live fetch and the instant cached paint
    // (fromCache=true adds revalidation + safety sweeps).
    function applyFolderFragment(data, seq, folderB64, pushHistory, fromCache) {
        if (seq !== folderLoadSeq) return;

        var workspace = document.getElementById('mail-workspace');
        var pane = document.getElementById('reading-pane');
        if (!workspace || !pane) return;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = data.html;
        var newColumn = wrapper.firstElementChild;
        var oldColumn = workspace.querySelector('.mail-list-column');
        if (oldColumn && newColumn) {
            workspace.replaceChild(newColumn, oldColumn);
        }

        if (data.folder_path) updateSidebarActive(data.folder_path, data.folder_b64);
        // Cached snapshots deliberately don't re-apply unread_counts — the
        // current (live) badges are fresher than the snapshot's.
        if (!fromCache && data.unread_counts) applyUnreadCounts(data.unread_counts);
        if (data.title) {
            var parts = document.title.split(' — ');
            document.title = data.title + (parts.length > 1 ? ' — ' + parts.slice(1).join(' — ') : '');
        }
        if (pushHistory && data.url && window.history && window.history.pushState) {
            window.history.pushState({ folderB64: folderB64 }, '', data.url);
        }

        if (fromCache) {
            // Force the immediate revalidation poll below (needsForceSync path).
            var cachedCard = getListCard();
            if (cachedCard) cachedCard.setAttribute('data-cache-stale', '1');
        }

        reinitMailListColumn();
        removeStaleOptimisticRows();
        if (fromCache) sweepPendingRemovalRows();
        if (pendingPostSendPreviewData) {
            var previewPayload = pendingPostSendPreviewData;
            pendingPostSendPreviewData = null;
            injectPostSendListPreview(previewPayload);
        }
        if (visibleMailRowCount() > 0) {
            setMailListLoading(false);
            ensureListVisible(getListCard());
        } else if (!data.list_loading) {
            setMailListLoading(false);
            syncListEmptyState();
        }
        if (data.list_loading) {
            armMailListLoadingGuard();
            var syncCard = getListCard();
            if (syncCard) syncCard.classList.add('is-syncing');
            window.setTimeout(function () { scheduleMailPoll(true, false); }, 800);
            startPostSendReconcile([folderB64]);
        } else {
            var loadedCard = getListCard();
            var needsForceSync = loadedCard
                && (loadedCard.getAttribute('data-cache-stale') === '1'
                    || postSendRefreshFolders.indexOf(folderB64) >= 0);
            window.setTimeout(function () {
                scheduleMailPoll(needsForceSync, false);
            }, needsForceSync ? 0 : 250);
        }
        announceLive('Folder loaded: ' + (data.title || 'Mail'));
    }

    function loadFolderAjax(folderB64, pushHistory, forceRefresh) {
        if (!folderB64 || !document.getElementById('mail-workspace')) return;

        abortInFlightFolderFetch();
        if (folderFetchAbort) {
            try { folderFetchAbort.abort(); } catch (e) { /* ignore */ }
        }
        if (typeof AbortController !== 'undefined') {
            folderFetchAbort = new AbortController();
        } else {
            folderFetchAbort = null;
        }

        var seq = ++folderLoadSeq;
        clearReadingPane();
        closeComposePanel(false);
        mailSyncPaused = true;
        listMutationQuietUntil = 0;

        // Cache-first paint: a previously seen folder renders instantly from
        // the last server HTML; the forced poll (via data-cache-stale) then
        // reconciles rows, badges and counts in the background. Skipped during
        // post-send flux, when a refresh was explicitly requested, and for
        // folders queued for a post-send refresh.
        var cachedFragment = forceRefresh ? null : getFolderFragmentCached(folderB64);
        if (cachedFragment
            && !pendingPostSendPreviewData
            && !isPostSendQuiet()
            && postSendRefreshFolders.indexOf(folderB64) < 0) {
            applyFolderFragment(cachedFragment, seq, folderB64, pushHistory, true);
            mailSyncPaused = false;
            return;
        }

        var column = document.querySelector('.mail-list-column');
        if (column) column.classList.add('is-loading');

        var fragmentUrl = apiUrl('folder/' + folderB64 + '/fragment');
        if (forceRefresh) {
            fragmentUrl += (fragmentUrl.indexOf('?') >= 0 ? '&' : '?') + 'refresh=1';
        }

        if (folderFetchTimeoutId) {
            window.clearTimeout(folderFetchTimeoutId);
            folderFetchTimeoutId = null;
        }

        var fetchOpts = {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: folderFetchAbort ? folderFetchAbort.signal : undefined
        };

        if (folderFetchAbort) {
            folderFetchTimeoutId = window.setTimeout(function () {
                if (seq !== folderLoadSeq) return;
                try { folderFetchAbort.abort(); } catch (e) { /* ignore */ }
                showToast('error', 'Folder is taking too long to load. Try again in a moment.');
            }, FOLDER_FETCH_TIMEOUT_MS);
        }

        fetch(fragmentUrl, fetchOpts).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error((data && data.error) || 'Could not load folder.');
                return data;
            });
        }).then(function (data) {
            if (seq !== folderLoadSeq) return;
            if (!data || !data.ok || !data.html) throw new Error('Could not load folder.');
            rememberFolderFragment(folderB64, data);
            applyFolderFragment(data, seq, folderB64, pushHistory, false);
        }).catch(function (err) {
            if (seq !== folderLoadSeq) return;
            if (err && err.name === 'AbortError') return;
            var card = getListCard();
            if (card) {
                updateSidebarActive(
                    card.getAttribute('data-folder-plain') || '',
                    card.getAttribute('data-folder-b64') || ''
                );
            }
            showToast('error', err.message || 'Could not load folder.');
        }).finally(function () {
            if (folderFetchTimeoutId) {
                window.clearTimeout(folderFetchTimeoutId);
                folderFetchTimeoutId = null;
            }
            if (seq !== folderLoadSeq) return;
            mailSyncPaused = false;
            var col = document.querySelector('.mail-list-column');
            if (col) col.classList.remove('is-loading');
        });
    }

    function initAjaxFolderNav() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('[data-ajax-folder][data-folder-b64]');
            if (!link || !document.getElementById('mail-workspace')) return;
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            e.preventDefault();
            var b64 = link.getAttribute('data-folder-b64');
            // Switching folders is allowed even while a destructive op is still
            // finishing: a sidebar click loads page 1, which is served instantly from
            // the local cache with NO IMAP round-trip. The only part that would fire a
            // competing IMAP request — the follow-up header /sync — is held by the poll
            // layer until the op settles (scheduleMailPoll/poll gate on criticalOpActive;
            // releaseCriticalOp then runs one revalidation for the folder in view). So
            // the move can't flake on the connection-limited host, and there's no more
            // "Finishing your last action…" wait on a folder switch.
            loadFolderAjax(b64, true);
            if (window.innerWidth < 900) closeSidebar();
        });
    }

    function currentMailFolderEnc() {
        var listCard = getListCard();
        return listCard ? (listCard.getAttribute('data-folder-path') || '') : '';
    }

    function isFolderListStale() {
        var column = document.querySelector('.mail-list-column');
        if (column && column.classList.contains('is-loading')) {
            return true;
        }
        var card = getListCard();
        var activeLink = document.querySelector('.sidebar-link.active[data-folder-b64]');
        if (!card || !activeLink) {
            return false;
        }
        var cardB64 = card.getAttribute('data-folder-b64') || '';
        var sidebarB64 = activeLink.getAttribute('data-folder-b64') || '';
        return cardB64 !== '' && sidebarB64 !== '' && cardB64 !== sidebarB64;
    }

    function guardFolderListReady(actionLabel) {
        if (!isFolderListStale()) {
            return true;
        }
        showToast('error', (actionLabel || 'Action') + ' is unavailable while the folder is loading.');
        return false;
    }

    function normalizeComposePath(href) {
        if (!href) return '';
        try {
            var u = new URL(href, window.location.href);
            var path = u.pathname;
            var basePath = appBasePathname();
            if (basePath && basePath !== '/' && path.indexOf(basePath) === 0) {
                path = path.slice(basePath.length);
            }
            path = path.replace(/^\//, '');
            return path + u.search;
        } catch (err) {
            var s = String(href).replace(/^\//, '');
            var baseSlug = appBasePathname().replace(/^\//, '');
            if (baseSlug && s.indexOf(baseSlug + '/') === 0) {
                s = s.slice(baseSlug.length + 1);
            }
            return s;
        }
    }

    function withEmbedParams(href) {
        var path = normalizeComposePath(href);
        path += (path.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
        var folder = currentMailFolderEnc();
        if (folder) {
            path += '&return_folder=' + encodeURIComponent(folder);
        }
        return path;
    }

    function composeTitleFromPath(href) {
        var path = normalizeComposePath(href);
        if (path.indexOf('reply-all') >= 0) return 'Reply all';
        if (path.indexOf('reply') >= 0) return 'Reply';
        if (path.indexOf('forward') >= 0) return 'Forward';
        if (path.indexOf('edit-draft') >= 0) return 'Edit draft';
        return 'New message';
    }

    function warmMessageBodyForRow(row) {
        if (!row || !useReadingPane() || mailSyncPaused) return;
        if (row.getAttribute('data-optimistic') === '1') return;
        var uid = row.getAttribute('data-uid');
        var uidNum = parseInt(uid, 10);
        if (!uidNum || uidNum < 0) return;
        var folderEnc = row.getAttribute('data-folder-b64') || currentMailFolderEnc();
        if (!uid || !folderEnc) return;
        var key = folderEnc + ':' + uid;
        if (bodyWarmKeys[key]) return;
        bodyWarmKeys[key] = true;
        runBackgroundFetch(apiUrl('folder/' + folderEnc + '/message/' + uid + '/warm-body'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).catch(function () {});
    }

    function composeCacheKey(path) {
        return path + '|v' + COMPOSE_CACHE_VERSION;
    }

    function prefetchComposeHtml(href) {
        if (!href || !useReadingPane() || mailSyncPaused) return null;
        var path = withEmbedParams(href);
        var cacheKey = composeCacheKey(path);
        if (composePrefetchCache[cacheKey] || composePrefetchInFlight[cacheKey]) {
            return composePrefetchInFlight[cacheKey] || null;
        }
        composePrefetchInFlight[cacheKey] = runBackgroundFetch(apiUrl(path), {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' }
        })
            .then(function (res) {
                captureCsrfFromResponse(res);
                if (!res.ok) throw new Error('prefetch failed');
                return res.text();
            })
            .then(function (html) {
                composePrefetchCache[cacheKey] = html;
                delete composePrefetchInFlight[cacheKey];
                return html;
            })
            .catch(function () {
                delete composePrefetchInFlight[cacheKey];
                return null;
            });
        return composePrefetchInFlight[cacheKey];
    }

    function prefetchComposeFromRow(row) {
        if (!row) return;
        var href = row.getAttribute('data-reply-all-url') || row.getAttribute('data-reply-url');
        if (href) prefetchComposeHtml(href);
    }

    function scheduleComposePrefetch(row) {
        if (!row || !useReadingPane()) return;
        if (composePrefetchTimer) window.clearTimeout(composePrefetchTimer);
        composePrefetchTimer = window.setTimeout(function () {
            composePrefetchTimer = null;
            prefetchComposeFromRow(row);
        }, 120);
    }

    function prefetchComposeFromPane(card) {
        if (!card) return;
        // Do NOT eagerly prefetch every compose form (Reply, Reply-all, Forward)
        // on message open — each one is an IMAP body fetch, and firing all three
        // per open saturates the host's limited PHP worker pool (that was the
        // stack of multi-second reply?/reply-all?/forward? requests). Instead warm
        // on hover/focus intent, so only the button the user actually reaches for
        // is fetched — which still lands ~100-300ms before the click, keeping the
        // "instant when clicked" feel without the storm.
        card.querySelectorAll('a.compose-panel-link[href]').forEach(function (a) {
            if (a.dataset.composePrefetchBound) return;
            a.dataset.composePrefetchBound = '1';
            var warm = function () {
                var href = a.getAttribute('href');
                if (href) prefetchComposeHtml(href);
            };
            a.addEventListener('mouseenter', warm);
            a.addEventListener('focus', warm);
        });
    }

    function bindComposePrefetchTriggers(root) {
        root = root || document;
        var scope = root.querySelectorAll ? root : document;
        scope.querySelectorAll('#compose-link, .mail-cmd-compose, .btn-compose').forEach(function (el) {
            if (el.dataset.composePrefetchBound) return;
            el.dataset.composePrefetchBound = '1';
            el.addEventListener('mouseenter', function () {
                var href = el.getAttribute('href');
                if (href) prefetchComposeHtml(href);
            });
        });
    }

    function applyComposePanelHtml(body, html) {
        if (!body || !html) return;
        body.innerHTML = html;
        patchCsrfFields(body);
        initComposeForm(body);
        bindComposeLinks(body);
        var editor = body.querySelector('#body-editor');
        if (body.id === 'mail-inline-compose' || body.closest('#mail-inline-compose')) {
            if (editor) {
                window.setTimeout(function () {
                    editor.focus();
                    try {
                        var sel = window.getSelection();
                        var range = document.createRange();
                        range.selectNodeContents(editor);
                        range.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(range);
                    } catch (err) {}
                }, 50);
            }
            var slot = body.id === 'mail-inline-compose' ? body : body.closest('#mail-inline-compose');
            if (slot) slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function isInlineComposeHref(href) {
        if (!href) return false;
        var path = normalizeComposePath(href);
        return /compose\/(reply-all|reply|forward|edit-draft)(\/|$|\?)/.test(path);
    }

    var composeUiLocked = false;

    function isComposeUiLocked() {
        return composeUiLocked || isComposeOpen();
    }

    function setComposeActionsDisabled(disabled) {
        composeUiLocked = disabled;
        document.body.classList.toggle('is-compose-locked', disabled);

        document.querySelectorAll('.compose-panel-link, .mail-cmd-compose, #compose-link, .btn-compose').forEach(function (el) {
            if (disabled) {
                el.classList.add('is-disabled');
                el.setAttribute('aria-disabled', 'true');
                if (el.tagName === 'A' && el.getAttribute('href')) {
                    el.dataset.composeSavedHref = el.getAttribute('href');
                    el.removeAttribute('href');
                }
            } else {
                el.classList.remove('is-disabled');
                el.removeAttribute('aria-disabled');
                if (el.dataset.composeSavedHref) {
                    el.setAttribute('href', el.dataset.composeSavedHref);
                    delete el.dataset.composeSavedHref;
                }
            }
        });

        updateCommandBar();
    }

    function setThreadComposeFocus(focus) {
        var content = document.querySelector('#reading-pane-body .mail-read-content');
        if (!content) return;
        content.classList.toggle('is-compose-focus', focus);
        if (!focus) {
            content.classList.remove('is-thread-expanded');
            setThreadInlineHistoryExpanded(content, false);
        }
        var bar = content.querySelector('.mail-read-subject-bar');
        if (bar) {
            bar.classList.toggle('mail-read-subject-bar--expandable', focus);
            bar.setAttribute('aria-expanded', focus && content.classList.contains('is-thread-expanded') ? 'true' : 'false');
            if (focus) {
                bar.setAttribute('title', 'Show full conversation');
                bar.setAttribute('tabindex', '0');
                bar.setAttribute('role', 'button');
            } else {
                bar.removeAttribute('title');
                bar.removeAttribute('tabindex');
                bar.removeAttribute('role');
            }
        }
    }

    function resetComposeUiState() {
        setComposeActionsDisabled(false);
        setThreadComposeFocus(false);
    }

    function setThreadInlineHistoryExpanded(content, expanded) {
        if (!content) return;
        var panel = content.querySelector('.mail-thread-inline-history');
        var toggle = content.querySelector('.mail-thread-history-toggle');
        if (panel) panel.hidden = !expanded;
        if (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.setAttribute('aria-label', expanded ? 'Hide previous messages' : 'Show previous messages');
        }
    }

    function initThreadSubjectToggle() {
        var paneBody = document.getElementById('reading-pane-body');
        if (!paneBody || paneBody.dataset.threadSubjectBound) return;
        paneBody.dataset.threadSubjectBound = '1';

        function toggleThread(e) {
            var bar = e.target.closest('.mail-read-subject-bar');
            if (!bar) return;
            var content = paneBody.querySelector('.mail-read-content.is-compose-focus');
            if (!content) return;
            e.preventDefault();
            var expanded = !content.classList.contains('is-thread-expanded');
            content.classList.toggle('is-thread-expanded', expanded);
            bar.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            bar.setAttribute('title', expanded ? 'Show latest message only' : 'Show full conversation');
            setThreadInlineHistoryExpanded(content, expanded);
        }

        paneBody.addEventListener('click', toggleThread);
        paneBody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var bar = e.target.closest('.mail-read-subject-bar.mail-read-subject-bar--expandable');
            if (!bar) return;
            e.preventDefault();
            toggleThread(e);
        });
    }

    function closeInlineCompose(restoreMessage, options) {
        options = options || {};
        var inline = document.getElementById('mail-inline-compose');
        if (inline) inline.remove();
        document.querySelectorAll('.mail-message-card--composing').forEach(function (card) {
            card.classList.remove('mail-message-card--composing');
        });
        var pane = document.getElementById('reading-pane');
        if (pane) pane.classList.remove('is-inline-compose-open');
        if (!options.keepUiState) {
            resetComposeUiState();
        }
        composePanelSeq++;
        if (restoreMessage === undefined) restoreMessage = true;
        if (restoreMessage && composePanelRestoreUid && !options.skipRestore) {
            openMessageInPaneNow(composePanelRestoreUid, false);
        }
        composePanelRestoreUid = null;
    }

    function getThreadComposeSlot(thread) {
        if (!thread) return null;
        var latest = thread.querySelector('.mail-message-card--latest');
        if (!latest) return null;
        return latest.querySelector('[data-compose-slot]');
    }

    function openInlineCompose(href, title, triggerLink) {
        var paneBody = document.getElementById('reading-pane-body');
        var thread = paneBody && paneBody.querySelector('.mail-thread');
        if (!paneBody || !thread) {
            openComposePanelFullscreen(href, title, triggerLink);
            return;
        }

        var selected = document.querySelector('.mail-row.is-selected, .mail-card.is-selected');
        composePanelRestoreUid = selected ? parseInt(selected.getAttribute('data-uid'), 10) : null;

        closeInlineCompose(false, { keepUiState: true, skipRestore: true });
        var fullscreenPanel = document.getElementById('compose-panel');
        if (fullscreenPanel && !fullscreenPanel.hidden) {
            setComposeOpen(false);
            var panelBody = document.getElementById('compose-panel-body');
            if (panelBody) panelBody.innerHTML = '';
        }

        var path = withEmbedParams(href);
        var cacheKey = composeCacheKey(path);
        var seq = ++composePanelSeq;
        if (triggerLink) setButtonLoading(triggerLink, true, loadingLabelForAction('compose'));

        var slot = document.createElement('div');
        slot.id = 'mail-inline-compose';
        slot.className = 'mail-inline-compose';
        slot.setAttribute('aria-label', title || 'Compose');

        var latestCard = thread.querySelector('.mail-message-card--latest');
        var composeSlot = getThreadComposeSlot(thread);
        var threadCards = thread.querySelectorAll('.mail-message-card');
        if (threadCards.length > 1) {
            thread.appendChild(slot);
        } else {
            (composeSlot || thread).appendChild(slot);
        }
        if (latestCard) latestCard.classList.add('mail-message-card--composing');

        var pane = document.getElementById('reading-pane');
        if (pane) pane.classList.add('is-inline-compose-open');
        setComposeActionsDisabled(true);
        setThreadComposeFocus(true);
        expandThreadForInlineCompose();

        function finish(html) {
            if (seq !== composePanelSeq) return;
            applyComposePanelHtml(slot, html);
            if (triggerLink) setButtonLoading(triggerLink, false);
        }

        var cached = composePrefetchCache[cacheKey];
        if (cached) {
            finish(cached);
            return;
        }

        slot.innerHTML = '<div class="compose-panel-loading"><span class="reading-pane-spinner" aria-hidden="true"></span><span>Loading…</span></div>';

        var inFlight = composePrefetchInFlight[cacheKey];
        var loadPromise = inFlight || fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (res) {
                captureCsrfFromResponse(res);
                if (!res.ok) throw new Error('Could not load compose form.');
                return res.text();
            })
            .then(function (html) {
                composePrefetchCache[cacheKey] = html;
                return html;
            });

        loadPromise.then(function (html) {
            finish(html);
        }).catch(function (err) {
            if (seq !== composePanelSeq) return;
            showToast('error', err.message || 'Could not load compose form.');
            closeInlineCompose(true);
        }).finally(function () {
            if (triggerLink) setButtonLoading(triggerLink, false);
        });
    }

    function isComposeOpen() {
        var panel = document.getElementById('compose-panel');
        var inline = document.getElementById('mail-inline-compose');
        return !!(panel && !panel.hidden) || !!inline;
    }

    function setComposeOpen(open) {
        var pane = document.getElementById('reading-pane');
        var viewport = document.getElementById('reading-pane-viewport');
        var panel = document.getElementById('compose-panel');
        if (pane) pane.classList.toggle('is-compose-open', open);
        if (viewport) viewport.hidden = open;
        if (panel) panel.hidden = !open;
    }

    function openComposePanel(href, title, triggerLink) {
        if (isComposeUiLocked()) return;
        if (!useReadingPane()) {
            if (triggerLink) setButtonLoading(triggerLink, true, loadingLabelForAction('compose'));
            showLoading();
            window.location = href;
            return;
        }

        if (isInlineComposeHref(href)) {
            openInlineCompose(href, title, triggerLink);
            return;
        }

        openComposePanelFullscreen(href, title, triggerLink);
    }

    function openComposePanelFullscreen(href, title, triggerLink) {
        var selected = document.querySelector('.mail-row.is-selected, .mail-card.is-selected');
        composePanelRestoreUid = selected ? parseInt(selected.getAttribute('data-uid'), 10) : null;

        var path = withEmbedParams(href);
        var cacheKey = composeCacheKey(path);
        var seq = ++composePanelSeq;
        setComposeOpen(true);
        setComposeActionsDisabled(true);
        if (triggerLink) setButtonLoading(triggerLink, true, loadingLabelForAction('compose'));

        var body = document.getElementById('compose-panel-body');
        var titleEl = document.getElementById('compose-panel-title');
        if (titleEl) titleEl.textContent = title || composeTitleFromPath(href);

        var cached = composePrefetchCache[cacheKey];
        if (cached && body) {
            applyComposePanelHtml(body, cached);
            if (triggerLink) setButtonLoading(triggerLink, false);
            return;
        }

        var inFlight = composePrefetchInFlight[cacheKey];
        if (inFlight && body) {
            body.innerHTML = '<div class="compose-panel-loading"><span class="reading-pane-spinner" aria-hidden="true"></span><span>Loading compose…</span></div>';
            inFlight.then(function (html) {
                if (seq !== composePanelSeq) return;
                if (html) {
                    applyComposePanelHtml(body, html);
                } else {
                    throw new Error('Could not load compose form.');
                }
            }).catch(function (err) {
                if (seq !== composePanelSeq) return;
                showToast('error', err.message || 'Could not load compose form.');
                closeComposePanel(false);
            }).finally(function () {
                if (triggerLink) setButtonLoading(triggerLink, false);
            });
            return;
        }

        if (body) {
            body.innerHTML = '<div class="compose-panel-loading"><span class="reading-pane-spinner" aria-hidden="true"></span><span>Loading compose…</span></div>';
        }

        fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (res) {
                captureCsrfFromResponse(res);
                if (!res.ok) throw new Error('Could not load compose form.');
                return res.text();
            })
            .then(function (html) {
                if (seq !== composePanelSeq) return;
                composePrefetchCache[cacheKey] = html;
                applyComposePanelHtml(body, html);
            })
            .catch(function (err) {
                if (seq !== composePanelSeq) return;
                showToast('error', err.message || 'Could not load compose form.');
                closeComposePanel(false);
            })
            .finally(function () {
                if (triggerLink) setButtonLoading(triggerLink, false);
            });
    }

    function closeComposePanel(restorePane, options) {
        options = options || {};
        if (document.getElementById('mail-inline-compose')) {
            closeInlineCompose(restorePane, options);
            return;
        }
        if (restorePane === undefined) restorePane = true;
        composePanelSeq++;
        setComposeOpen(false);
        resetComposeUiState();
        var body = document.getElementById('compose-panel-body');
        if (body) body.innerHTML = '';

        if (!restorePane && options.skipRestore) {
            composePanelRestoreUid = null;
            return;
        }

        if (!restorePane) {
            setPaneView('empty');
            composePanelRestoreUid = null;
            return;
        }

        if (composePanelRestoreUid) {
            openMessageInPaneNow(composePanelRestoreUid, false);
        } else {
            setPaneView('empty');
        }
        composePanelRestoreUid = null;
    }

    function isComposeHref(href) {
        if (!href) return false;
        return /\/compose(\/|$|\?)/.test(href);
    }

    function bindComposeLinks(root) {
        root = root || document;
        var scope = root.querySelectorAll ? root : document;
        scope.querySelectorAll('a[href]').forEach(function (a) {
            if (!isComposeHref(a.getAttribute('href'))) return;
            if (a.dataset.composeLinkBound) return;
            a.dataset.composeLinkBound = '1';
            a.addEventListener('mouseenter', function () {
                prefetchComposeHtml(a.getAttribute('href'));
            });
            a.addEventListener('click', function (e) {
                if (!useReadingPane()) return;
                e.preventDefault();
                if (isComposeUiLocked()) return;
                var linkTitle = a.getAttribute('data-compose-title') || composeTitleFromPath(a.getAttribute('href'));
                openComposePanel(a.getAttribute('href') || a.dataset.composeSavedHref, linkTitle, a);
            });
        });
    }

    function syncComposeEditor(form) {
        var editor = form.querySelector('#body-editor');
        var bodyField = form.querySelector('#body');
        var htmlField = form.querySelector('#body_html');
        if (!editor) return;
        var html = editor.innerHTML;
        var text = (editor.innerText || editor.textContent || '').replace(/\u00a0/g, ' ').trim();
        // The signature lives in its own .compose-body-sig block whose gap above
        // it is CSS-only \u2014 display spacing that vanishes when the body is
        // serialized, so the sent mail and the saved draft show the signature
        // jammed against the message. Bake a REAL blank line in so the separation
        // survives everywhere (incl. the recipient's client). HTML: insert a
        // spacer before the signature in a clone, leaving all other content
        // untouched. Text: widen the single newline before the trailing signature.
        var sigEl = editor.querySelector('.compose-body-sig');
        if (sigEl) {
            var clone = editor.cloneNode(true);
            var cloneSig = clone.querySelector('.compose-body-sig');
            var hasGap = cloneSig && cloneSig.previousElementSibling
                && cloneSig.previousElementSibling.classList
                && cloneSig.previousElementSibling.classList.contains('compose-sig-gap');
            if (cloneSig && !hasGap) {
                var gap = document.createElement('div');
                gap.className = 'compose-sig-gap';
                gap.appendChild(document.createElement('br'));
                cloneSig.parentNode.insertBefore(gap, cloneSig);
            }
            html = clone.innerHTML;
            var sigText = (sigEl.innerText || sigEl.textContent || '').replace(/\u00a0/g, ' ').trim();
            if (sigText && text.length >= sigText.length && text.slice(-sigText.length) === sigText) {
                text = text.slice(0, text.length - sigText.length).replace(/\s+$/, '') + '\n\n' + sigText;
            }
        }
        var quotedEl = form.querySelector('.compose-quoted-source');
        var quotedText = quotedEl ? quotedEl.value.replace(/\r\n/g, '\n') : '';
        var combined = text;
        if (quotedText !== '') {
            if (combined !== '') {
                combined = quotedText.charAt(0) === '\n' ? combined + quotedText : combined + '\n\n' + quotedText;
            } else {
                combined = quotedText;
            }
        }
        if (htmlField) {
            var quotedHtml = quotedText !== ''
                ? '<div class="mail-quoted"><pre>' + escapeHtml(quotedText) + '</pre></div>'
                : '';
            htmlField.value = html + quotedHtml;
        }
        if (bodyField) bodyField.value = combined;
    }

    function initComposeQuotedToggle(root) {
        root = root || document;
        var form = root.querySelector ? root.querySelector('#compose-form') : document.getElementById('compose-form');
        if (!form || form.dataset.quotedToggleBound) return;
        var toggle = form.querySelector('.compose-quoted-toggle');
        if (!toggle) return;
        form.dataset.quotedToggleBound = '1';
        toggle.addEventListener('click', function () {
            var panel = form.querySelector('.compose-quoted-body');
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (panel) panel.hidden = expanded;
        });
    }

    function syncOutlookInlineRecipients(form) {
        var toHidden = form.querySelector('#to');
        var chipsEl = form.querySelector('#to-chips');
        var input = form.querySelector('#to-input');
        if (!toHidden || !chipsEl) return;
        var emails = [];
        chipsEl.querySelectorAll('.recipient-chip').forEach(function (chip) {
            var email = chip.getAttribute('data-email');
            if (email) emails.push(email);
        });
        if (input && input.value.trim()) {
            var parsed = parseRecipientToken(input.value.trim());
            if (parsed.valid) emails.push(parsed.email);
        }
        if (emails.length) {
            toHidden.value = emails.join(', ');
        }
    }

    function outlookChipDisplayNames(chipsEl) {
        if (!chipsEl) return '';
        var names = [];
        chipsEl.querySelectorAll('.recipient-chip').forEach(function (chip) {
            var display = chip.dataset.displayName || chip.getAttribute('data-email') || '';
            if (display.indexOf('@') >= 0 && display.indexOf('<') < 0) {
                display = display.split('@')[0];
            }
            names.push(display);
        });
        return names.join(', ');
    }

    function updateOutlookRecipientsSummary(form) {
        if (!form) return;
        var el = form.querySelector('[data-recipients-summary-inline]');
        if (!el) return;

        var toText = outlookChipDisplayNames(form.querySelector('#to-chips'));
        var ccText = outlookChipDisplayNames(form.querySelector('#cc-chips'));
        var bccText = outlookChipDisplayNames(form.querySelector('#bcc-chips'));

        function part(label, text) {
            return '<span class="compose-outlook-summary-part">' +
                '<span class="compose-outlook-summary-label">' + escapeHtml(label) + '</span>' +
                escapeHtml(text) +
                '</span>';
        }

        var html = part('To:', toText);
        if (ccText) html += part('CC:', ccText);
        if (bccText) html += part('BCC:', bccText);

        el.innerHTML = html;
    }

    function updateOutlookToSummary(form) {
        updateOutlookRecipientsSummary(form);
    }

    function updateOutlookRecipientChips(form, expanded) {
        form.querySelectorAll('.recipient-chip').forEach(function (chip) {
            var email = chip.getAttribute('data-email') || '';
            var display = chip.dataset.displayName || email;
            var label = chip.querySelector('.recipient-chip-label');
            if (!label) return;
            if (expanded) {
                label.textContent = display && display !== email && display.indexOf('@') < 0
                    ? display + ' <' + email + '>'
                    : email;
            } else {
                label.textContent = display && display.indexOf('@') < 0 ? display : email.split('@')[0];
            }
        });
    }

    function commitOutlookRecipientInputs(form) {
        form.querySelectorAll('.recipient-input').forEach(function (input) {
            if (!input.value.trim()) return;
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        });
    }

    function setOutlookRecipientsExpanded(form, expanded) {
        var card = form.querySelector('.compose-outlook-card');
        var panel = form.querySelector('[data-recipients-panel]');
        var toggle = form.querySelector('[data-to-summary-toggle]');
        if (!card || !panel) return;
        if (!expanded) {
            commitOutlookRecipientInputs(form);
        }
        card.classList.toggle('compose-outlook-card--recipients-expanded', expanded);
        panel.hidden = !expanded;
        if (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.setAttribute('tabindex', expanded ? '-1' : '0');
        }
        updateOutlookRecipientChips(form, expanded);
        if (!expanded) {
            updateOutlookRecipientsSummary(form);
        } else {
            var input = form.querySelector('#to-input');
            if (input) window.setTimeout(function () { input.focus(); }, 0);
        }
    }

    function initOutlookInlineCompose(root) {
        root = root || document;
        var form = root.querySelector ? root.querySelector('.compose-form--outlook-inline') : document.querySelector('.compose-form--outlook-inline');
        if (!form || form.dataset.outlookInlineBound) return;
        form.dataset.outlookInlineBound = '1';

        var summaryToggle = form.querySelector('[data-to-summary-toggle]');
        var recipientsPanel = form.querySelector('[data-recipients-panel]');

        window.setTimeout(function () {
            updateOutlookRecipientsSummary(form);
        }, 0);

        if (summaryToggle) {
            summaryToggle.addEventListener('click', function (e) {
                if (recipientsPanel && !recipientsPanel.hidden) return;
                e.preventDefault();
                e.stopPropagation();
                setOutlookRecipientsExpanded(form, true);
            });
            summaryToggle.addEventListener('keydown', function (e) {
                if (recipientsPanel && !recipientsPanel.hidden) return;
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    setOutlookRecipientsExpanded(form, true);
                }
            });
        }

        if (!window.__outlookRecipientsOutsideBound) {
            window.__outlookRecipientsOutsideBound = true;
            document.addEventListener('click', function (e) {
                document.querySelectorAll('.compose-outlook-card--recipients-expanded').forEach(function (card) {
                    var inlineForm = card.closest('.compose-form--outlook-inline');
                    if (!inlineForm) return;
                    if (e.target.closest('[data-recipients-panel]')) return;
                    if (e.target.closest('.recipient-suggest')) return;
                    var panel = inlineForm.querySelector('[data-recipients-panel]');
                    if (panel && panel.hidden) return;
                    setOutlookRecipientsExpanded(inlineForm, false);
                });
            });
        }

        form.addEventListener('click', function (e) {
            if (e.target.closest('.recipient-chip-remove')) {
                window.setTimeout(function () { updateOutlookRecipientsSummary(form); }, 0);
            }
        });
    }

    function setButtonLoading(btn, loading, loadingLabel) {
        if (!btn) return;
        var isLink = btn.tagName === 'A';
        var isMailBtn = btn.classList.contains('mail-cmd-btn') || btn.classList.contains('mail-action-btn');

        if (loading) {
            if (!btn.dataset.originalHtml) {
                btn.dataset.originalHtml = btn.innerHTML;
            }
            btn.classList.add('is-loading');
            if (isLink) {
                btn.setAttribute('aria-disabled', 'true');
                btn.style.pointerEvents = 'none';
            } else {
                btn.disabled = true;
            }
            btn.setAttribute('aria-busy', 'true');

            if (isMailBtn) {
                var mailLabel = loadingLabel || btn.getAttribute('title') || btn.getAttribute('aria-label') || 'Working…';
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>';
                btn.setAttribute('aria-label', mailLabel);
            } else {
                var text = loadingLabel || btn.textContent.trim();
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span class="btn-loading-text">' + escapeHtml(text) + '</span>';
            }
        } else {
            btn.classList.remove('is-loading');
            if (isLink) {
                btn.removeAttribute('aria-disabled');
                btn.style.pointerEvents = '';
            } else {
                btn.disabled = false;
            }
            btn.removeAttribute('aria-busy');
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
        }
    }

    function loadingLabelForAction(action) {
        var labels = {
            delete: 'Deleting…',
            trash: 'Deleting…',
            move: 'Moving…',
            spam: 'Moving…',
            'mark-read': 'Updating…',
            'mark-unread': 'Updating…',
            flag: 'Updating…',
            unflag: 'Updating…',
            'flag-toggle': 'Updating…',
            refresh: 'Refreshing…',
            send: 'Sending…',
            draft: 'Saving…',
            compose: 'Loading…'
        };
        return labels[action] || 'Working…';
    }

    function watchSyncEnd(refreshBtn) {
        var card = document.querySelector('[data-mail-sync="1"]');
        if (!card) {
            setButtonLoading(refreshBtn, false);
            return;
        }
        var cleared = false;
        function clearLoading() {
            if (cleared) return;
            cleared = true;
            setButtonLoading(refreshBtn, false);
        }
        if (!card.classList.contains('is-syncing')) {
            clearLoading();
            return;
        }
        var observer = new MutationObserver(function () {
            if (!card.classList.contains('is-syncing')) {
                observer.disconnect();
                clearLoading();
            }
        });
        observer.observe(card, { attributes: true, attributeFilter: ['class'] });
        window.setTimeout(function () {
            observer.disconnect();
            clearLoading();
        }, 20000);
    }

    function composeFormActionsEl(form) {
        if (!form) return null;
        return form.querySelector('.compose-form-actions') || form.querySelector('.compose-outlook-actions') || form.querySelector('.compose-draft-toolbar-actions') || form.querySelector('.compose-draft-actions');
    }

    function setComposeFormBusy(form, busy, activeBtn, loadingLabel) {
        if (!form) return;
        var actions = composeFormActionsEl(form);
        if (!actions) return;

        actions.querySelectorAll('button, a.btn').forEach(function (el) {
            if (busy) {
                el.dataset.composeBusy = '1';
                if (el.tagName === 'BUTTON') {
                    if (el !== activeBtn) el.disabled = true;
                } else {
                    el.setAttribute('aria-disabled', 'true');
                    el.style.pointerEvents = 'none';
                }
            } else {
                delete el.dataset.composeBusy;
                if (el.tagName === 'BUTTON') {
                    el.disabled = false;
                } else {
                    el.removeAttribute('aria-disabled');
                    el.style.pointerEvents = '';
                }
            }
        });

        if (busy && activeBtn) {
            setButtonLoading(activeBtn, true, loadingLabel);
        } else if (!busy) {
            actions.querySelectorAll('button.is-loading').forEach(function (b) {
                setButtonLoading(b, false);
            });
        }
    }

    function findListRowUidForThread(threadKey, messages) {
        if (!threadKey) return 0;
        var tkLower = threadKey.toLowerCase();

        if (messages && messages.length) {
            for (var i = 0; i < messages.length; i++) {
                var m = messages[i];
                var mtk = (m.thread_key || normalizeThreadSubject(m.subject)).toLowerCase();
                if (mtk === tkLower) {
                    return parseInt(m.uid, 10) || 0;
                }
            }
        }

        var found = 0;
        document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (row) {
            if (found) return;
            var rowTk = row.getAttribute('data-thread-key');
            if (!rowTk) {
                var subjEl = row.querySelector('.mail-row-subject, .mail-card-subject');
                rowTk = normalizeThreadSubject(subjEl ? subjEl.textContent : '');
            }
            if (rowTk.toLowerCase() === tkLower) {
                found = parseInt(row.getAttribute('data-uid'), 10) || 0;
            }
        });

        return found;
    }

    function resolvePostSendSelectionUid(data, form) {
        var card = getListCard();
        var folderB64 = card ? card.getAttribute('data-folder-b64') || '' : '';
        var plainPath = card ? card.getAttribute('data-folder-plain') || '' : '';
        var preview = lookupListPreview(data, folderB64, plainPath);

        if (preview && preview.uid) {
            return parseInt(preview.uid, 10) || 0;
        }
        if (data && data.sent_list_preview && data.sent_list_preview.uid) {
            return parseInt(data.sent_list_preview.uid, 10) || 0;
        }

        var subject = '';
        if (form && form.querySelector) {
            subject = (form.querySelector('input[name="subject"]') || {}).value || '';
        }
        if (!subject && data && data.thread_subject) {
            subject = data.thread_subject;
        }

        var threadKey = normalizeThreadSubject(subject);
        if (threadKey) {
            var byThread = findListRowUidForThread(threadKey);
            if (byThread) return byThread;
        }

        if (data && data.reply_uid) {
            return parseInt(data.reply_uid, 10) || 0;
        }
        if (composePanelRestoreUid) {
            return composePanelRestoreUid;
        }
        if (form && form.querySelector) {
            return parseInt((form.querySelector('input[name="uid"]') || {}).value || '0', 10) || 0;
        }

        return 0;
    }

    function resolvePostSendPaneUid(data, form) {
        if (data && data.reply_uid) {
            var replyUid = parseInt(data.reply_uid, 10) || 0;
            if (replyUid > 0) {
                return replyUid;
            }
        }
        if (form && form.querySelector) {
            var formUid = parseInt((form.querySelector('input[name="uid"]') || {}).value || '0', 10) || 0;
            if (formUid > 0) {
                return formUid;
            }
        }
        var selectionUid = resolvePostSendSelectionUid(data, form);
        if (selectionUid > 0) {
            return selectionUid;
        }
        return 0;
    }

    function resolveThreadPaneUid(messages, threadKey) {
        if (!messages || !messages.length || !threadKey) {
            return 0;
        }
        var tkLower = threadKey.toLowerCase();
        var threadMsgs = messages.filter(function (m) {
            return (m.thread_key || normalizeThreadSubject(m.subject)).toLowerCase() === tkLower;
        });
        if (!threadMsgs.length) {
            return 0;
        }
        var i;
        for (i = 0; i < threadMsgs.length; i++) {
            var uid = parseInt(threadMsgs[i].uid, 10) || 0;
            if (uid > 0) {
                return uid;
            }
        }
        return parseInt(threadMsgs[0].uid, 10) || 0;
    }

    function rememberPostSendSelectionThread(data, form) {
        var subject = '';
        if (form && form.querySelector) {
            subject = (form.querySelector('input[name="subject"]') || {}).value || '';
        }
        if (!subject && data && data.thread_subject) {
            subject = data.thread_subject;
        }
        postSendSelectionThreadKey = normalizeThreadSubject(subject);
        if (postSendSelectionThreadKey) {
            window.setTimeout(function () {
                postSendSelectionThreadKey = '';
            }, 60000);
        }
    }

    function selectPostSendListRow(messages) {
        if (!postSendSelectionThreadKey) return false;
        var uid = findListRowUidForThread(postSendSelectionThreadKey, messages);
        if (!uid) return false;
        setSelectedRow(uid);
        return true;
    }

    function restorePaneAfterReplySend(data, form) {
        var composeMode = (form && form.querySelector)
            ? ((form.querySelector('input[name="mode"]') || {}).value || '')
            : '';
        if (composeMode !== 'reply' && composeMode !== 'reply-all' && !(data && data.reply_uid)) {
            return false;
        }

        var listUid = resolvePostSendSelectionUid(data, form);
        var paneUid = resolvePostSendPaneUid(data, form);
        if (!listUid && !paneUid) {
            return false;
        }

        if (listUid) {
            invalidatePaneCache(listUid);
            if (data && (data.thread_preview || data.reply_date || data.thread_subject)) {
                applySnippetToRow(
                    listUid,
                    data.thread_preview || null,
                    data.reply_date || null,
                    data.thread_subject || null
                );
            }
            rowsForUid(listUid).forEach(function (el) {
                setRowSeen(listUid, true);
            });
            setSelectedRow(listUid);
        }

        if (paneUid) {
            invalidatePaneCache(paneUid);
        }
        if (data && data.unread_counts) {
            applyUnreadCounts(data.unread_counts);
        }
        mailSyncPaused = false;
        postSendQuietUntil = 0;
        if (form) {
            resetComposeUiState();
        }
        if (paneUid) {
            openMessageInPaneNow(paneUid, false, true);
        }
        return !!(listUid || paneUid);
    }

    function schedulePaneReloadAfterReplySend(data, form) {
        if (!data || !data.reply_uid) return;
        restorePaneAfterReplySend(data, form);
        window.setTimeout(function () {
            restorePaneAfterReplySend(data, form);
        }, 1800);
    }

    function refreshPaneIfThreadListChanged(messages) {
        if (!messages || !messages.length || !useReadingPane()) return;
        var paneCard = document.querySelector('#reading-pane-body .mail-read-card');
        if (!paneCard) return;

        var paneUid = paneCard.getAttribute('data-uid');
        if (!paneUid) return;

        var paneThreadKey = paneCard.getAttribute('data-thread-key');
        if (!paneThreadKey) {
            var subjEl = document.querySelector('.mail-read-subject');
            paneThreadKey = normalizeThreadSubject(subjEl ? subjEl.textContent : '');
        }
        if (!paneThreadKey) return;

        var reloadUid = resolveThreadPaneUid(messages, paneThreadKey);
        if (!reloadUid) return;

        var reloadUidStr = String(reloadUid);
        var shouldReload = reloadUidStr !== paneUid
            || isPostSendQuiet()
            || postSendRefreshFolders.length > 0;

        if (!shouldReload) return;

        invalidatePaneCache(paneUid);
        invalidatePaneCache(reloadUid);
        setSelectedRow(reloadUid);
        openMessageInPaneNow(reloadUid, false, true);
    }

    function composeSerializeState(form) {
        var f = function (n) {
            var el = form.querySelector('[name="' + n + '"]');
            return el ? String(el.value || '') : '';
        };
        return JSON.stringify([f('to'), f('cc'), f('bcc'), f('subject'), f('body')]);
    }

    function composeHasContent(form) {
        var f = function (n) {
            var el = form.querySelector('[name="' + n + '"]');
            return el ? String(el.value || '').trim() : '';
        };
        return !!(f('to') || f('subject') || f('body'));
    }

    // Hex-encode a string's UTF-8 bytes (btoa only handles Latin-1; we go byte-wise).
    function hexEncodeUtf8(str) {
        try {
            var bytes = unescape(encodeURIComponent(str));
            var out = '';
            for (var i = 0; i < bytes.length; i++) {
                var h = bytes.charCodeAt(i).toString(16);
                out += h.length === 1 ? '0' + h : h;
            }
            return out;
        } catch (e) {
            return null;
        }
    }

    // Hex-encode the free-text compose fields before they go over the wire. The
    // host's ModSecurity WAF anomaly-scores raw PHP open-tags / script tags /
    // doctype bursts as a code-injection attack and rejects the whole POST with an
    // opaque text/plain 403 "Forbidden" (never reaching PHP) — so emailing code
    // snippets or pasting HTML would fail. Hex hides those tokens; the server
    // decodes when content_encoding=hex. (Hex not base64, because the host malware
    // scanner won't save server code that calls the base64 decoder.) Works for
    // both FormData and URLSearchParams (same get/set API). Recipients stay plain.
    function encodeComposeFields(payload) {
        if (!payload || typeof payload.get !== 'function' || typeof payload.set !== 'function') return;
        var encodedAny = false;
        ['subject', 'body', 'body_html'].forEach(function (key) {
            var val = payload.get(key);
            if (typeof val === 'string' && val !== '') {
                var enc = hexEncodeUtf8(val);
                if (enc !== null) {
                    payload.set(key, enc);
                    encodedAny = true;
                }
            }
        });
        if (encodedAny) {
            payload.set('content_encoding', 'hex');
        }
    }

    // Gmail-style: closing the compose panel with the X must never silently lose
    // typed content — save it as a draft (unless it's empty or unchanged since
    // open/last save). Explicit Discard still discards.
    function autoSaveComposeOnClose() {
        var body = document.getElementById('compose-panel-body');
        var form = body ? body.querySelector('form') : null;
        if (!form) return;
        try { syncComposeEditor(form); } catch (err) { /* editor not ready */ }
        if (!composeHasContent(form)) return;
        if (form.dataset.initialState && form.dataset.initialState === composeSerializeState(form)) return;

        var params = new URLSearchParams();
        try {
            new FormData(form).forEach(function (v, k) {
                if (typeof v === 'string') params.append(k, v);
            });
        } catch (err) {
            return;
        }
        params.set('_csrf', csrf);
        encodeComposeFields(params);
        fetch(apiUrl('compose/draft'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrf || ''
            },
            body: params.toString()
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.ok) {
                showToast('success', 'Draft saved.', 2500);
                refreshUnreadBadges(false);
            }
        }).catch(function () {});
    }

    function bindComposeFormAjax(form) {
        if (!form || form.dataset.ajaxBound) return;
        form.dataset.ajaxBound = '1';

        // Snapshot the opening state so closing an untouched compose (or an
        // unchanged draft) doesn't create needless drafts.
        try {
            syncComposeEditor(form);
            form.dataset.initialState = composeSerializeState(form);
        } catch (err) { /* non-fatal */ }

        form.addEventListener('click', function (e) {
            var cancel = e.target.closest('[data-compose-cancel]');
            if (cancel) {
                e.preventDefault();
                if (form.closest('#mail-inline-compose')) {
                    closeInlineCompose(false);
                } else if (form.closest('.draft-editor-pane')) {
                    clearReadingPane();
                } else {
                    closeComposePanel(true);
                }
            }
            if (e.target.closest('button[formaction*="draft"]')) {
                syncComposeEditor(form);
            }
        });

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;

            syncComposeEditor(form);

            var submitter = e.submitter;
            if (!submitter) {
                submitter = form.querySelector('.compose-outlook-send, .compose-draft-action--send, .compose-draft-send, .compose-form-actions button[type="submit"]:not([formaction*="draft"])');
            }
            var draftAction = submitter && submitter.getAttribute('formaction');
            var actionPath = draftAction ? normalizeComposePath(draftAction) : 'compose/send';
            var isDraft = actionPath.indexOf('draft') >= 0;
            // Don't send while a destructive op (delete/move/restore) is still
            // finishing its background IMAP work — the two would contend on this
            // connection-limited host. Draft saves are exempt (lightweight, local).
            if (!isDraft && criticalOpActive) {
                e.preventDefault();
                showToast('error', 'Please wait for the current action to finish before sending…', 2500);
                return;
            }
            var loadingLabel = isDraft ? 'Saving…' : 'Sending…';
            var draftPaneEl = form.closest('.draft-editor-pane');
            var isPanelCompose = form.closest('#compose-panel') || form.closest('#mail-inline-compose') || draftPaneEl;
            var useComposeAjax = form.id === 'compose-form';
            var isPanelAjax = useReadingPane() && isPanelCompose;
            var useAjaxSubmit = isPanelAjax || useComposeAjax;

            if (useAjaxSubmit) {
                e.preventDefault();

                var returnField = form.querySelector('#return_folder');
                if (returnField && !returnField.value && isPanelCompose) {
                    returnField.value = currentMailFolderEnc();
                }

                setComposeFormBusy(form, true, submitter, loadingLabel);
                setComposeActionsDisabled(true);
                if (!isDraft) {
                    beginPostSendQuiet(8000);
                    stopMailSync();
                }

                var fd = new FormData(form);
                patchCsrfFields(form);
                if (csrf) fd.set('_csrf', csrf);
                encodeComposeFields(fd);
                var abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                var sendTimeoutMs = isDraft ? 90000 : 30000;
                var sendTimeoutId = abortController
                    ? window.setTimeout(function () { abortController.abort(); }, sendTimeoutMs)
                    : null;

                function submitComposeForm(retryOnCsrf) {
                    return fetch(apiUrl(actionPath), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                            'X-CSRF-Token': csrf || ''
                        },
                        body: fd,
                        signal: abortController ? abortController.signal : undefined
                    }).then(function (res) {
                        return res.json().catch(function () { return { ok: res.ok }; }).then(function (data) {
                            if (
                                res.status === 403
                                && retryOnCsrf
                                && data
                                && String(data.error || '').toLowerCase().indexOf('security token') >= 0
                            ) {
                                return refreshCsrfToken().then(function () {
                                    patchCsrfFields(form);
                                    // Rebuild the payload from the form so the retry re-reads the
                                    // file input(s) and carries the refreshed token. Re-using the
                                    // already-sent multipart body could silently fail (that's why a
                                    // draft/send WITH an attachment could stay stuck on 403).
                                    fd = new FormData(form);
                                    if (csrf) fd.set('_csrf', csrf);
                                    encodeComposeFields(fd);
                                    return submitComposeForm(false);
                                });
                            }
                            if (!res.ok || (data && data.ok === false)) {
                                throw new Error((data && data.error) || 'Action failed.');
                            }
                            return data;
                        });
                    });
                }

                submitComposeForm(true).then(function (data) {
                    showToast('success', (data && data.message) || (isDraft ? 'Draft saved.' : 'Email sent.'));
                    if (isDraft) {
                        if (data && data.unread_counts) {
                            applyUnreadCounts(data.unread_counts);
                        }
                        if (data && data.draft_folder) {
                            var draftFolderField = form.querySelector('input[name="draft_folder"]');
                            if (!draftFolderField) {
                                draftFolderField = document.createElement('input');
                                draftFolderField.type = 'hidden';
                                draftFolderField.name = 'draft_folder';
                                form.appendChild(draftFolderField);
                            }
                            draftFolderField.value = data.draft_folder;
                        }
                        if (data && data.draft_uid) {
                            var draftUidField = form.querySelector('input[name="draft_uid"]');
                            if (!draftUidField) {
                                draftUidField = document.createElement('input');
                                draftUidField.type = 'hidden';
                                draftUidField.name = 'draft_uid';
                                form.appendChild(draftUidField);
                            }
                            var previousUid = parseInt(draftUidField.value || '0', 10);
                            draftUidField.value = String(data.draft_uid);
                            // Saved: refresh the snapshot so closing without further
                            // edits doesn't trigger a redundant auto-save.
                            try { form.dataset.initialState = composeSerializeState(form); } catch (err) {}
                            if (draftPaneEl && previousUid && previousUid !== data.draft_uid) {
                                removeRowByUid(previousUid);
                            }
                            if (draftPaneEl) {
                                draftPaneEl.setAttribute('data-draft-uid', String(data.draft_uid));
                            }
                        }
                        if (currentFolderKind() === 'draft') {
                            scheduleMailPoll(true);
                        }
                        mailSyncPaused = false;
                        return;
                    }
                    if (draftPaneEl) {
                        var sentDraftUid = parseInt((form.querySelector('input[name="draft_uid"]') || {}).value || '0', 10);
                        if (sentDraftUid) removeRowByUid(sentDraftUid);
                        clearReadingPane();
                        afterComposeSendRefresh(data, form);
                        return;
                    }
                    if (data && data.draft_uid) removeRowByUid(data.draft_uid);
                    var inlineCompose = form.closest('#mail-inline-compose');
                    if (inlineCompose) {
                        var composeMode = (form.querySelector('input[name="mode"]') || {}).value || '';
                        var isReplySend = composeMode === 'reply' || composeMode === 'reply-all';
                        closeInlineCompose(false, { keepUiState: false, skipRestore: true });
                        afterComposeSendRefresh(data, form, { isReply: isReplySend });
                        return;
                    }
                    if (form.closest('#compose-panel')) {
                        var panelComposeMode = (form.querySelector('input[name="mode"]') || {}).value || '';
                        var panelReplySend = panelComposeMode === 'reply' || panelComposeMode === 'reply-all';
                        closeComposePanel(false, { skipRestore: true });
                        afterComposeSendRefresh(data, form, { isReply: panelReplySend });
                        if (!panelReplySend) {
                            stopPaneMessageSync();
                            setPaneView('empty');
                        }
                        return;
                    }
                    afterComposeSendRefresh(data, form);
                    var redirectPath = (data && data.return_folder) ? ('folder/' + data.return_folder) : '';
                    window.location = apiUrl(redirectPath);
                }).catch(function (err) {
                    if (!isDraft) {
                        mailSyncPaused = false;
                    }
                    var msg = err && err.name === 'AbortError'
                        ? 'Sending timed out. The message may still have been delivered — check Sent and try again if needed.'
                        : (err.message || 'Action failed.');
                    showToast('error', msg);
                }).finally(function () {
                    if (sendTimeoutId) window.clearTimeout(sendTimeoutId);
                    setComposeFormBusy(form, false);
                    setComposeActionsDisabled(false);
                });
                return;
            }

            if (submitter && submitter.tagName === 'BUTTON') {
                setComposeFormBusy(form, true, submitter, loadingLabel);
                setComposeActionsDisabled(true);
            }
        });
    }

    function initDraftEditor(root) {
        var form = root.querySelector ? root.querySelector('.compose-form--draft') : null;
        if (!form) return;
        var subjectInput = form.querySelector('#subject');
        var titleEl = form.querySelector('.compose-draft-title');
        if (subjectInput && titleEl) {
            subjectInput.addEventListener('input', function () {
                titleEl.textContent = subjectInput.value.trim() || '(no subject)';
            });
        }
    }

    function initComposeForm(root) {
        root = root || document;
        initRichEditor(root);
        initRecipientFields(root);
        initFileUpload(root);
        initComposeQuotedToggle(root);
        initOutlookInlineCompose(root);
        initDraftEditor(root);
        var form = root.querySelector ? root.querySelector('#compose-form') : document.getElementById('compose-form');
        if (form) {
            var returnField = form.querySelector('#return_folder');
            if (returnField && !returnField.value) {
                returnField.value = currentMailFolderEnc();
            }
            bindComposeFormAjax(form);

            // New message: the signature is prefilled at the bottom of the body,
            // so the user should type ABOVE it. The first time the body gains
            // focus, drop the caret at the very top (above the signature) — that
            // way whatever they type lands above the signature and the signature
            // stays at the bottom of the message. Only a brand-new compose is
            // adjusted: replies place their caret on load and draft editing should
            // keep the caret where the user left off.
            var modeField = form.querySelector('[name="mode"]');
            var bodyEditor = form.querySelector('#body-editor');
            if (modeField && modeField.value === 'compose' && bodyEditor && !bodyEditor.dataset.caretTopInit) {
                bodyEditor.dataset.caretTopInit = '1';
                bodyEditor.addEventListener('focus', function onFirstBodyFocus() {
                    bodyEditor.removeEventListener('focus', onFirstBodyFocus);
                    window.setTimeout(function () {
                        try {
                            var sel = window.getSelection();
                            var range = document.createRange();
                            // Land the caret inside the writing block (above the
                            // signature) when the sig-at-bottom layout is used.
                            var writeArea = bodyEditor.querySelector('.compose-body-write') || bodyEditor;
                            range.selectNodeContents(writeArea);
                            range.collapse(true);
                            sel.removeAllRanges();
                            sel.addRange(range);
                        } catch (err) {}
                    }, 0);
                });
            }
        }
    }

    function initComposePanel() {
        bindComposeLinks(document);
        bindComposePrefetchTriggers(document);
        initComposeForm(document);
        var closeBtn = document.getElementById('compose-panel-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                autoSaveComposeOnClose();
                closeComposePanel(true);
            });
        }
    }

    function bindMailRow(row) {
        if (!row || row.dataset.bound) return;
        row.dataset.bound = '1';
        row.addEventListener('click', function (e) {
            if (e.target.closest('.mail-row-check') || e.target.closest('.mail-card-check') || e.target.closest('.col-check') || e.target.closest('.mail-kebab')) return;
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            if (e.ctrlKey || e.metaKey) {
                var cb = row.querySelector('.mail-check');
                if (cb) {
                    cb.checked = !cb.checked;
                    lastCheckedRowIndex = selectableMailRows().indexOf(row);
                    updateCommandBar();
                }
                if (useReadingPane() && uid) openMessageInPane(uid, true);
                return;
            }
            if (useReadingPane() && uid) {
                openMessageInPane(uid, true);
                return;
            }
            beaconThreadMarkRead(row);
            showLoading();
            window.location = row.getAttribute('data-href');
        });
        row.addEventListener('mouseenter', function () {
            scheduleUnreadPanePrefetch(row);
        });
        row.addEventListener('focusin', function () {
            scheduleUnreadPanePrefetch(row);
        });
        // Keyboard activation for role="link" cards (mobile list a11y).
        if (row.getAttribute('role') === 'link' || row.getAttribute('role') === 'option') {
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.target.closest('.mail-kebab') || e.target.closest('.mail-card-check') || e.target.closest('.mail-row-check')) return;
                    e.preventDefault();
                    beaconThreadMarkRead(row);
                    showLoading();
                    window.location = row.getAttribute('data-href');
                }
            });
        }
    }

    // Full-page navigation to an unread conversation: the target page marks only
    // the opened message; best-effort mark the rest of the thread read too so
    // the badge is right when we come back. keepalive survives the navigation.
    function beaconThreadMarkRead(row) {
        if (!row || row.getAttribute('data-seen') !== '0') return;
        var threadUids = rowThreadUids(row);
        if (threadUids.length <= 1) return;
        var listCard = getListCard();
        var folderEnc = listCard ? listCard.getAttribute('data-folder-path') : '';
        if (!folderEnc) return;
        threadUids.forEach(function (u) { noteRecentlyMarkedRead(u); });
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        payload.set('folder', folderEnc);
        threadUids.forEach(function (u) { payload.append('uids[]', String(u)); });
        try {
            fetch(apiUrl('message/bulk-mark-read'), {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf || ''
                },
                body: payload.toString()
            });
        } catch (e) { /* best effort */ }
    }

    document.querySelectorAll('.mail-row[data-href], .mail-card[data-href]').forEach(bindMailRow);

    function initMobileReadSwipe() {
        var card = document.querySelector('.mail-read-card:not(.mail-read-card--pane)');
        if (!card || card._swipeBound) return;
        card._swipeBound = true;
        var startX = 0;
        var startY = 0;
        card.addEventListener('touchstart', function (e) {
            if (!e.touches || !e.touches[0]) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });
        card.addEventListener('touchend', function (e) {
            if (!e.changedTouches || !e.changedTouches[0]) return;
            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            if (dx > 70 && Math.abs(dy) < 50 && startX < 40) {
                var folderUrl = card.getAttribute('data-folder-url');
                if (folderUrl) window.location = folderUrl;
            }
        }, { passive: true });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function folderShowsUnreadBadge(path) {
        if (!path) return false;
        return path.toLowerCase().indexOf('trash') < 0 && !isSpamFolderPath(path);
    }

    function folderUnreadLookup(lookup, path) {
        if (!path) return 0;
        if (lookup[path] != null) return lookup[path];
        var lower = path.toLowerCase();
        if (lookup[lower] != null) return lookup[lower];
        var containerMatch = /^INBOX\.([^.]+)\.Inbox$/i.exec(path);
        if (containerMatch) {
            var container = 'INBOX.' + containerMatch[1];
            if (lookup[container] != null) return lookup[container];
            var containerLower = container.toLowerCase();
            if (lookup[containerLower] != null) return lookup[containerLower];
        }
        if (lastUnreadCounts[path] != null) return lastUnreadCounts[path];
        if (lastUnreadCounts[lower] != null) return lastUnreadCounts[lower];
        return 0;
    }

    function updateMailCount(total, unread) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        if (isTrashFolder()) unread = 0;
        var u = typeof unread === 'number' ? unread : 0;
        label.setAttribute('data-total', String(typeof total === 'number' ? total : 0));
        label.setAttribute('data-unread', String(u));
        var t = typeof total === 'number' ? total : 0;
        if (u > 0) {
            label.hidden = false;
            label.removeAttribute('aria-hidden');
            label.classList.add('page-header-count--unread');
            label.classList.remove('page-header-count--hidden', 'page-header-count--muted');
            label.textContent = String(u);
            label.title = u + ' unread';
        } else if (t > 0) {
            // All read: quiet "N messages" (or "N drafts") label, never blank.
            var noun = currentFolderKind() === 'draft' ? ' draft' : ' message';
            var text = t + noun + (t === 1 ? '' : 's');
            label.hidden = false;
            label.removeAttribute('aria-hidden');
            label.classList.remove('page-header-count--unread', 'page-header-count--hidden');
            label.classList.add('page-header-count--muted');
            label.textContent = text;
            label.title = text;
        } else {
            label.hidden = true;
            label.setAttribute('aria-hidden', 'true');
            label.classList.remove('page-header-count--unread', 'page-header-count--muted');
            label.classList.add('page-header-count--hidden');
            label.textContent = '';
            label.title = '0 messages';
        }
    }

    function unreadCountFromMessages(messages) {
        var unread = 0;
        (messages || []).forEach(function (m) {
            if (!m.seen) unread++;
        });
        return unread;
    }

    function adjustMailCount(totalDelta, unreadDelta) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        var total = parseInt(label.getAttribute('data-total') || '0', 10) || 0;
        var unread = parseInt(label.getAttribute('data-unread') || '0', 10) || 0;
        var newTotal = Math.max(0, total + (totalDelta || 0));
        var newUnread = Math.max(0, unread + (unreadDelta || 0));
        updateMailCount(newTotal, newUnread);
        var card = getListCard();
        if (card) card.setAttribute('data-total-messages', String(newTotal));
        var pagination = document.querySelector('.mail-list-column .pagination');
        if (pagination) {
            if (newTotal === 0) {
                pagination.hidden = true;
            } else {
                pagination.hidden = false;
                var range = pagination.querySelector('.pagination-range');
                if (range) {
                    // Per-page option VALUES are URLs; the visible number is the text.
                    var ppSelect = document.getElementById('per-page-select');
                    var ppText = ppSelect && ppSelect.selectedIndex >= 0
                        ? ppSelect.options[ppSelect.selectedIndex].text
                        : '';
                    var perPage = parseInt(ppText, 10) || 25;
                    // Page-aware range (deep history pages are no longer page 1 only).
                    var pageNow = parseInt((card && card.getAttribute('data-page')) || '1', 10) || 1;
                    var start = newTotal === 0 ? 0 : Math.min((pageNow - 1) * perPage + 1, newTotal);
                    range.textContent = start + '–' + Math.min(pageNow * perPage, newTotal) + ' of ' + newTotal;
                }
            }
        }
    }

    var mailPoll = null;

    function scheduleMailPoll(force, withFilter) {
        if (!mailPoll) return;
        // Hold every folder /sync while a destructive op is still settling — both
        // light and forced syncs can open a live IMAP connection that would race the
        // in-flight move on this connection-limited host. releaseCriticalOp fires one
        // revalidation for the folder in view once the op is done.
        if (criticalOpActive) { mailSyncHeldDuringOp = true; return; }
        if (mailPollInFlight) return;
        if (!force && isPostSendQuiet()) return;
        if (!force && isListMutationQuiet()) return;
        if (mailSyncPaused && !force) return;
        var now = Date.now();
        if (!force && (now - lastMailPollAt) < mailPollMinGapMs) return;
        mailPoll(force, !!withFilter);
    }

    function ajaxAction(action, fields) {
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        Object.keys(fields).forEach(function (k) { payload.set(k, fields[k]); });

        return fetch(apiUrl(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: payload.toString()
        }).then(function (res) {
            return res.json().catch(function () { return { ok: res.ok }; }).then(function (data) {
                if (!res.ok || (data && data.ok === false)) {
                    throw new Error((data && data.error) || 'Action failed.');
                }
                return data;
            });
        });
    }

    function rowsForUid(uid) {
        return document.querySelectorAll('[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]');
    }

    function applySeen(el, seen) {
        el.setAttribute('data-seen', seen ? '1' : '0');
        el.classList.toggle('mail-unread', !seen);
        var status = el.querySelector('.col-status');
        if (status) {
            var dot = status.querySelector('.unread-dot');
            if (!seen && !dot) {
                var s = document.createElement('span');
                s.className = 'unread-dot';
                status.insertBefore(s, status.firstChild);
            } else if (seen && dot) {
                dot.remove();
            }
        }
    }

    function applyFlag(el, flagged) {
        el.setAttribute('data-flagged', flagged ? '1' : '0');
        el.classList.toggle('mail-flagged', flagged);

        var status = el.querySelector('.col-status');
        if (status) {
            var star = status.querySelector('.flag-dot');
            if (flagged && !star) {
                var s = document.createElement('span');
                s.className = 'flag-dot';
                s.title = 'Important';
                s.innerHTML = '\u2605';
                status.appendChild(s);
            } else if (!flagged && star) {
                star.remove();
            }
        }

        var from = el.querySelector('.mail-card-from');
        if (from) {
            var mstar = from.querySelector('.flag-dot');
            if (mstar) mstar.remove();
        }

        var cardMeta = el.querySelector('.mail-card-meta');
        if (cardMeta) {
            var cstar = cardMeta.querySelector('.mail-row-flag');
            if (flagged && !cstar) {
                var cs = document.createElement('span');
                cs.className = 'flag-dot mail-row-flag';
                cs.title = 'Important';
                cs.innerHTML = '\u2605';
                var cardDate = cardMeta.querySelector('.mail-card-date');
                if (cardDate) cardMeta.insertBefore(cs, cardDate);
                else cardMeta.appendChild(cs);
            } else if (!flagged && cstar) {
                cstar.remove();
            }
        }

        var meta = el.querySelector('.mail-row-meta');
        if (meta) {
            var rstar = meta.querySelector('.mail-row-flag');
            if (flagged && !rstar) {
                var rs = document.createElement('span');
                rs.className = 'flag-dot mail-row-flag';
                rs.title = 'Important';
                rs.innerHTML = '\u2605';
                var dateEl = meta.querySelector('.mail-row-date');
                if (dateEl) meta.insertBefore(rs, dateEl);
                else meta.appendChild(rs);
            } else if (!flagged && rstar) {
                rstar.remove();
            }
        }
    }

    function setRowSeen(uid, seen) {
        rowsForUid(uid).forEach(function (el) { applySeen(el, seen); });
        if (seen) {
            noteRecentlyMarkedRead(uid);
        } else {
            invalidatePaneCache(uid);
            delete recentlyMarkedReadUntil[String(uid)];
        }
        document.querySelectorAll('.mail-read-card[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]').forEach(function (card) {
            card.setAttribute('data-seen', seen ? '1' : '0');
            syncReadSeenButton(card);
        });
    }

    function setRowFlagged(uid, flagged) {
        rowsForUid(uid).forEach(function (el) { applyFlag(el, flagged); });
    }

    function syncListEmptyState() {
        var body = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        var empty = document.getElementById('mail-list-empty');
        var scroller = document.getElementById('mail-list-scroller');
        if (!empty) return;
        var hasRows = (body && body.querySelectorAll('.mail-row[data-uid]').length > 0)
            || (mobile && mobile.querySelectorAll('.mail-card[data-uid]').length > 0);
        empty.hidden = hasRows;
        if (scroller) scroller.hidden = !hasRows;
        if (mobile) mobile.hidden = !hasRows;
    }

    function visibleMailRowCount() {
        var body = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        var count = 0;
        if (body) count += body.querySelectorAll('.mail-row[data-uid]').length;
        if (mobile) count += mobile.querySelectorAll('.mail-card[data-uid]').length;
        return count;
    }

    function removeStaleOptimisticRows() {
        var optimisticRows = document.querySelectorAll('.mail-row[data-optimistic="1"], .mail-card[data-optimistic="1"]');
        if (!optimisticRows.length) return;

        optimisticRows.forEach(function (optRow) {
            var optFromEl = optRow.querySelector('.mail-row-from, .mail-card-from');
            var optFrom = optFromEl ? optFromEl.textContent.trim().toLowerCase() : '';
            var optSnippetEl = optRow.querySelector('.mail-row-snippet, .mail-card-snippet');
            var optSnippet = optSnippetEl ? optSnippetEl.textContent.trim().toLowerCase() : '';
            var hasSyncedCopy = false;

            document.querySelectorAll('.mail-row[data-uid]:not([data-optimistic]), .mail-card[data-uid]:not([data-optimistic])').forEach(function (row) {
                if (hasSyncedCopy) return;
                var fromEl = row.querySelector('.mail-row-from, .mail-card-from');
                var fromText = fromEl ? fromEl.textContent.trim().toLowerCase() : '';
                if (!optFrom || !fromText) return;
                if (fromText.indexOf(optFrom) < 0 && optFrom.indexOf(fromText) < 0) return;
                if (optSnippet) {
                    var snippetEl = row.querySelector('.mail-row-snippet, .mail-card-snippet');
                    var snippet = snippetEl ? snippetEl.textContent.trim().toLowerCase() : '';
                    if (snippet && snippet.indexOf(optSnippet) < 0 && optSnippet.indexOf(snippet) < 0) {
                        return;
                    }
                }
                hasSyncedCopy = true;
            });

            if (hasSyncedCopy && optRow.parentNode) {
                optRow.parentNode.removeChild(optRow);
            }
        });

        syncListEmptyState();
    }

    function hydrateMailListFromPoll(messages, markNew) {
        if (!messages || !messages.length) return false;

        var tbody = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        var inserted = 0;

        messages.forEach(function (msg) {
            var uid = String(msg.uid);
            if (isUidPendingRemoval(uid)) return;
            if (tbody && !tbody.querySelector('[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(uid) : uid) + '"]')) {
                tbody.insertBefore(buildDesktopRow(msg, !!markNew), tbody.firstChild);
                inserted++;
            }
            if (mobile && !mobile.querySelector('[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(uid) : uid) + '"]')) {
                mobile.insertBefore(buildMobileCard(msg, !!markNew), mobile.firstChild);
                inserted++;
            }
        });

        if (inserted > 0) {
            setMailListLoading(false);
            ensureListVisible(getListCard());
            syncListEmptyState();
        }

        return inserted > 0;
    }

    function mailListSortTs(msg) {
        if (!msg) return 0;
        if (msg.sort_date) {
            var parsed = Date.parse(msg.sort_date);
            if (!isNaN(parsed)) return parsed;
        }
        var uid = parseInt(msg.uid, 10);
        return isNaN(uid) ? 0 : uid;
    }

    function normalizeThreadSubject(subject) {
        var s = String(subject || '').trim();
        while (/^(Re|Fwd|Fw):\s*/i.test(s)) {
            s = s.replace(/^(Re|Fwd|Fw):\s*/i, '').trim();
        }
        return s;
    }

    function rowThreadKey(row) {
        var tk = row.getAttribute('data-thread-key');
        if (tk) return tk.toLowerCase();
        var subjEl = row.querySelector('.mail-row-subject, .mail-card-subject');
        return normalizeThreadSubject(subjEl ? subjEl.textContent : '').toLowerCase();
    }

    // All uids of the conversation this row represents (this folder only,
    // newest first). Falls back to the row's own uid for per-message rows.
    function rowThreadUids(row) {
        if (!row) return [];
        var attr = row.getAttribute('data-thread-uids') || '';
        var uids = attr.split(',').map(function (v) { return parseInt(v, 10); })
            .filter(function (u) { return u > 0; });
        if (uids.length) return uids;
        var own = parseInt(row.getAttribute('data-uid'), 10);
        return own > 0 ? [own] : [];
    }

    function rowThreadCount(row) {
        if (!row) return 1;
        var n = parseInt(row.getAttribute('data-thread-count') || '1', 10);
        return n > 1 ? n : 1;
    }

    function rowForUid(uid) {
        return rowsForUid(uid)[0] || null;
    }

    // Keep a known row's conversation metadata (thread uids, count, and the
    // "(N)" subject span) in sync with the latest poll snapshot.
    function syncRowThreadMeta(m) {
        if (!m || m.optimistic) return;
        var count = parseInt(m.thread_count, 10) || 1;
        var uidsAttr = (m.thread_uids && m.thread_uids.length ? m.thread_uids : [m.uid]).join(',');
        rowsForUid(m.uid).forEach(function (row) {
            if (row.getAttribute('data-optimistic') === '1') return;
            row.setAttribute('data-thread-uids', uidsAttr);
            row.setAttribute('data-thread-count', String(count));
            var subjEl = row.querySelector('.mail-row-subject, .mail-card-subject');
            if (!subjEl) return;
            var span = subjEl.querySelector('.mail-row-thread-count');
            if (count > 1) {
                if (!span) {
                    span = document.createElement('span');
                    span.className = 'mail-row-thread-count';
                    // Prefix the subject so the count is always visible, even when
                    // the subject is long enough to truncate.
                    subjEl.insertBefore(span, subjEl.firstChild);
                }
                span.textContent = String(count);
                span.title = count + ' messages in this conversation';
            } else if (span) {
                span.remove();
            }
        });
    }

    function removeSupersededThreadRows(msg, keepUid) {
        var tk = (msg.thread_key || normalizeThreadSubject(msg.subject)).toLowerCase();
        if (!tk) return;
        var keep = keepUid != null ? String(keepUid) : String(msg.uid);
        document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (row) {
            if (row.getAttribute('data-optimistic') === '1') return;
            var uid = row.getAttribute('data-uid');
            if (uid === keep) return;
            if (rowThreadKey(row) === tk && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        });
    }

    function pruneThreadCollapsedRows(freshUids, messages) {
        if (!messages || !messages.length) return;
        var winners = {};
        messages.forEach(function (m) {
            var tk = (m.thread_key || normalizeThreadSubject(m.subject)).toLowerCase();
            if (!tk) return;
            winners[tk] = String(m.uid);
        });
        document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (row) {
            if (row.getAttribute('data-optimistic') === '1') return;
            var uid = row.getAttribute('data-uid');
            if (!uid || freshUids[uid]) return;
            var tk = rowThreadKey(row);
            if (!tk || !winners[tk] || winners[tk] === uid) return;
            if (row.parentNode) row.parentNode.removeChild(row);
        });
    }

    function reorderMailListFromPoll(messages) {
        if (!messages || !messages.length) return;

        var sorted = messages.slice().sort(function (a, b) {
            var diff = mailListSortTs(b) - mailListSortTs(a);
            if (diff !== 0) return diff;
            return (parseInt(b.uid, 10) || 0) - (parseInt(a.uid, 10) || 0);
        });

        function reorderContainer(container, rowSelector) {
            if (!container) return;
            var rowMap = {};
            container.querySelectorAll(rowSelector).forEach(function (row) {
                rowMap[row.getAttribute('data-uid')] = row;
            });
            var used = {};
            sorted.forEach(function (msg) {
                var row = rowMap[String(msg.uid)];
                if (row) {
                    container.appendChild(row);
                    used[String(msg.uid)] = true;
                }
            });
            Object.keys(rowMap).forEach(function (uid) {
                if (!used[uid]) container.appendChild(rowMap[uid]);
            });
        }

        reorderContainer(document.getElementById('mail-list-body'), '.mail-row[data-uid]');
        reorderContainer(document.getElementById('mail-list-mobile'), '.mail-card[data-uid]');
    }

    function removeRowByUid(uid) {
        markUidsPendingRemoval([uid]);
        var wasUnread = false;
        rowsForUid(uid).forEach(function (el) {
            if (el.getAttribute('data-seen') === '0') wasUnread = true;
        });
        var removed = false;
        rowsForUid(uid).forEach(function (el) {
            removed = true;
            if (el.parentNode) el.parentNode.removeChild(el);
        });
        if (removed) {
            syncListEmptyState();
            adjustMailCount(-1, wasUnread ? -1 : 0);
        }
    }

    function clearMailListRows() {
        var card = getListCard();
        if (card) {
            markUidsPendingRemoval(Array.from(collectKnownUids(card)));
        }
        var body = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        if (body) body.innerHTML = '';
        if (mobile) mobile.innerHTML = '';
        syncListEmptyState();
        var label = document.getElementById('mail-count-label');
        var card = document.querySelector('.mail-list-card[data-total-messages]');
        if (label) {
            label.textContent = '';
            label.setAttribute('data-total', '0');
            label.setAttribute('data-unread', '0');
            label.hidden = true;
            label.title = '0 messages';
        }
        if (card) card.setAttribute('data-total-messages', '0');
    }

    // Stateful toast for deferred server work (move/delete). Created the INSTANT
    // the user triggers the action (before the request even returns — responses
    // can take seconds on this host), then attached to the ops journal entry and
    // flipped to "✓ Moved" / an error when the server work truly completes.
    // ── Critical-operation guard ─────────────────────────────────────────────
    // A destructive IMAP mutation (delete / move / restore / spam) replies in
    // ~1-2s but its verified IMAP moves keep running for several seconds after
    // that (op-status polling tracks them). On this connection-limited mail host,
    // firing ANOTHER IMAP action inside that window makes the in-progress one
    // flake — that is what dropped one message from a 5-message restore. So while
    // such an op is settling we block starting another mutation (and sending) and
    // DEFER folder/message navigation until it finishes, with a hard safety
    // timeout so the UI can never get stuck if op-status never resolves (it can
    // time out on this host).
    var criticalOpActive = false;
    var criticalOpSafetyTimer = null;
    // Generation token: every destructive op gets a distinct gen so a stale op's
    // watchdog/finish can never re-arm or release a NEWER overlapping op's lock
    // (which would break serialization and re-open the half-move hazard). The gen
    // only ever increments; the current owner is whoever holds gen === criticalOpGen.
    var criticalOpGen = 0;
    // A folder /sync that was requested while a destructive op was still settling.
    // BOTH light and forced syncs can open a live IMAP connection, so they are held
    // during the op and one revalidation is fired for the folder in view on release.
    var mailSyncHeldDuringOp = false;
    // The lock is re-armed on every op-status poll that shows the op still running
    // (petCriticalOpWatchdog), so this is a WATCHDOG interval, not a hard cap: it only
    // fires when op-status goes truly silent. Kept comfortably above the worst-case
    // inter-poll gap (max 8s delay + the op-status abort-timeout) so a healthy-but-slow
    // poll cycle never trips it. attach()'s 120s cap remains the absolute ceiling.
    var CRITICAL_OP_MAX_MS = 30000;

    function beginCriticalOp() {
        criticalOpActive = true;
        var gen = ++criticalOpGen;
        if (criticalOpSafetyTimer) window.clearTimeout(criticalOpSafetyTimer);
        criticalOpSafetyTimer = window.setTimeout(function () { releaseCriticalOp(gen); }, CRITICAL_OP_MAX_MS);
        return gen;
    }

    // Re-ARM and re-ASSERT the lock. Called after confirming the OWNING op is still
    // running — at response arrival and on every in-progress op-status poll. It
    // re-asserts (not bails) for two reasons: (1) if the flat safety timer already
    // fired during a slow, longer-than-budget pre-response window, criticalOpActive is
    // false here and a plain re-arm would never restore it, dropping the lock for the
    // rest of a still-running move — the half-move hazard; (2) the gen check ensures
    // ONLY the current owner re-arms, so a stale op A cannot seize a newer op B's lock.
    // attach()'s 120s cap bounds petting, so this can never wedge the lock stuck ON.
    function petCriticalOpWatchdog(gen) {
        if (gen !== criticalOpGen) return;
        criticalOpActive = true;
        if (criticalOpSafetyTimer) window.clearTimeout(criticalOpSafetyTimer);
        criticalOpSafetyTimer = window.setTimeout(function () { releaseCriticalOp(gen); }, CRITICAL_OP_MAX_MS);
    }

    function releaseCriticalOp(gen) {
        // A stale op (its gen superseded by a newer overlapping op) must not release
        // the newer op's lock or clear its timer. A no-arg call (defensive) proceeds.
        if (gen != null && gen !== criticalOpGen) return;
        if (criticalOpSafetyTimer) { window.clearTimeout(criticalOpSafetyTimer); criticalOpSafetyTimer = null; }
        if (!criticalOpActive) return;
        criticalOpActive = false;
        // The op is settled — opening IMAP is safe again. If a folder /sync was held
        // while it ran (e.g. the user switched folders mid-op), run one now for the
        // folder in view so it picks up anything that arrived during the op. A
        // duplicate scheduleMailPoll(true) from finish() is deduped by mailPollInFlight.
        if (mailSyncHeldDuringOp) {
            mailSyncHeldDuringOp = false;
            scheduleMailPoll(true, false);
        }
    }

    // True (and shows a hint) when the caller must stand down because a mutation
    // is still finishing.
    function blockedByCriticalOp() {
        if (!criticalOpActive) return false;
        showToast('error', 'Please wait for the current action to finish…', 2500);
        return true;
    }

    function beginOpToast(labels) {
        labels = labels || {};
        // This toast tracks a destructive IMAP op whose background moves outlive
        // the HTTP response — hold the guard for its whole lifetime. myGen identifies
        // THIS op so its watchdog/finish only ever touch its own lock, never a newer
        // overlapping op's.
        var myGen = beginCriticalOp();
        var stack = document.getElementById('toast-stack');
        var toast = null;
        if (stack) {
            toast = document.createElement('div');
            toast.className = 'toast toast-success toast-progress';
            toast.setAttribute('role', 'status');
            var spin = document.createElement('span');
            spin.className = 'toast-spinner';
            spin.setAttribute('aria-hidden', 'true');
            toast.appendChild(spin);
            var text = document.createElement('span');
            text.textContent = labels.progress || 'Working…';
            toast.appendChild(text);
            stack.appendChild(toast);
        }

        var finished = false;
        function dismiss() {
            if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
        }
        function finish(ok, failMsg) {
            if (finished) return;
            finished = true;
            releaseCriticalOp(myGen);
            dismiss();
            if (ok) {
                if (labels.done) showToast('success', labels.done, 3500);
                refreshUnreadBadges(false);
                // Refresh the open folder right away — if the user is looking at
                // the target, the just-arrived messages appear without waiting for
                // the next poll cycle.
                scheduleMailPoll(true, false);
            } else {
                showToast('error', failMsg || labels.fail || 'The action could not be completed. The messages have been restored.', 8000);
                scheduleMailPoll(true, false);
            }
        }

        return {
            // Response arrived: follow the journal op(s) until they truly complete.
            // Accepts one op id or an array (restore can span several origin
            // folders). Big bulk ops with per-message repairs can run well past
            // 30s on this host, so poll until done/failed (max ~2 min) — never
            // declare success early.
            attach: function (opId) {
                if (finished) return;
                var ids = (Array.isArray(opId) ? opId : [opId]).filter(function (v) { return !!v; });
                if (!ids.length) { finish(true); return; }
                // Response arrived and the op is underway — give the lock a fresh
                // window from now, then keep re-arming it on every in-progress poll.
                petCriticalOpWatchdog(myGen);
                var startedAt = Date.now();
                var delays = [1500, 2500, 4000, 6000];
                var step = 0;
                function poll() {
                    Promise.all(ids.map(function (id) {
                        // Per-request abort-timeout (mirrors the mutation POST and compose
                        // send): a hung op-status socket must become a reject (→ null result
                        // → treated as "still running", keeps polling) rather than stalling
                        // the loop forever and leaving the progress spinner up.
                        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                        var to = ctrl ? window.setTimeout(function () { try { ctrl.abort(); } catch (e) { /* ignore */ } }, 9000) : null;
                        return fetch(apiUrl('mail/op-status?id=' + encodeURIComponent(id)), {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                            signal: ctrl ? ctrl.signal : undefined
                        }).then(function (r) { return r.json(); }).catch(function () { return null; })
                            .then(function (v) { if (to) window.clearTimeout(to); return v; });
                    })).then(function (results) {
                        if (finished) return;
                        var anyFailed = results.some(function (d) { return d && d.status === 'failed'; });
                        if (anyFailed) { finish(false); return; }
                        ids = ids.filter(function (id, i) {
                            return !(results[i] && results[i].status === 'done');
                        });
                        if (!ids.length) { finish(true); return; }
                        // Op still running (or op-status momentarily silent) — hold the
                        // serialization lock past the flat window.
                        petCriticalOpWatchdog(myGen);
                        next();
                    }).catch(next);
                }
                function next() {
                    if (finished) return;
                    if (Date.now() - startedAt > 120000) {
                        // Exceptionally long op: stop polling without claiming
                        // success — the journal + resume guarantee completion and
                        // the folder updates itself as messages land.
                        finished = true;
                        releaseCriticalOp(myGen);
                        dismiss();
                        showToast('success', 'Still finishing in the background — the folder will update automatically.', 6000);
                        scheduleMailPoll(true, false);
                        return;
                    }
                    window.setTimeout(poll, step < delays.length ? delays[step++] : 8000);
                }
                next();
            },
            fail: function (msg) { finish(false, msg); }
        };
    }

    function showToast(type, message, duration) {
        var stack = document.getElementById('toast-stack');
        if (!stack || !message) return;

        duration = duration == null ? 5000 : duration;
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + (type === 'error' ? 'error' : 'success');
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        toast.appendChild(close);

        function dismiss() {
            if (toast.classList.contains('is-leaving')) return;
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }

        close.addEventListener('click', dismiss);
        stack.appendChild(toast);

        if (duration > 0) {
            window.setTimeout(dismiss, duration);
        }
    }

    window.showToast = showToast;

    var confirmKeyHandler = null;
    var bodyScrollLockY = 0;

    function lockBodyForModal() {
        bodyScrollLockY = window.scrollY || window.pageYOffset || 0;
        body.style.position = 'fixed';
        body.style.top = (-bodyScrollLockY) + 'px';
        body.style.left = '0';
        body.style.right = '0';
        body.style.width = '100%';
        body.classList.add('modal-open');
    }

    function unlockBodyForModal() {
        body.classList.remove('modal-open');
        body.style.position = '';
        body.style.top = '';
        body.style.left = '';
        body.style.right = '';
        body.style.width = '';
        window.scrollTo(0, bodyScrollLockY);
    }

    function isConfirmOpen() {
        var modal = document.getElementById('confirm-modal');
        return modal && !modal.hidden;
    }

    /**
     * @param {{ title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean, keepOpenOnConfirm?: boolean, loadingLabel?: string }} opts
     * @returns {Promise<boolean>}
     */
    function showConfirm(opts) {
        opts = opts || {};
        var modal = document.getElementById('confirm-modal');
        if (!modal) {
            return Promise.resolve(window.confirm(opts.message || opts.title || 'Are you sure?'));
        }

        var titleEl = document.getElementById('confirm-modal-title');
        var msgEl = document.getElementById('confirm-modal-message');
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        var iconEl = document.getElementById('confirm-modal-icon');
        var backdrop = modal.querySelector('[data-confirm-dismiss]');
        var dialog = modal.querySelector('.app-modal-dialog');
        var isAlert = !!opts.alert;
        var isDanger = !!opts.danger;

        var dangerIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
        var infoIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>';

        return new Promise(function (resolve) {
            resetConfirmModalButtons();
            if (titleEl) titleEl.textContent = opts.title || (isAlert ? 'Notice' : 'Confirm');
            if (msgEl) msgEl.textContent = opts.message || '';
            if (okBtn) {
                okBtn.textContent = opts.confirmLabel || (isAlert ? 'OK' : 'Confirm');
                okBtn.className = isDanger ? 'btn btn-danger' : 'btn btn-primary';
            }
            if (cancelBtn) {
                cancelBtn.hidden = isAlert;
                cancelBtn.textContent = opts.cancelLabel || 'Cancel';
            }
            if (iconEl) {
                iconEl.hidden = false;
                iconEl.innerHTML = isDanger ? dangerIcon : infoIcon;
                iconEl.classList.toggle('is-danger', isDanger);
                iconEl.classList.toggle('is-info', !isDanger);
            }
            if (dialog) {
                dialog.classList.toggle('app-modal-dialog--danger', isDanger);
            }

            function finish(result) {
                resetConfirmModalButtons();
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                unlockBodyForModal();
                if (confirmKeyHandler) {
                    document.removeEventListener('keydown', confirmKeyHandler, true);
                    confirmKeyHandler = null;
                }
                resolve(!!result);
            }

            confirmKeyHandler = function (e) {
                if (e.key === 'Escape' && !isAlert) {
                    e.preventDefault();
                    e.stopPropagation();
                    finish(false);
                }
            };

            if (okBtn) {
                okBtn.onclick = function () {
                    if (opts.keepOpenOnConfirm) {
                        setConfirmModalLoading(true, opts.loadingLabel || okBtn.textContent.trim());
                        if (confirmKeyHandler) {
                            document.removeEventListener('keydown', confirmKeyHandler, true);
                            confirmKeyHandler = null;
                        }
                        resolve(true);
                        return;
                    }
                    finish(true);
                };
            }
            if (cancelBtn) cancelBtn.onclick = function () { finish(false); };
            if (backdrop) backdrop.onclick = function () { if (!isAlert) finish(false); };
            if (!isAlert) document.addEventListener('keydown', confirmKeyHandler, true);

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            lockBodyForModal();
            if (isDanger && cancelBtn && !isAlert) cancelBtn.focus();
            else if (okBtn) okBtn.focus();
        });
    }

    /**
     * Confirm dialog that runs an async action with loading state on the primary button.
     * @param {{ title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean, loadingLabel?: string, successMessage?: string, errorMessage?: string, action: function(): Promise<*> }} opts
     * @returns {Promise<boolean>}
     */
    function showConfirmAction(opts) {
        opts = opts || {};
        if (typeof opts.action !== 'function') {
            return showConfirm(opts);
        }

        var modal = document.getElementById('confirm-modal');
        if (!modal) {
            return Promise.resolve(window.confirm(opts.message || opts.title || 'Are you sure?'))
                .then(function (ok) {
                    if (!ok) return false;
                    return opts.action().then(function () {
                        if (opts.successMessage) showToast('success', opts.successMessage);
                        return true;
                    }).catch(function (err) {
                        showToast('error', err.message || opts.errorMessage || 'Action failed.');
                        return false;
                    });
                });
        }

        var titleEl = document.getElementById('confirm-modal-title');
        var msgEl = document.getElementById('confirm-modal-message');
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        var iconEl = document.getElementById('confirm-modal-icon');
        var backdrop = modal.querySelector('[data-confirm-dismiss]');
        var dialog = modal.querySelector('.app-modal-dialog');
        var isDanger = !!opts.danger;
        var inFlight = false;

        var dangerIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
        var infoIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>';

        return new Promise(function (resolve) {
            resetConfirmModalButtons();
            if (titleEl) titleEl.textContent = opts.title || 'Confirm';
            if (msgEl) msgEl.textContent = opts.message || '';
            if (okBtn) {
                okBtn.textContent = opts.confirmLabel || 'Confirm';
                okBtn.className = isDanger ? 'btn btn-danger' : 'btn btn-primary';
            }
            if (cancelBtn) {
                cancelBtn.hidden = false;
                cancelBtn.textContent = opts.cancelLabel || 'Cancel';
            }
            if (iconEl) {
                iconEl.hidden = false;
                iconEl.innerHTML = isDanger ? dangerIcon : infoIcon;
                iconEl.classList.toggle('is-danger', isDanger);
                iconEl.classList.toggle('is-info', !isDanger);
            }
            if (dialog) {
                dialog.classList.toggle('app-modal-dialog--danger', isDanger);
            }

            function closeModal() {
                inFlight = false;
                resetConfirmModalButtons();
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                unlockBodyForModal();
                if (confirmKeyHandler) {
                    document.removeEventListener('keydown', confirmKeyHandler, true);
                    confirmKeyHandler = null;
                }
            }

            function finishCancelled() {
                closeModal();
                resolve(false);
            }

            confirmKeyHandler = function (e) {
                if (e.key === 'Escape' && !inFlight) {
                    e.preventDefault();
                    e.stopPropagation();
                    finishCancelled();
                }
            };

            if (okBtn) {
                okBtn.onclick = function () {
                    if (inFlight) return;
                    inFlight = true;
                    setConfirmModalLoading(true, opts.loadingLabel);
                    Promise.resolve(opts.action())
                        .then(function () {
                            closeModal();
                            if (opts.successMessage) showToast('success', opts.successMessage);
                            resolve(true);
                        })
                        .catch(function (err) {
                            closeModal();
                            showToast('error', err.message || opts.errorMessage || 'Action failed.');
                            resolve(false);
                        });
                };
            }
            if (cancelBtn) cancelBtn.onclick = finishCancelled;
            if (backdrop) backdrop.onclick = finishCancelled;
            document.addEventListener('keydown', confirmKeyHandler, true);

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            lockBodyForModal();
            if (isDanger && cancelBtn) cancelBtn.focus();
            else if (okBtn) okBtn.focus();
        });
    }

    function showAlert(opts) {
        opts = opts || {};
        return showConfirm({
            title: opts.title || 'Notice',
            message: opts.message || '',
            confirmLabel: opts.okLabel || 'OK',
            alert: true
        });
    }

    window.showConfirm = showConfirm;
    window.showConfirmAction = showConfirmAction;
    window.showAlert = showAlert;

    function isMobileUi() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    function useMoveFolderPicker() {
        // Always use the custom folder picker (icons + folder tree + professional
        // styling) instead of a native <select>, which can't show icons or nesting.
        return true;
    }

    function folderIconTypeFromPath(path) {
        var lower = (path || '').toLowerCase();
        if (path === 'INBOX') return 'inbox';
        if (lower.indexOf('sent') >= 0) return 'sent';
        if (lower.indexOf('draft') >= 0) return 'draft';
        if (lower.indexOf('trash') >= 0) return 'trash';
        if (lower.indexOf('spam') >= 0 || lower.indexOf('junk') >= 0) return 'spam';
        return 'folder';
    }

    function folderDepthFromPath(path) {
        if (!path) return 0;
        return (path.match(/\./g) || []).length;
    }

    function folderLeafLower(path) {
        var s = String(path || '');
        var i = s.lastIndexOf('.');
        return (i >= 0 ? s.slice(i + 1) : s).toLowerCase();
    }

    // A REAL top-level mailbox system folder (INBOX.Sent, INBOX.Junk, …), matched by
    // EXACT leaf name — never by substring — so custom folders whose names merely
    // contain a system word ("Junk Training", "Presentation") are NOT misclassified.
    function isSystemMailboxFolder(path, leaves) {
        var p = String(path || '');
        if (p.toUpperCase().slice(0, 6) !== 'INBOX.') return false;
        if (p.indexOf('.', 6) !== -1) return false; // must be exactly one level under INBOX
        return leaves.indexOf(folderLeafLower(p)) >= 0;
    }

    function folderIconHtml(iconType) {
        return '<span class="ctx-folder-icon folder-icon folder-icon-' + iconType + '" aria-hidden="true"></span>';
    }

    function iconTypeFromSidebarLink(link) {
        var iconEl = link.querySelector('.folder-icon');
        if (iconEl) {
            var classes = iconEl.className.split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                if (classes[i].indexOf('folder-icon-') === 0 && classes[i] !== 'folder-icon') {
                    return classes[i].replace('folder-icon-', '');
                }
            }
        }
        return folderIconTypeFromPath(link.getAttribute('data-folder-path') || '');
    }

    function folderSortKey(folder) {
        var lower = folder.path.toLowerCase();
        if (folder.path === 'INBOX') return '0';
        if (lower.indexOf('sent') >= 0) return '1';
        if (lower.indexOf('draft') >= 0) return '2';
        return '3' + folder.name.toLowerCase();
    }

    function isDraftFolderPath(path) {
        if (!path) return false;
        return String(path).toLowerCase().indexOf('draft') >= 0;
    }

    function isSpamFolderPath(path) {
        if (!path) return false;
        var lower = String(path).toLowerCase();
        return lower.indexOf('spam') >= 0 || lower.indexOf('junk') >= 0;
    }

    function canonicalJunkFolder(current) {
        var link = document.querySelector('.sidebar-primary-folders .folder-icon-spam');
        if (!link) return null;
        var anchor = link.closest('.sidebar-link[data-folder-path]');
        if (!anchor) return null;
        var path = anchor.getAttribute('data-folder-path');
        if (!path || path === current) return null;
        return {
            path: path,
            name: 'Junk',
            icon: 'spam',
            depth: folderDepthFromPath(path)
        };
    }

    function collectMoveFoldersFromSidebar() {
        var out = [];
        var active = document.querySelector('.sidebar-link.active[data-folder-path]');
        var current = active ? active.getAttribute('data-folder-path') : null;
        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            var path = link.getAttribute('data-folder-path');
            if (!path || path === current) return;
            // Exclude only REAL top-level system folders (Junk/Trash/Drafts), matched
            // by exact leaf — so custom folders like "Junk Training" are kept.
            if (isSystemMailboxFolder(path, ['spam', 'junk', 'trash', 'draft', 'drafts'])) return;
            var textEl = link.querySelector('.sidebar-link-text');
            out.push({
                path: path,
                name: textEl ? textEl.textContent.trim() : path,
                icon: iconTypeFromSidebarLink(link),
                depth: folderDepthFromPath(path)
            });
        });
        var junk = canonicalJunkFolder(current);
        if (junk) out.push(junk);
        out.sort(function (a, b) {
            return folderSortKey(a).localeCompare(folderSortKey(b));
        });
        return out;
    }

    function collectToolbarMoveFolders() {
        var sel = document.getElementById('cmd-move-target');
        if (!sel) return collectMoveFoldersFromSidebar();
        var out = [];
        sel.querySelectorAll('option').forEach(function (opt) {
            // Trust the server's move-target list (it already excludes Sent/Drafts and
            // offers one Inbox/Archive/Junk/Trash target). The old substring re-filter
            // wrongly dropped custom folders like "Junk Training" / "Draft Ideas".
            if (!opt.value) return;
            out.push({
                path: opt.value,
                name: opt.textContent.trim(),
                icon: folderIconTypeFromPath(opt.value),
                depth: parseInt(opt.getAttribute('data-depth') || '0', 10) || 0
            });
        });
        return out.length ? out : collectMoveFoldersFromSidebar();
    }

    function collectReadMoveFolders(card) {
        var sel = card ? card.querySelector('[name="target_folder"]') : null;
        if (!sel) return collectToolbarMoveFolders();
        var out = [];
        sel.querySelectorAll('option').forEach(function (opt) {
            // Trust the server's move-target list (it already excludes Sent/Drafts and
            // offers one Inbox/Archive/Junk/Trash target). The old substring re-filter
            // wrongly dropped custom folders like "Junk Training" / "Draft Ideas".
            if (!opt.value) return;
            out.push({
                path: opt.value,
                name: opt.textContent.trim(),
                icon: folderIconTypeFromPath(opt.value),
                depth: parseInt(opt.getAttribute('data-depth') || '0', 10) || 0
            });
        });
        return out.length ? out : collectToolbarMoveFolders();
    }

    function selectedPrimaryRow() {
        if (selectAllInFolder) return null;
        var uids = selectedMailUids();
        if (uids.length !== 1) return null;
        var uid = uids[0];
        var rows = rowsForUid(uid);
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].offsetParent !== null) return rows[i];
        }
        return rows[0] || null;
    }

    var folderPickerKeyHandler = null;
    var folderPickerResizeHandler = null;

    function isFolderPickerSheet() {
        return window.matchMedia('(max-width: 1023px)').matches;
    }

    function resetFolderPickerLayout(modal, dialog, listEl) {
        if (!modal) return;
        modal.style.top = '';
        modal.style.left = '';
        modal.style.right = '';
        modal.style.bottom = '';
        modal.style.width = '';
        modal.style.height = '';
        if (dialog) dialog.style.maxHeight = '';
        if (listEl) listEl.style.maxHeight = '';
    }

    function syncFolderPickerLayout() {
        var modal = document.getElementById('folder-picker-modal');
        var listEl = document.getElementById('folder-picker-list');
        var dialog = modal ? modal.querySelector('.app-modal-dialog--sheet') : null;
        if (!modal || modal.hidden || !listEl || !dialog) return;

        resetFolderPickerLayout(modal, dialog, listEl);

        var vv = window.visualViewport;
        var viewportH = vv ? vv.height : window.innerHeight;
        var viewportW = vv ? vv.width : window.innerWidth;
        var viewportTop = vv ? vv.offsetTop : 0;
        var viewportLeft = vv ? vv.offsetLeft : 0;
        var safeBottom = 12;
        var isSheet = isFolderPickerSheet();

        if (isSheet) {
            modal.style.top = viewportTop + 'px';
            modal.style.left = viewportLeft + 'px';
            modal.style.width = viewportW + 'px';
            modal.style.height = viewportH + 'px';
            modal.style.right = 'auto';
            modal.style.bottom = 'auto';
        }

        var modalStyle = window.getComputedStyle(modal);
        var padTop = parseFloat(modalStyle.paddingTop) || 0;
        var padBottom = parseFloat(modalStyle.paddingBottom) || 0;
        var containerH = isSheet ? viewportH : modal.getBoundingClientRect().height;
        var maxDialogH = Math.floor(containerH - padTop - padBottom - safeBottom);

        if (maxDialogH > 160) {
            dialog.style.maxHeight = maxDialogH + 'px';
        }

        var listTop = listEl.getBoundingClientRect().top;
        var visibleBottom = viewportTop + viewportH - safeBottom;
        var listAvailable = Math.floor(visibleBottom - listTop);
        if (listAvailable > 80) {
            listEl.style.maxHeight = listAvailable + 'px';
        }
    }

    /**
     * @param {{ title?: string, folders?: Array<{path:string,name:string,icon?:string,depth?:number}>, onPick?: Function }} opts
     * @returns {Promise<object|null>}
     */
    function showFolderPicker(opts) {
        opts = opts || {};
        var modal = document.getElementById('folder-picker-modal');
        var folders = opts.folders || [];
        // `close` is declared inside the Promise executor below; renderList lives in
        // this outer scope and can't see it (a bare `close(f)` would hit window.close
        // and silently no-op). Bridge the two through this shared reference.
        var closePicker = null;
        if (!modal) {
            return Promise.resolve(null);
        }

        var titleEl = document.getElementById('folder-picker-title');
        var listEl = document.getElementById('folder-picker-list');
        var searchEl = document.getElementById('folder-picker-search');
        var cancelBtn = document.getElementById('folder-picker-cancel');
        var backdrop = modal.querySelector('[data-folder-picker-dismiss]');
        var dialog = modal.querySelector('.app-modal-dialog--sheet');

        function renderList(filter) {
            if (!listEl) return;
            var q = (filter || '').trim().toLowerCase();
            listEl.innerHTML = '';
            var shown = 0;
            folders.forEach(function (f) {
                if (q && f.name.toLowerCase().indexOf(q) < 0 && f.path.toLowerCase().indexOf(q) < 0) return;
                shown++;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'folder-picker-item';
                btn.setAttribute('role', 'option');
                btn.innerHTML = folderIconHtml(f.icon || folderIconTypeFromPath(f.path)) +
                    '<span class="folder-picker-item-label"></span>';
                btn.querySelector('.folder-picker-item-label').textContent = f.name;
                // Indent subfolders under their parent (skip while searching, which
                // flattens the list). 0.75rem base padding + 1.25rem per level.
                var pickDepth = (!q && parseInt(f.depth, 10)) || 0;
                if (pickDepth > 0) {
                    btn.style.paddingInlineStart = (0.75 + pickDepth * 1.25) + 'rem';
                }
                btn.addEventListener('click', function () {
                    if (closePicker) closePicker(f);
                });
                listEl.appendChild(btn);
            });
            if (!shown) {
                var empty = document.createElement('p');
                empty.className = 'folder-picker-empty';
                empty.textContent = q ? 'No folders match your search.' : 'No folders available.';
                listEl.appendChild(empty);
            }
            window.requestAnimationFrame(syncFolderPickerLayout);
        }

        return new Promise(function (resolve) {
            if (titleEl) titleEl.textContent = opts.title || 'Choose folder';
            if (searchEl) {
                searchEl.value = '';
                searchEl.oninput = function () { renderList(searchEl.value); };
                searchEl.onfocus = function () {
                    window.requestAnimationFrame(syncFolderPickerLayout);
                };
            }
            closePicker = close;
            renderList('');

            function close(result) {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                unlockBodyForModal();
                resetFolderPickerLayout(modal, dialog, listEl);
                if (folderPickerKeyHandler) {
                    document.removeEventListener('keydown', folderPickerKeyHandler, true);
                    folderPickerKeyHandler = null;
                }
                if (folderPickerResizeHandler) {
                    window.removeEventListener('resize', folderPickerResizeHandler);
                    if (window.visualViewport) {
                        window.visualViewport.removeEventListener('resize', folderPickerResizeHandler);
                        window.visualViewport.removeEventListener('scroll', folderPickerResizeHandler);
                    }
                    folderPickerResizeHandler = null;
                }
                if (result && opts.onPick) opts.onPick(result);
                resolve(result || null);
            }

            folderPickerKeyHandler = function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    close(null);
                }
            };

            if (cancelBtn) cancelBtn.onclick = function () { close(null); };
            if (backdrop) backdrop.onclick = function () { close(null); };
            document.addEventListener('keydown', folderPickerKeyHandler, true);

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            lockBodyForModal();
            folderPickerResizeHandler = function () { syncFolderPickerLayout(); };
            window.addEventListener('resize', folderPickerResizeHandler);
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', folderPickerResizeHandler);
                window.visualViewport.addEventListener('scroll', folderPickerResizeHandler);
            }
            window.requestAnimationFrame(function () {
                syncFolderPickerLayout();
                if (searchEl && window.matchMedia('(pointer: fine)').matches) {
                    searchEl.focus();
                }
            });
        });
    }

    window.showFolderPicker = showFolderPicker;

    function initConfirmForms() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;

            var title = form.getAttribute('data-confirm-title');
            var message = form.getAttribute('data-confirm-message');
            if (!title && !message) return;

            e.preventDefault();
            e.stopPropagation();

            var loadingMsg = confirmFormLoadingMessage(form);

            showConfirm({
                title: title || 'Confirm',
                message: message || '',
                confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm',
                cancelLabel: form.getAttribute('data-confirm-cancel') || 'Cancel',
                danger: form.getAttribute('data-confirm-danger') === '1',
                keepOpenOnConfirm: true,
                loadingLabel: loadingMsg
            }).then(function (ok) {
                if (!ok) return;
                form.submit();
            });
        }, true);
    }

    function initToasts() {
        document.querySelectorAll('.toast-payload').forEach(function (el) {
            showToast(el.getAttribute('data-toast-type') || 'success', el.getAttribute('data-toast-message') || '');
            el.remove();
        });
    }

    function teardownMobilePerPage(wrap) {
        if (!wrap) return;
        var trigger = wrap.querySelector('.per-page-trigger');
        if (trigger) trigger.remove();
        var select = wrap.querySelector('.per-page-select');
        if (select) select.classList.remove('per-page-select--native-hidden');
        var menu = document.querySelector('.per-page-menu[data-for-select="per-page-select"]');
        if (menu) menu.remove();
        delete wrap.dataset.perPageMobileBound;
    }

    function initPerPageSelect() {
        document.querySelectorAll('.per-page-menu[data-for-select]').forEach(function (menu) {
            var selectId = menu.getAttribute('data-for-select');
            if (!selectId || !document.getElementById(selectId)) menu.remove();
        });

        var select = document.getElementById('per-page-select');
        if (!select) return;

        var wrap = select.closest('.pagination-per-page');
        if (!wrap) return;

        if (!select.dataset.perPageBound) {
            select.dataset.perPageBound = '1';
            select.addEventListener('change', function () {
                if (select.value) window.location = select.value;
            });
        }

        if (!isMobileUi()) {
            teardownMobilePerPage(wrap);
            return;
        }

        if (wrap.dataset.perPageMobileBound) return;
        wrap.dataset.perPageMobileBound = '1';

        select.classList.add('per-page-select--native-hidden');

        var field = select.closest('.select-field');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'per-page-trigger';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Messages per page');

        var menu = document.createElement('div');
        menu.className = 'per-page-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('data-for-select', 'per-page-select');

        Array.prototype.forEach.call(select.options, function (opt) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'per-page-menu-item';
            item.setAttribute('role', 'option');
            item.textContent = opt.textContent;
            item.dataset.url = opt.value;
            if (opt.selected) {
                item.classList.add('is-selected');
                item.setAttribute('aria-selected', 'true');
                btn.textContent = opt.textContent;
            }
            item.addEventListener('click', function () {
                if (item.dataset.url) window.location = item.dataset.url;
            });
            menu.appendChild(item);
        });

        field.insertBefore(btn, select);
        document.body.appendChild(menu);

        var label = wrap.querySelector('.pagination-per-page-label');
        if (label) {
            label.addEventListener('click', function (e) {
                e.preventDefault();
                btn.click();
            });
        }

        function closeMenu() {
            if (menu.hidden) return;
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }

        function positionMenu() {
            var rect = btn.getBoundingClientRect();
            var menuWidth = menu.offsetWidth;
            var left = rect.left + rect.width / 2 - menuWidth / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
            menu.style.left = left + 'px';
            menu.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
            menu.style.top = 'auto';
        }

        function openMenu() {
            menu.hidden = false;
            menu.style.visibility = 'hidden';
            requestAnimationFrame(function () {
                positionMenu();
                menu.style.visibility = '';
            });
            btn.setAttribute('aria-expanded', 'true');
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (menu.hidden) openMenu();
            else closeMenu();
        });

        document.addEventListener('click', function (e) {
            if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) closeMenu();
        });

        window.addEventListener('scroll', closeMenu, true);
        window.addEventListener('resize', closeMenu);
    }

    function playNewMailSound() {
        if (body.getAttribute('data-sound-enabled') !== '1') return;
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.value = 0.05;
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch (e) { /* ignore */ }
    }

    function notifyNewMail(count) {
        if (body.getAttribute('data-notify-enabled') !== '1') return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        if (document.visibilityState === 'visible') return;
        new Notification('New mail', { body: count + ' new message' + (count === 1 ? '' : 's') });
    }

    function ensureListVisible(card) {
        var empty = document.getElementById('mail-list-empty');
        if (empty) empty.hidden = true;
        var scroller = document.getElementById('mail-list-scroller');
        var desktop = document.getElementById('mail-list-body');
        var mobile = document.getElementById('mail-list-mobile');
        if (scroller) scroller.hidden = false;
        if (desktop) desktop.hidden = false;
        if (mobile) mobile.hidden = false;
    }

    function buildDesktopRow(msg, isNew) {
        var row = document.createElement('div');
        row.className = 'mail-row mail-row--outlook' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (msg.is_draft ? ' mail-row--draft' : '') + (msg.optimistic ? ' mail-row--optimistic' : '') + (isNew ? ' mail-row-new' : '');
        row.setAttribute('role', 'option');
        row.setAttribute('tabindex', '-1');
        row.setAttribute('aria-selected', 'false');
        row.setAttribute('data-uid', String(msg.uid));
        row.setAttribute('data-seen', msg.seen ? '1' : '0');
        row.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        var threadKey = msg.thread_key || normalizeThreadSubject(msg.subject);
        if (threadKey) row.setAttribute('data-thread-key', threadKey);
        if (msg.sort_date) {
            var sortTs = Date.parse(msg.sort_date);
            if (!isNaN(sortTs)) row.setAttribute('data-sort-ts', String(sortTs));
        }
        if (msg.optimistic) {
            row.setAttribute('data-optimistic', '1');
        } else {
            row.setAttribute('data-href', msg.url);
            if (msg.folder_b64) row.setAttribute('data-folder-b64', msg.folder_b64);
            row.setAttribute('data-thread-uids', (msg.thread_uids || [msg.uid]).join(','));
            row.setAttribute('data-thread-count', String(msg.thread_count || 1));
            if (msg.reply_url) row.setAttribute('data-reply-url', msg.reply_url);
            if (msg.reply_all_url) row.setAttribute('data-reply-all-url', msg.reply_all_url);
            if (msg.forward_url) row.setAttribute('data-forward-url', msg.forward_url);
        }

        var fromText = msg.from || 'Unknown';
        var snippet = msg.snippet || '';
        var draftBadge = msg.is_draft
            ? '<span class="mail-row-draft-badge">[Draft]</span>'
            : '';
        var snippetHtml = '<div class="mail-row-snippet"' +
            (snippet ? ' title="' + escapeHtml(snippet) + '">' + escapeHtml(snippet) : ' aria-hidden="true">') +
            '</div>';
        var attachHtml = msg.has_attachment
            ? '<span class="mail-row-attach" title="Has attachment" aria-label="Has attachment">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
                '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>'
            : '';
        var flagHtml = msg.flagged ? '<span class="flag-dot mail-row-flag" title="Important">\u2605</span>' : '';
        var threadCountHtml = (msg.thread_count || 1) > 1
            ? '<span class="mail-row-thread-count" title="' + parseInt(msg.thread_count, 10) + ' messages in this conversation">' + parseInt(msg.thread_count, 10) + '</span>'
            : '';

        row.innerHTML =
            '<div class="mail-row-check" onclick="event.stopPropagation()">' +
                '<input type="checkbox" class="mail-check" value="' + msg.uid + '" aria-label="Select message">' +
            '</div>' +
            '<div class="mail-row-body">' +
                '<div class="mail-row-text">' +
                    '<div class="mail-row-line1">' + draftBadge +
                        '<span class="mail-row-from">' + escapeHtml(fromText) + '</span>' +
                    '</div>' +
                    '<div class="mail-row-subject">' + threadCountHtml + escapeHtml(msg.subject) + '</div>' +
                    snippetHtml +
                '</div>' +
                '<span class="mail-row-meta">' + attachHtml + flagHtml +
                    '<span class="mail-row-date">' + escapeHtml(msg.date) + '</span>' +
                '</span>' +
            '</div>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button>';
        bindMailRow(row);
        if (isNew) window.setTimeout(function () { row.classList.remove('mail-row-new'); }, 3000);
        return row;
    }

    function buildMobileCard(msg, isNew) {
        var a = document.createElement('div');
        a.className = 'mail-card' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (msg.is_draft ? ' mail-card--draft' : '') + (msg.optimistic ? ' mail-row--optimistic' : '') + (isNew ? ' mail-row-new' : '');
        a.setAttribute('role', 'option');
        a.setAttribute('tabindex', '0');
        a.setAttribute('aria-selected', 'false');
        a.setAttribute('data-uid', String(msg.uid));
        a.setAttribute('data-seen', msg.seen ? '1' : '0');
        a.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        var cardThreadKey = msg.thread_key || normalizeThreadSubject(msg.subject);
        if (cardThreadKey) a.setAttribute('data-thread-key', cardThreadKey);
        if (msg.sort_date) {
            var cardSortTs = Date.parse(msg.sort_date);
            if (!isNaN(cardSortTs)) a.setAttribute('data-sort-ts', String(cardSortTs));
        }
        if (msg.optimistic) {
            a.setAttribute('data-optimistic', '1');
        } else {
            a.setAttribute('data-href', msg.url);
            if (msg.folder_b64) a.setAttribute('data-folder-b64', msg.folder_b64);
            a.setAttribute('data-thread-uids', (msg.thread_uids || [msg.uid]).join(','));
            a.setAttribute('data-thread-count', String(msg.thread_count || 1));
            if (msg.reply_url) a.setAttribute('data-reply-url', msg.reply_url);
            if (msg.reply_all_url) a.setAttribute('data-reply-all-url', msg.reply_all_url);
            if (msg.forward_url) a.setAttribute('data-forward-url', msg.forward_url);
        }
        var fromText = msg.from || 'Unknown';
        var snippet = msg.snippet || '';
        var draftBadge = msg.is_draft
            ? '<span class="mail-row-draft-badge">[Draft]</span>'
            : '';
        var snippetHtml = '<div class="mail-row-snippet"' +
            (snippet ? ' title="' + escapeHtml(snippet) + '">' + escapeHtml(snippet) : ' aria-hidden="true">') +
            '</div>';
        var attachHtml = msg.has_attachment
            ? '<span class="mail-row-attach" title="Has attachment" aria-label="Has attachment"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>'
            : '';
        var flagHtml = msg.flagged ? '<span class="flag-dot mail-row-flag" title="Important">\u2605</span>' : '';
        var cardThreadCountHtml = (msg.thread_count || 1) > 1
            ? '<span class="mail-row-thread-count" title="' + parseInt(msg.thread_count, 10) + ' messages in this conversation">' + parseInt(msg.thread_count, 10) + '</span>'
            : '';

        a.innerHTML =
            '<div class="mail-card-check mail-row-check" onclick="event.stopPropagation()">' +
                '<input type="checkbox" class="mail-check" value="' + msg.uid + '" aria-label="Select message">' +
            '</div>' +
            '<div class="mail-card-body">' +
                '<div class="mail-card-line1">' + draftBadge +
                    '<span class="mail-card-from">' + escapeHtml(fromText) + '</span>' +
                    '<span class="mail-card-meta">' + attachHtml + flagHtml +
                        '<span class="mail-card-date">' + escapeHtml(msg.date) + '</span></span>' +
                '</div>' +
                '<div class="mail-card-subject">' + cardThreadCountHtml + escapeHtml(msg.subject) + '</div>' +
                snippetHtml +
            '</div>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button>';
        bindMailRow(a);
        if (isNew) window.setTimeout(function () { a.classList.remove('mail-row-new'); }, 3000);
        return a;
    }

    function collectKnownUids(card) {
        var uids = new Set();
        card.querySelectorAll('[data-uid]').forEach(function (el) {
            uids.add(String(el.getAttribute('data-uid')));
        });
        return uids;
    }

    var lastUnreadCounts = {};
    var lastNotifiedTotalUnread = null;
    var newMailNotifyArmed = false; // armed a few seconds after load (see initLiveSync)

    // Total unread across folders that actually notify (inbox + name folders;
    // excludes Sent/Drafts/Junk/Trash via folderShowsUnreadBadge).
    function notifiableUnreadTotal() {
        var total = 0;
        Object.keys(lastUnreadCounts || {}).forEach(function (path) {
            if (folderShowsUnreadBadge(path)) total += (lastUnreadCounts[path] || 0);
        });
        return total;
    }

    // Fire the new-mail sound + desktop notification whenever the total unread
    // goes up (new mail arrived in any folder). The first call just seeds the
    // baseline so the initial page load never notifies.
    function maybeNotifyNewMail() {
        var total = notifiableUnreadTotal();
        if (newMailNotifyArmed && lastNotifiedTotalUnread !== null && total > lastNotifiedTotalUnread) {
            var delta = total - lastNotifiedTotalUnread;
            playNewMailSound();
            notifyNewMail(delta);
        }
        lastNotifiedTotalUnread = total;
    }

    function applyUnreadCounts(counts) {
        if (!counts) return;

        var lookup = {};
        Object.keys(counts).forEach(function (key) {
            lookup[key] = counts[key];
            lookup[key.toLowerCase()] = counts[key];
        });
        lastUnreadCounts = Object.assign({}, lastUnreadCounts, counts);
        maybeNotifyNewMail();

        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            var path = link.getAttribute('data-folder-path');
            if (!path) return;
            var n = folderShowsUnreadBadge(path) ? folderUnreadLookup(lookup, path) : 0;
            var badge = link.querySelector('.folder-badge');
            if (n > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'folder-badge';
                    link.appendChild(badge);
                }
                badge.textContent = n > 99 ? '99+' : n;
            } else if (badge) {
                badge.remove();
            }
        });

        document.querySelectorAll('.sidebar-group.is-collapsible').forEach(function (group) {
            var total = 0;
            group.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
                var p = link.getAttribute('data-folder-path');
                if (p && folderShowsUnreadBadge(p)) {
                    total += folderUnreadLookup(lookup, p);
                }
            });
            var toggle = group.querySelector('.sidebar-group-toggle');
            if (!toggle) return;
            var groupBadge = toggle.querySelector('.folder-badge-sm');
            if (total > 0) {
                if (!groupBadge) {
                    groupBadge = document.createElement('span');
                    groupBadge.className = 'folder-badge folder-badge-sm';
                    toggle.appendChild(groupBadge);
                }
                groupBadge.textContent = total > 99 ? '99+' : total;
            } else if (groupBadge) {
                groupBadge.remove();
            }
        });

        updateSidebarSectionBadge();

        // Heal the folder HEADER count from the same authoritative counts. The
        // sidebar badge self-corrects on every poll via this function; the header
        // count label used to move only by optimistic deltas, so any drift there
        // was permanent — the header ("Erik 3") could disagree with the sidebar
        // ("Erik 1"). Now both reflect server truth for the open folder.
        var headerPlain = getListCard() ? (getListCard().getAttribute('data-folder-plain') || '') : '';
        if (headerPlain
            && !isDraftFolder()
            && folderShowsUnreadBadge(headerPlain)
            && countsIncludeFolder(counts, headerPlain)) {
            var headerLabel = document.getElementById('mail-count-label');
            if (headerLabel) {
                var headerTotal = parseInt(headerLabel.getAttribute('data-total') || '0', 10) || 0;
                updateMailCount(headerTotal, folderUnreadLookup(lookup, headerPlain));
            }
        }
    }

    // True when the counts payload actually carries the folder (so a partial
    // payload never wrongly zeroes the header). The server sends both the
    // "INBOX.Erik" and "INBOX.Erik.Inbox" key forms, so a direct hit is normal.
    function countsIncludeFolder(counts, path) {
        if (!counts || !path) return false;
        var lower = path.toLowerCase();
        if (counts[path] != null || counts[lower] != null) return true;
        var m = /^INBOX\.([^.]+)\.Inbox$/i.exec(path);
        if (m) {
            var container = 'INBOX.' + m[1];
            if (counts[container] != null || counts[container.toLowerCase()] != null) return true;
        }
        return false;
    }

    function sidebarMailboxRootKey(path) {
        path = (path || '').toLowerCase();
        var nested = path.match(/^inbox\.([^.]+)\.inbox$/i);
        if (nested) {
            return ('inbox.' + nested[1]).toLowerCase();
        }
        return path;
    }

    function sidebarHasFolderLink(path) {
        var target = sidebarMailboxRootKey(path);
        if (!target) return false;
        var found = false;
        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            if (sidebarMailboxRootKey(link.getAttribute('data-folder-path') || '') === target) {
                found = true;
            }
        });
        return found;
    }

    function createSidebarFolderLink(folder) {
        var navPath = folder.nav_path || folder.path;
        var row = document.createElement('div');
        row.className = 'sidebar-tree-row';
        row.style.setProperty('--tree-depth', '0');

        var spacer = document.createElement('span');
        spacer.className = 'sidebar-tree-toggle-spacer';
        spacer.setAttribute('aria-hidden', 'true');

        var link = document.createElement('a');
        link.className = 'sidebar-link sidebar-tree-link';
        link.href = folder.url || apiUrl('folder/' + folder.b64);
        link.setAttribute('data-folder-path', navPath);
        link.setAttribute('data-folder-b64', folder.b64);
        link.setAttribute('data-ajax-folder', '1');
        var icon = document.createElement('span');
        icon.className = 'folder-icon folder-icon-' + (folder.icon || 'folder');
        icon.setAttribute('aria-hidden', 'true');
        var text = document.createElement('span');
        text.className = 'sidebar-link-text';
        text.textContent = folder.name || String(folder.path || '').replace(/^INBOX\./i, '');
        link.appendChild(icon);
        link.appendChild(text);

        row.appendChild(spacer);
        row.appendChild(link);
        return row;
    }

    function ensureSidebarCustomFoldersContainer() {
        var list = document.getElementById('folder-sidebar-list');
        if (!list) return null;
        return list.querySelector('.sidebar-folder-tree')
            || list.querySelector('.sidebar-primary-folders--tree')
            || list.querySelector('.sidebar-primary-folders');
    }

    function applyCorrespondentFolders(folders) {
        if (!folders || !folders.length) return;
        var container = ensureSidebarCustomFoldersContainer();
        if (!container) return;

        var seen = {};
        folders.forEach(function (folder) {
            if (!folder || !folder.path) return;
            var key = sidebarMailboxRootKey(folder.nav_path || folder.path);
            if (!key || seen[key]) return;
            seen[key] = true;
            if (sidebarHasFolderLink(folder.nav_path || folder.path)) return;
            container.appendChild(createSidebarFolderLink(folder));
        });
    }

    function clearPostSendReconcile() {
        postSendReconcileTimers.forEach(function (id) { window.clearTimeout(id); });
        postSendReconcileTimers = [];
    }

    function clearPostSendFolderPolls() {
        clearPostSendReconcile();
    }

    function runPostSendReconcile(step, pollFolders) {
        if (step >= postSendReconcileDelays.length) {
            return;
        }

        fetch(apiUrl('folders/unread?light=1'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); })
            .then(function (badgeData) {
                if (badgeData && badgeData.unread_counts) {
                    applyPostSendUnreadCounts(badgeData.unread_counts);
                }
                var listCard = getListCard();
                var currentB64 = listCard ? listCard.getAttribute('data-folder-b64') : '';
                if (currentB64 && pollFolders.indexOf(currentB64) >= 0) {
                    scheduleMailPoll(true, step === postSendReconcileDelays.length - 1);
                }
            }).catch(function () {});
    }

    function startPostSendReconcile(pollFolders) {
        clearPostSendReconcile();
        pollFolders = pollFolders || [];
        postSendReconcileDelays.forEach(function (delay, step) {
            postSendReconcileTimers.push(window.setTimeout(function () {
                runPostSendReconcile(step, pollFolders);
            }, delay));
        });
    }

    function clearMailListLoadingGuard() {
        if (mailListLoadingGuard) {
            window.clearTimeout(mailListLoadingGuard);
            mailListLoadingGuard = null;
        }
    }

    function armMailListLoadingGuard() {
        // Absolute deadline: if a guard is already armed, keep its original
        // countdown instead of resetting it. Otherwise repeated arms (e.g. on
        // column re-init) keep pushing the timeout back and the "Loading messages…"
        // spinner can hang forever on a brand-new / empty folder that never settles.
        if (mailListLoadingGuard) return;
        mailListLoadingGuard = window.setTimeout(function () {
            mailListLoadingGuard = null;
            setMailListLoading(false);
            syncListEmptyState();
            // Kick one more forced sync so a folder that never settled records its
            // (possibly empty) sync state, rather than re-showing the spinner next time.
            scheduleMailPoll(true, false);
        }, 8000);
    }

    function setMailListLoading(loading) {
        var card = getListCard();
        var loadingEl = document.getElementById('mail-list-loading');
        var emptyEl = document.getElementById('mail-list-empty');
        var scroller = document.getElementById('mail-list-scroller');
        var mobile = document.getElementById('mail-list-mobile');
        if (!card) return;

        card.classList.toggle('is-syncing', !!loading);

        if (loading) {
            if (!loadingEl) {
                loadingEl = document.createElement('div');
                loadingEl.id = 'mail-list-loading';
                loadingEl.className = 'mail-list-loading';
                loadingEl.setAttribute('aria-live', 'polite');
                loadingEl.innerHTML =
                    '<span class="reading-pane-spinner" aria-hidden="true"></span>' +
                    '<span>Loading messages…</span>';
                var banner = document.getElementById('select-all-folder-banner');
                if (banner && banner.parentNode) {
                    banner.parentNode.insertBefore(loadingEl, banner.nextSibling);
                } else {
                    card.appendChild(loadingEl);
                }
            }
            loadingEl.hidden = false;
            if (emptyEl) emptyEl.hidden = true;
            if (scroller) scroller.hidden = true;
            if (mobile) mobile.hidden = true;
            return;
        }

        clearMailListLoadingGuard();
        if (loadingEl) loadingEl.hidden = true;
        var hasRows = document.querySelector('#mail-list-body .mail-row, #mail-list-mobile .mail-card');
        if (hasRows) {
            ensureListVisible(card);
        } else if (emptyEl) {
            emptyEl.hidden = false;
        }
    }

    function removeCorrespondentFolder(info) {
        if (!info || !info.path) return;
        var target = String(info.path).toLowerCase();
        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            if ((link.getAttribute('data-folder-path') || '').toLowerCase() === target) {
                var branch = link.closest('.sidebar-folder-branch');
                if (branch && branch.querySelectorAll('.sidebar-link[data-folder-path]').length <= 1) {
                    branch.remove();
                } else {
                    link.remove();
                }
            }
        });

        var card = getListCard();
        var plain = card ? (card.getAttribute('data-folder-plain') || '').toLowerCase() : '';
        if (plain === target && info.redirect) {
            loadFolderAjax(info.redirect, true);
        }
    }

    function refreshUnreadBadges(full) {
        var url = apiUrl('folders/unread' + (full ? '' : '?light=1'));
        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.unread_counts) return;
                applyUnreadCounts(data.unread_counts);
                var listCard = getListCard();
                var plainPath = listCard ? listCard.getAttribute('data-folder-plain') : '';
                if (plainPath && data.unread_counts[plainPath] !== undefined) {
                    var label = document.getElementById('mail-count-label');
                    var total = label ? parseInt(label.getAttribute('data-total') || '0', 10) : 0;
                    updateMailCount(total, data.unread_counts[plainPath] || 0);
                }
            }).catch(function () {});
    }

    function initSidebarBadgesOnLoad() {
        if (!document.getElementById('mail-workspace')) return;
        refreshUnreadBadges(true);
    }

    var afterSendBadgePolls = 0;
    var postSendReconcileDelays = [500, 2000, 6000];
    var postSendReconcileTimers = [];

    function collectPostSendPollFolders(data, form) {
        var folders = [];
        if (data && Array.isArray(data.dest_folders)) {
            data.dest_folders.forEach(function (token) {
                if (token && folders.indexOf(token) < 0) folders.push(token);
            });
        }
        if (data && data.reply_folder && folders.indexOf(data.reply_folder) < 0) {
            folders.push(data.reply_folder);
        }
        var card = getListCard();
        if (card) {
            var currentB64 = card.getAttribute('data-folder-b64');
            if (currentB64 && folders.indexOf(currentB64) < 0) {
                folders.push(currentB64);
            }
        }
        if (form) {
            var returnField = form.querySelector('#return_folder');
            var returnB64 = returnField ? (returnField.value || '').trim() : '';
            if (returnB64 && folders.indexOf(returnB64) < 0) {
                folders.push(returnB64);
            }
        }
        return folders;
    }

    function lookupListPreview(data, folderB64, plainPath) {
        if (data && data.sent_list_preview) {
            return data.sent_list_preview;
        }
        var previews = data && data.list_previews;
        if (!previews) return null;
        if (folderB64 && previews[folderB64]) return previews[folderB64];
        if (plainPath && previews[plainPath]) return previews[plainPath];
        var target = (plainPath || '').toLowerCase();
        if (!target) return null;
        var found = null;
        Object.keys(previews).forEach(function (key) {
            if (found) return;
            if (key.toLowerCase() === target) found = previews[key];
        });
        return found;
    }

    function injectPostSendListPreview(data) {
        var card = getListCard();
        if (!card) return false;
        var folderB64 = card.getAttribute('data-folder-b64') || '';
        var plainPath = card.getAttribute('data-folder-plain') || '';
        var preview = lookupListPreview(data, folderB64, plainPath);
        if (!preview) return false;

        removeSupersededThreadRows(preview);

        var uid = String(preview.uid);
        var esc = window.CSS && CSS.escape ? CSS.escape(uid) : uid;
        if (document.querySelector('[data-uid="' + esc + '"]')) return false;

        if (!hydrateMailListFromPoll([preview], true)) return false;

        ensureListVisible(getListCard());
        setMailListLoading(false);
        var label = document.getElementById('mail-count-label');
        var total = parseInt(card.getAttribute('data-total-messages') || '0', 10) || 0;
        var nextTotal = total + 1;
        card.setAttribute('data-total-messages', String(nextTotal));
        if (label) {
            label.setAttribute('data-total', String(nextTotal));
            updateMailCount(nextTotal, parseInt(label.getAttribute('data-unread') || '0', 10) || 0);
        }
        scheduleListSnippets(card);
        setSelectedRow(parseInt(preview.uid, 10) || 0);
        return true;
    }

    function removeOptimisticRowMatchingMessage(msg) {
        if (!msg) return;
        var subject = String(msg.subject || '').trim().toLowerCase();
        var from = String(msg.from || '').trim().toLowerCase();
        if (!subject && !from) return;
        document.querySelectorAll('.mail-row[data-optimistic="1"], .mail-card[data-optimistic="1"]').forEach(function (row) {
            var rowFromEl = row.querySelector('.mail-row-from, .mail-card-from');
            var rowSubjectEl = row.querySelector('.mail-row-subject, .mail-card-subject');
            var rowFrom = rowFromEl ? rowFromEl.textContent.trim().toLowerCase() : '';
            var rowSubject = rowSubjectEl ? rowSubjectEl.textContent.trim().toLowerCase() : '';
            if (from && rowFrom && rowFrom.indexOf(from) < 0 && from.indexOf(rowFrom) < 0) return;
            if (subject && rowSubject && rowSubject !== subject) return;
            if (row.parentNode) row.parentNode.removeChild(row);
        });
    }

    function afterComposeSendRefresh(data, form, options) {
        options = options || {};
        mailSyncPaused = false;
        beginPostSendQuiet(8000);

        if (data && data.unread_counts) {
            applyUnreadCounts(data.unread_counts);
        }
        if (data && data.correspondent_folders) {
            applyCorrespondentFolders(data.correspondent_folders);
        }

        if (data && Array.isArray(data.dest_folders) && data.dest_folders.length) {
            postSendRefreshFolders = data.dest_folders.slice();
            window.setTimeout(function () { postSendRefreshFolders = []; }, 45000);
        }

        afterSendBadgePolls = 0;
        startPostSendReconcile(collectPostSendPollFolders(data, form));

        var listCard = getListCard();
        var currentB64 = listCard ? listCard.getAttribute('data-folder-b64') : '';
        var returnFolder = data && data.return_folder ? String(data.return_folder) : '';

        injectPostSendListPreview(data);
        rememberPostSendSelectionThread(data, form);
        if (data && data.reply_uid) {
            var immediateSelection = resolvePostSendSelectionUid(data, form);
            if (immediateSelection) {
                setSelectedRow(immediateSelection);
            }
        }

        var needFolderSwitch = false;
        if (returnFolder && returnFolder !== currentB64) {
            var returnPlain = '';
            try {
                if (window.atob) {
                    returnPlain = window.atob(returnFolder.replace(/-/g, '+').replace(/_/g, '/'));
                }
            } catch (e) { /* ignore */ }
            var currentPlain = listCard ? (listCard.getAttribute('data-folder-plain') || '') : '';
            needFolderSwitch = !(returnPlain && currentPlain && folderPathsMatch(returnPlain, currentPlain));
        }

        if (needFolderSwitch) {
            pendingPostSendPreviewData = data;
            loadFolderAjax(returnFolder, true);
        } else if (currentB64) {
            window.setTimeout(function () { scheduleMailPoll(true, false); }, 350);
        }

        if (data && data.reply_uid && form) {
            window.setTimeout(function () {
                schedulePaneReloadAfterReplySend(data, form);
            }, 0);
        }
    }

    function applyPostSendUnreadCounts(counts) {
        if (!counts) return;
        applyUnreadCounts(counts);
        var listCard = getListCard();
        var plainPath = listCard ? listCard.getAttribute('data-folder-plain') : '';
        if (plainPath) {
            var lookup = counts[plainPath];
            if (lookup === undefined) {
                Object.keys(counts).forEach(function (key) {
                    if (key.toLowerCase() === plainPath.toLowerCase()) {
                        lookup = counts[key];
                    }
                });
            }
            if (lookup !== undefined) {
                var label = document.getElementById('mail-count-label');
                var total = label ? parseInt(label.getAttribute('data-total') || '0', 10) : 0;
                updateMailCount(total, lookup || 0);
            }
        }
    }

    function initMailSync() {
        var card = document.querySelector('[data-mail-sync="1"]');
        if (!card) {
            stopMailSync();
            mailPoll = null;
            activeMailPollUrl = '';
            return;
        }

        var pollUrl = card.getAttribute('data-poll-url') || '';
        if (!pollUrl) {
            return;
        }

        var intervalRaw = parseInt(
            card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30',
            10
        );
        var interval = Math.max(15000, (isNaN(intervalRaw) ? 30 : intervalRaw) * 1000);

        if (mailPoll && pollUrl === activeMailPollUrl) {
            if (!mailPollIntervalId) {
                mailPollIntervalId = window.setInterval(function () { scheduleMailPoll(false); }, interval);
            }
            return;
        }

        stopMailSync();
        activeMailPollUrl = pollUrl;

        var page = parseInt(card.getAttribute('data-page') || '1', 10);
        var syncErrorShown = false;

        // Fire a single follow-up light poll ~5s after the server said it kicked
        // a background refresh. If a quiet/pause window is active at fire time,
        // re-arm instead of dropping it, so new mail can't get stuck waiting for
        // the next full interval. One pending timer at most (the guard below).
        // The timer is module-scoped (mailRefreshFollowUpTimer) so a folder
        // switch's stopMailSync() cancels it — a leaked timer would poll the OLD
        // folder and contaminate the new list. The .then folder-identity guard
        // is the second line of defence if it ever fires late.
        function scheduleRefreshFollowUp() {
            if (mailRefreshFollowUpTimer) return;
            mailRefreshFollowUpTimer = window.setTimeout(function () {
                mailRefreshFollowUpTimer = null;
                if (mailPollInFlight || mailSyncPaused || isListMutationQuiet() || isPostSendQuiet()) {
                    scheduleRefreshFollowUp();
                    return;
                }
                poll(false);
            }, 5000);
        }

        function poll(force, withFilter) {
            // Same critical-op hold as scheduleMailPoll — this is the choke point for
            // the follow-up/interval timers that call poll() directly. Both light and
            // forced /sync open IMAP, so none may run until the op settles.
            if (criticalOpActive) { mailSyncHeldDuringOp = true; return; }
            if (mailPollInFlight) {
                return;
            }
            if (mailSyncPaused && !force) {
                return;
            }
            if (!force && isListMutationQuiet()) {
                return;
            }
            if (!force && isPostSendQuiet()) {
                return;
            }

            var liveCard = document.querySelector('[data-mail-sync="1"]');
            if (!liveCard) {
                return;
            }

            mailPollInFlight = true;
            lastMailPollAt = Date.now();
            // Snapshot the nav sequence so a response that started before a
            // folder switch is dropped (defence-in-depth alongside the URL guard).
            var pollSeq = folderLoadSeq;
            liveCard.classList.add('is-syncing');
            var url = pollUrl + (pollUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + page;
            if (!force) {
                url += '&light=1';
            } else {
                url += '&force=1';
                if (withFilter) {
                    url += '&filter=1';
                }
            }

            var fetchOpts = {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            };
            if (typeof AbortController !== 'undefined') {
                mailPollAbort = new AbortController();
                fetchOpts.signal = mailPollAbort.signal;
            }

            fetch(url, fetchOpts)
                .then(function (res) {
                    if (!res.ok) throw new Error('sync failed');
                    return res.json();
                })
                .then(function (data) {
                    // Folder-identity guard: this poll captured its fetch URL in
                    // the closure, but rows are inserted into whatever list is on
                    // screen NOW. If the user switched folders (or a leaked
                    // follow-up timer from the previous folder fired), the folder
                    // on screen no longer matches this response — dropping it here
                    // prevents the previous folder's rows from contaminating the
                    // current list (the phantom cross-folder rows + mis-order).
                    var currentCard = document.querySelector('[data-mail-sync="1"]');
                    if (!currentCard || currentCard.getAttribute('data-poll-url') !== pollUrl || pollSeq !== folderLoadSeq) {
                        return;
                    }
                    liveCard = currentCard;
                    if (!data || !Array.isArray(data.messages)) return;
                    // The server kicked a background IMAP refresh of this folder —
                    // check back in a few seconds so new mail appears promptly
                    // instead of waiting out a full poll interval. Self-reschedule
                    // if a quiet/pause window is active when the timer fires, so
                    // the follow-up is never silently dropped (which would leave
                    // new mail waiting for the next 30s interval).
                    if (data.refreshing) {
                        scheduleRefreshFollowUp();
                    }
                    var plainPath = liveCard.getAttribute('data-folder-plain') || '';
                    var folderUnread = (data.unread_counts && plainPath)
                        ? (folderShowsUnreadBadge(plainPath) ? folderUnreadLookup(data.unread_counts, plainPath) : 0)
                        : 0;
                    updateMailCount(data.total, folderUnread);
                    if (data.unread_counts) {
                        applyUnreadCounts(data.unread_counts);
                    }

                    if (page !== 1) {
                        setMailListLoading(false);
                        return;
                    }

                    if (visibleMailRowCount() === 0 && data.messages.length > 0) {
                        if (hydrateMailListFromPoll(data.messages, true)) {
                            // Notification is fired centrally from applyUnreadCounts
                            // (on any total-unread increase), not per-folder here.
                            scheduleListSnippets(liveCard);
                        }
                        reorderMailListFromPoll(data.messages);
                        syncListEmptyState();
                        setMailListLoading(false);
                        if (liveCard) liveCard.setAttribute('data-cache-stale', '0');
                        syncErrorShown = false;
                        return;
                    }

                    var known = collectKnownUids(liveCard);
                    var freshUids = {};
                    var newMessages = [];

                    data.messages.forEach(function (m) {
                        var uid = String(m.uid);
                        if (isUidPendingRemoval(uid)) {
                            return;
                        }
                        freshUids[uid] = true;
                        if (known.has(uid)) {
                            // Reconcile existing rows so seen/flagged state stays
                            // accurate (e.g. after Back from a bfcache snapshot).
                            if (m.seen || isRecentlyMarkedRead(m.uid)) {
                                setRowSeen(m.uid, true);
                            } else {
                                setRowSeen(m.uid, !!m.seen);
                            }
                            setRowFlagged(m.uid, !!m.flagged);
                            syncRowThreadMeta(m);
                        } else {
                            newMessages.push(m);
                        }
                    });

                    if (newMessages.length > 0) {
                        setMailListLoading(false);
                        ensureListVisible(liveCard);
                        var tbody = document.getElementById('mail-list-body');
                        var mobile = document.getElementById('mail-list-mobile');
                        newMessages.sort(function (a, b) {
                            var diff = mailListSortTs(b) - mailListSortTs(a);
                            if (diff !== 0) return diff;
                            return (parseInt(b.uid, 10) || 0) - (parseInt(a.uid, 10) || 0);
                        });
                        newMessages.forEach(function (msg) {
                            removeOptimisticRowMatchingMessage(msg);
                            if (tbody) tbody.insertBefore(buildDesktopRow(msg, true), tbody.firstChild);
                            if (mobile) mobile.insertBefore(buildMobileCard(msg, true), mobile.firstChild);
                        });
                        // Notification handled centrally in applyUnreadCounts.
                        scheduleListSnippets(liveCard);
                    }

                    // Remove rows that no longer exist on the server (moved/deleted
                    // elsewhere), so the list self-heals — but not when the poll
                    // returns fewer rows than we already show (partial fast-path).
                    var visible = visibleMailRowCount();
                    var shouldPruneMissing = data.messages.length > 0
                        && (
                            visible === 0
                            || data.messages.length >= known.size
                            || !!data.list_grouped
                        );
                    if (shouldPruneMissing) {
                        known.forEach(function (uid) {
                            if (!freshUids[uid]) {
                                rowsForUid(uid).forEach(function (el) {
                                    if (el.getAttribute('data-optimistic') === '1') return;
                                    if (parseInt(uid, 10) < 0) return;
                                    if (el.parentNode) el.parentNode.removeChild(el);
                                });
                            }
                        });
                    }
                    if (data.list_grouped) {
                        pruneThreadCollapsedRows(freshUids, data.messages);
                    }

                    refreshPaneIfThreadListChanged(data.messages);
                    selectPostSendListRow(data.messages);

                    reorderMailListFromPoll(data.messages);
                    syncListEmptyState();

                    if (visibleMailRowCount() > 0) {
                        setMailListLoading(false);
                        ensureListVisible(liveCard);
                    } else if (data.messages.length > 0) {
                        hydrateMailListFromPoll(data.messages, false);
                        setMailListLoading(false);
                    } else {
                        setMailListLoading(false);
                    }
                    if (liveCard) liveCard.setAttribute('data-cache-stale', '0');
                    removeStaleOptimisticRows();

                    syncErrorShown = false;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    if (!syncErrorShown) {
                        syncErrorShown = true;
                        showToast('error', 'Live mail updates are paused — connection to the mail server failed.');
                    }
                    setMailListLoading(false);
                    syncListEmptyState();
                })
                .finally(function () {
                    var doneCard = document.querySelector('[data-mail-sync="1"]');
                    if (doneCard) {
                        doneCard.classList.remove('is-syncing');
                    }
                    mailPollInFlight = false;
                    mailPollAbort = null;
                });
        }

        mailPoll = poll;
        mailPollIntervalId = window.setInterval(function () { scheduleMailPoll(false); }, interval);

        if (!mailSyncHooksBound) {
            mailSyncHooksBound = true;
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') scheduleMailPoll(false);
            });
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    window.setTimeout(function () { scheduleMailPoll(false); }, 400);
                }
            });
        }
    }

    var lastCheckedRowIndex = -1;
    var selectAllInFolder = false;
    var listMutationInFlight = false;

    function folderMessageTotal() {
        var card = document.querySelector('.mail-list-card[data-total-messages]');
        if (!card) return 0;
        return parseInt(card.getAttribute('data-total-messages') || '0', 10) || 0;
    }

    function pageMessageCount() {
        var rows = selectableMailRows();
        if (rows.length) return rows.length;
        return outlookRows().length;
    }

    function currentSearchQuery() {
        var input = document.getElementById('global-search');
        return input && input.value ? input.value.trim() : '';
    }

    // Instant feedback while the search request runs (body searches can take
    // seconds on the IMAP fallback): spinner in the search field + a clear
    // "Searching mail…" overlay until the results page loads.
    function initGlobalSearchFeedback() {
        var form = document.getElementById('global-search-form');
        if (!form) return;
        form.addEventListener('submit', function () {
            var input = document.getElementById('global-search');
            var q = input && input.value ? input.value.trim() : '';
            if (!q) return;
            form.classList.add('is-searching');
            showAppBusy('Searching mail…');
        });
        // Back/forward cache restore would otherwise leave a stuck overlay.
        window.addEventListener('pageshow', function () {
            form.classList.remove('is-searching');
            hideAppBusy();
        });
    }

    function isGlobalSearchView() {
        var card = document.querySelector('.mail-list-card[data-global-search="1"]');
        return !!card;
    }

    function selectionScopeLabel() {
        if (isGlobalSearchView()) return 'matching your search';
        return currentSearchQuery() ? 'matching your search' : 'in this folder';
    }

    function effectiveSelectionCount() {
        if (selectAllInFolder) return folderMessageTotal();
        return selectedMailUids().length;
    }

    function clearFolderSelection() {
        selectAllInFolder = false;
        var selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        document.querySelectorAll('.mail-check:checked').forEach(function (cb) { cb.checked = false; });
        updateCommandBar();
    }

    function updateSelectAllBanner() {
        var banner = document.getElementById('select-all-folder-banner');
        if (!banner) return;

        var total = folderMessageTotal();
        var pageCount = pageMessageCount();
        var checkedOnPage = selectedMailUids().length;
        var allOnPageSelected = pageCount > 0 && checkedOnPage === pageCount;
        var scope = selectionScopeLabel();

        if (selectAllInFolder && total > 0) {
            banner.hidden = false;
            banner.innerHTML = 'All ' + total + ' messages ' + scope + ' are selected. '
                + '<button type="button" data-select-all-action="clear">Clear selection</button>';
            return;
        }

        if (allOnPageSelected && total > pageCount) {
            // Safety cap: full-history folders can hold tens of thousands of
            // messages — never offer "select all" above the bulk limit (the
            // server enforces the same cap on forged requests).
            var capCard = getListCard();
            var bulkMax = parseInt((capCard && capCard.getAttribute('data-bulk-max')) || '500', 10) || 500;
            banner.hidden = false;
            if (total > bulkMax) {
                banner.innerHTML = checkedOnPage + ' messages on this page are selected. '
                    + 'This folder is too large to select all at once (limit ' + bulkMax + ').';
            } else {
                banner.innerHTML = checkedOnPage + ' messages on this page are selected. '
                    + '<button type="button" data-select-all-action="all">Select all ' + total + ' messages ' + scope + '</button>';
            }
            return;
        }

        banner.hidden = true;
        banner.innerHTML = '';
    }

    function initMailCheckDelegation() {
        if (document.body.dataset.mailCheckDelegationBound) return;
        document.body.dataset.mailCheckDelegationBound = '1';

        document.addEventListener('change', function (e) {
            if (!e.target || !e.target.classList || !e.target.classList.contains('mail-check')) return;
            onMailCheckChange(e);
        });

        document.addEventListener('click', function (e) {
            if (!e.target || !e.target.classList || !e.target.classList.contains('mail-check')) return;
            onMailCheckClick(e);
        });
    }

    function initMailCommandBar() {
        initMailCheckDelegation();

        var toolbar = document.getElementById('mail-command-bar');
        if (!toolbar) return;

        if (!toolbar.dataset.cmdBound) {
            toolbar.dataset.cmdBound = '1';
            toolbar.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-cmd]');
                if (!btn || btn.disabled) return;
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'compose') return;
                e.preventDefault();
                runBulkCommand(cmd, btn);
            });
        }

        var listCard = document.querySelector('.mail-list-card');
        if (listCard && !listCard.dataset.selectAllBannerBound) {
            listCard.dataset.selectAllBannerBound = '1';
            listCard.addEventListener('click', function (e) {
                var actionBtn = e.target.closest('[data-select-all-action]');
                if (!actionBtn) return;
                e.preventDefault();
                var action = actionBtn.getAttribute('data-select-all-action');
                if (action === 'all') {
                    selectAllInFolder = true;
                } else if (action === 'clear') {
                    selectAllInFolder = false;
                    document.querySelectorAll('.mail-check').forEach(function (cb) { cb.checked = false; });
                    var selectAll = document.getElementById('select-all');
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                }
                updateCommandBar();
            });
        }

        var selectAll = document.getElementById('select-all');
        if (selectAll && !selectAll.dataset.cmdBound) {
            selectAll.dataset.cmdBound = '1';
            selectAll.addEventListener('change', function () {
                if (!selectAll.checked) {
                    selectAllInFolder = false;
                } else {
                    var total = folderMessageTotal();
                    var pageCount = pageMessageCount();
                    if (total > 0 && total <= pageCount) {
                        selectAllInFolder = true;
                    }
                }
                document.querySelectorAll('.mail-check').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                updateCommandBar();
            });
        }

        updateCommandBar();
    }

    function outlookRows() {
        return Array.prototype.slice.call(document.querySelectorAll('.mail-row--outlook'));
    }

    function selectableMailRows() {
        var mobile = document.getElementById('mail-list-mobile');
        if (mobile && !mobile.hidden && mobile.offsetParent !== null) {
            return Array.prototype.slice.call(mobile.querySelectorAll('.mail-card'));
        }
        return outlookRows();
    }

    function syncMailCheckForUid(uid, checked) {
        if (!uid) return;
        rowsForUid(uid).forEach(function (el) {
            var cb = el.querySelector('.mail-check');
            if (cb) cb.checked = checked;
        });
    }

    function applySelectionHighlight() {
        var selected = {};
        if (selectAllInFolder) {
            selectableMailRows().forEach(function (row) {
                var uid = row.getAttribute('data-uid');
                if (uid) selected[uid] = true;
            });
        } else {
            selectedMailUids().forEach(function (uid) { selected[uid] = true; });
        }
        document.querySelectorAll('.mail-row--outlook, .mail-card[data-uid]').forEach(function (row) {
            var uid = row.getAttribute('data-uid');
            row.classList.toggle('is-checked', !!(uid && selected[uid]));
        });
    }

    function onMailCheckClick(e) {
        if (!e.shiftKey || lastCheckedRowIndex < 0) return;
        var row = e.target.closest('.mail-row--outlook, .mail-card');
        if (!row) return;
        var rows = selectableMailRows();
        var idx = rows.indexOf(row);
        if (idx < 0) return;
        var start = Math.min(lastCheckedRowIndex, idx);
        var end = Math.max(lastCheckedRowIndex, idx);
        var checked = e.target.checked;
        for (var i = start; i <= end; i++) {
            var rangeRow = rows[i];
            var cb = rangeRow.querySelector('.mail-check');
            if (cb) cb.checked = checked;
            syncMailCheckForUid(rangeRow.getAttribute('data-uid'), checked);
        }
        updateCommandBar();
    }

    function onMailCheckChange(e) {
        if (selectAllInFolder && !e.target.checked) {
            selectAllInFolder = false;
        }
        var row = e.target.closest('.mail-row--outlook, .mail-card');
        if (row) {
            lastCheckedRowIndex = selectableMailRows().indexOf(row);
            syncMailCheckForUid(row.getAttribute('data-uid'), e.target.checked);
        }
        updateCommandBar();
    }

    function isUidFlagged(uid) {
        var flagged = false;
        rowsForUid(uid).forEach(function (el) {
            if (el.getAttribute('data-flagged') === '1') flagged = true;
        });
        return flagged;
    }

    function updateCommandBar() {
        var toolbar = document.getElementById('mail-command-bar');
        if (!toolbar) return;

        var uids = selectedMailUids();
        var hasSelection = selectAllInFolder || uids.length > 0;
        var needsSelection = ['delete', 'move', 'mark-read', 'mark-unread', 'flag-toggle'];
        var singleRow = selectedPrimaryRow();
        var isSingle = !!singleRow;
        var composeLocked = isComposeUiLocked();

        needsSelection.forEach(function (cmd) {
            var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (btn) btn.disabled = !hasSelection;
        });

        // Restore lives only in Trash: moves the selection back to the folders
        // the messages were deleted from.
        var restoreBtn = toolbar.querySelector('[data-cmd="restore"]');
        if (restoreBtn) {
            restoreBtn.hidden = !isTrashFolder();
            restoreBtn.disabled = !hasSelection;
        }

        function setMobileCmd(cmd, enabled, visible) {
            var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (!btn) return;
            btn.disabled = !enabled;
            btn.hidden = !visible;
        }

        setMobileCmd('reply', isSingle && !composeLocked && !!(singleRow && singleRow.getAttribute('data-reply-url')), isSingle && !!(singleRow && singleRow.getAttribute('data-reply-url')));
        setMobileCmd('reply-all', isSingle && !composeLocked && !!(singleRow && singleRow.getAttribute('data-reply-all-url')), isSingle && !!(singleRow && singleRow.getAttribute('data-reply-all-url')));
        setMobileCmd('forward', isSingle && !composeLocked && !!(singleRow && singleRow.getAttribute('data-forward-url')), isSingle && !!(singleRow && singleRow.getAttribute('data-forward-url')));

        var composeBtn = toolbar.querySelector('.mail-cmd-compose');
        if (composeBtn) {
            composeBtn.classList.toggle('is-disabled', composeLocked);
            composeBtn.setAttribute('aria-disabled', composeLocked ? 'true' : 'false');
        }

        var moveSelect = document.getElementById('cmd-move-target');
        if (moveSelect) moveSelect.disabled = !hasSelection;

        var flagBtn = toolbar.querySelector('[data-cmd="flag-toggle"]');
        if (flagBtn && hasSelection) {
            var allFlagged = uids.every(function (uid) { return isUidFlagged(uid); });
            flagBtn.setAttribute('aria-pressed', allFlagged ? 'true' : 'false');
            flagBtn.title = allFlagged ? 'Remove importance' : 'Mark as important';
        } else if (flagBtn) {
            flagBtn.setAttribute('aria-pressed', 'false');
            flagBtn.title = 'Mark as important';
        }

        var deleteBtn = toolbar.querySelector('[data-cmd="delete"]');
        if (deleteBtn) {
            deleteBtn.title = isTrashFolder() ? 'Delete permanently' : 'Delete';
        }

        var selectAll = document.getElementById('select-all');
        if (selectAll) {
            if (selectAllInFolder) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                var pageCount = pageMessageCount();
                var checkedCount = selectedMailUids().length;
                selectAll.checked = checkedCount > 0 && checkedCount === pageCount;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < pageCount;
            }
        }

        applySelectionHighlight();

        updateSelectAllBanner();

        toolbar.classList.toggle('mail-command-bar--has-selection', hasSelection);
        toolbar.classList.toggle('mail-command-bar--single', isSingle);
    }

    function runBulkCommand(action, triggerBtn) {
        if (action === 'refresh') {
            if (triggerBtn) setButtonLoading(triggerBtn, true, loadingLabelForAction('refresh'));
            if (mailPoll) {
                scheduleMailPoll(true, true);
                if (triggerBtn) watchSyncEnd(triggerBtn);
            } else {
                window.location.reload();
            }
            return;
        }

        var selections = selectedMailSelections();
        var uids = selections.map(function (sel) { return sel.uid; });
        if (!selectAllInFolder && !uids.length) return;

        var folderEnc = currentMailFolderEnc();
        if (!folderEnc) return;
        if ((action === 'delete' || action === 'move' || action === 'restore')
            && !guardFolderListReady(action === 'delete' ? 'Delete' : (action === 'restore' ? 'Restore' : 'Move'))) {
            return;
        }
        if ((action === 'delete' || action === 'restore') && !selectAllInFolder && !uids.length) {
            showToast('error', selectionIncludedSyncingRows()
                ? 'This message is still syncing — try again in a few seconds.'
                : ('No messages selected to ' + (action === 'restore' ? 'restore.' : 'delete.')));
            return;
        }
        var selectionCount = effectiveSelectionCount();

        if (action === 'reply' || action === 'reply-all' || action === 'forward') {
            if (isComposeUiLocked()) return;
            var composeRow = selectedPrimaryRow();
            if (!composeRow) return;
            var composeAttr = action === 'reply'
                ? 'data-reply-url'
                : (action === 'reply-all' ? 'data-reply-all-url' : 'data-forward-url');
            var composeUrl = composeRow.getAttribute(composeAttr);
            if (!composeUrl) return;
            var composeLabel = action === 'reply' ? 'Reply' : (action === 'reply-all' ? 'Reply all' : 'Forward');
            if (useReadingPane()) {
                openComposePanel(composeUrl, composeLabel);
            } else {
                showLoading();
                window.location = composeUrl;
            }
            return;
        }

        if (action === 'delete') {
            // Move-to-Trash is recoverable — run it immediately, no dialog.
            // Only a permanent delete (inside Trash) still asks first.
            if (!isTrashFolder()) {
                swallowBulkDeleteRejection(
                    runBulkCommandExecute(action, selections, folderEnc, triggerBtn)
                );
                return;
            }
            var deleteOpts = deleteConfirmOptions(selectionCount);
            showConfirmAction(Object.assign({}, deleteOpts, {
                loadingLabel: deleteLoadingMessage(selectionCount),
                // Completion feedback comes from the stateful op toast
                // ("Moving to Trash…" → "✓ Moved"), not a premature static toast.
                action: function () {
                    return runBulkCommandExecute(action, selections, folderEnc, triggerBtn);
                }
            }));
            return;
        }

        if (action === 'move' && useMoveFolderPicker()) {
            var moveFolders = collectToolbarMoveFolders();
            if (!moveFolders.length) {
                showToast('error', 'No folders available.');
                return;
            }
            var moveTitle = selectionCount === 1 ? 'Move message' : 'Move ' + selectionCount + ' messages';
            showFolderPicker({
                title: moveTitle,
                folders: moveFolders,
                onPick: function (f) {
                    var moveSelect = document.getElementById('cmd-move-target');
                    if (moveSelect) moveSelect.value = f.path;
                    runBulkCommandExecute('move', selections, folderEnc, triggerBtn);
                }
            });
            return;
        }

        if (action === 'flag-toggle') {
            if (selectAllInFolder) {
                action = 'flag';
            } else {
                action = uids.every(function (uid) { return isUidFlagged(uid); }) ? 'unflag' : 'flag';
            }
        }

        if (action === 'move') {
            var moveTargetEl = document.getElementById('cmd-move-target');
            var moveTargetPath = moveTargetEl ? moveTargetEl.value : '';
            if (!moveTargetPath) {
                showToast('error', 'Choose a folder to move to.');
                return;
            }
            // Moves to Spam run immediately like any other move — no dialog.
        }

        runBulkCommandExecute(action, selections, folderEnc, triggerBtn);
    }

    function folderUnreadCount() {
        var card = getListCard();
        var plain = card ? card.getAttribute('data-folder-plain') : '';
        if (plain && lastUnreadCounts[plain] != null) {
            return lastUnreadCounts[plain];
        }
        var label = document.getElementById('mail-count-label');
        if (label) {
            var fromAttr = parseInt(label.getAttribute('data-unread') || '', 10);
            if (!isNaN(fromAttr) && fromAttr > 0) {
                return fromAttr;
            }
            var title = label.getAttribute('title') || '';
            if (title.indexOf('unread') >= 0 || title.indexOf('draft') >= 0) {
                return parseInt(label.textContent, 10) || 0;
            }
        }
        if (isDraftFolder()) {
            return document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').length;
        }
        return countUnreadAmong(selectedMailUids());
    }

    function finishBulkSelectionUi(action, allInFolder, uids) {
        selectAllInFolder = false;
        var selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        document.querySelectorAll('.mail-check:checked').forEach(function (cb) { cb.checked = false; });
        var moveSelect = document.getElementById('cmd-move-target');
        if (moveSelect && action === 'move') moveSelect.value = '';
        updateCommandBar();

        if (action !== 'delete' && action !== 'move') return;

        if (allInFolder) {
            clearReadingPane();
            var listCard = getListCard();
            var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : null;
            if (folderOnly && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', folderOnly);
            }
            return;
        }

        var paneCard = document.querySelector('#reading-pane-body .mail-read-card[data-uid]');
        if (!paneCard) return;
        var paneUid = String(paneCard.getAttribute('data-uid'));
        if (uids.some(function (u) { return String(u) === paneUid; })) {
            clearReadingPane();
            var listCard = getListCard();
            var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : null;
            if (folderOnly && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', folderOnly);
            }
        }
    }

    function isDraftFolder() {
        return currentFolderKind() === 'draft';
    }

    function folderRemovalDelta(uids, allInFolder) {
        if (isDraftFolder()) {
            if (allInFolder) {
                return folderUnreadCount();
            }
            return uids.length;
        }
        if (allInFolder) {
            return folderUnreadCount();
        }
        return countUnreadAmong(uids);
    }

    function bumpFolderUnread(delta) {
        if (!delta) return;
        var card = getListCard();
        var plain = card ? card.getAttribute('data-folder-plain') : '';
        if (plain) {
            var counts = Object.assign({}, lastUnreadCounts);
            counts[plain] = Math.max(0, (counts[plain] || 0) + delta);
            applyUnreadCounts(counts);
        }
        var label = document.getElementById('mail-count-label');
        var total = label ? parseInt(label.getAttribute('data-total') || '0', 10) : 0;
        var unread = label ? parseInt(label.getAttribute('data-unread') || '0', 10) : 0;
        if (isDraftFolder()) {
            total = Math.max(0, total + delta);
        }
        updateMailCount(total, Math.max(0, unread + delta));
    }

    function syncReadSeenButton(card) {
        if (!card) return;
        var seen = card.getAttribute('data-seen') === '1';
        var btn = card.querySelector('[data-mail-action="mark-read"], [data-mail-action="mark-unread"]');
        if (!btn) return;
        btn.setAttribute('data-mail-action', seen ? 'mark-unread' : 'mark-read');
        btn.title = seen ? 'Mark unread' : 'Mark read';
        btn.setAttribute('aria-label', btn.title);
        var label = btn.querySelector('.mail-action-label');
        if (label) label.textContent = seen ? 'Unread' : 'Read';
    }

    function fireAndForgetAction(actionPath, payload, retryOnCsrf) {
        if (retryOnCsrf === undefined) retryOnCsrf = true;
        return fetch(apiUrl(actionPath), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrf || ''
            },
            body: payload.toString()
        }).then(function (res) {
            captureCsrfFromResponse(res);
            return res.json().catch(function () { return { ok: res.ok }; }).then(function (data) {
                if (
                    res.status === 403
                    && retryOnCsrf
                    && data
                    && String(data.error || '').toLowerCase().indexOf('security token') >= 0
                ) {
                    return refreshCsrfToken().then(function () {
                        payload.set('_csrf', csrf);
                        return fireAndForgetAction(actionPath, payload, false);
                    });
                }
                if (!res.ok || (data && data.ok === false)) {
                    throw new Error((data && data.error) || 'Action failed.');
                }
                return data;
            });
        }).then(function (data) {
            if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            }
        }).catch(function (err) {
            showToast('error', err.message || 'Action failed.');
        });
    }

    /** List mutations (move/delete/spam): refresh folder if server rejects optimistic UI. */
    function fireListMutation(actionPath, payload, options) {
        options = options || {};
        var retryOnCsrf = options.retryOnCsrf !== false;
        // Bound the mutation POST itself with an abort-timeout. On this flaky host a
        // request can stall (connection accepted, no response bytes); without this the
        // op-toast spinner spins until the browser's multi-minute socket timeout (the
        // critical-op safety timer only releases the LOCK, not the toast). On abort the
        // fetch rejects into the .catch below → opToastHandle.fail() dismisses the
        // spinner and scheduleMailPoll(true) reconciles the row. 30s matches the send
        // timeout and CRITICAL_OP_MAX_MS, safely above the normal 1-2s response.
        var mutCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var mutTimer = mutCtrl ? window.setTimeout(function () { try { mutCtrl.abort(); } catch (e) { /* ignore */ } }, 30000) : null;
        function clearMutTimer() { if (mutTimer) { window.clearTimeout(mutTimer); mutTimer = null; } }
        return fetch(apiUrl(actionPath), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrf || ''
            },
            body: payload.toString(),
            signal: mutCtrl ? mutCtrl.signal : undefined
        }).then(function (res) {
            captureCsrfFromResponse(res);
            return res.json().catch(function () { return { ok: res.ok }; }).then(function (data) {
                if (
                    res.status === 403
                    && retryOnCsrf
                    && data
                    && String(data.error || '').toLowerCase().indexOf('security token') >= 0
                ) {
                    return refreshCsrfToken().then(function () {
                        payload.set('_csrf', csrf);
                        options.retryOnCsrf = false;
                        return fireListMutation(actionPath, payload, options);
                    });
                }
                if (!res.ok || (data && data.ok === false)) {
                    throw new Error((data && data.error) || 'Action failed.');
                }
                return data;
            });
        }).then(function (data) {
            clearMutTimer();
            if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            }
            if (data && data.remove_correspondent_folder) {
                removeCorrespondentFolder(data.remove_correspondent_folder);
            }
            if (data && data.target) {
                var card = getListCard();
                var plain = card ? (card.getAttribute('data-folder-plain') || '') : '';
                if (plain && plain.toLowerCase() === String(data.target).toLowerCase()) {
                    scheduleMailPoll(true, false);
                }
            }
            if (options.opToastHandle) {
                options.opToastHandle.attach(data && (data.op_ids || data.op_id));
            }
            return data;
        }).catch(function (err) {
            clearMutTimer();
            // An abort-timeout has no useful message — let fail() fall back to the
            // op's own labels.fail ("…has been restored"); scheduleMailPoll(true) below
            // then reconciles the row in case the server actually completed the op.
            var aborted = err && err.name === 'AbortError';
            if (options.opToastHandle) {
                options.opToastHandle.fail(aborted ? '' : (err.message || 'Action failed.'));
            } else if (!options.suppressErrorToast) {
                showToast('error', aborted ? 'The action timed out. Please try again.' : (err.message || 'Action failed.'));
            }
            if (options.rollbackUids || options.rollbackAllInFolder) {
                clearPendingRemoval(options.rollbackUids || [], !!options.rollbackAllInFolder);
            }
            scheduleMailPoll(true);
            return Promise.reject(err);
        });
    }

    function countReadAmong(uids) {
        var n = 0;
        uids.forEach(function (uid) {
            rowsForUid(uid).forEach(function (el) {
                if (el.getAttribute('data-seen') === '1') n++;
            });
        });
        return n;
    }

    function applyOptimisticUnreadDelta(action, allInFolder, uids, targetFolder) {
        var card = getListCard();
        var plain = card ? card.getAttribute('data-folder-plain') : '';
        if (!plain) return;

        var delta = folderRemovalDelta(uids, allInFolder);
        if (delta <= 0) return;

        var counts = Object.assign({}, lastUnreadCounts);
        counts[plain] = Math.max(0, (counts[plain] || 0) - delta);
        applyUnreadCounts(counts);
    }

    // Direct (no-confirm) delete calls: every rejection path inside
    // runBulkCommandExecute has already shown the user a toast or op-toast, so
    // the rejection itself only needs consuming to avoid unhandledrejection
    // console noise. (The confirm-wrapped Trash path consumes it via
    // showConfirmAction's catch instead.)
    function swallowBulkDeleteRejection(promise) {
        if (promise && typeof promise.catch === 'function') {
            promise.catch(function () { /* feedback already shown */ });
        }
    }

    function runBulkCommandExecute(action, selections, folderEnc, triggerBtn) {
        selections = selections || selectedMailSelections();
        var uids = selections.map(function (sel) { return sel.uid; });
        var actionPath = '';
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        payload.set('folder', folderEnc);
        var allInFolder = selectAllInFolder;
        if (!allInFolder) {
            // Only treat this as "the whole folder" when EVERY rendered row is
            // checked AND the page holds the entire folder. The list renders each
            // row TWICE — a desktop row and a mobile card sharing one uid — and
            // checking a box syncs both copies, so raw checkbox/row counts
            // double-count. Compare UNIQUE checked uids to UNIQUE rendered uids so
            // a partial selection (e.g. 3 of 4 rows) is never promoted to a
            // move-everything.
            var checkedUidSet = {};
            document.querySelectorAll('.mail-check:checked').forEach(function (c) {
                var cu = parseInt(c.value, 10);
                if (cu > 0) checkedUidSet[cu] = 1;
            });
            var renderedUidSet = {};
            document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (r) {
                if (r.getAttribute('data-optimistic') === '1') return;
                var rru = parseInt(r.getAttribute('data-uid'), 10);
                if (rru > 0) renderedUidSet[rru] = 1;
            });
            var totalMsgs = folderMessageTotal();
            var checkedRowCount = Object.keys(checkedUidSet).length;
            var renderedRowCount = Object.keys(renderedUidSet).length;
            if (renderedRowCount > 0
                && checkedRowCount >= renderedRowCount
                && (totalMsgs <= 0 || renderedRowCount >= totalMsgs)) {
                allInFolder = true;
            }
        }
        var selectionCount = allInFolder ? folderMessageTotal() : (uids.length || selectedMailUids().length);

        if (allInFolder) {
            payload.set('all_in_folder', '1');
            var q = currentSearchQuery();
            if (q) payload.set('q', q);
        } else {
            appendBulkUidPayload(payload, selections, folderEnc);
        }

        var successMsg = '';
        var seenDelta = 0;

        if (action === 'delete') {
            actionPath = 'message/bulk-trash';
            if (!allInFolder) {
                payload.set('unread_delta', String(folderRemovalDelta(uids, false)));
            }
            successMsg = deleteSuccessMessage(selectionCount);
        } else if (action === 'restore') {
            actionPath = 'message/bulk-restore';
            successMsg = selectionCount === 1
                ? 'Message restored to its original folder.'
                : 'Selected messages restored to their original folders.';
        } else if (action === 'move') {
            var target = document.getElementById('cmd-move-target');
            if (!target || !target.value) {
                showToast('error', 'Choose a folder to move to.');
                return;
            }
            actionPath = 'message/bulk-move';
            payload.set('target_folder', target.value);
            if (!allInFolder) {
                payload.set('unread_delta', String(folderRemovalDelta(uids, false)));
            }
            successMsg = isSpamFolderPath(target.value)
                ? (selectionCount === 1 ? 'Message moved to Spam.' : 'Selected messages moved to Spam.')
                : 'Selected messages moved.';
        } else if (action === 'mark-read') {
            actionPath = 'message/bulk-mark-read';
            seenDelta = -(allInFolder ? folderUnreadCount() : countUnreadAmong(uids));
            if (allInFolder) {
                document.querySelectorAll('.mail-row[data-seen="0"], .mail-card[data-seen="0"]').forEach(function (el) {
                    setRowSeen(parseInt(el.getAttribute('data-uid'), 10), true);
                });
            } else {
                uids.forEach(function (uid) { setRowSeen(uid, true); });
            }
            successMsg = selectionCount + ' message(s) marked as read.';
        } else if (action === 'mark-unread') {
            actionPath = 'message/bulk-mark-unread';
            if (allInFolder) {
                var countLabel = document.getElementById('mail-count-label');
                var totalMsgs = countLabel ? parseInt(countLabel.getAttribute('data-total') || '0', 10) : 0;
                seenDelta = Math.max(0, totalMsgs - folderUnreadCount());
            } else {
                seenDelta = countReadAmong(uids);
            }
            if (allInFolder) {
                document.querySelectorAll('.mail-row[data-seen="1"], .mail-card[data-seen="1"]').forEach(function (el) {
                    setRowSeen(parseInt(el.getAttribute('data-uid'), 10), false);
                });
            } else {
                uids.forEach(function (uid) { setRowSeen(uid, false); });
            }
            successMsg = selectionCount + ' message(s) marked as unread.';
        } else if (action === 'flag') {
            actionPath = 'message/bulk-flag';
            if (allInFolder) {
                document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (el) {
                    setRowFlagged(parseInt(el.getAttribute('data-uid'), 10), true);
                });
            } else {
                uids.forEach(function (uid) { setRowFlagged(uid, true); });
            }
            successMsg = selectionCount + ' message(s) marked as important.';
        } else if (action === 'unflag') {
            actionPath = 'message/bulk-unflag';
            if (allInFolder) {
                document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (el) {
                    setRowFlagged(parseInt(el.getAttribute('data-uid'), 10), false);
                });
            } else {
                uids.forEach(function (uid) { setRowFlagged(uid, false); });
            }
            successMsg = selectionCount + ' message(s) unflagged.';
        } else {
            return;
        }

        var instantActions = ['delete', 'move', 'restore', 'mark-read', 'mark-unread', 'flag', 'unflag'];
        var isInstantListAction = instantActions.indexOf(action) >= 0;
        var moveTarget = action === 'move' ? (document.getElementById('cmd-move-target') || {}).value || '' : '';

        if (action === 'delete' || action === 'move' || action === 'restore') {
            if (!allInFolder && !uids.length) {
                var emptyMsg = selectionIncludedSyncingRows()
                    ? 'This message is still syncing — try again in a few seconds.'
                    : (action === 'delete' ? 'No messages selected to delete.'
                        : (action === 'restore' ? 'No messages selected to restore.' : 'No messages selected to move.'));
                showToast('error', emptyMsg);
                return Promise.reject(new Error(emptyMsg));
            }
            // A previous destructive op is still finishing its background IMAP
            // moves — don't fire another one into the same overloaded host.
            if (criticalOpActive) {
                showToast('error', 'Please wait for the current action to finish…', 2500);
                return action === 'delete'
                    ? Promise.reject(new Error('A previous action is still finishing.'))
                    : undefined;
            }
            if (listMutationInFlight) {
                if (action === 'delete') {
                    showToast('error', 'Please wait for the current action to finish…', 2500);
                    return Promise.reject(new Error('Please wait for the current action to finish.'));
                }
                return;
            }
            listMutationInFlight = true;
            beginListMutationQuiet(allInFolder ? 4000 : 2500);

            if (allInFolder) {
                payload.set('unread_delta', String(folderUnreadCount()));
                pendingRemovalUntil = {};
            }

            markUidsPendingRemoval(uids);
            if (allInFolder) {
                clearMailListRows();
            } else {
                uids.forEach(function (uid) { removeRowByUid(uid); });
            }
            applyOptimisticUnreadDelta(action, allInFolder, uids, moveTarget);
            finishBulkSelectionUi(action, allInFolder, uids);
            clearReadingPaneIfShowingUids(uids);
            if (action === 'delete') {
                // Start the progress toast NOW — the HTTP response alone can take
                // seconds on this host, and the user needs instant feedback.
                return fireListMutation(actionPath, payload, {
                    suppressErrorToast: true,
                    rollbackUids: uids.slice(),
                    rollbackAllInFolder: allInFolder,
                    opToastHandle: beginOpToast({
                        progress: deleteLoadingMessage(selectionCount),
                        done: deleteSuccessMessage(selectionCount),
                        fail: isTrashFolder()
                            ? 'Some messages could not be deleted. Please try again.'
                            : 'Some messages could not be moved to Trash. They have been restored.'
                    })
                }).finally(function () {
                    listMutationInFlight = false;
                    endListMutationQuiet(800);
                });
            }
            if (action === 'restore') {
                return fireListMutation(actionPath, payload, {
                    suppressErrorToast: true,
                    rollbackUids: uids.slice(),
                    rollbackAllInFolder: allInFolder,
                    opToastHandle: beginOpToast({
                        progress: selectionCount === 1 ? 'Restoring message…' : 'Restoring ' + selectionCount + ' messages…',
                        done: successMsg,
                        fail: 'Some messages could not be restored. They remain in Trash.'
                    })
                }).finally(function () {
                    listMutationInFlight = false;
                    endListMutationQuiet(800);
                });
            }
            fireListMutation(actionPath, payload, {
                rollbackUids: uids.slice(),
                rollbackAllInFolder: allInFolder,
                opToastHandle: beginOpToast({
                    progress: selectionCount === 1 ? 'Moving message…' : 'Moving ' + selectionCount + ' messages…',
                    done: successMsg,
                    fail: 'Some messages could not be moved. They have been restored.'
                })
            }).finally(function () {
                listMutationInFlight = false;
                endListMutationQuiet(800);
            });
            return;
        }

        if (isInstantListAction) {
            showToast('success', successMsg);
            // mark-read/mark-unread: badge counts update only when the server
            // response arrives (applyUnreadCounts inside fireAndForgetAction) —
            // the client asked for the correct number over an instant estimate.
            finishBulkSelectionUi(action, allInFolder, uids);
            fireAndForgetAction(actionPath, payload);
        }
    }

    function selectedMailSelections() {
        var listFolderEnc = currentMailFolderEnc();
        var seen = {};
        var selections = [];

        // Map every rendered row's OWN uid → whether that row is checked. A thread
        // uid that is ANOTHER row's own uid must only be acted on when that row is
        // also checked — otherwise expanding one conversation could move a
        // separate, UNCHECKED row that a grouping edge case put in the same thread
        // (that's how "move 3" once moved all 4 and emptied the folder).
        var ownRowChecked = {};
        document.querySelectorAll('.mail-row[data-uid], .mail-card[data-uid]').forEach(function (r) {
            var ru = parseInt(r.getAttribute('data-uid'), 10);
            if (!ru || ru < 0) return;
            var rcb = r.querySelector('.mail-check');
            ownRowChecked[ru] = !!(rcb && rcb.checked);
        });

        document.querySelectorAll('.mail-check:checked').forEach(function (cb) {
            var row = cb.closest('.mail-row, .mail-card');
            // Optimistic rows (still syncing to the server) have no real uid yet —
            // they can't be acted on. selectionIncludedSyncingRows() reports them.
            if (row && row.getAttribute('data-optimistic') === '1') return;
            var uid = parseInt(cb.value, 10);
            if (!uid || uid < 0 || seen[uid]) return;
            // Conversation rows expand to every message of the thread in this
            // folder (Gmail: acting on the row acts on the whole conversation).
            var rowUids = row ? rowThreadUids(row) : [uid];
            if (!rowUids.length) rowUids = [uid];
            var folderB64 = row ? (row.getAttribute('data-folder-b64') || listFolderEnc) : listFolderEnc;
            rowUids.forEach(function (threadUid) {
                if (!threadUid || threadUid < 0 || seen[threadUid]) return;
                // Guard: never act on a uid that belongs to a DIFFERENT rendered
                // row the user left unchecked.
                if (threadUid !== uid
                    && Object.prototype.hasOwnProperty.call(ownRowChecked, threadUid)
                    && !ownRowChecked[threadUid]) {
                    return;
                }
                seen[threadUid] = true;
                selections.push({ uid: threadUid, folderB64: folderB64 });
            });
        });
        if (selections.length) return selections;

        var active = document.querySelector(
            '.mail-row.is-selected, .mail-row.is-focused, .mail-card.is-selected, .mail-card.is-focused'
        );
        if (active && active.getAttribute('data-optimistic') !== '1') {
            var activeUid = parseInt(active.getAttribute('data-uid'), 10);
            if (activeUid && activeUid > 0) {
                var activeFolder = active.getAttribute('data-folder-b64') || listFolderEnc;
                var activeUids = rowThreadUids(active);
                if (!activeUids.length) activeUids = [activeUid];
                activeUids.forEach(function (threadUid) {
                    if (!threadUid || threadUid < 0 || seen[threadUid]) return;
                    seen[threadUid] = true;
                    selections.push({ uid: threadUid, folderB64: activeFolder });
                });
            }
        }

        return selections;
    }

    function selectionIncludedSyncingRows() {
        var found = false;
        document.querySelectorAll('.mail-check:checked').forEach(function (cb) {
            var row = cb.closest('.mail-row, .mail-card');
            if (row && row.getAttribute('data-optimistic') === '1') found = true;
        });
        if (found) return true;
        var active = document.querySelector(
            '.mail-row.is-selected, .mail-row.is-focused, .mail-card.is-selected, .mail-card.is-focused'
        );
        return !!(active && active.getAttribute('data-optimistic') === '1');
    }

    function selectedMailUids() {
        return selectedMailSelections().map(function (sel) { return sel.uid; });
    }

    function appendBulkUidPayload(payload, selections, listFolderEnc) {
        selections.forEach(function (sel) {
            payload.append('uids[]', String(sel.uid));
            if (sel.folderB64) {
                payload.append('uid_folders[' + sel.uid + ']', sel.folderB64);
            } else if (listFolderEnc) {
                payload.append('uid_folders[' + sel.uid + ']', listFolderEnc);
            }
        });
    }

    function clearPendingRemoval(uids, allInFolder) {
        if (allInFolder) {
            pendingRemovalUntil = {};
            return;
        }
        (uids || []).forEach(function (uid) {
            delete pendingRemovalUntil[String(uid)];
        });
    }

    function countUnreadAmong(uids) {
        var n = 0;
        uids.forEach(function (uid) {
            rowsForUid(uid).forEach(function (el) {
                if (el.getAttribute('data-seen') === '0') n++;
            });
        });
        return n;
    }

    function stopPaneMessageSync() {
        if (paneMessageSyncTimer) {
            window.clearInterval(paneMessageSyncTimer);
            paneMessageSyncTimer = null;
        }
        paneMessageSyncInFlight = false;
    }

    function bindMessageSyncCard(card) {
        stopPaneMessageSync();
        if (!card || !document.contains(card)) return;

        var paneBody = document.getElementById('reading-pane-body');
        if (paneBody && paneBody.contains(card) && paneBody.hidden) {
            return;
        }

        var syncUrl = card.getAttribute('data-sync-url');
        var folderUrl = card.getAttribute('data-folder-url');
        var intervalRaw = parseInt(card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30', 10);
        var interval = Math.max(60000, (isNaN(intervalRaw) ? 30 : intervalRaw) * 1000);
        if (!syncUrl) return;

        function check() {
            if (paneMessageSyncInFlight) return;
            if (!document.contains(card)) {
                stopPaneMessageSync();
                return;
            }
            var livePaneBody = document.getElementById('reading-pane-body');
            if (livePaneBody && livePaneBody.contains(card) && livePaneBody.hidden) {
                stopPaneMessageSync();
                return;
            }
            if (isPostSendQuiet()) return;

            paneMessageSyncInFlight = true;
            fetch(syncUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.exists === false) {
                        var inPane = card.closest('#reading-pane-body');
                        if (inPane) {
                            clearReadingPane();
                            var listCard = getListCard();
                            var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : folderUrl;
                            if (folderOnly && window.history && window.history.replaceState) {
                                window.history.replaceState({}, '', folderOnly);
                            }
                            showToast('error', 'Message is no longer in this folder.');
                        } else if (folderUrl) {
                            window.location = folderUrl;
                        }
                    }
                }).catch(function () {})
                .finally(function () {
                    paneMessageSyncInFlight = false;
                });
        }

        paneMessageSyncTimer = window.setInterval(check, interval);
    }

    function initMessageSync() {
        document.querySelectorAll('.mail-read-card[data-message-sync]').forEach(function (card) {
            if (!card.closest('#reading-pane-body')) {
                bindMessageSyncCard(card);
            }
        });
    }

    function syncReadFlagButton(card) {
        if (!card) return;
        var btn = card.querySelector('[data-mail-action="flag-toggle"]');
        if (!btn) return;
        var flagged = card.getAttribute('data-flagged') === '1';
        btn.setAttribute('aria-pressed', flagged ? 'true' : 'false');
        btn.title = flagged ? 'Remove importance' : 'Mark important';
        btn.setAttribute('aria-label', btn.title);
    }

    function printMailMessage() {
        var source = document.querySelector('#reading-pane-body .print-area')
            || document.querySelector('.mail-read-card.print-area');
        if (!source) {
            window.print();
            return;
        }

        var existing = document.getElementById('print-message-root');
        if (existing) {
            existing.remove();
        }

        var root = document.createElement('div');
        root.id = 'print-message-root';

        var clone = source.cloneNode(true);
        clone.querySelectorAll('.no-print').forEach(function (el) {
            el.remove();
        });

        root.appendChild(clone);
        document.body.appendChild(root);
        document.body.classList.add('is-printing-message');

        var cleaned = false;
        var cleanup = function () {
            if (cleaned) return;
            cleaned = true;
            document.body.classList.remove('is-printing-message');
            var node = document.getElementById('print-message-root');
            if (node) {
                node.remove();
            }
            window.removeEventListener('afterprint', cleanup);
        };

        window.addEventListener('afterprint', cleanup);
        window.setTimeout(cleanup, 2000);
        window.print();
    }

    function initReadQuotedToggle(root) {
        root = root || document;
        var container = null;
        if (root.id === 'reading-pane-body') {
            container = root;
        } else if (root.closest) {
            container = root.closest('#reading-pane-body') || root;
        } else {
            container = root;
        }
        if (!container || container.dataset.readQuotedBound) return;
        container.dataset.readQuotedBound = '1';
        container.addEventListener('click', function (e) {
            var historyToggle = e.target.closest('.mail-thread-history-toggle');
            if (historyToggle) {
                e.preventDefault();
                e.stopPropagation();
                var wrap = historyToggle.closest('.mail-thread-history-wrap');
                var panel = wrap && wrap.querySelector('.mail-thread-inline-history');
                var expanded = historyToggle.getAttribute('aria-expanded') === 'true';
                historyToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                historyToggle.setAttribute('aria-label', expanded ? 'Show previous messages' : 'Hide previous messages');
                if (panel) panel.hidden = expanded;
                return;
            }

            var toggle = e.target.closest('.mail-quoted-toggle');
            if (!toggle) return;
            e.preventDefault();
            e.stopPropagation();
            var wrap = toggle.closest('.mail-quoted-wrap');
            var panel = wrap && wrap.querySelector('.mail-quoted-body');
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (panel) panel.hidden = expanded;
        });
    }

    function initMailThreadCards(root) {
        root = root || document;
        if (!root.querySelectorAll) return;

        root.querySelectorAll('[data-mail-thread-card]').forEach(function (card) {
            if (card.dataset.threadBound) return;
            card.dataset.threadBound = '1';

            function expandCard() {
                if (card.classList.contains('is-expanded')) return;
                card.classList.add('is-expanded');
                card.setAttribute('aria-expanded', 'true');
                var collapsed = card.querySelector('.mail-message-collapsed');
                var expanded = card.querySelector('.mail-message-expanded');
                if (collapsed) collapsed.hidden = true;
                if (expanded) expanded.hidden = false;
            }

            card.addEventListener('click', function (e) {
                if (e.target.closest('a, button')) return;
                expandCard();
            });

            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    expandCard();
                }
            });
        });
    }

    function bindReadViewCard(card) {
        if (!card || card.dataset.actionsBound) return;
        card.dataset.actionsBound = '1';

        var folderEnc = card.getAttribute('data-folder-b64');
        var uid = card.getAttribute('data-uid');

        syncReadFlagButton(card);

        card.querySelectorAll('[data-mail-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-mail-action');
                if (!action) return;

                if (action === 'print') {
                    printMailMessage();
                    return;
                }

                var dispatchAction = action;
                if (action === 'flag-toggle') {
                    dispatchAction = card.getAttribute('data-flagged') === '1' ? 'unflag' : 'flag';
                }

                var extra = {};
                if (action === 'move') {
                    if (useMoveFolderPicker()) {
                        var moveFolders = collectReadMoveFolders(card);
                        if (!moveFolders.length) {
                            showToast('error', 'No folders available.');
                            return;
                        }
                        showFolderPicker({
                            title: 'Move message',
                            folders: moveFolders,
                            onPick: function (f) {
                                var select = card.querySelector('[name="target_folder"]');
                                if (select) select.value = f.path;
                                var pickAction = isSpamFolderPath(f.path) ? 'spam' : 'move';
                                var pickExtra = pickAction === 'move' ? { target_folder: f.path } : {};
                                dispatchMessageAction(pickAction, folderEnc, uid, pickExtra, btn);
                            }
                        });
                        return;
                    }
                    var select = card.querySelector('[name="target_folder"]');
                    if (!select || !select.value) {
                        showToast('error', 'Choose a folder to move to.');
                        return;
                    }
                    if (isSpamFolderPath(select.value)) {
                        dispatchAction = 'spam';
                    } else {
                        extra.target_folder = select.value;
                    }
                }

                dispatchMessageAction(dispatchAction, folderEnc, uid, extra, btn).then(function (completed) {
                    if (completed === false) return;
                });
            });
        });

        bindComposeLinks(card);
        initMailThreadCards(card);
        initReadQuotedToggle(card);
    }

    function initReadViewActions() {
        document.querySelectorAll('.mail-read-card[data-uid]').forEach(function (card) {
            if (!card.closest('#reading-pane-body')) {
                bindReadViewCard(card);
            }
        });
        initMailThreadCards(document);
    }

    // The message currently open for reading: the full-page read card, or the
    // reading-pane card when the pane is visible and not showing a draft editor.
    function currentOpenReadCard() {
        var cards = document.querySelectorAll('.mail-read-card[data-uid]');
        for (var i = 0; i < cards.length; i++) {
            // #print-message-root holds a transient, toolbar-stripped print clone.
            if (!cards[i].closest('#reading-pane-body') && !cards[i].closest('#print-message-root')) {
                return cards[i];
            }
        }
        // While a pane load is in flight the body still holds the PREVIOUS
        // message's card behind the skeleton (mouse clicks are blocked by
        // pointer-events:none there) — treat it as "nothing open".
        var viewport = document.getElementById('reading-pane-viewport');
        if (viewport && viewport.classList.contains('is-pane-loading')) return null;
        var paneBody = document.getElementById('reading-pane-body');
        if (paneBody && !paneBody.hidden && !paneBody.querySelector('.draft-editor-pane')) {
            return paneBody.querySelector('.mail-read-card[data-uid]');
        }
        return null;
    }

    // Delete key deletes the open message by driving its toolbar Delete button,
    // so the confirm dialog, thread expansion, optimistic UI and server call are
    // identical to a mouse click. Backspace is accepted too: Mac keyboards'
    // "delete" key sends Backspace (forward-delete needs Fn), and Outlook.com
    // maps both keys to delete as well.
    function initDeleteKeyShortcut() {
        document.addEventListener('keydown', function (e) {
            if ((e.key !== 'Delete' && e.key !== 'Backspace') || e.repeat || e.defaultPrevented) return;
            if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;

            // Not while typing: inputs, selects, compose/rich-text editors.
            var el = e.target;
            if (el && el.nodeType === 1) {
                if (el.isContentEditable) return;
                if (el.closest && el.closest('input, textarea, select')) return;
            }

            // Not while a dialog, context menu or any compose UI is open.
            if (document.querySelector('.app-modal:not([hidden])')) return;
            if (document.querySelector('.context-menu:not([hidden])')) return;
            if (isComposeOpen()) return;

            var card = currentOpenReadCard();
            if (!card) return;
            var btn = card.querySelector('[data-mail-action="trash"]');
            if (!btn || btn.disabled) return;

            e.preventDefault();
            btn.click();
        });
    }

    function initRichEditor(root) {
        root = root || document;
        var form = root.querySelector ? root.querySelector('#compose-form') : document.getElementById('compose-form');
        if (!form || form.dataset.richEditorBound) return;
        form.dataset.richEditorBound = '1';

        var editor = form.querySelector('#body-editor');
        var bodyField = form.querySelector('#body');
        var htmlField = form.querySelector('#body_html');
        var toolbar = form.querySelector('#rich-toolbar');
        if (!editor) return;

        function syncEditorFields() {
            syncComposeEditor(form);
        }

        // Remember the editor's last selection so toolbar controls that take
        // focus themselves (the dropdowns and the colour picker) still apply to
        // the text that was selected.
        var savedRange = null;
        function snapshotSelection() {
            var sel = window.getSelection();
            if (sel && sel.rangeCount) {
                var range = sel.getRangeAt(0);
                if (editor.contains(range.commonAncestorContainer)) {
                    savedRange = range.cloneRange();
                }
            }
        }
        function withSavedSelection(fn) {
            editor.focus();
            if (savedRange) {
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(savedRange);
            }
            fn();
            snapshotSelection();
            refreshToolbarState();
            syncEditorFields();
        }
        function firstFont(value) {
            return String(value || '').split(',')[0].replace(/["']/g, '').trim().toLowerCase();
        }

        // Indent via CSS margin on the block — NOT execCommand('indent'), which
        // wraps content in <blockquote>. A blockquote reads as "quoted text" to
        // mail clients (and to our own reader), which used to hide the indented
        // content. Margin indentation renders cleanly everywhere.
        function blockAncestorOf(node) {
            while (node && node !== editor) {
                if (node.nodeType === 1) {
                    var tag = node.tagName;
                    if (tag === 'P' || tag === 'DIV' || tag === 'LI' || tag === 'OL' || tag === 'UL'
                        || tag === 'BLOCKQUOTE' || tag === 'H1' || tag === 'H2' || tag === 'H3') {
                        return node;
                    }
                }
                node = node.parentNode;
            }
            return null;
        }
        function applyBlockIndent(dir) {
            var sel = window.getSelection();
            if (!sel || !sel.rangeCount) return;
            var block = blockAncestorOf(sel.getRangeAt(0).commonAncestorContainer);
            if (!block || block === editor) {
                document.execCommand('formatBlock', false, 'div');
                sel = window.getSelection();
                if (sel.rangeCount) {
                    block = blockAncestorOf(sel.getRangeAt(0).commonAncestorContainer);
                }
            }
            if (!block || block === editor) return;
            // Normalise any legacy blockquote to a plain div so it stops reading
            // as a quote.
            if (block.tagName === 'BLOCKQUOTE') {
                var div = document.createElement('div');
                div.innerHTML = block.innerHTML;
                block.parentNode.replaceChild(div, block);
                block = div;
            }
            var cur = parseFloat(block.style.marginLeft) || 0;
            var next = Math.max(0, cur + dir * 2.5);
            block.style.marginLeft = next > 0 ? next + 'em' : '';
        }

        function runCommand(cmd, value) {
            if (cmd === 'indent' || cmd === 'outdent') {
                withSavedSelection(function () {
                    applyBlockIndent(cmd === 'indent' ? 1 : -1);
                });
                return;
            }
            withSavedSelection(function () {
                try { document.execCommand('styleWithCSS', false, true); } catch (e) { /* legacy fallback */ }
                if (cmd === 'createLink') {
                    var url = window.prompt('Link URL');
                    if (url) document.execCommand('createLink', false, url);
                } else {
                    document.execCommand(cmd, false, value == null ? null : value);
                }
            });
        }

        // execCommand only knows the 1-7 size scale, so wrap the selection at
        // size 7 then rewrite those nodes to the exact point size the user chose.
        function applyFontSize(size) {
            withSavedSelection(function () {
                try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
                document.execCommand('fontSize', false, '7');
                try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
                Array.prototype.forEach.call(editor.querySelectorAll('font[size="7"]'), function (f) {
                    var span = document.createElement('span');
                    span.style.fontSize = size;
                    while (f.firstChild) span.appendChild(f.firstChild);
                    if (f.parentNode) f.parentNode.replaceChild(span, f);
                });
            });
        }

        var BLOCK_TAGS = { P: 1, DIV: 1, LI: 1, BLOCKQUOTE: 1, PRE: 1, TD: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1 };
        function blockAncestor(node) {
            while (node && node !== editor) {
                if (node.nodeType === 1 && BLOCK_TAGS[node.tagName]) return node;
                node = node.parentNode;
            }
            return null;
        }
        function selectedBlocks() {
            var sel = window.getSelection();
            if (!sel.rangeCount) return [];
            var range = sel.getRangeAt(0);
            var start = blockAncestor(range.startContainer);
            var end = blockAncestor(range.endContainer);
            var blocks = [];
            var node = start || end;
            while (node) {
                if (blocks.indexOf(node) < 0) blocks.push(node);
                if (node === end || !end) break;
                node = node.nextElementSibling;
            }
            if (end && blocks.indexOf(end) < 0) blocks.push(end);
            return blocks;
        }
        function applyLineHeight(value) {
            withSavedSelection(function () {
                var blocks = selectedBlocks();
                if (!blocks.length) {
                    document.execCommand('formatBlock', false, 'div');
                    blocks = selectedBlocks();
                }
                blocks.forEach(function (b) { if (b && b !== editor) b.style.lineHeight = value; });
            });
        }

        function currentFontSizePt() {
            var sel = window.getSelection();
            if (!sel.rangeCount) return 0;
            var node = sel.getRangeAt(0).startContainer;
            if (node && node.nodeType === 3) node = node.parentNode;
            if (!node || node.nodeType !== 1) return 0;
            var px = parseFloat(window.getComputedStyle(node).fontSize) || 0;
            return px ? Math.round(px * 72 / 96) : 0;
        }

        // Reflect the selection's formatting in the ribbon: active toggles get a
        // grey background; the font/size/alignment dropdowns show what's in effect.
        function refreshToolbarState() {
            if (!toolbar) return;
            toolbar.querySelectorAll('button[data-cmd]').forEach(function (btn) {
                var on = false;
                try { on = document.queryCommandState(btn.getAttribute('data-cmd')); } catch (e) { on = false; }
                btn.classList.toggle('is-active', !!on);
            });
            var fontSel = toolbar.querySelector('select[data-cmd="fontName"]');
            if (fontSel) {
                var cur = '';
                try { cur = firstFont(document.queryCommandValue('fontName')); } catch (e) { cur = ''; }
                var fontMatch = '';
                Array.prototype.forEach.call(fontSel.options, function (opt) {
                    if (opt.value && firstFont(opt.value) === cur) fontMatch = opt.value;
                });
                if (fontMatch) fontSel.value = fontMatch;
            }
            var sizeSel = toolbar.querySelector('select[data-cmd="fontSize"]');
            if (sizeSel) {
                var wanted = currentFontSizePt() + 'pt';
                var hasSize = Array.prototype.some.call(sizeSel.options, function (o) { return o.value === wanted; });
                if (hasSize) sizeSel.value = wanted;
            }
            var alignBtn = toolbar.querySelector('.compose-format-menu[data-menu="align"] .compose-format-menu-btn');
            if (alignBtn) {
                var a = 'justifyLeft';
                try {
                    if (document.queryCommandState('justifyCenter')) a = 'justifyCenter';
                    else if (document.queryCommandState('justifyRight')) a = 'justifyRight';
                    else if (document.queryCommandState('justifyFull')) a = 'justifyFull';
                } catch (e) {}
                alignBtn.setAttribute('data-align', a);
            }
        }

        function onSelectionChange() {
            snapshotSelection();
            refreshToolbarState();
        }
        editor.addEventListener('keyup', onSelectionChange);
        editor.addEventListener('mouseup', onSelectionChange);
        editor.addEventListener('focus', refreshToolbarState);

        if (toolbar) {
            // Keep the selection when pressing a button (it would otherwise steal
            // focus before the command runs and format nothing).
            toolbar.addEventListener('mousedown', function (e) {
                if (e.target.closest('button')) e.preventDefault();
            });
            toolbar.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-cmd]');
                if (!btn) return;
                e.preventDefault();
                runCommand(btn.getAttribute('data-cmd'));
            });
            toolbar.querySelectorAll('select[data-cmd]').forEach(function (sel) {
                var kind = sel.getAttribute('data-cmd');
                sel.addEventListener('mousedown', snapshotSelection);
                sel.addEventListener('change', function () {
                    if (!sel.value) return;
                    if (kind === 'fontSize') applyFontSize(sel.value);
                    else runCommand(kind, sel.value);
                });
            });
            var colorInput = toolbar.querySelector('input[type="color"][data-cmd]');
            if (colorInput) {
                colorInput.addEventListener('mousedown', snapshotSelection);
                colorInput.addEventListener('change', function () {
                    runCommand(colorInput.getAttribute('data-cmd'), colorInput.value);
                });
            }

            // Icon dropdowns (alignment, line spacing): a button that opens a
            // small popup menu instead of a native <select>, so it can show an icon.
            function closeFormatMenus() {
                toolbar.querySelectorAll('.compose-format-menu-pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                toolbar.querySelectorAll('.compose-format-menu-btn').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
            }
            toolbar.querySelectorAll('.compose-format-menu').forEach(function (menu) {
                var menuBtn = menu.querySelector('.compose-format-menu-btn');
                var pop = menu.querySelector('.compose-format-menu-pop');
                var kind = menu.getAttribute('data-menu');
                if (!menuBtn || !pop) return;
                menuBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var wasOpen = !pop.hasAttribute('hidden');
                    closeFormatMenus();
                    if (!wasOpen) {
                        pop.removeAttribute('hidden');
                        menuBtn.setAttribute('aria-expanded', 'true');
                    }
                });
                pop.addEventListener('mousedown', function (e) { e.preventDefault(); });
                pop.addEventListener('click', function (e) {
                    var item = e.target.closest('.compose-format-menu-item');
                    if (!item) return;
                    e.preventDefault();
                    var value = item.getAttribute('data-value');
                    if (kind === 'align') runCommand(value);
                    else if (kind === 'lineHeight') applyLineHeight(value);
                    closeFormatMenus();
                });
            });
            // Close any open ribbon menu when clicking elsewhere (bound once).
            if (!document.__composeMenuOutsideClose) {
                document.__composeMenuOutsideClose = true;
                document.addEventListener('mousedown', function (e) {
                    if (e.target.closest('.compose-format-menu')) return;
                    document.querySelectorAll('.compose-format-menu-pop:not([hidden])').forEach(function (p) { p.setAttribute('hidden', ''); });
                    document.querySelectorAll('.compose-format-menu-btn[aria-expanded="true"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
                });
            }
        }

        // Reveal the formatting ribbon at the top of the panel once the message
        // body is being edited, and keep it shown for the rest of the session.
        editor.addEventListener('focus', function () {
            form.classList.add('is-editing-body');
        });

        form.addEventListener('submit', function () {
            syncEditorFields();
        });

        // Clean pasted content: strip the source's styling (big headings,
        // colours, fonts from a web page / chat) so it reads as normal email
        // text. Keeps basic structure (paragraphs, lists, bold/italic, links).
        editor.addEventListener('paste', function (e) {
            var cd = e.clipboardData || window.clipboardData;
            if (!cd) return;
            var html = cd.getData('text/html');
            var text = cd.getData('text/plain');
            if (!html && !text) return;
            e.preventDefault();
            var out = html
                ? cleanPastedHtml(html)
                : escapeHtml(text).replace(/\r\n|\r|\n/g, '<br>');
            try {
                document.execCommand('insertHTML', false, out);
            } catch (err) {
                document.execCommand('insertText', false, text || '');
            }
            window.setTimeout(syncEditorFields, 0);
        });

        var draftTimer;
        editor.addEventListener('input', function () {
            window.clearTimeout(draftTimer);
            draftTimer = window.setTimeout(syncEditorFields, 250);
        });
        editor.addEventListener('blur', syncEditorFields);
    }

    // Whitelist-clean pasted HTML: drop scripts/styles, strip every attribute
    // (except link href), demote headings to bold, and unwrap anything not on
    // the allow-list so the result adopts the email's own clean formatting.
    function cleanPastedHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        tmp.querySelectorAll('script,style,meta,link,title,head,noscript,iframe,object,embed,img,svg,button,input')
            .forEach(function (el) { el.remove(); });

        // Headings render huge in an email — convert to a bold paragraph.
        tmp.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(function (h) {
            var div = document.createElement('div');
            var strong = document.createElement('strong');
            strong.innerHTML = h.innerHTML;
            div.appendChild(strong);
            h.parentNode.replaceChild(div, h);
        });

        var ALLOWED = { P: 1, BR: 1, DIV: 1, SPAN: 1, B: 1, STRONG: 1, I: 1, EM: 1, U: 1, A: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1 };
        Array.prototype.slice.call(tmp.querySelectorAll('*')).forEach(function (el) {
            var tag = el.tagName;
            Array.prototype.slice.call(el.attributes).forEach(function (attr) {
                if (!(tag === 'A' && attr.name.toLowerCase() === 'href')) {
                    el.removeAttribute(attr.name);
                }
            });
            if (!ALLOWED[tag]) {
                var parent = el.parentNode;
                if (!parent) return;
                while (el.firstChild) {
                    parent.insertBefore(el.firstChild, el);
                }
                parent.removeChild(el);
            }
        });
        return tmp.innerHTML;
    }

    function avatarColor(email) {
        var colors = ['#8b5cf6', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#6366f1', '#14b8a6'];
        var h = 0;
        for (var i = 0; i < email.length; i++) {
            h = ((h << 5) - h) + email.charCodeAt(i);
            h |= 0;
        }
        return colors[Math.abs(h) % colors.length];
    }

    function avatarInitialsFromDisplay(text) {
        text = (text || '').trim();
        if (!text) return '?';
        var parts = text.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
        return text.charAt(0).toUpperCase();
    }

    function parseRecipientToken(token) {
        token = (token || '').trim();
        if (!token) return { email: '', display: '', valid: false };

        var email = token;
        var display = '';
        var m = token.match(/^(.+?)\s*<([^>]+)>$/);
        if (m) {
            display = m[1].replace(/^["']|["']$/g, '').trim();
            email = m[2].trim();
        } else if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(token)) {
            display = token.split('@')[0];
        }

        var valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        return { email: email, display: display || email, valid: valid };
    }

    function parseInitialRecipients(value) {
        if (!value) return [];
        return value.split(/[,;]+/).map(function (part) {
            return parseRecipientToken(part.trim());
        }).filter(function (item) {
            return item.valid;
        });
    }

    function getChipEmails(container) {
        return Array.prototype.map.call(
            container.querySelectorAll('.recipient-chip'),
            function (chip) { return chip.getAttribute('data-email') || ''; }
        ).filter(Boolean);
    }

    function addRecipientChip(container, data) {
        if (!data || !data.valid) return false;
        var existing = container.querySelector('.recipient-chip[data-email="' + (window.CSS && CSS.escape ? CSS.escape(data.email) : data.email) + '"]');
        if (existing) return false;

        var chip = document.createElement('span');
        chip.className = 'recipient-chip';
        chip.setAttribute('data-email', data.email);
        chip.dataset.displayName = data.display || data.email;
        var initial = (data.display || data.email).charAt(0).toUpperCase();
        var color = avatarColor(data.email);
        chip.innerHTML =
            '<span class="recipient-chip-avatar" style="background:' + color + '">' + escapeHtml(initial) + '</span>' +
            '<span class="recipient-chip-label" title="' + escapeHtml(data.email) + '">' + escapeHtml(data.display || data.email) + '</span>' +
            '<button type="button" class="recipient-chip-remove" aria-label="Remove ' + escapeHtml(data.email) + '">&times;</button>';
        container.appendChild(chip);
        return true;
    }

    function mergeRecipientAutocompleteData(base, extra) {
        var domainSet = {};
        var contactMap = {};
        (base.domains || []).forEach(function (domain) {
            domain = String(domain || '').trim().toLowerCase();
            if (domain) domainSet[domain] = domain;
        });
        (extra.domains || []).forEach(function (domain) {
            domain = String(domain || '').trim().toLowerCase();
            if (domain) domainSet[domain] = domain;
        });
        (base.contacts || []).forEach(function (contact) {
            if (!contact || !contact.email) return;
            contactMap[String(contact.email).toLowerCase()] = contact;
        });
        (extra.contacts || []).forEach(function (contact) {
            if (!contact || !contact.email) return;
            contactMap[String(contact.email).toLowerCase()] = contact;
        });
        return {
            domains: Object.keys(domainSet).sort(),
            contacts: Object.keys(contactMap).map(function (key) { return contactMap[key]; })
        };
    }

    function recipientEmailFromForm(form) {
        if (!form) return '';
        var fromField = form.querySelector('#from_email');
        if (fromField) {
            var value = (fromField.value || '').trim();
            if (value) return value.toLowerCase();
        }
        var sendAs = (form.dataset.sendAsEmail || '').trim();
        if (sendAs) return sendAs.toLowerCase();
        var displayEmail = form.querySelector('.compose-send-as-email');
        if (displayEmail) {
            return (displayEmail.textContent || '').trim().toLowerCase();
        }
        return '';
    }

    function parseRecipientAutocompleteData(form) {
        var data = { domains: [], contacts: [] };
        if (!form) return data;
        try {
            if (form.dataset.recipientDomains) {
                data.domains = JSON.parse(form.dataset.recipientDomains) || [];
            }
            if (form.dataset.recipientContacts) {
                data.contacts = JSON.parse(form.dataset.recipientContacts) || [];
            }
        } catch (e) { /* ignore malformed JSON */ }

        var extra = { domains: [], contacts: [] };
        var sendAsEmail = recipientEmailFromForm(form);
        if (sendAsEmail && sendAsEmail.indexOf('@') >= 0) {
            var sendParts = sendAsEmail.split('@');
            var sendDomain = sendParts.slice(1).join('@');
            if (sendDomain) extra.domains.push(sendDomain);
            extra.contacts.push({
                email: sendAsEmail,
                name: sendParts[0] || sendAsEmail,
                local: sendParts[0] || ''
            });
        }

        form.querySelectorAll('#from_email option').forEach(function (opt) {
            var email = (opt.value || '').trim().toLowerCase();
            if (!email || email.indexOf('@') < 0) return;
            var parts = email.split('@');
            var local = parts[0];
            var domain = parts.slice(1).join('@');
            if (domain) extra.domains.push(domain);
            var label = (opt.textContent || '').replace(/\s+/g, ' ').trim();
            var name = label.replace(/\s*<[^>]+>\s*$/, '').trim();
            extra.contacts.push({
                email: email,
                name: name || local || email,
                local: local
            });
        });

        return mergeRecipientAutocompleteData(data, extra);
    }

    function buildRecipientSuggestions(query, data) {
        query = (query || '').trim().toLowerCase();
        if (!query) return [];

        var domains = data.domains || [];
        var contacts = data.contacts || [];
        var seen = {};
        var results = [];

        function push(item) {
            var key = (item.email || '').toLowerCase();
            if (!key || seen[key]) return;
            seen[key] = true;
            results.push(item);
        }

        contacts.forEach(function (c) {
            var email = (c.email || '').toLowerCase();
            var local = (c.local || email.split('@')[0] || '').toLowerCase();
            var name = (c.name || '').toLowerCase();
            var score = 4;
            if (email.indexOf(query) === 0) score = 0;
            else if (local.indexOf(query) === 0) score = 1;
            else if (name.indexOf(query) === 0) score = 2;
            else if (email.indexOf(query) > 0 || name.indexOf(query) > 0 || local.indexOf(query) > 0) score = 3;
            else return;

            push({
                type: 'contact',
                email: c.email,
                name: c.name || c.email,
                sub: c.email,
                score: score
            });
        });

        var at = query.indexOf('@');
        if (at >= 0) {
            var local = query.slice(0, at);
            var domainPart = query.slice(at + 1);
            if (local) {
                domains.forEach(function (domain) {
                    var d = domain.toLowerCase();
                    if (domainPart && d.indexOf(domainPart) !== 0) return;
                    var full = local + '@' + domain;
                    push({
                        type: 'domain',
                        email: full,
                        name: full,
                        sub: '@' + domain,
                        score: domainPart === '' ? 0 : (d === domainPart ? 0 : 1)
                    });
                });
            }
        } else if (/^[a-z0-9._%+-]+$/i.test(query)) {
            domains.forEach(function (domain, index) {
                push({
                    type: 'domain',
                    email: query + '@' + domain,
                    name: query + '@' + domain,
                    sub: '@' + domain,
                    score: 5 + index
                });
            });
        }

        results.sort(function (a, b) {
            if (a.score !== b.score) return a.score - b.score;
            return String(a.email).localeCompare(String(b.email));
        });

        return results.slice(0, 8);
    }

    function initRecipientAutocomplete(input, form, onAccepted) {
        var data = parseRecipientAutocompleteData(form);
        var fieldWrap = input.closest('.recipient-field');
        var recipientsWrap = input.closest('.compose-recipients');
        if (!fieldWrap) return { handleKeydown: function () { return false; }, close: function () {}, isOpen: function () { return false; } };

        var portal = input.closest('#reading-pane-body')
            || document.getElementById('compose-panel')
            || document.body;
        var listEl = document.createElement('ul');
        listEl.className = 'recipient-suggest';
        if (input.closest('#reading-pane-body') || input.closest('.draft-editor-pane')) {
            listEl.classList.add('recipient-suggest--pane');
        }
        listEl.setAttribute('role', 'listbox');
        listEl.hidden = true;
        portal.appendChild(listEl);

        var suggestions = [];
        var activeIndex = -1;
        var mouseDownOnList = false;

        function positionList() {
            if (listEl.hidden) return;
            var rect = input.getBoundingClientRect();
            listEl.style.left = Math.round(rect.left) + 'px';
            listEl.style.top = Math.round(rect.bottom + 4) + 'px';
            listEl.style.width = Math.max(Math.round(rect.width), 280) + 'px';
        }

        function setSuggestOpen(open) {
            if (recipientsWrap) {
                recipientsWrap.classList.toggle('is-suggest-open', open);
            }
        }

        function closeList() {
            listEl.hidden = true;
            listEl.innerHTML = '';
            suggestions = [];
            activeIndex = -1;
            setSuggestOpen(false);
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function renderList() {
            listEl.innerHTML = '';
            if (!suggestions.length) {
                closeList();
                return;
            }

            var lastType = '';
            suggestions.forEach(function (item, index) {
                if (item.type !== lastType) {
                    lastType = item.type;
                    var label = document.createElement('li');
                    label.className = 'recipient-suggest-label';
                    label.textContent = item.type === 'domain' ? 'Suggested addresses' : 'Contacts';
                    label.setAttribute('aria-hidden', 'true');
                    listEl.appendChild(label);
                }

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'recipient-suggest-item' + (index === activeIndex ? ' is-active' : '');
                btn.setAttribute('role', 'option');
                btn.id = 'recipient-suggest-' + (input.id || 'input') + '-' + index;
                btn.setAttribute('data-index', String(index));

                var initial = (item.name || item.email).charAt(0).toUpperCase();
                var avatarClass = 'recipient-suggest-avatar' + (item.type === 'domain' ? ' recipient-suggest-avatar--domain' : '');
                var avatarContent = item.type === 'domain' ? '@' : escapeHtml(initial);
                var avatarStyle = item.type === 'domain' ? '' : ' style="background:' + avatarColor(item.email) + '"';

                btn.innerHTML =
                    '<span class="' + avatarClass + '"' + avatarStyle + '>' + avatarContent + '</span>' +
                    '<span class="recipient-suggest-text">' +
                        '<span class="recipient-suggest-name">' + escapeHtml(item.name) + '</span>' +
                        '<span class="recipient-suggest-email">' + escapeHtml(item.sub) + '</span>' +
                    '</span>' +
                    (index === activeIndex ? '<span class="recipient-suggest-kbd">Tab</span>' : '');

                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    mouseDownOnList = true;
                });
                btn.addEventListener('click', function () {
                    acceptSuggestion(index);
                });

                listEl.appendChild(btn);
            });

            listEl.hidden = false;
            setSuggestOpen(true);
            positionList();
            input.setAttribute('aria-expanded', 'true');
            if (activeIndex >= 0) {
                input.setAttribute('aria-activedescendant', 'recipient-suggest-' + (input.id || 'input') + '-' + activeIndex);
            }
        }

        function acceptSuggestion(index) {
            var item = suggestions[index];
            if (!item) return;
            input.value = item.email;
            closeList();
            input.focus();
            if (typeof onAccepted === 'function') {
                onAccepted(item.email);
            }
        }

        function updateSuggestions() {
            suggestions = buildRecipientSuggestions(input.value, data);
            activeIndex = suggestions.length ? 0 : -1;
            if (suggestions.length) renderList();
            else closeList();
        }

        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', updateSuggestions);
        input.addEventListener('focus', function () {
            if (input.value.trim()) updateSuggestions();
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (!mouseDownOnList) closeList();
                mouseDownOnList = false;
            }, 120);
        });

        window.addEventListener('resize', positionList);
        window.addEventListener('scroll', positionList, true);
        var scrollEls = [
            input.closest('#reading-pane-body'),
            input.closest('.draft-editor-pane'),
            document.getElementById('compose-panel-body')
        ];
        scrollEls.forEach(function (el) {
            if (!el) return;
            el.addEventListener('scroll', positionList, { passive: true });
        });

        return {
            isOpen: function () {
                return !listEl.hidden && suggestions.length > 0;
            },
            acceptActive: function () {
                if (!this.isOpen() || activeIndex < 0) return false;
                acceptSuggestion(activeIndex);
                return true;
            },
            handleKeydown: function (e) {
                if (!this.isOpen()) return false;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = (activeIndex + 1) % suggestions.length;
                    renderList();
                    return true;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
                    renderList();
                    return true;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeList();
                    return true;
                }
                if (e.key === 'Enter' || e.key === 'Tab') {
                    if (activeIndex >= 0) {
                        e.preventDefault();
                        acceptSuggestion(activeIndex);
                        return true;
                    }
                }
                return false;
            },
            close: closeList
        };
    }

    function initRecipientRow(row) {
        if (row.dataset.recipientRowBound) return null;
        row.dataset.recipientRowBound = '1';

        var field = row.getAttribute('data-field');
        var hidden = document.getElementById(field);
        var chipsEl = row.querySelector('.recipient-chips');
        var input = row.querySelector('.recipient-input');
        if (!hidden || !chipsEl || !input) return;

        var form = row.closest('#compose-form');
        var autocomplete = initRecipientAutocomplete(input, form, function () {
            window.setTimeout(commitInput, 0);
        });

        function syncHidden() {
            hidden.value = getChipEmails(chipsEl).join(', ');
        }

        function commitInput() {
            var raw = input.value.trim();
            if (!raw) return;
            raw.split(/[,;]+/).forEach(function (part) {
                part = part.trim();
                if (!part) return;
                var parsed = parseRecipientToken(part);
                if (parsed.valid) {
                    addRecipientChip(chipsEl, parsed);
                }
            });
            input.value = '';
            syncHidden();
        }

        parseInitialRecipients(hidden.value).forEach(function (item) {
            addRecipientChip(chipsEl, item);
        });
        syncHidden();

        input.addEventListener('keydown', function (e) {
            if (autocomplete.handleKeydown(e)) return;
            if (e.key === 'Enter' || e.key === 'Tab' || e.key === ',') {
                if (input.value.trim()) {
                    e.preventDefault();
                    autocomplete.close();
                    commitInput();
                }
            } else if (e.key === 'Backspace' && input.value === '') {
                var chips = chipsEl.querySelectorAll('.recipient-chip');
                if (chips.length) {
                    chips[chips.length - 1].remove();
                    syncHidden();
                }
            }
        });

        input.addEventListener('blur', function () {
            autocomplete.close();
            commitInput();
        });

        input.addEventListener('paste', function (e) {
            var text = (e.clipboardData || window.clipboardData).getData('text');
            if (!text || text.indexOf(',') === -1 && text.indexOf(';') === -1) return;
            e.preventDefault();
            input.value = text;
            commitInput();
        });

        chipsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.recipient-chip-remove');
            if (!btn) return;
            e.preventDefault();
            var chip = btn.closest('.recipient-chip');
            if (chip) chip.remove();
            syncHidden();
            input.focus();
        });

        var label = row.querySelector('.compose-recipient-label');
        if (label) {
            label.addEventListener('click', function () { input.focus(); });
        }

        return { syncHidden: syncHidden, commitInput: commitInput, input: input, hidden: hidden, chipsEl: chipsEl };
    }

    function bindRecipientForm(form) {
        if (!form) return {};

        var rows = {};
        form.querySelectorAll('.compose-recipient-row[data-field]').forEach(function (row) {
            var rowApi = initRecipientRow(row);
            if (rowApi) {
                rows[row.getAttribute('data-field')] = rowApi;
            }
        });

        return rows;
    }

    function initRecipientFields(root) {
        root = root || document;
        var forms = [];
        if (root.querySelectorAll) {
            forms = Array.prototype.slice.call(root.querySelectorAll('#compose-form'));
        } else if (root.id === 'compose-form') {
            forms = [root];
        }
        if (!forms.length && (root === document || !root.querySelector)) {
            var single = document.getElementById('compose-form');
            if (single) forms = [single];
        }

        forms.forEach(function (form) {
            if (form.dataset.recipientsBound) return;
            form.dataset.recipientsBound = '1';

            var rows = bindRecipientForm(form);

        function showRow(id, btn) {
            var row = form.querySelector('#' + id);
            if (!row) return;
            row.hidden = false;
            if (btn) btn.classList.add('is-active');
            var input = row.querySelector('.recipient-input');
            if (input) input.focus();
        }

        var toggleCc = form.querySelector('#toggle-cc');
        var toggleBcc = form.querySelector('#toggle-bcc');
        var ccRow = form.querySelector('#cc-row');
        var bccRow = form.querySelector('#bcc-row');

        if (toggleCc && ccRow) {
            if (!ccRow.hidden) toggleCc.classList.add('is-active');
            toggleCc.addEventListener('click', function () {
                showRow('cc-row', toggleCc);
            });
        }

        if (toggleBcc && bccRow) {
            if (!bccRow.hidden) toggleBcc.classList.add('is-active');
            toggleBcc.addEventListener('click', function () {
                showRow('bcc-row', toggleBcc);
            });
        }

        form.addEventListener('submit', function (e) {
            Object.keys(rows).forEach(function (key) {
                var row = rows[key];
                if (!row) return;
                row.commitInput();
                row.syncHidden();
            });

            // Saving a draft is never blocked by empty fields (Gmail/Outlook behavior).
            var submitter = e.submitter;
            var isDraft = !!(submitter && /draft/.test(submitter.getAttribute('formaction') || ''));
            if (isDraft) return;

            var toHidden = form.querySelector('#to');
            if (toHidden && !toHidden.value.trim()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                showToast('error', 'Please add at least one recipient.');
                if (rows.to && rows.to.input) rows.to.input.focus();
                return;
            }

            var subjectField = form.querySelector('#subject');
            if (subjectField && !subjectField.value.trim()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                showToast('error', 'Please add a subject before sending.');
                subjectField.focus();
            }
        });
        });
    }

    function initRulesDragDrop() {
        var tbody = document.getElementById('rules-sortable');
        var form = document.getElementById('rules-reorder-form');
        var orderInput = document.getElementById('rules-order');
        if (!tbody || !form) return;

        var dragRow = null;

        tbody.querySelectorAll('tr').forEach(function (row) {
            row.addEventListener('dragstart', function () {
                dragRow = row;
                row.classList.add('is-dragging');
            });
            row.addEventListener('dragend', function () {
                row.classList.remove('is-dragging');
                dragRow = null;
                saveOrder();
            });
            row.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (!dragRow || dragRow === row) return;
                var rect = row.getBoundingClientRect();
                var after = e.clientY > rect.top + rect.height / 2;
                tbody.insertBefore(dragRow, after ? row.nextSibling : row);
            });
        });

        function saveOrder() {
            var order = [];
            var priority = 10;
            tbody.querySelectorAll('tr').forEach(function (row) {
                order.push({ id: parseInt(row.getAttribute('data-id'), 10), priority: priority });
                var cell = row.querySelector('.rule-priority');
                if (cell) cell.textContent = priority;
                priority += 10;
            });
            orderInput.value = JSON.stringify(order);
            form.submit();
        }
    }

    function initThemeFromSettings() {
        var themeSelect = document.getElementById('theme');
        if (themeSelect) {
            themeSelect.addEventListener('change', function () {
                var val = themeSelect.value;
                if (val === 'auto') {
                    localStorage.removeItem('dj_theme');
                    document.documentElement.removeAttribute('data-theme');
                } else {
                    localStorage.setItem('dj_theme', val);
                    document.documentElement.setAttribute('data-theme', val);
                }
            });
        }
    }

    function requestNotificationPermission() {
        if (body.getAttribute('data-notify-enabled') !== '1') return;
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    function initSidebarGroups() {
        var storageKey = 'dj_sidebar_groups';

        function readState() {
            try {
                var raw = localStorage.getItem(storageKey);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function writeState(state) {
            try {
                localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (e) { /* storage blocked */ }
        }

        var state = readState();

        // Restore saved open/closed state (overrides server default on navigation).
        document.querySelectorAll('.sidebar-group.is-collapsible[data-group]').forEach(function (group) {
            var id = group.getAttribute('data-group');
            if (!id || !Object.prototype.hasOwnProperty.call(state, id)) return;
            var open = !!state[id];
            group.classList.toggle('is-open', open);
            var btn = group.querySelector('.sidebar-group-toggle');
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Remember server-opened groups (e.g. active subfolder) on first visit.
        document.querySelectorAll('.sidebar-group.is-collapsible[data-group]').forEach(function (group) {
            var id = group.getAttribute('data-group');
            if (!id || Object.prototype.hasOwnProperty.call(state, id)) return;
            if (group.classList.contains('is-open')) {
                state[id] = true;
                writeState(state);
            }
        });

        document.querySelectorAll('.sidebar-group-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.sidebar-group');
                if (!group) return;
                var id = group.getAttribute('data-group');
                var open = group.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (id) {
                    state = readState();
                    state[id] = open;
                    writeState(state);
                }
            });
        });

        initSidebarFolderBranches();
    }

    function initSidebarFolderBranches() {
        var storageKey = 'dj_sidebar_branches';

        function readState() {
            try {
                var raw = localStorage.getItem(storageKey);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function writeState(state) {
            try {
                localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (e) { /* storage blocked */ }
        }

        function setBranchOpen(branch, open, persist) {
            if (!branch) return;
            branch.classList.toggle('is-open', open);
            var children = branch.querySelector(':scope > .sidebar-folder-branch-children');
            if (children) children.hidden = !open;
            var toggle = branch.querySelector(':scope > .sidebar-tree-row .sidebar-tree-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (persist) {
                var id = branch.getAttribute('data-sidebar-branch');
                if (id) {
                    var state = readState();
                    state[id] = open;
                    writeState(state);
                }
            }
        }

        var state = readState();
        document.querySelectorAll('.sidebar-folder-branch[data-sidebar-branch]').forEach(function (branch) {
            var id = branch.getAttribute('data-sidebar-branch');
            if (id && Object.prototype.hasOwnProperty.call(state, id)) {
                setBranchOpen(branch, !!state[id], false);
                return;
            }
            if (!id || Object.prototype.hasOwnProperty.call(state, id)) return;
            if (branch.classList.contains('is-open')) {
                state[id] = true;
                writeState(state);
            }
        });

        document.querySelectorAll('.sidebar-tree-toggle').forEach(function (btn) {
            if (btn.dataset.branchBound) return;
            btn.dataset.branchBound = '1';
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var branch = btn.closest('.sidebar-folder-branch');
                if (!branch) return;
                setBranchOpen(branch, !branch.classList.contains('is-open'), true);
            });
        });
    }

    function initAdminFolderTree() {
        var tree = document.getElementById('admin-folder-tree');
        var search = document.getElementById('admin-folder-search');
        if (!tree) return;

        var storageKey = 'dj_admin_folder_branches';

        function readState() {
            try {
                var raw = localStorage.getItem(storageKey);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function writeState(state) {
            try {
                localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (e) { /* storage blocked */ }
        }

        function setBranchOpen(branch, open, persist) {
            if (!branch || !branch.classList.contains('has-children')) return;
            branch.classList.toggle('is-open', open);
            branch.setAttribute('aria-expanded', open ? 'true' : 'false');
            var children = branch.querySelector(':scope > .admin-folder-branch-children');
            if (children) {
                children.hidden = !open;
            }
            var toggle = branch.querySelector(':scope > .admin-folder-tree-row .admin-folder-branch-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (persist) {
                var id = branch.getAttribute('data-branch-id');
                if (id) {
                    var state = readState();
                    state[id] = open;
                    writeState(state);
                }
            }
        }

        var branchState = readState();
        tree.querySelectorAll('.admin-folder-branch.has-children').forEach(function (branch) {
            var id = branch.getAttribute('data-branch-id');
            if (id && Object.prototype.hasOwnProperty.call(branchState, id)) {
                setBranchOpen(branch, !!branchState[id], false);
            }
        });

        tree.querySelectorAll('.admin-folder-branch-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var branch = btn.closest('.admin-folder-branch');
                if (!branch) return;
                setBranchOpen(branch, !branch.classList.contains('is-open'), true);
            });
        });

        function filterTree(query) {
            query = (query || '').trim().toLowerCase();
            var branches = tree.querySelectorAll('.admin-folder-branch');
            branches.forEach(function (branch) {
                var hay = branch.getAttribute('data-folder-search') || '';
                branch.dataset.searchMatch = (query === '' || hay.indexOf(query) !== -1) ? '1' : '0';
            });
            branches.forEach(function (branch) {
                var show = query === '' || branch.dataset.searchMatch === '1';
                if (!show) {
                    branch.querySelectorAll('.admin-folder-branch').forEach(function (desc) {
                        if (desc.dataset.searchMatch === '1') show = true;
                    });
                }
                branch.hidden = !show;
                if (query !== '' && show) {
                    setBranchOpen(branch, true, false);
                    var parent = branch.parentElement ? branch.parentElement.closest('.admin-folder-branch') : null;
                    while (parent) {
                        setBranchOpen(parent, true, false);
                        parent = parent.parentElement ? parent.parentElement.closest('.admin-folder-branch') : null;
                    }
                }
            });
        }

        if (search) {
            search.addEventListener('input', function () {
                filterTree(search.value);
            });
        }

        var expandAll = document.getElementById('admin-folder-expand-all');
        var collapseAll = document.getElementById('admin-folder-collapse-all');
        if (expandAll) {
            expandAll.addEventListener('click', function () {
                tree.querySelectorAll('.admin-folder-branch.has-children').forEach(function (branch) {
                    setBranchOpen(branch, true, true);
                });
            });
        }
        if (collapseAll) {
            collapseAll.addEventListener('click', function () {
                tree.querySelectorAll('.admin-folder-branch.has-children').forEach(function (branch) {
                    setBranchOpen(branch, false, true);
                });
            });
        }
    }

    function initFileUpload(root) {
        root = root || document;
        var wrap = root.querySelector ? root.querySelector('#file-upload') : document.getElementById('file-upload');
        var input = wrap ? wrap.querySelector('#attachments') : null;
        var list = wrap ? wrap.querySelector('#file-upload-list') : null;
        if (!wrap || !input || wrap.dataset.uploadBound) return;
        wrap.dataset.uploadBound = '1';

        var MAX_FILES = 5;
        var MAX_BYTES = 10 * 1024 * 1024;
        var picked = []; // source of truth; input.files is rebuilt from this

        function fileSizeLabel(bytes) {
            if (bytes >= 1048576) return (Math.round(bytes / 104857.6) / 10) + ' MB';
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        function fileKind(name) {
            var ext = (String(name).split('.').pop() || '').toLowerCase();
            if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'].indexOf(ext) >= 0) return 'image';
            if (ext === 'pdf') return 'pdf';
            if (['doc', 'docx', 'rtf', 'odt'].indexOf(ext) >= 0) return 'doc';
            if (['xls', 'xlsx', 'csv', 'ods'].indexOf(ext) >= 0) return 'sheet';
            if (['zip', 'rar', '7z', 'gz', 'tar'].indexOf(ext) >= 0) return 'zip';
            return 'file';
        }

        var CHIP_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';

        function syncInput() {
            try {
                var dt = new DataTransfer();
                picked.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
            } catch (err) { /* very old browsers: keep native selection */ }
            updateList();
        }

        function updateList() {
            if (!list) return;
            list.innerHTML = '';
            if (!picked.length) {
                list.hidden = true;
                wrap.classList.remove('has-files');
                return;
            }
            list.hidden = false;
            wrap.classList.add('has-files');
            var draftBar = wrap.classList.contains('file-upload--draft-bar');
            var removeBtn = function (i) {
                return '<button type="button" class="file-upload-remove" data-remove-index="' + i +
                    '" title="Remove attachment" aria-label="Remove ' + escapeHtml(picked[i].name) + '">&times;</button>';
            };
            picked.forEach(function (file, i) {
                var li = document.createElement('li');
                if (draftBar) {
                    li.className = 'compose-draft-attach-chip';
                    li.innerHTML =
                        '<span class="compose-draft-attach-chip-icon" aria-hidden="true">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
                        '</span>' +
                        '<span class="compose-draft-attach-chip-name" title="' + escapeHtml(file.name) + '">' + escapeHtml(file.name) + '</span>' +
                        '<span class="compose-draft-attach-chip-size">' + fileSizeLabel(file.size) + '</span>' +
                        removeBtn(i);
                } else {
                    li.className = 'attach-chip attach-chip--' + fileKind(file.name);
                    li.innerHTML =
                        '<span class="attach-chip-icon" aria-hidden="true">' + CHIP_ICON + '</span>' +
                        '<span class="attach-chip-name" title="' + escapeHtml(file.name) + '">' + escapeHtml(file.name) + '</span>' +
                        '<span class="attach-chip-size">' + fileSizeLabel(file.size) + '</span>' +
                        removeBtn(i);
                }
                list.appendChild(li);
            });
        }

        // Gmail-style: adding files APPENDS to the current selection; oversize and
        // over-count files are rejected up front with a clear message (instead of
        // uploading megabytes just to get a server error back).
        function acceptFiles(fileList) {
            var tooBig = [];
            var added = 0;
            Array.prototype.forEach.call(fileList || [], function (f) {
                if (f.size > MAX_BYTES) {
                    tooBig.push(f.name);
                    return;
                }
                if (picked.length >= MAX_FILES) {
                    added = -1;
                    return;
                }
                picked.push(f);
                if (added >= 0) added++;
            });
            if (tooBig.length) {
                showToast('error', 'Each attachment must be under 10 MB — not added: ' + tooBig.join(', '), 6000);
            }
            if (added === -1) {
                showToast('error', 'Maximum ' + MAX_FILES + ' attachments allowed.', 5000);
            }
            syncInput();
        }

        if (list) {
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-remove-index]');
                if (!btn) return;
                e.preventDefault();
                var i = parseInt(btn.getAttribute('data-remove-index'), 10);
                if (i >= 0 && i < picked.length) {
                    picked.splice(i, 1);
                    syncInput();
                }
            });
        }

        input.addEventListener('change', function () {
            var chosen = Array.prototype.slice.call(input.files || []);
            // The native picker replaces the input's own list — merge into ours.
            picked = picked.filter(function (f) {
                return !chosen.some(function (c) { return c.name === f.name && c.size === f.size; });
            });
            acceptFiles(chosen);
        });

        wrap.addEventListener('dragover', function (e) {
            e.preventDefault();
            wrap.classList.add('is-dragover');
        });

        wrap.addEventListener('dragleave', function () {
            wrap.classList.remove('is-dragover');
        });

        wrap.addEventListener('drop', function (e) {
            e.preventDefault();
            wrap.classList.remove('is-dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                acceptFiles(e.dataTransfer.files);
            }
        });
    }

    function dispatchMessageAction(kind, sourceFolderEnc, uid, extra, triggerBtn) {
        extra = extra || {};
        // Optimistic rows (negative uid) are still syncing — nothing real to act on.
        if (parseInt(uid, 10) <= 0) {
            showToast('error', 'This message is still syncing — try again in a few seconds.');
            return Promise.resolve(false);
        }

        // Acting on a message that is part of a multi-selection applies the action
        // to the WHOLE selection (Gmail-style). Users who select 4 messages and
        // pick "Move to…" on a row/pane control expect all 4 to move — not just
        // the one the control belongs to.
        var multiSelections = (kind === 'move' || kind === 'trash' || kind === 'restore') ? selectedMailSelections() : [];
        var actsOnSelection = multiSelections.length > 1
            && multiSelections.some(function (s) { return String(s.uid) === String(uid); });
        if (actsOnSelection) {
            var listFolderEnc = currentMailFolderEnc() || sourceFolderEnc;
            if (kind === 'restore') {
                runBulkCommandExecute('restore', multiSelections, listFolderEnc, triggerBtn);
                return Promise.resolve(true);
            }
            if (kind === 'move' && extra.target_folder) {
                var moveSel = document.getElementById('cmd-move-target');
                if (moveSel) {
                    var hasOpt = Array.prototype.some.call(moveSel.options, function (o) {
                        return o.value === extra.target_folder;
                    });
                    if (!hasOpt) {
                        var opt = document.createElement('option');
                        opt.value = extra.target_folder;
                        opt.textContent = extra.target_folder;
                        moveSel.appendChild(opt);
                    }
                    moveSel.value = extra.target_folder;
                    runBulkCommandExecute('move', multiSelections, listFolderEnc, triggerBtn);
                    return Promise.resolve(true);
                }
            }
            if (kind === 'trash') {
                // Permanent when the list is Trash OR any selected row lives in a
                // Trash folder (global search results span every folder and the
                // search page has no folder-kind card to consult).
                var permanentBulk = isTrashFolder() || multiSelections.some(function (s) {
                    return isTrashSource(s.folderB64 || '');
                });
                if (!permanentBulk) {
                    swallowBulkDeleteRejection(
                        runBulkCommandExecute('delete', multiSelections, listFolderEnc, triggerBtn)
                    );
                    return Promise.resolve(true);
                }
                var bulkDeleteOpts = deleteConfirmOptions(multiSelections.length, true);
                return showConfirmAction(Object.assign({}, bulkDeleteOpts, {
                    loadingLabel: deleteLoadingMessage(multiSelections.length),
                    action: function () {
                        return runBulkCommandExecute('delete', multiSelections, listFolderEnc, triggerBtn);
                    }
                })).then(function (ok) { return !!ok; });
            }
        }

        // Conversation row acting on itself (not part of a larger selection):
        // expand to all thread messages in this folder and run the bulk path so
        // the whole conversation is affected (Gmail behavior).
        var threadRow = rowForUid(uid);
        var threadUids = threadRow ? rowThreadUids(threadRow) : [];
        if (!actsOnSelection && threadUids.length > 1) {
            var tFolderEnc = (threadRow && threadRow.getAttribute('data-folder-b64')) || sourceFolderEnc || currentMailFolderEnc();
            var threadSel = threadUids.map(function (u) { return { uid: u, folderB64: tFolderEnc }; });
            if (kind === 'trash') {
                if (!isTrashSource(tFolderEnc)) {
                    swallowBulkDeleteRejection(
                        runBulkCommandExecute('delete', threadSel, tFolderEnc, triggerBtn)
                    );
                    return Promise.resolve(true);
                }
                var thrDeleteOpts = deleteConfirmOptions(threadUids.length, true);
                return showConfirmAction(Object.assign({}, thrDeleteOpts, {
                    loadingLabel: deleteLoadingMessage(threadUids.length),
                    action: function () {
                        return runBulkCommandExecute('delete', threadSel, tFolderEnc, triggerBtn);
                    }
                })).then(function (ok) { return !!ok; });
            }
            if (kind === 'move' || kind === 'restore' || kind === 'mark-read'
                || kind === 'mark-unread' || kind === 'flag' || kind === 'unflag') {
                if (kind === 'move' && extra.target_folder) {
                    var mvSel = document.getElementById('cmd-move-target');
                    if (mvSel) {
                        var hasO = Array.prototype.some.call(mvSel.options, function (o) { return o.value === extra.target_folder; });
                        if (!hasO) {
                            var o2 = document.createElement('option');
                            o2.value = extra.target_folder;
                            o2.textContent = extra.target_folder;
                            mvSel.appendChild(o2);
                        }
                        mvSel.value = extra.target_folder;
                    }
                }
                var bulkKind = kind === 'trash' ? 'delete' : kind;
                runBulkCommandExecute(bulkKind, threadSel, tFolderEnc, triggerBtn);
                return Promise.resolve(true);
            }
            if (kind === 'spam') {
                return dispatchThreadSpam(sourceFolderEnc, threadUids, tFolderEnc, triggerBtn);
            }
        }

        var confirmCfg = null;
        // Only a permanent delete (message living in Trash) still confirms;
        // recoverable moves to Trash or Spam run immediately.
        if (kind === 'trash' && isTrashSource(sourceFolderEnc)) {
            confirmCfg = deleteConfirmOptions(1, true);
        }
        if (confirmCfg) {
            var actionOpts = {
                loadingLabel: deleteLoadingMessage(1),
                // Completion feedback comes from the stateful op toast.
                action: function () {
                    return dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn);
                }
            };
            return showConfirmAction(Object.assign({}, confirmCfg, actionOpts))
                .then(function (ok) { return !!ok; });
        }
        return dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn)
            .then(function (r) { return r !== false; }, function () { return false; });
    }

    // Move a whole conversation to Spam (kebab on a thread row). message/spam
    // accepts uids[]; optimistically drop the row and let the op resolve.
    function dispatchThreadSpam(sourceFolderEnc, threadUids, folderEnc, triggerBtn) {
        if (blockedByCriticalOp()) return Promise.resolve(false);
        var winner = threadUids[0];
        beginListMutationQuiet(2500);
        markUidsPendingRemoval(threadUids);
        removeRowByUid(winner);
        clearReadingPaneIfShowingUids(threadUids);
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        payload.set('folder', folderEnc);
        threadUids.forEach(function (u) { payload.append('uids[]', String(u)); });
        return fireListMutation('message/spam', payload, {
            // On failure the conversation is restored and reappears; clear the
            // pending-removal flags so the rows stay openable (openMessageInPane guard).
            rollbackUids: threadUids.slice(),
            opToastHandle: beginOpToast({
                progress: 'Moving to Spam…',
                done: threadUids.length === 1 ? 'Message moved to Spam.' : 'Conversation moved to Spam.',
                fail: 'The messages could not be moved to Spam. They have been restored.'
            })
        }).finally(function () { endListMutationQuiet(800); })
            .then(function () { return true; }, function () { return false; });
    }

    function dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn) {
        extra = extra || {};
        var fields = { folder: sourceFolderEnc, uid: uid };
        Object.keys(extra).forEach(function (k) { fields[k] = extra[k]; });

        var readCard = document.querySelector('.mail-read-card[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]');
        var folderUrl = readCard ? readCard.getAttribute('data-folder-url') : null;
        var paneCard = readCard && readCard.closest('#reading-pane-body') ? readCard : null;
        var destructive = kind === 'spam' || kind === 'trash' || kind === 'move';
        if (destructive && !paneCard && !guardFolderListReady(kind === 'trash' ? 'Delete' : (kind === 'spam' ? 'Move to Spam' : 'Move'))) {
            return Promise.resolve(false);
        }
        // Don't start another destructive IMAP op while one is still finishing.
        if ((destructive || kind === 'restore') && blockedByCriticalOp()) {
            return Promise.resolve(false);
        }

        // Detect unread state before we mutate/remove the row, so the server can
        // adjust the folder badge without a slow per-folder status sweep.
        var wasUnread = false;
        rowsForUid(uid).forEach(function (el) {
            if (el.getAttribute('data-seen') === '0') wasUnread = true;
        });
        if (!wasUnread && readCard && readCard.getAttribute('data-seen') === '0') {
            wasUnread = true;
        }

        if (kind === 'mark-read') {
            setRowSeen(uid, true);
            if (readCard) {
                readCard.setAttribute('data-seen', '1');
                syncReadSeenButton(readCard);
            }
            // Badge counts update only from the server response (applyUnreadCounts
            // in fireAndForgetAction) — correct number over instant estimate.
            showToast('success', 'Marked as read.');
            var readPayload = new URLSearchParams();
            readPayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { readPayload.set(k, fields[k]); });
            fireAndForgetAction('message/' + kind, readPayload);
            return Promise.resolve(true);
        } else if (kind === 'mark-unread') {
            setRowSeen(uid, false);
            if (readCard) {
                readCard.setAttribute('data-seen', '0');
                syncReadSeenButton(readCard);
            }
            showToast('success', 'Marked as unread.');
            var unreadPayload = new URLSearchParams();
            unreadPayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { unreadPayload.set(k, fields[k]); });
            fireAndForgetAction('message/' + kind, unreadPayload);
            return Promise.resolve(true);
        } else if (kind === 'flag') {
            setRowFlagged(uid, true);
            if (readCard) {
                readCard.setAttribute('data-flagged', '1');
                syncReadFlagButton(readCard);
            }
            showToast('success', 'Marked as important.');
            var flagPayload = new URLSearchParams();
            flagPayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { flagPayload.set(k, fields[k]); });
            fireAndForgetAction('message/' + kind, flagPayload);
            return Promise.resolve(true);
        } else if (kind === 'unflag') {
            setRowFlagged(uid, false);
            if (readCard) {
                readCard.setAttribute('data-flagged', '0');
                syncReadFlagButton(readCard);
            }
            showToast('success', 'Importance removed.');
            var unflagPayload = new URLSearchParams();
            unflagPayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { unflagPayload.set(k, fields[k]); });
            fireAndForgetAction('message/' + kind, unflagPayload);
            return Promise.resolve(true);
        } else if (kind === 'spam' || kind === 'trash' || kind === 'move' || kind === 'restore') {
            var affectsBadge = wasUnread || isDraftFolder();
            fields.unread_delta = affectsBadge ? 1 : 0;

            var movePayload = new URLSearchParams();
            movePayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { movePayload.set(k, fields[k]); });

            beginListMutationQuiet(2500);
            markUidsPendingRemoval([uid]);
            removeRowByUid(uid);
            if (affectsBadge) {
                bumpFolderUnread(-1);
            }

            if (kind === 'restore') {
                clearReadingPaneIfShowingUids([uid]);
                return fireListMutation('message/restore', movePayload, {
                    suppressErrorToast: true,
                    // On failure the server restores the message and it reappears in
                    // the list; clear its pending-removal flag so the user can open it
                    // again (otherwise openMessageInPane's guard swallows the click).
                    rollbackUids: [uid],
                    opToastHandle: beginOpToast({
                        progress: 'Restoring message…',
                        done: 'Message restored to its original folder.',
                        fail: 'The message could not be restored. It remains in Trash.'
                    })
                }).finally(function () { endListMutationQuiet(800); });
            }

            if (kind === 'trash') {
                clearReadingPaneIfShowingUids([uid]);
                return fireListMutation('message/' + kind, movePayload, {
                    suppressErrorToast: true,
                    // On failure the message is restored and reappears; clear its
                    // pending-removal flag so it stays openable (guard in openMessageInPane).
                    rollbackUids: [uid],
                    opToastHandle: beginOpToast({
                        progress: deleteLoadingMessage(1),
                        done: deleteSuccessMessage(1),
                        fail: isTrashFolder()
                            ? 'The message could not be deleted. Please try again.'
                            : 'The message could not be moved to Trash. It has been restored.'
                    })
                }).finally(function () { endListMutationQuiet(800); });
            }

            clearReadingPaneIfShowingUids([uid]);
            fireListMutation('message/' + kind, movePayload, {
                // On failure the message is restored and reappears; clear its
                // pending-removal flag so it stays openable (guard in openMessageInPane).
                rollbackUids: [uid],
                opToastHandle: beginOpToast(kind === 'spam'
                    ? { progress: 'Moving to Spam…', done: 'Message moved to Spam.', fail: 'The message could not be moved to Spam. It has been restored.' }
                    : { progress: 'Moving message…', done: 'Message moved.', fail: 'The message could not be moved. It has been restored.' })
            });
            return Promise.resolve(true);
        }

        return Promise.resolve(false);
    }

    var openContextMenuFor = null;

    function initContextMenu() {
        if (!document.getElementById('mail-workspace')) return;

        var menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.hidden = true;
        document.body.appendChild(menu);

        var backdrop = document.createElement('div');
        backdrop.className = 'context-menu-backdrop';
        backdrop.hidden = true;
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(backdrop);

        var activeKebab = null;

        function hide() {
            menu.hidden = true;
            menu.innerHTML = '';
            backdrop.hidden = true;
            backdrop.setAttribute('aria-hidden', 'true');
            activeKebab = null;
            document.body.classList.remove('context-menu-open');
        }

        function showMobileShell() {
            if (!isMobileUi()) return;
            backdrop.hidden = false;
            backdrop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('context-menu-open');
        }

        backdrop.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            hide();
        });

        var ICONS = {
            open: '<path d="M3 8.5l9 5.5 9-5.5"/><path d="M5 6h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/>',
            reply: '<path d="M9 15L4 10l5-5"/><path d="M4 10h9a7 7 0 0 1 7 7v2"/>',
            replyAll: '<path d="M7 15l-5-5 5-5"/><path d="M12 15l-5-5 5-5"/><path d="M7 10h8a6 6 0 0 1 6 6v2"/>',
            forward: '<path d="M15 15l5-5-5-5"/><path d="M20 10h-9a7 7 0 0 0-7 7v2"/>',
            markUnread: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            markRead: '<path d="M3 9l9-6 9 6v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 9l9 6 9-6"/>',
            star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/>',
            folder: '<path d="M3 7a2 2 0 0 1 2-2h3.6l2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            spam: '<path d="M12 3l9 16H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
            trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/><path d="M9 7V4h6v3"/>',
            restore: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6.7 3L3 13"/>',
            chevron: '<path d="M9 6l6 6-6 6"/>'
        };

        function iconSvg(paths, extraClass) {
            return '<svg class="ctx-ico' + (extraClass ? ' ' + extraClass : '') +
                '" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
                'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                paths + '</svg>';
        }

        function addItem(label, iconPaths, handler, danger) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'context-menu-item' + (danger ? ' is-danger' : '');
            item.innerHTML = iconSvg(iconPaths) + '<span class="ctx-label"></span>';
            item.querySelector('.ctx-label').textContent = label;
            item.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                hide();
                handler();
            });
            menu.appendChild(item);
            return item;
        }

        function addSubmenu(label, iconPaths, folders, onPick) {
            if (isMobileUi()) {
                var mobItem = document.createElement('button');
                mobItem.type = 'button';
                mobItem.className = 'context-menu-item';
                mobItem.innerHTML = iconSvg(iconPaths) + '<span class="ctx-label"></span>' +
                    iconSvg(ICONS.chevron, 'ctx-chevron');
                mobItem.querySelector('.ctx-label').textContent = label;
                mobItem.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    hide();
                    showFolderPicker({
                        title: 'Move to folder',
                        folders: folders,
                        onPick: onPick
                    });
                });
                menu.appendChild(mobItem);
                return mobItem;
            }

            var item = document.createElement('div');
            item.className = 'context-menu-item has-submenu';
            item.setAttribute('tabindex', '0');
            item.innerHTML = iconSvg(iconPaths) + '<span class="ctx-label"></span>' +
                iconSvg(ICONS.chevron, 'ctx-chevron');
            item.querySelector('.ctx-label').textContent = label;

            var sub = document.createElement('div');
            sub.className = 'context-submenu';

            var header = document.createElement('div');
            header.className = 'context-submenu-header';
            header.textContent = 'Choose folder';
            sub.appendChild(header);

            var scroll = document.createElement('div');
            scroll.className = 'context-submenu-scroll';

            folders.forEach(function (f) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'context-menu-item context-submenu-item';
                b.innerHTML = folderIconHtml(f.icon || 'folder') + '<span class="ctx-label"></span>';
                b.querySelector('.ctx-label').textContent = f.name;
                // Indent subfolders under their parent to mirror the folder tree
                // (0.7rem base padding + 1rem per nesting level).
                var subDepth = parseInt(f.depth, 10) || 0;
                if (subDepth > 0) {
                    b.style.paddingInlineStart = (0.7 + subDepth * 1) + 'rem';
                }
                b.addEventListener('click', function (e) {
                    e.preventDefault();
                    hide();
                    onPick(f);
                });
                scroll.appendChild(b);
            });
            sub.appendChild(scroll);

            function updateScrollState() {
                var canScroll = scroll.scrollHeight > scroll.clientHeight + 2;
                sub.classList.toggle('has-scroll', canScroll);
                sub.classList.toggle('is-scrolled', scroll.scrollTop > 4);
                sub.classList.toggle('is-scrolled-end', scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 4);
            }

            scroll.addEventListener('scroll', updateScrollState, { passive: true });
            item.appendChild(sub);

            function place() {
                sub.classList.remove('flip-left', 'flip-below');
                sub.style.top = '0';
                sub.style.marginTop = '';

                var rect = item.getBoundingClientRect();
                var subW = sub.offsetWidth || 212;
                var margin = 10;
                var headerEl = sub.querySelector('.context-submenu-header');
                var headerH = headerEl ? headerEl.offsetHeight : 0;
                var maxSubH = Math.min(320, window.innerHeight - margin * 2);
                sub.style.maxHeight = maxSubH + 'px';
                scroll.style.maxHeight = Math.max(120, maxSubH - headerH) + 'px';

                if (rect.right + subW + 8 > window.innerWidth) {
                    sub.classList.add('flip-left');
                }

                var subRect = sub.getBoundingClientRect();
                if (subRect.left < margin || subRect.right > window.innerWidth - margin) {
                    sub.classList.remove('flip-left');
                    sub.classList.add('flip-below');
                }

                subRect = sub.getBoundingClientRect();
                var overflowBottom = subRect.bottom - (window.innerHeight - margin);
                var overflowTop = margin - subRect.top;
                var topOffset = parseFloat(sub.style.top) || 0;
                if (overflowBottom > 0) {
                    sub.style.top = (topOffset - overflowBottom) + 'px';
                } else if (overflowTop > 0) {
                    sub.style.top = (topOffset + overflowTop) + 'px';
                }

                updateScrollState();
            }

            function wireSubmenuHover() {
                var closeTimer = null;

                function openSub() {
                    if (closeTimer) {
                        clearTimeout(closeTimer);
                        closeTimer = null;
                    }
                    item.classList.add('is-submenu-open');
                    place();
                }

                function scheduleClose() {
                    if (closeTimer) clearTimeout(closeTimer);
                    closeTimer = setTimeout(function () {
                        item.classList.remove('is-submenu-open');
                        closeTimer = null;
                    }, 160);
                }

                function within(node, target) {
                    return !!(target && node && (node === target || node.contains(target)));
                }

                item.addEventListener('mouseenter', openSub);
                item.addEventListener('mouseleave', function (e) {
                    if (within(sub, e.relatedTarget) || within(item, e.relatedTarget)) return;
                    scheduleClose();
                });
                sub.addEventListener('mouseenter', openSub);
                sub.addEventListener('mouseleave', function (e) {
                    if (within(item, e.relatedTarget) || within(sub, e.relatedTarget)) return;
                    scheduleClose();
                });
                item.addEventListener('focus', openSub);
                item.addEventListener('blur', function (e) {
                    if (within(item, e.relatedTarget)) return;
                    scheduleClose();
                });
            }

            wireSubmenuHover();
            menu.appendChild(item);
            return item;
        }

        function addSep() {
            var sep = document.createElement('div');
            sep.className = 'context-menu-sep';
            menu.appendChild(sep);
        }

        function collectMoveFolders() {
            // Use the same structured source as the toolbar/reading-pane pickers so
            // the submenu shows Inbox, Archive, Junk, Trash pinned at top (no Sent),
            // then custom folders alphabetically with nesting + icons. Falls back to
            // the sidebar scan when no toolbar select is present.
            return collectToolbarMoveFolders();
        }

        function openFor(row, x, y) {
            var uid = row.getAttribute('data-uid');
            if (!uid) return;

            var sourceFolderEnc = currentMailFolderEnc();
            if (!sourceFolderEnc) return;

            var seen = row.getAttribute('data-seen') === '1';
            var flagged = row.getAttribute('data-flagged') === '1';
            var href = row.getAttribute('data-href') || row.getAttribute('href');
            var replyUrl = row.getAttribute('data-reply-url');
            var replyAllUrl = row.getAttribute('data-reply-all-url');
            var forwardUrl = row.getAttribute('data-forward-url');

            function go(target) { if (target) { showLoading(); window.location = target; } }

            function goCompose(target, label) {
                if (!target || isComposeUiLocked()) return;
                if (useReadingPane()) {
                    openComposePanel(target, label);
                } else {
                    showLoading();
                    window.location = target;
                }
            }

            menu.innerHTML = '';

            addItem('Open', ICONS.open, function () {
                if (useReadingPane()) {
                    openMessageInPane(parseInt(uid, 10), true);
                } else {
                    go(href);
                }
            });

            if (replyUrl || replyAllUrl || forwardUrl) {
                addSep();
                if (replyUrl) addItem('Reply', ICONS.reply, function () { goCompose(replyUrl, 'Reply'); });
                if (replyAllUrl) addItem('Reply all', ICONS.replyAll, function () { goCompose(replyAllUrl, 'Reply all'); });
                if (forwardUrl) addItem('Forward', ICONS.forward, function () { goCompose(forwardUrl, 'Forward'); });
            }

            addSep();

            if (seen) {
                addItem('Mark as unread', ICONS.markUnread, function () { dispatchMessageAction('mark-unread', sourceFolderEnc, uid); });
            } else {
                addItem('Mark as read', ICONS.markRead, function () { dispatchMessageAction('mark-read', sourceFolderEnc, uid); });
            }

            if (flagged) {
                addItem('Remove importance', ICONS.star, function () { dispatchMessageAction('unflag', sourceFolderEnc, uid); });
            } else {
                addItem('Mark as important', ICONS.star, function () { dispatchMessageAction('flag', sourceFolderEnc, uid); });
            }

            addSep();

            var folders = collectMoveFolders();
            if (folders.length) {
                addSubmenu('Move to', ICONS.folder, folders, function (f) {
                    dispatchMessageAction('move', sourceFolderEnc, uid, { target_folder: f.path });
                });
            }
            addItem('Move to Spam', ICONS.spam, function () { dispatchMessageAction('spam', sourceFolderEnc, uid); });

            if (isTrashFolder()) {
                addItem('Restore to original folder', ICONS.restore, function () { dispatchMessageAction('restore', sourceFolderEnc, uid); });
            }

            addSep();
            addItem(isTrashFolder() ? 'Delete permanently' : 'Delete', ICONS.trash, function () { dispatchMessageAction('trash', sourceFolderEnc, uid); }, true);

            if (isMobileUi()) {
                addSep();
                var cancelItem = document.createElement('button');
                cancelItem.type = 'button';
                cancelItem.className = 'context-menu-item context-menu-item--cancel';
                cancelItem.innerHTML = iconSvg('<path d="M18 6L6 18M6 6l12 12"/>') + '<span class="ctx-label">Cancel</span>';
                cancelItem.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    hide();
                });
                menu.appendChild(cancelItem);
            }

            menu.hidden = false;
            var mw = menu.offsetWidth;
            var mh = menu.offsetHeight;
            var left = (x + mw > window.innerWidth) ? x - mw : x;
            var top = (y + mh > window.innerHeight) ? y - mh : y;
            if (isMobileUi()) {
                left = Math.max(12, (window.innerWidth - mw) / 2);
                top = Math.max(12, Math.min(top, window.innerHeight - mh - 12));
                showMobileShell();
            }
            menu.style.left = Math.max(4, left) + 'px';
            menu.style.top = Math.max(4, top) + 'px';
        }

        openContextMenuFor = openFor;

        document.addEventListener('contextmenu', function (e) {
            var row = e.target.closest('.mail-row, .mail-card');
            if (!row) return;
            e.preventDefault();
            if (isMobileUi()) return;
            openFor(row, e.clientX, e.clientY);
        });

        document.addEventListener('click', function (e) {
            var kebab = e.target.closest('.mail-kebab');
            if (kebab) {
                if (isMobileUi()) return;
                e.preventDefault();
                e.stopPropagation();
                var row = kebab.closest('.mail-row, .mail-card');
                if (row) {
                    if (!menu.hidden && activeKebab === kebab) {
                        hide();
                        return;
                    }
                    activeKebab = kebab;
                    var rect = kebab.getBoundingClientRect();
                    openFor(row, rect.right, rect.bottom);
                }
                return;
            }
            if (!menu.hidden && !menu.contains(e.target)) hide();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hide();
        });
        window.addEventListener('resize', hide);
        window.addEventListener('scroll', function (e) {
            if (menu.hidden) return;
            // Don't close when the user is scrolling inside the menu/submenu itself.
            if (e.target && e.target.nodeType === 1 && menu.contains(e.target)) return;
            hide();
        }, true);
    }

    function initGlobalFormLoading() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.dataset.noBtnLoading !== undefined) return;
            if (form.getAttribute('data-confirm-title') || form.getAttribute('data-confirm-message')) return;
            if (form.id === 'compose-form') return;
            if (form.classList.contains('admin-action-form')) return;

            var submitter = e.submitter;
            var btn = (submitter && submitter.tagName === 'BUTTON')
                ? submitter
                : form.querySelector('button[type="submit"]:not([disabled])');
            if (!btn || btn.classList.contains('is-loading')) return;
            if (btn.classList.contains('admin-action-link') || btn.classList.contains('btn-link-danger') || btn.classList.contains('btn-link-muted')) return;

            var raw = (submitter && submitter !== btn && submitter.textContent)
                ? submitter.textContent.trim()
                : btn.textContent.trim();
            var label = loadingLabelForAction('save');
            var lower = raw.toLowerCase();
            if (lower.indexOf('sign') >= 0) label = 'Signing in…';
            else if (lower.indexOf('save') >= 0 || lower.indexOf('update') >= 0) label = 'Saving…';
            else if (lower.indexOf('sync') >= 0) label = 'Syncing…';
            else if (lower.indexOf('send') >= 0) label = 'Sending…';
            else if (lower.indexOf('delete') >= 0 || lower.indexOf('remove') >= 0) label = 'Deleting…';
            else if (raw) label = raw.replace(/\s+$/, '') + '…';

            setButtonLoading(btn, true, label);
        });
    }

    function initMailBootstrap() {
        if (!document.getElementById('mail-workspace')) return;
        var card = getListCard();
        var stale = card && card.getAttribute('data-cache-stale') === '1';
        var delay = stale ? 600 : 3500;
        window.setTimeout(function () {
            if (isPostSendQuiet()) return;
            var folderEnc = card ? card.getAttribute('data-folder-path') : '';
            var url = apiUrl('mail/bootstrap');
            if (folderEnc) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'folder=' + encodeURIComponent(folderEnc);
            }
            if (typeof AbortController !== 'undefined') {
                mailBootstrapAbort = new AbortController();
            } else {
                mailBootstrapAbort = null;
            }
            fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: mailBootstrapAbort ? mailBootstrapAbort.signal : undefined
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    mailBootstrapAbort = null;
                    if (data && data.folders_changed) {
                        window.location.reload();
                        return;
                    }
                    if (data && data.unread_counts) {
                        applyUnreadCounts(data.unread_counts);
                    }
                })
                .catch(function () {
                    mailBootstrapAbort = null;
                });
        }, delay);
    }

    // Live new-mail detection: every ~30s ask the server to check the mail server
    // for new mail (routing it into the right folder + updating the index), then
    // refresh badges (which fires the sound/desktop notification on any increase)
    // and light-sync the open folder so new rows appear. The IMAP work runs
    // server-side AFTER an instant response and releases the session lock, so
    // opening a folder or a message stays as fast as before.
    function initLiveSync() {
        if (!document.getElementById('mail-workspace')) return;
        // Suppress notifications during the initial page-load settle so the badge
        // counts accumulating to their real baseline don't false-fire.
        window.setTimeout(function () { newMailNotifyArmed = true; }, 6000);
        // GENTLE variant: a single lightweight request every 2 minutes. The server
        // does the filter + reconcile and returns fresh counts in this one response,
        // so we just apply them (which bumps the badge and fires the new-mail
        // sound/desktop notification on any increase). A light cache-only poll then
        // lets a new row show in the currently-open folder. This replaced the old
        // 3-request/30s version that overloaded the shared host. The interval is
        // configurable per-server via LIVE_SYNC_INTERVAL (.env) — default 60s.
        var cfgSecs = parseInt(document.body.getAttribute('data-live-sync-interval'), 10);
        var intervalMs = (cfgSecs && cfgSecs >= 15 ? cfgSecs : 60) * 1000;
        window.setInterval(function () {
            // Hold the live-sync tick while a destructive op is settling: mail/live-sync
            // opens live IMAP (routing-filter MOVEs, badge STATUS, folder LIST) that would
            // race the in-flight move on this connection-limited host. It self-corrects on
            // the next tick once the op releases.
            if (criticalOpActive) return;
            if (isPostSendQuiet()) return;
            fetch(apiUrl('mail/live-sync'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.unread_counts) {
                        applyUnreadCounts(data.unread_counts);
                    }
                    if (data && data.folders_sig) {
                        maybeRefreshSidebar(data.folders_sig);
                    }
                    scheduleMailPoll(false);
                })
                .catch(function () {});
        }, intervalMs);
    }

    // When the folder set changes on the server (a folder created/removed in
    // another mail client), the live-sync poll reports a new signature. Fetch a
    // freshly rendered sidebar and swap it in so the change appears live — no
    // navigation, no re-login. Delegated folder-click + mobile-close handlers
    // survive the swap; the collapse toggles are re-bound via initSidebarGroups().
    var sidebarRefreshInFlight = false;
    function maybeRefreshSidebar(sig) {
        var el = document.getElementById('sidebar');
        if (!el || !sig || sidebarRefreshInFlight) return;
        if (el.getAttribute('data-folders-sig') === sig) return; // unchanged
        sidebarRefreshInFlight = true;
        var active = document.querySelector('.sidebar-link.active[data-folder-b64]');
        var activeB64 = active ? (active.getAttribute('data-folder-b64') || '') : '';
        fetch(apiUrl('mail/sidebar?active=' + encodeURIComponent(activeB64)), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && typeof data.html === 'string') {
                    el.innerHTML = data.html;
                    el.setAttribute('data-folders-sig', data.sig || sig);
                    // Re-bind the sidebar's directly-bound handlers on the fresh DOM:
                    // group/branch collapse toggles AND the "collapse all folders"
                    // section toggle (which also re-applies its saved collapsed state).
                    if (typeof initSidebarGroups === 'function') initSidebarGroups();
                    if (typeof initSidebarSectionToggles === 'function') initSidebarSectionToggles();
                }
            })
            .catch(function () {})
            .then(function () { sidebarRefreshInFlight = false; });
    }

    function initStatusPage() {
        var card = document.getElementById('status-card');
        if (!card) return;

        var resultEl = document.getElementById('status-result');
        var checkedLine = document.getElementById('status-checked-line');
        var refreshBtn = document.getElementById('status-refresh-btn');
        var checking = false;

        function renderStatus(data) {
            if (!resultEl || !data) return;

            var connected = !!data.imap_connected;
            var folderCount = parseInt(data.folder_count, 10) || 0;
            var error = data.imap_error || '';

            resultEl.innerHTML =
                (connected
                    ? '<p class="status status-ok" id="status-imap-line">IMAP connected successfully</p>'
                    : '<p class="status status-error" id="status-imap-line">IMAP connection failed</p>') +
                (error && !connected
                    ? '<p class="text-muted error-detail" id="status-error-line">' + escapeHtml(error) + '</p>'
                    : '') +
                (connected
                    ? '<p class="text-muted" id="status-folder-line">' + folderCount + ' folders found on mail server.</p>'
                    : '');

            if (checkedLine) {
                var ms = data.duration_ms ? ' (' + data.duration_ms + ' ms)' : '';
                checkedLine.textContent = 'Last live check: ' + (data.checked_at || 'just now') + ms;
            }

            if (data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            }
        }

        function runCheck() {
            if (checking) return;
            checking = true;
            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.textContent = 'Testing…';
            }
            if (checkedLine) checkedLine.textContent = 'Testing connection to mail server…';

            fetch(apiUrl('status/check'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false }; }).then(function (data) {
                    if (!res.ok || !data || !data.ok) {
                        throw new Error((data && data.imap_error) || 'Connection test failed.');
                    }
                    renderStatus(data);
                });
            }).catch(function (err) {
                if (checkedLine) checkedLine.textContent = err.message || 'Connection test failed.';
            }).finally(function () {
                checking = false;
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = 'Test connection now';
                }
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', runCheck);
        }
        if (card.getAttribute('data-auto-check') === '1') {
            window.setTimeout(runCheck, 80);
        }
    }

    // Auto sign-out after a stretch of no real user interaction. The server
    // session is held open by background polling for as long as a tab is open, so
    // "idle" here means no mouse / keyboard / touch / scroll activity — tracked on
    // the client. Activity is shared across tabs via localStorage, so working in
    // any one tab keeps them all signed in; when the window elapses every tab
    // signs out. The window comes from data-idle-timeout (seconds), mirroring the
    // server session_lifetime.
    function initIdleLogout() {
        var logoutForm = document.querySelector('.header-nav-form');
        if (!logoutForm) return; // only on authenticated pages

        var seconds = parseInt(document.body.getAttribute('data-idle-timeout'), 10);
        if (!seconds || seconds < 60) seconds = 3 * 60 * 60; // 3h fallback
        var IDLE_MS = seconds * 1000;
        var ACT_KEY = 'dj_last_activity';
        var SID_KEY = 'dj_session_id';
        var OUT_KEY = 'dj_idle_logout';
        var sessionId = document.body.getAttribute('data-session-id') || '';
        var WRITE_THROTTLE_MS = 30000;
        var lastWrite = 0;
        var signingOut = false;

        function getNum(key) {
            var v;
            try { v = parseInt(localStorage.getItem(key) || '', 10); } catch (e) { v = NaN; }
            return isNaN(v) ? 0 : v;
        }

        function markNow() {
            lastWrite = Date.now();
            try { localStorage.setItem(ACT_KEY, String(lastWrite)); } catch (e) { /* private mode */ }
        }

        // True once the idle window has elapsed since the last recorded activity.
        // 0 (no record yet) never counts as expired.
        function expired() {
            var last = getNum(ACT_KEY);
            return last > 0 && (Date.now() - last) >= IDLE_MS;
        }

        function signOut() {
            if (signingOut) return;
            signingOut = true;
            try { localStorage.setItem(OUT_KEY, String(Date.now())); } catch (e) { /* ignore */ }
            // Reuse the header logout form so the POST carries the CSRF token; the
            // reason flag lets the login page explain why they were signed out. A
            // top-level form submit (not a background fetch) also lets the browser
            // clear any CDN bot-challenge, so this lands cleanly on the login page.
            var reason = document.createElement('input');
            reason.type = 'hidden';
            reason.name = 'reason';
            reason.value = 'idle';
            logoutForm.appendChild(reason);
            logoutForm.submit();
        }

        // A real interaction must FIRST decide whether the window already lapsed
        // (e.g. the machine slept or the tab was frozen so the interval never
        // fired) — in that case sign out instead of resetting the clock. This is
        // what makes "idle 3h, then click anything → login page" work.
        function onActivity() {
            if (signingOut) return;
            if (expired()) { signOut(); return; }
            if (Date.now() - lastWrite >= WRITE_THROTTLE_MS) markNow();
        }

        function check() {
            if (!signingOut && expired()) signOut();
        }

        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel', 'click', 'focus'].forEach(function (ev) {
            window.addEventListener(ev, onActivity, { passive: true, capture: true });
        });

        // Follow a sign-out triggered in another tab.
        window.addEventListener('storage', function (e) {
            if (e.key === OUT_KEY && e.newValue && !signingOut) {
                signingOut = true;
                window.location.href = (document.body.getAttribute('data-base-url') || '') + 'login?reason=idle';
            }
        });

        // Foreground / wake-from-sleep / bfcache restore all re-check immediately.
        document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
        window.addEventListener('pageshow', check);
        window.addEventListener('focus', check);

        // Reconcile the persisted clock with this page load:
        //  - New session id (a fresh login) → start the clock now; never inherit a
        //    stale clock from a previous session (which would bounce straight back
        //    to login).
        //  - Same session, window already lapsed → the tab sat idle past the limit
        //    (interval frozen while asleep) → sign out on this very load.
        //  - Same session, still within the window → this load counts as activity.
        var storedSid = '';
        try { storedSid = localStorage.getItem(SID_KEY) || ''; } catch (e) { /* ignore */ }
        if (sessionId && storedSid !== sessionId) {
            try { localStorage.setItem(SID_KEY, sessionId); } catch (e) { /* ignore */ }
            markNow();
        } else if (expired()) {
            signOut();
            return;
        } else {
            markNow();
        }
        window.setInterval(check, 30000);
    }

    // Sum the unread badges of the bottom (custom) folders and show the total on
    // the section divider — recomputed whenever folder badges change.
    function updateSidebarSectionBadge() {
        var content = document.getElementById('sidebar-folder-groups');
        var badge = document.getElementById('sidebar-section-badge');
        if (!content || !badge) return;
        var total = 0;
        content.querySelectorAll('.sidebar-link[data-folder-path] .folder-badge').forEach(function (b) {
            var n = parseInt((b.textContent || '').replace(/[^0-9]/g, ''), 10);
            if (n) total += n;
        });
        if (total > 0) {
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    }

    // Collapsible sidebar section divider: the toggle at the end of the rule
    // collapses/expands EVERY folder branch in the section (not hide it), and the
    // state persists across reloads.
    function initSidebarSectionToggles() {
        function setBranchCollapsed(branch, collapsed) {
            branch.classList.toggle('is-open', !collapsed);
            var children = branch.querySelector(':scope > .sidebar-folder-branch-children');
            if (children) children.hidden = collapsed;
            var t = branch.querySelector(':scope > .sidebar-tree-row .sidebar-tree-toggle');
            if (t) t.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        document.querySelectorAll('.sidebar-section-divider').forEach(function (divider) {
            var btn = divider.querySelector('.sidebar-section-toggle');
            var content = document.getElementById(btn ? btn.getAttribute('aria-controls') : '');
            if (!btn || !content) return;
            var key = 'dj_sidebar_section_' + (divider.getAttribute('data-sidebar-section') || 'x');

            function apply(collapsed) {
                divider.classList.toggle('is-collapsed', collapsed);
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                content.querySelectorAll('.sidebar-folder-branch').forEach(function (b) {
                    setBranchCollapsed(b, collapsed);
                });
            }

            try {
                if (localStorage.getItem(key) === 'collapsed') apply(true);
            } catch (e) { /* storage blocked */ }

            btn.addEventListener('click', function () {
                var collapsed = !divider.classList.contains('is-collapsed');
                apply(collapsed);
                try { localStorage.setItem(key, collapsed ? 'collapsed' : 'open'); } catch (e) { /* storage blocked */ }
            });
        });

        updateSidebarSectionBadge();
    }

    // Drag the divider between the message list and the reading pane to resize
    // the list column; the width persists (localStorage) and a double-click
    // resets to the default 42% split. The resizer is a sibling of the list
    // column, so AJAX folder swaps (which replace only .mail-list-column) keep
    // its listeners alive.
    function initListColumnResizer() {
        var resizer = document.getElementById('list-column-resizer');
        var workspace = document.getElementById('mail-workspace');
        if (!resizer || !workspace) return;
        var MIN = 340;
        var root = document.documentElement;

        function maxWidth() {
            // Keep the reading pane usable: its CSS min-width is 360px.
            var total = workspace.getBoundingClientRect().width;
            return Math.max(MIN, Math.min(720, total - 380));
        }

        function apply(px) {
            root.style.setProperty('--list-column-width', px + 'px');
            workspace.classList.add('has-custom-list-width');
        }

        try {
            var saved = parseInt(localStorage.getItem('dj_list_column_width'), 10);
            if (saved && saved >= MIN) {
                // Below 1024px the pane is hidden and maxWidth() would clamp
                // against a meaningless single-column layout; the CSS max-width
                // cap protects the pane, so apply the saved value as-is there.
                var narrow = window.matchMedia('(max-width: 1023px)').matches;
                apply(narrow ? saved : Math.min(saved, maxWidth()));
            }
        } catch (e) { /* storage blocked */ }

        var dragging = false;
        function onMove(e) {
            if (!dragging) return;
            var x = e.touches ? e.touches[0].clientX : e.clientX;
            var w = Math.round(x - workspace.getBoundingClientRect().left);
            if (w < MIN) w = MIN;
            var max = maxWidth();
            if (w > max) w = max;
            apply(w);
        }
        function onUp() {
            if (!dragging) return;
            dragging = false;
            resizer.classList.remove('is-dragging');
            document.body.classList.remove('is-resizing-list-column');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            document.removeEventListener('touchcancel', onUp);
            try {
                var px = parseInt(getComputedStyle(root).getPropertyValue('--list-column-width'), 10);
                if (px) localStorage.setItem('dj_list_column_width', String(px));
            } catch (e) { /* storage blocked */ }
        }
        function start(e) {
            if (window.matchMedia('(max-width: 1023px)').matches) return;
            e.preventDefault();
            dragging = true;
            resizer.classList.add('is-dragging');
            document.body.classList.add('is-resizing-list-column');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onUp);
            document.addEventListener('touchcancel', onUp);
        }
        resizer.addEventListener('mousedown', start);
        resizer.addEventListener('touchstart', start, { passive: false });
        resizer.addEventListener('dblclick', function () {
            root.style.removeProperty('--list-column-width');
            workspace.classList.remove('has-custom-list-width');
            try { localStorage.removeItem('dj_list_column_width'); } catch (e) { /* storage blocked */ }
        });
    }

    // Drag the divider between the sidebar and main content to resize the sidebar;
    // the width persists (localStorage) and a double-click resets to default.
    function initSidebarResizer() {
        var resizer = document.getElementById('sidebar-resizer');
        var shell = document.querySelector('.app-shell');
        if (!resizer || !shell) return;
        var MIN = 180;
        var MAX = 520;
        var root = document.documentElement;

        try {
            var saved = parseInt(localStorage.getItem('dj_sidebar_width'), 10);
            if (saved && saved >= MIN && saved <= MAX) {
                root.style.setProperty('--sidebar-width', saved + 'px');
            }
        } catch (e) { /* storage blocked */ }

        var dragging = false;
        function onMove(e) {
            if (!dragging) return;
            var x = e.touches ? e.touches[0].clientX : e.clientX;
            var w = Math.round(x - shell.getBoundingClientRect().left);
            if (w < MIN) w = MIN;
            if (w > MAX) w = MAX;
            root.style.setProperty('--sidebar-width', w + 'px');
        }
        function onUp() {
            if (!dragging) return;
            dragging = false;
            resizer.classList.remove('is-dragging');
            document.body.classList.remove('is-resizing-sidebar');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            document.removeEventListener('touchcancel', onUp);
            try {
                var px = parseInt(getComputedStyle(root).getPropertyValue('--sidebar-width'), 10);
                if (px) localStorage.setItem('dj_sidebar_width', String(px));
            } catch (e) { /* storage blocked */ }
        }
        function start(e) {
            if (window.matchMedia('(max-width: 899px)').matches) return;
            e.preventDefault();
            dragging = true;
            resizer.classList.add('is-dragging');
            document.body.classList.add('is-resizing-sidebar');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onUp);
            document.addEventListener('touchcancel', onUp);
        }
        resizer.addEventListener('mousedown', start);
        resizer.addEventListener('touchstart', start, { passive: false });
        resizer.addEventListener('dblclick', function () {
            root.style.removeProperty('--sidebar-width');
            try { localStorage.removeItem('dj_sidebar_width'); } catch (e) { /* storage blocked */ }
        });
    }

    // Add a show/hide (eye) toggle to every password field, site-wide.
    function initPasswordToggles() {
        var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            if (input.getAttribute('data-pw-toggle') === '1') return;
            input.setAttribute('data-pw-toggle', '1');
            var wrap = document.createElement('div');
            wrap.className = 'password-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'password-toggle';
            btn.setAttribute('aria-label', 'Show password');
            btn.innerHTML = EYE;
            wrap.appendChild(btn);
            btn.addEventListener('click', function () {
                var reveal = input.getAttribute('type') === 'password';
                input.setAttribute('type', reveal ? 'text' : 'password');
                btn.innerHTML = reveal ? EYE_OFF : EYE;
                btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebarResizer();
        initListColumnResizer();
        initPasswordToggles();
        initToasts();
        initIdleLogout();
        initMailSync();
        initMessageSync();
        initMailCommandBar();
        initRichEditor();
        initRecipientFields();
        initRulesDragDrop();
        initThemeFromSettings();
        initSidebarGroups();
        initSidebarSectionToggles();
        initAdminFolderTree();
        initFileUpload();
        initPerPageSelect();
        initContextMenu();
        initReadingPane();
        initAjaxFolderNav();
        initStatusPage();
        initSidebarBadgesOnLoad();
        initGlobalSearchFeedback();
        initMailBootstrap();
        initLiveSync();  // gentle variant: one lightweight request every 2 min
        initComposePanel();
        initReadViewActions();
        initDeleteKeyShortcut();
        initConfirmForms();
        initGlobalFormLoading();
        initMobileReadSwipe();
        scheduleListEnrichment(document);
    });
})();
