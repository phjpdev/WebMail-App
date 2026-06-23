(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    var menuToggle = document.getElementById('menu-toggle');
    var sidebarBackdrop = document.getElementById('sidebar-backdrop');
    var body = document.body;
    var csrf = body.getAttribute('data-csrf') || '';
    var appBase = (body.getAttribute('data-base-url') || '').replace(/\/$/, '');

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
        path = String(path).replace(/^\//, '');
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
    var composePanelSeq = 0;
    var composePanelRestoreUid = null;
    var paneNavTimer = null;
    var paneNavPendingUid = null;
    var paneNavPendingHistory = false;
    var paneCache = {};
    var mailPollIntervalId = null;
    var mailSyncPaused = false;
    var lastMailPollAt = 0;
    var mailPollMinGapMs = 25000;
    var mailSyncHooksBound = false;
    var pendingRemovalUntil = {};
    var PENDING_REMOVAL_MS = 120000;
    var PANE_CACHE_MAX = 24;
    var PANE_NAV_DEBOUNCE_MS = 0;

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

    function currentFolderKind() {
        var card = getListCard();
        return card ? (card.getAttribute('data-folder-kind') || '') : '';
    }

    function isTrashFolder() {
        return currentFolderKind() === 'trash';
    }

    function deleteConfirmOptions(count) {
        var n = count || 1;
        if (isTrashFolder()) {
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

    function paneFetchUrl(uid) {
        var card = getListCard();
        if (!card) return null;
        var b64 = card.getAttribute('data-folder-b64');
        return apiUrl('folder/' + b64 + '/message/' + uid + '/pane');
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

    function updateSidebarActive(folderPath) {
        document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
            var active = link.getAttribute('data-folder-path') === folderPath;
            link.classList.toggle('active', active);
            if (active && link.closest('.sidebar-group.is-collapsible')) {
                link.closest('.sidebar-group').classList.add('is-open');
                var toggle = link.closest('.sidebar-group').querySelector('.sidebar-group-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
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
        rowsForUid(uid).forEach(function (el) {
            el.classList.add('is-selected');
            el.classList.add('is-focused');
            el.setAttribute('aria-selected', 'true');
        });
        setRowAriaSelected(uid);
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
        if (bodyEl) {
            var card = bodyEl.querySelector('.mail-read-card[data-uid]');
            if (card && card._syncTimer) {
                clearInterval(card._syncTimer);
                card._syncTimer = null;
            }
            bodyEl.innerHTML = '';
        }
        setPaneView('empty');
        clearMailRowSelection();
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

    function confirmPrefetchedPane(uid, pushHistory) {
        var url = paneFetchUrl(uid);
        if (!url) return;
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok || !data || !data.ok) return;
                    rememberPaneCache(uid, data);
                    if (data.was_unread) {
                        setRowSeen(uid, true);
                    }
                    if (data.unread_counts) {
                        applyUnreadCounts(data.unread_counts);
                    } else if (typeof data.folder_unread === 'number') {
                        var countLabel = document.getElementById('mail-count-label');
                        var totalMsgs = countLabel
                            ? parseInt(countLabel.getAttribute('data-total') || countLabel.textContent, 10) || 0
                            : 0;
                        updateMailCount(totalMsgs, data.folder_unread);
                    }
                });
            }).catch(function () {});
    }

    function applyPaneHtml(uid, data, pushHistory) {
        var bodyEl = document.getElementById('reading-pane-body');
        if (!bodyEl) return;

        bodyEl.innerHTML = data.html;
        setPaneView('content');

        if (data.was_unread || isRowUnread(uid)) {
            setRowSeen(uid, true);
            var readCard = bodyEl.querySelector('.mail-read-card[data-uid]');
            if (readCard) {
                readCard.setAttribute('data-seen', '1');
                syncReadSeenButton(readCard);
            }
        }
        if (data.unread_counts) {
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
        bindReadViewCard(card);
        bindComposeLinks(card);
        bindMessageSyncCard(card);

        var subject = data.subject || 'Message';
        announceLive('Loaded: ' + subject);
    }

    function openMessageInPaneNow(uid, pushHistory) {
        if (!uid) return;
        if (!useReadingPane()) {
            var row = rowsForUid(uid)[0];
            if (row) {
                showLoading();
                window.location = row.getAttribute('data-href');
            }
            return;
        }

        var isUnread = isRowUnread(uid);
        var cached = !isUnread ? getPaneCache(uid) : null;
        if (cached && cached.html) {
            var rowCached = rowsForUid(uid)[0];
            var hrefCached = rowCached ? rowCached.getAttribute('data-href') : null;
            if (pushHistory && hrefCached && window.history && window.history.pushState) {
                window.history.pushState({ paneUid: uid }, '', hrefCached);
            }
            setSelectedRow(uid);
            applyPaneHtml(uid, cached, pushHistory);
            if (cached.prefetched) {
                confirmPrefetchedPane(uid, pushHistory);
            }
            return;
        }

        var url = paneFetchUrl(uid);
        if (!url) return;

        var seq = ++paneLoadSeq;
        mailSyncPaused = true;
        showPanePreviewFromRow(uid);
        setSelectedRow(uid);

        var row = rowsForUid(uid)[0];
        var messageHref = row ? row.getAttribute('data-href') : null;
        if (pushHistory && messageHref && window.history && window.history.pushState) {
            window.history.pushState({ paneUid: uid }, '', messageHref);
        }

        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) throw new Error((data && data.error) || 'Could not load message.');
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
                setPaneView('empty');
                announceLive('Could not load message.');
                showToast('error', err.message || 'Could not load message.');
            })
            .finally(function () {
                if (seq === paneLoadSeq) {
                    mailSyncPaused = false;
                }
            });
    }

    function openMessageInPane(uid, pushHistory) {
        if (!uid) return;
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

    function prefetchPane(uid) {
        if (!uid || getPaneCache(uid) || !useReadingPane()) return;
        var url = paneFetchUrl(uid);
        if (!url) return;
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'prefetch=1';
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (res.ok && data && data.ok && data.html) {
                        data.prefetched = true;
                        rememberPaneCache(uid, data);
                    }
                });
            }).catch(function () {});
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
    }

    function stopMailSync() {
        if (mailPollIntervalId) {
            window.clearInterval(mailPollIntervalId);
            mailPollIntervalId = null;
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

    function loadAttachmentHints(root) {
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

        fetch(apiUrl('folder/' + b64 + '/attachments?uids=' + uids.join(',')), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.has_attachment) return;
                Object.keys(data.has_attachment).forEach(function (uidKey) {
                    if (!data.has_attachment[uidKey]) return;
                    rowsForUid(parseInt(uidKey, 10)).forEach(function (row) {
                        applyAttachmentIcon(row, true);
                    });
                });
            }).catch(function () {});
    }

    function prefetchVisiblePanes() {
        if (!useReadingPane()) return;
        outlookRows().slice(0, 4).forEach(function (row, index) {
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            if (!uid) return;
            window.setTimeout(function () { prefetchPane(uid); }, index * 40);
        });
    }

    function reinitMailListColumn() {
        selectAllInFolder = false;
        bindAllMailRows(document);
        initMailCommandBar();
        initMailSync();
        initPerPageSelect();
        window.setTimeout(function () { loadAttachmentHints(document); }, 400);
        prefetchVisiblePanes();
        if (mailPoll) scheduleMailPoll(false);
    }

    function loadFolderAjax(folderB64, pushHistory, forceRefresh) {
        if (!folderB64 || !document.getElementById('mail-workspace')) return;

        var seq = ++folderLoadSeq;
        clearReadingPane();
        closeComposePanel(false);

        var column = document.querySelector('.mail-list-column');
        if (column) column.classList.add('is-loading');

        var fragmentUrl = apiUrl('folder/' + folderB64 + '/fragment');
        if (forceRefresh) {
            fragmentUrl += (fragmentUrl.indexOf('?') >= 0 ? '&' : '?') + 'refresh=1';
        }

        fetch(fragmentUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error((data && data.error) || 'Could not load folder.');
                return data;
            });
        }).then(function (data) {
            if (seq !== folderLoadSeq) return;
            if (!data || !data.ok || !data.html) throw new Error('Could not load folder.');

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

            if (data.folder_path) updateSidebarActive(data.folder_path);
            if (data.unread_counts) applyUnreadCounts(data.unread_counts);
            if (data.title) {
                var parts = document.title.split(' — ');
                document.title = data.title + (parts.length > 1 ? ' — ' + parts.slice(1).join(' — ') : '');
            }
            if (pushHistory && data.url && window.history && window.history.pushState) {
                window.history.pushState({ folderB64: folderB64 }, '', data.url);
            }

            reinitMailListColumn();
            announceLive('Folder loaded: ' + (data.title || 'Mail'));
        }).catch(function (err) {
            if (seq !== folderLoadSeq) return;
            showToast('error', err.message || 'Could not load folder.');
        }).finally(function () {
            if (seq !== folderLoadSeq) return;
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
            loadFolderAjax(b64, true);
            if (window.innerWidth < 900) closeSidebar();
        });
    }

    function currentMailFolderEnc() {
        var listCard = getListCard();
        return listCard ? (listCard.getAttribute('data-folder-path') || '') : '';
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

    function isComposeOpen() {
        var panel = document.getElementById('compose-panel');
        return !!(panel && !panel.hidden);
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
        if (!useReadingPane()) {
            if (triggerLink) setButtonLoading(triggerLink, true, loadingLabelForAction('compose'));
            showLoading();
            window.location = href;
            return;
        }

        var selected = document.querySelector('.mail-row.is-selected, .mail-card.is-selected');
        composePanelRestoreUid = selected ? parseInt(selected.getAttribute('data-uid'), 10) : null;

        var path = withEmbedParams(href);
        var seq = ++composePanelSeq;
        setComposeOpen(true);
        if (triggerLink) setButtonLoading(triggerLink, true, loadingLabelForAction('compose'));

        var body = document.getElementById('compose-panel-body');
        var titleEl = document.getElementById('compose-panel-title');
        if (titleEl) titleEl.textContent = title || composeTitleFromPath(href);
        if (body) {
            body.innerHTML = '<div class="compose-panel-loading"><span class="reading-pane-spinner" aria-hidden="true"></span><span>Loading compose…</span></div>';
        }

        fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (res) {
                if (!res.ok) throw new Error('Could not load compose form.');
                return res.text();
            })
            .then(function (html) {
                if (seq !== composePanelSeq) return;
                if (body) body.innerHTML = html;
                initComposeForm(body);
                bindComposeLinks(body);
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

    function closeComposePanel(restorePane) {
        if (restorePane === undefined) restorePane = true;
        composePanelSeq++;
        setComposeOpen(false);
        var body = document.getElementById('compose-panel-body');
        if (body) body.innerHTML = '';

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
            a.addEventListener('click', function (e) {
                if (!useReadingPane()) return;
                e.preventDefault();
                var linkTitle = a.getAttribute('data-compose-title') || composeTitleFromPath(a.getAttribute('href'));
                openComposePanel(a.getAttribute('href'), linkTitle, a);
            });
        });
    }

    function syncComposeEditor(form) {
        var editor = form.querySelector('#body-editor');
        var bodyField = form.querySelector('#body');
        var htmlField = form.querySelector('#body_html');
        if (htmlField && editor) htmlField.value = editor.innerHTML;
        if (bodyField && editor) bodyField.value = editor.innerText;
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

    function setComposeFormBusy(form, busy, activeBtn, loadingLabel) {
        if (!form) return;
        var actions = form.querySelector('.compose-form-actions');
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

    function bindComposeFormAjax(form) {
        if (!form || form.dataset.ajaxBound) return;
        form.dataset.ajaxBound = '1';

        form.addEventListener('click', function (e) {
            var cancel = e.target.closest('[data-compose-cancel]');
            if (cancel) {
                e.preventDefault();
                closeComposePanel(true);
            }
        });

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;

            syncComposeEditor(form);

            var submitter = e.submitter;
            var draftAction = submitter && submitter.getAttribute('formaction');
            var actionPath = draftAction ? normalizeComposePath(draftAction) : 'compose/send';
            var isDraft = actionPath.indexOf('draft') >= 0;
            var loadingLabel = isDraft ? 'Saving…' : 'Sending…';
            var isPanelAjax = useReadingPane() && form.closest('#compose-panel');

            if (isPanelAjax) {
                e.preventDefault();

                var returnField = form.querySelector('#return_folder');
                if (returnField && !returnField.value) {
                    returnField.value = currentMailFolderEnc();
                }

                setComposeFormBusy(form, true, submitter, loadingLabel);

                var fd = new FormData(form);
                fetch(apiUrl(actionPath), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json'
                    },
                    body: fd
                }).then(function (res) {
                    return res.json().catch(function () { return { ok: res.ok }; }).then(function (data) {
                        if (!res.ok || (data && data.ok === false)) {
                            throw new Error((data && data.error) || 'Action failed.');
                        }
                        return data;
                    });
                }).then(function (data) {
                    showToast('success', (data && data.message) || (isDraft ? 'Draft saved.' : 'Email sent.'));
                    if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                        applyUnreadCounts(data.unread_counts);
                    } else {
                        refreshUnreadBadges();
                    }
                    if (isDraft) return;
                    if (data && data.draft_uid) removeRowByUid(data.draft_uid);
                    closeComposePanel(false);
                    setPaneView('empty');
                    var returnFolder = data && data.return_folder ? data.return_folder : '';
                    var currentFolder = currentMailFolderEnc();
                    if (currentFolder) {
                        loadFolderAjax(currentFolder, false, false);
                    } else if (returnFolder) {
                        loadFolderAjax(returnFolder, false, false);
                    }
                    if (mailPoll) scheduleMailPoll(true);
                }).catch(function (err) {
                    showToast('error', err.message || 'Action failed.');
                }).finally(function () {
                    setComposeFormBusy(form, false);
                });
                return;
            }

            if (submitter && submitter.tagName === 'BUTTON') {
                setComposeFormBusy(form, true, submitter, loadingLabel);
            }
        });
    }

    function initComposeForm(root) {
        root = root || document;
        initRichEditor(root);
        initRecipientFields(root);
        initFileUpload(root);
        var form = root.querySelector ? root.querySelector('#compose-form') : document.getElementById('compose-form');
        if (form) {
            var returnField = form.querySelector('#return_folder');
            if (returnField && !returnField.value) {
                returnField.value = currentMailFolderEnc();
            }
            bindComposeFormAjax(form);
        }
    }

    function initComposePanel() {
        bindComposeLinks(document);
        initComposeForm(document);
        var closeBtn = document.getElementById('compose-panel-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { closeComposePanel(true); });
        }
    }

    function bindMailRow(row) {
        if (!row || row.dataset.bound) return;
        row.dataset.bound = '1';
        var prefetchTimer = null;
        row.addEventListener('mouseenter', function () {
            if (!useReadingPane()) return;
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            if (!uid) return;
            prefetchTimer = window.setTimeout(function () { prefetchPane(uid); }, 60);
        });
        row.addEventListener('mouseleave', function () {
            if (prefetchTimer) window.clearTimeout(prefetchTimer);
        });
        row.addEventListener('click', function (e) {
            if (e.target.closest('.mail-row-check') || e.target.closest('.col-check') || e.target.closest('.mail-kebab')) return;
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            if (e.ctrlKey || e.metaKey) {
                var cb = row.querySelector('.mail-check');
                if (cb) {
                    cb.checked = !cb.checked;
                    lastCheckedRowIndex = outlookRows().indexOf(row);
                    updateCommandBar();
                }
                if (useReadingPane() && uid) openMessageInPane(uid, true);
                return;
            }
            if (useReadingPane() && uid) {
                openMessageInPane(uid, true);
                return;
            }
            showLoading();
            window.location = row.getAttribute('data-href');
        });
        // Keyboard activation for role="link" cards (mobile list a11y).
        if (row.getAttribute('role') === 'link' || row.getAttribute('role') === 'option') {
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.target.closest('.mail-kebab')) return;
                    e.preventDefault();
                    showLoading();
                    window.location = row.getAttribute('data-href');
                }
            });
        }
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

    function updateMailCount(total, unread) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        var u = typeof unread === 'number' ? unread : 0;
        label.setAttribute('data-total', String(typeof total === 'number' ? total : 0));
        label.setAttribute('data-unread', String(u));
        if (u > 0) {
            label.hidden = false;
            label.removeAttribute('aria-hidden');
            label.classList.add('page-header-count--unread');
            label.classList.remove('page-header-count--hidden');
            label.textContent = String(u);
            label.title = u + ' unread';
        } else {
            label.hidden = true;
            label.setAttribute('aria-hidden', 'true');
            label.classList.remove('page-header-count--unread');
            label.classList.add('page-header-count--hidden');
            label.textContent = '';
            label.title = (typeof total === 'number' ? total : 0) + ' message' + (total === 1 ? '' : 's');
        }
    }

    function unreadCountFromMessages(messages) {
        var unread = 0;
        (messages || []).forEach(function (m) {
            if (!m.seen) unread++;
        });
        return unread;
    }

    function adjustMailCount(delta) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        var total = parseInt(label.getAttribute('data-total') || '0', 10) || 0;
        var unread = parseInt(label.getAttribute('data-unread') || '0', 10) || 0;
        updateMailCount(total, Math.max(0, unread + delta));
    }

    var mailPoll = null;

    function scheduleMailPoll(force) {
        if (!mailPoll) return;
        if (mailSyncPaused && !force) return;
        var now = Date.now();
        if (!force && (now - lastMailPollAt) < mailPollMinGapMs) return;
        mailPoll(force);
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
            if (flagged && !mstar) {
                var ms = document.createElement('span');
                ms.className = 'flag-dot';
                ms.title = 'Important';
                ms.innerHTML = '\u2605';
                from.insertBefore(document.createTextNode(' '), from.firstChild);
                from.insertBefore(ms, from.firstChild);
            } else if (!flagged && mstar) {
                mstar.remove();
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
        if (!seen) {
            invalidatePaneCache(uid);
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
        var hasRows = (body && body.children.length > 0) || (mobile && mobile.children.length > 0);
        empty.hidden = hasRows;
        if (scroller) scroller.hidden = !hasRows;
        if (mobile) mobile.hidden = !hasRows;
    }

    function removeRowByUid(uid) {
        markUidsPendingRemoval([uid]);
        var removed = false;
        rowsForUid(uid).forEach(function (el) {
            removed = true;
            if (el.parentNode) el.parentNode.removeChild(el);
        });
        if (removed) {
            syncListEmptyState();
            adjustMailCount(-1);
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

    function isConfirmOpen() {
        var modal = document.getElementById('confirm-modal');
        return modal && !modal.hidden;
    }

    /**
     * @param {{ title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean }} opts
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

        return new Promise(function (resolve) {
            if (titleEl) titleEl.textContent = opts.title || 'Confirm';
            if (msgEl) msgEl.textContent = opts.message || '';
            if (okBtn) {
                okBtn.textContent = opts.confirmLabel || 'OK';
                okBtn.className = opts.danger ? 'btn btn-danger' : 'btn btn-primary';
            }
            if (cancelBtn) cancelBtn.textContent = opts.cancelLabel || 'Cancel';
            if (iconEl) iconEl.hidden = !opts.danger;

            function finish(result) {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                body.classList.remove('modal-open');
                if (confirmKeyHandler) {
                    document.removeEventListener('keydown', confirmKeyHandler, true);
                    confirmKeyHandler = null;
                }
                resolve(!!result);
            }

            confirmKeyHandler = function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    finish(false);
                }
            };

            if (okBtn) okBtn.onclick = function () { finish(true); };
            if (cancelBtn) cancelBtn.onclick = function () { finish(false); };
            if (backdrop) backdrop.onclick = function () { finish(false); };
            document.addEventListener('keydown', confirmKeyHandler, true);

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            body.classList.add('modal-open');
            if (opts.danger && cancelBtn) cancelBtn.focus();
            else if (okBtn) okBtn.focus();
        });
    }

    window.showConfirm = showConfirm;

    function initToasts() {
        document.querySelectorAll('.toast-payload').forEach(function (el) {
            showToast(el.getAttribute('data-toast-type') || 'success', el.getAttribute('data-toast-message') || '');
            el.remove();
        });
    }

    function initPerPageSelect() {
        var select = document.getElementById('per-page-select');
        if (!select) return;
        select.addEventListener('change', function () {
            if (select.value) window.location = select.value;
        });
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
        row.className = 'mail-row mail-row--outlook' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (isNew ? ' mail-row-new' : '');
        row.setAttribute('role', 'option');
        row.setAttribute('tabindex', '-1');
        row.setAttribute('aria-selected', 'false');
        row.setAttribute('data-uid', String(msg.uid));
        row.setAttribute('data-seen', msg.seen ? '1' : '0');
        row.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        row.setAttribute('data-href', msg.url);
        if (msg.reply_url) row.setAttribute('data-reply-url', msg.reply_url);
        if (msg.reply_all_url) row.setAttribute('data-reply-all-url', msg.reply_all_url);
        if (msg.forward_url) row.setAttribute('data-forward-url', msg.forward_url);

        var fromText = msg.from || 'Unknown';
        var initial = fromText.trim().charAt(0).toUpperCase() || '?';
        var color = avatarColor(fromText);
        var attachHtml = msg.has_attachment
            ? '<span class="mail-row-attach" title="Has attachment" aria-label="Has attachment">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
                '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>'
            : '';
        var flagHtml = msg.flagged ? '<span class="flag-dot mail-row-flag" title="Important">\u2605</span>' : '';

        row.innerHTML =
            '<div class="mail-row-check" onclick="event.stopPropagation()">' +
                '<input type="checkbox" class="mail-check" value="' + msg.uid + '" aria-label="Select message">' +
            '</div>' +
            '<div class="mail-row-avatar" style="background-color:' + color + '" aria-hidden="true">' + escapeHtml(initial) + '</div>' +
            '<div class="mail-row-body">' +
                '<div class="mail-row-line1">' +
                    '<span class="mail-row-from">' + escapeHtml(fromText) + '</span>' +
                    '<span class="mail-row-meta">' + attachHtml + flagHtml +
                        '<span class="mail-row-date">' + escapeHtml(msg.date) + '</span>' +
                    '</span>' +
                '</div>' +
                '<div class="mail-row-subject">' + escapeHtml(msg.subject) + '</div>' +
            '</div>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button>';
        bindMailRow(row);
        initMailCommandBar();
        if (isNew) window.setTimeout(function () { row.classList.remove('mail-row-new'); }, 3000);
        return row;
    }

    function buildMobileCard(msg, isNew) {
        var a = document.createElement('div');
        a.className = 'mail-card' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (isNew ? ' mail-row-new' : '');
        a.setAttribute('role', 'option');
        a.setAttribute('tabindex', '0');
        a.setAttribute('aria-selected', 'false');
        a.setAttribute('data-uid', String(msg.uid));
        a.setAttribute('data-seen', msg.seen ? '1' : '0');
        a.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        a.setAttribute('data-href', msg.url);
        if (msg.reply_url) a.setAttribute('data-reply-url', msg.reply_url);
        if (msg.reply_all_url) a.setAttribute('data-reply-all-url', msg.reply_all_url);
        if (msg.forward_url) a.setAttribute('data-forward-url', msg.forward_url);
        a.innerHTML =
            '<div class="mail-card-top"><span class="mail-card-from">' + (msg.flagged ? '<span class="flag-dot" title="Important">\u2605</span> ' : '') + escapeHtml(msg.from) +
            '</span><span class="mail-card-meta">' + (msg.has_attachment ? '<span class="mail-row-attach" title="Has attachment" aria-label="Has attachment"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>' : '') +
            '<span class="mail-card-date">' + escapeHtml(msg.date) + '</span></span>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button></div>' +
            '<div class="mail-card-subject">' + escapeHtml(msg.subject) + '</div>';
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

    function applyUnreadCounts(counts) {
        if (!counts) return;
        lastUnreadCounts = Object.assign({}, lastUnreadCounts, counts);
        Object.keys(counts).forEach(function (path) {
            var link = document.querySelector('.sidebar-link[data-folder-path="' + (window.CSS && CSS.escape ? CSS.escape(path) : path) + '"]');
            if (!link) return;
            var badge = link.querySelector('.folder-badge');
            var n = counts[path];
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
                if (p && counts[p]) total += counts[p];
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
    }

    function refreshUnreadBadges() {
        fetch(apiUrl('folders/unread'), {
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

    function initMailSync() {
        stopMailSync();
        var card = document.querySelector('[data-mail-sync="1"]');
        if (!card) return;

        var pollUrl = card.getAttribute('data-poll-url');
        var page = parseInt(card.getAttribute('data-page') || '1', 10);
        var interval = parseInt(card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30', 10) * 1000;
        var polling = false;
        var syncErrorShown = false;

        function poll(force) {
            if (polling) return;
            if (mailSyncPaused && !force) return;

            polling = true;
            lastMailPollAt = Date.now();
            card.classList.add('is-syncing');
            var url = pollUrl + (pollUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + page;
            if (!force) {
                url += '&light=1';
            } else {
                url += '&force=1';
            }

            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) {
                    if (!res.ok) throw new Error('sync failed');
                    return res.json();
                })
                .then(function (data) {
                    if (!data || !Array.isArray(data.messages)) return;
                    var plainPath = card.getAttribute('data-folder-plain') || '';
                    var folderUnread = (data.unread_counts && plainPath)
                        ? (data.unread_counts[plainPath] || 0)
                        : 0;
                    if (page === 1 && plainPath && data.messages.length) {
                        var pageUnread = unreadCountFromMessages(data.messages);
                        if (pageUnread > folderUnread) {
                            folderUnread = pageUnread;
                            data.unread_counts = data.unread_counts || {};
                            data.unread_counts[plainPath] = pageUnread;
                        }
                    }
                    updateMailCount(data.total, folderUnread);
                    if (data.unread_counts) {
                        applyUnreadCounts(data.unread_counts);
                    }

                    if (page !== 1) {
                        return;
                    }

                    var known = collectKnownUids(card);
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
                            setRowSeen(m.uid, !!m.seen);
                            setRowFlagged(m.uid, !!m.flagged);
                        } else {
                            newMessages.push(m);
                        }
                    });

                    if (newMessages.length > 0) {
                        ensureListVisible(card);
                        var tbody = document.getElementById('mail-list-body');
                        var mobile = document.getElementById('mail-list-mobile');
                        newMessages.forEach(function (msg) {
                            if (tbody) tbody.insertBefore(buildDesktopRow(msg, true), tbody.firstChild);
                            if (mobile) mobile.insertBefore(buildMobileCard(msg, true), mobile.firstChild);
                        });
                        playNewMailSound();
                        notifyNewMail(newMessages.length);
                    }

                    // Remove rows that no longer exist on the server (moved/deleted
                    // elsewhere), so the list self-heals.
                    known.forEach(function (uid) {
                        if (!freshUids[uid]) {
                            rowsForUid(uid).forEach(function (el) {
                                if (el.parentNode) el.parentNode.removeChild(el);
                            });
                        }
                    });

                    syncErrorShown = false;
                })
                .catch(function () {
                    if (!syncErrorShown) {
                        syncErrorShown = true;
                        showToast('error', 'Live mail updates are paused — connection to the mail server failed.');
                    }
                })
                .finally(function () {
                    card.classList.remove('is-syncing');
                    polling = false;
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

    function folderMessageTotal() {
        var card = document.querySelector('.mail-list-card[data-total-messages]');
        if (!card) return 0;
        return parseInt(card.getAttribute('data-total-messages') || '0', 10) || 0;
    }

    function pageMessageCount() {
        return document.querySelectorAll('.mail-check').length;
    }

    function currentSearchQuery() {
        var input = document.getElementById('mail-search');
        return input && input.value ? input.value.trim() : '';
    }

    function selectionScopeLabel() {
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
        var checkedOnPage = document.querySelectorAll('.mail-check:checked').length;
        var allOnPageSelected = pageCount > 0 && checkedOnPage === pageCount;
        var scope = selectionScopeLabel();

        if (selectAllInFolder && total > 0) {
            banner.hidden = false;
            banner.innerHTML = 'All ' + total + ' messages ' + scope + ' are selected. '
                + '<button type="button" data-select-all-action="clear">Clear selection</button>';
            return;
        }

        if (allOnPageSelected && total > pageCount) {
            banner.hidden = false;
            banner.innerHTML = checkedOnPage + ' messages on this page are selected. '
                + '<button type="button" data-select-all-action="all">Select all ' + total + ' messages ' + scope + '</button>';
            return;
        }

        banner.hidden = true;
        banner.innerHTML = '';
    }

    function initMailCommandBar() {
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

        document.querySelectorAll('.mail-check:not([data-cmd-bound])').forEach(function (cb) {
            cb.setAttribute('data-cmd-bound', '1');
            cb.addEventListener('change', onMailCheckChange);
            cb.addEventListener('click', onMailCheckClick);
        });

        var selectAll = document.getElementById('select-all');
        if (selectAll && !selectAll.dataset.cmdBound) {
            selectAll.dataset.cmdBound = '1';
            selectAll.addEventListener('change', function () {
                if (!selectAll.checked) {
                    selectAllInFolder = false;
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

    function onMailCheckClick(e) {
        if (!e.shiftKey || lastCheckedRowIndex < 0) return;
        var row = e.target.closest('.mail-row--outlook');
        if (!row) return;
        var rows = outlookRows();
        var idx = rows.indexOf(row);
        if (idx < 0) return;
        var start = Math.min(lastCheckedRowIndex, idx);
        var end = Math.max(lastCheckedRowIndex, idx);
        var checked = e.target.checked;
        for (var i = start; i <= end; i++) {
            var cb = rows[i].querySelector('.mail-check');
            if (cb) cb.checked = checked;
        }
        updateCommandBar();
    }

    function onMailCheckChange(e) {
        if (selectAllInFolder && !e.target.checked) {
            selectAllInFolder = false;
        }
        var row = e.target.closest('.mail-row--outlook');
        if (row) {
            lastCheckedRowIndex = outlookRows().indexOf(row);
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

        needsSelection.forEach(function (cmd) {
            var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (btn) btn.disabled = !hasSelection;
        });

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

        var countEl = document.getElementById('cmd-selection-count');
        if (countEl) {
            var count = effectiveSelectionCount();
            countEl.textContent = hasSelection ? count + ' selected' : '';
            countEl.hidden = !hasSelection;
        }

        var deleteBtn = toolbar.querySelector('[data-cmd="delete"]');
        if (deleteBtn) {
            deleteBtn.title = isTrashFolder() ? 'Delete permanently' : 'Delete';
        }

        var selectAll = document.getElementById('select-all');
        var checks = document.querySelectorAll('.mail-check');
        if (selectAll && checks.length) {
            if (selectAllInFolder) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                var checkedCount = document.querySelectorAll('.mail-check:checked').length;
                selectAll.checked = checkedCount > 0 && checkedCount === checks.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checks.length;
            }
        }

        outlookRows().forEach(function (row) {
            var cb = row.querySelector('.mail-check');
            row.classList.toggle('is-checked', !!(cb && cb.checked) || selectAllInFolder);
        });

        updateSelectAllBanner();
    }

    function runBulkCommand(action, triggerBtn) {
        if (action === 'refresh') {
            if (triggerBtn) setButtonLoading(triggerBtn, true, loadingLabelForAction('refresh'));
            if (mailPoll) {
                scheduleMailPoll(true);
                if (triggerBtn) watchSyncEnd(triggerBtn);
            } else {
                window.location.reload();
            }
            return;
        }

        var uids = selectedMailUids();
        if (!selectAllInFolder && !uids.length) return;

        var listCard = document.querySelector('.mail-list-card[data-folder-path]');
        if (!listCard) return;
        var folderEnc = listCard.getAttribute('data-folder-path');
        var selectionCount = effectiveSelectionCount();

        if (action === 'delete') {
            showConfirm(deleteConfirmOptions(selectionCount)).then(function (ok) {
                if (ok) runBulkCommandExecute(action, uids, folderEnc, triggerBtn);
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

        runBulkCommandExecute(action, uids, folderEnc, triggerBtn);
    }

    function folderUnreadCount() {
        var card = getListCard();
        var plain = card ? card.getAttribute('data-folder-plain') : '';
        if (plain && lastUnreadCounts[plain] != null) {
            return lastUnreadCounts[plain];
        }
        var label = document.getElementById('mail-count-label');
        if (label) {
            var title = label.getAttribute('title') || '';
            if (title.indexOf('unread') >= 0) {
                return parseInt(label.textContent, 10) || 0;
            }
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

    function fireAndForgetAction(actionPath, payload) {
        fetch(apiUrl(actionPath), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
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
        }).then(function (data) {
            if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            }
        }).catch(function (err) {
            showToast('error', err.message || 'Action failed.');
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

        var delta = allInFolder ? folderUnreadCount() : countUnreadAmong(uids);
        if (delta <= 0) return;

        var counts = Object.assign({}, lastUnreadCounts);
        counts[plain] = Math.max(0, (counts[plain] || 0) - delta);
        if (action === 'move' && targetFolder) {
            counts[targetFolder] = (counts[targetFolder] || 0) + delta;
        }
        applyUnreadCounts(counts);
    }

    function runBulkCommandExecute(action, uids, folderEnc, triggerBtn) {
        var actionPath = '';
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        payload.set('folder', folderEnc);
        var allInFolder = selectAllInFolder;
        var selectionCount = effectiveSelectionCount();

        if (allInFolder) {
            payload.set('all_in_folder', '1');
            var q = currentSearchQuery();
            if (q) payload.set('q', q);
        } else {
            uids.forEach(function (uid) { payload.append('uids[]', uid); });
        }

        var successMsg = '';
        var seenDelta = 0;

        if (action === 'delete') {
            actionPath = 'message/bulk-trash';
            if (!allInFolder) {
                payload.set('unread_delta', String(countUnreadAmong(uids)));
                uids.forEach(function (uid) { removeRowByUid(uid); });
            } else {
                clearMailListRows();
            }
            successMsg = deleteSuccessMessage(selectionCount);
        } else if (action === 'move') {
            var target = document.getElementById('cmd-move-target');
            if (!target || !target.value) {
                showToast('error', 'Choose a folder to move to.');
                return;
            }
            actionPath = 'message/bulk-move';
            payload.set('target_folder', target.value);
            if (!allInFolder) {
                payload.set('unread_delta', String(countUnreadAmong(uids)));
                uids.forEach(function (uid) { removeRowByUid(uid); });
            } else {
                clearMailListRows();
            }
            successMsg = 'Selected messages moved.';
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

        var instantActions = ['delete', 'move', 'mark-read', 'mark-unread', 'flag', 'unflag'];
        var isInstantListAction = instantActions.indexOf(action) >= 0;
        var moveTarget = action === 'move' ? (document.getElementById('cmd-move-target') || {}).value || '' : '';

        if (isInstantListAction) {
            if (allInFolder && (action === 'delete' || action === 'move')) {
                payload.set('unread_delta', String(folderUnreadCount()));
            }
            showToast('success', successMsg);
            if (action === 'delete' || action === 'move') {
                applyOptimisticUnreadDelta(action, allInFolder, uids, moveTarget);
            } else if (action === 'mark-read' || action === 'mark-unread') {
                bumpFolderUnread(seenDelta);
            }
            finishBulkSelectionUi(action, allInFolder, uids);
            fireAndForgetAction(actionPath, payload);
        }
    }

    function selectedMailUids() {
        var checked = Array.prototype.slice.call(document.querySelectorAll('.mail-check:checked'))
            .map(function (cb) { return cb.value; });
        if (checked.length) return checked;

        var active = document.querySelector(
            '.mail-row.is-selected, .mail-row.is-focused, .mail-card.is-selected, .mail-card.is-focused'
        );
        if (active) {
            var uid = active.getAttribute('data-uid');
            if (uid) return [uid];
        }
        return [];
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

    function bindMessageSyncCard(card) {
        if (!card) return;
        if (card._syncTimer) {
            clearInterval(card._syncTimer);
            card._syncTimer = null;
        }

        var syncUrl = card.getAttribute('data-sync-url');
        var folderUrl = card.getAttribute('data-folder-url');
        var interval = parseInt(card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30', 10) * 1000;
        if (!syncUrl) return;

        function check() {
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
                }).catch(function () {});
        }

        card._syncTimer = window.setInterval(check, interval);
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

                var dispatchAction = action;
                if (action === 'flag-toggle') {
                    dispatchAction = card.getAttribute('data-flagged') === '1' ? 'unflag' : 'flag';
                }

                var extra = {};
                if (action === 'move') {
                    var select = card.querySelector('[name="target_folder"]');
                    if (!select || !select.value) {
                        showToast('error', 'Choose a folder to move to.');
                        return;
                    }
                    extra.target_folder = select.value;
                }

                dispatchMessageAction(dispatchAction, folderEnc, uid, extra, btn).then(function (completed) {
                    if (completed === false) return;
                });
            });
        });

        bindComposeLinks(card);
    }

    function initReadViewActions() {
        document.querySelectorAll('.mail-read-card[data-uid]').forEach(function (card) {
            if (!card.closest('#reading-pane-body')) {
                bindReadViewCard(card);
            }
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

        if (toolbar) {
            toolbar.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-cmd]');
                if (!btn) return;
                e.preventDefault();
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'createLink') {
                    var url = prompt('Link URL');
                    if (url) document.execCommand(cmd, false, url);
                } else {
                    document.execCommand(cmd, false, null);
                }
                editor.focus();
            });
        }

        form.addEventListener('submit', function () {
            if (htmlField) htmlField.value = editor.innerHTML;
            if (bodyField) bodyField.value = editor.innerText;
        });

        var draftTimer;
        form.addEventListener('input', function () {
            window.clearTimeout(draftTimer);
            draftTimer = window.setTimeout(function () {
                if (htmlField) htmlField.value = editor.innerHTML;
            }, 60000);
        });
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
        var initial = (data.display || data.email).charAt(0).toUpperCase();
        var color = avatarColor(data.email);
        chip.innerHTML =
            '<span class="recipient-chip-avatar" style="background:' + color + '">' + escapeHtml(initial) + '</span>' +
            '<span class="recipient-chip-label" title="' + escapeHtml(data.email) + '">' + escapeHtml(data.display || data.email) + '</span>' +
            '<button type="button" class="recipient-chip-remove" aria-label="Remove ' + escapeHtml(data.email) + '">&times;</button>';
        container.appendChild(chip);
        return true;
    }

    function initRecipientRow(row) {
        var field = row.getAttribute('data-field');
        var hidden = document.getElementById(field);
        var chipsEl = row.querySelector('.recipient-chips');
        var input = row.querySelector('.recipient-input');
        if (!hidden || !chipsEl || !input) return;

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
            if (e.key === 'Enter' || e.key === 'Tab' || e.key === ',') {
                if (input.value.trim()) {
                    e.preventDefault();
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

        input.addEventListener('blur', commitInput);

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

    function initRecipientFields(root) {
        root = root || document;
        var form = root.querySelector ? root.querySelector('#compose-form') : document.getElementById('compose-form');
        if (!form || form.dataset.recipientsBound) return;
        form.dataset.recipientsBound = '1';

        var rows = {};
        form.querySelectorAll('.compose-recipient-row[data-field]').forEach(function (row) {
            rows[row.getAttribute('data-field')] = initRecipientRow(row);
        });

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
            if (form.dataset.ajaxBound && form.closest('#compose-panel')) {
                Object.keys(rows).forEach(function (key) {
                    var row = rows[key];
                    if (!row) return;
                    row.commitInput();
                    row.syncHidden();
                });
                var toHidden = form.querySelector('#to');
                if (toHidden && !toHidden.value.trim()) {
                    e.preventDefault();
                    showToast('error', 'At least one To address is required.');
                    if (rows.to && rows.to.input) rows.to.input.focus();
                }
                return;
            }

            Object.keys(rows).forEach(function (key) {
                var row = rows[key];
                if (!row) return;
                row.commitInput();
                row.syncHidden();
            });

            var toHidden = form.querySelector('#to');
            if (toHidden && !toHidden.value.trim()) {
                e.preventDefault();
                showToast('error', 'At least one To address is required.');
                if (rows.to && rows.to.input) rows.to.input.focus();
            }
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
    }

    function initFileUpload(root) {
        root = root || document;
        var wrap = root.querySelector ? root.querySelector('#file-upload') : document.getElementById('file-upload');
        var input = wrap ? wrap.querySelector('#attachments') : null;
        var list = wrap ? wrap.querySelector('#file-upload-list') : null;
        if (!wrap || !input || wrap.dataset.uploadBound) return;
        wrap.dataset.uploadBound = '1';

        function updateList() {
            if (!list) return;
            list.innerHTML = '';
            if (!input.files || input.files.length === 0) {
                list.hidden = true;
                return;
            }
            list.hidden = false;
            Array.prototype.forEach.call(input.files, function (file) {
                var li = document.createElement('li');
                li.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                list.appendChild(li);
            });
        }

        input.addEventListener('change', updateList);

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
                input.files = e.dataTransfer.files;
                updateList();
            }
        });
    }

    function dispatchMessageAction(kind, sourceFolderEnc, uid, extra, triggerBtn) {
        extra = extra || {};
        var confirmCfg = null;
        if (kind === 'trash') {
            confirmCfg = deleteConfirmOptions(1);
        } else if (kind === 'spam') {
            confirmCfg = {
                title: 'Move to Spam?',
                message: 'This message will be moved to the Spam folder.',
                confirmLabel: 'Move to Spam',
                danger: false
            };
        }
        if (confirmCfg) {
            return showConfirm(confirmCfg).then(function (ok) {
                if (!ok) return false;
                return dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn).then(function () { return true; });
            });
        }
        return dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn).then(function () { return true; });
    }

    function dispatchMessageActionExecute(kind, sourceFolderEnc, uid, extra, triggerBtn) {
        extra = extra || {};
        var fields = { folder: sourceFolderEnc, uid: uid };
        Object.keys(extra).forEach(function (k) { fields[k] = extra[k]; });

        var readCard = document.querySelector('.mail-read-card[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]');
        var folderUrl = readCard ? readCard.getAttribute('data-folder-url') : null;

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
            if (wasUnread) bumpFolderUnread(-1);
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
            if (!wasUnread) bumpFolderUnread(1);
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
        } else if (kind === 'spam' || kind === 'trash' || kind === 'move') {
            fields.unread_delta = wasUnread ? 1 : 0;
            removeRowByUid(uid);

            if (wasUnread) {
                bumpFolderUnread(-1);
                if (kind === 'move' && extra.target_folder) {
                    var moveCounts = Object.assign({}, lastUnreadCounts);
                    moveCounts[extra.target_folder] = (moveCounts[extra.target_folder] || 0) + 1;
                    applyUnreadCounts(moveCounts);
                }
            }

            if (kind === 'trash') {
                showToast('success', deleteSuccessMessage(1));
            } else if (kind === 'spam') {
                showToast('success', 'Message moved to Spam.');
            } else if (kind === 'move') {
                showToast('success', 'Message moved.');
            }

            var paneHost = document.getElementById('reading-pane-body');
            var inPane = paneHost && paneHost.querySelector('.mail-read-card[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]');
            if (inPane) {
                clearReadingPane();
                var listCardUrl = getListCard();
                var folderOnly = listCardUrl ? listCardUrl.getAttribute('data-folder-url') : null;
                if (folderOnly && window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', folderOnly);
                }
            }

            var movePayload = new URLSearchParams();
            movePayload.set('_csrf', csrf);
            Object.keys(fields).forEach(function (k) { movePayload.set(k, fields[k]); });
            fireAndForgetAction('message/' + kind, movePayload);
            return Promise.resolve(true);
        }

        return Promise.resolve(false);
    }

    var openContextMenuFor = null;

    function initContextMenu() {
        var listCard = document.querySelector('.mail-list-card[data-folder-path]');
        if (!listCard) return;

        var sourceFolderEnc = listCard.getAttribute('data-folder-path');

        var menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.hidden = true;
        document.body.appendChild(menu);

        function hide() {
            menu.hidden = true;
            menu.innerHTML = '';
        }

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
                hide();
                handler();
            });
            menu.appendChild(item);
            return item;
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

        function folderIconHtml(iconType) {
            return '<span class="ctx-folder-icon folder-icon folder-icon-' + iconType + '" aria-hidden="true"></span>';
        }

        function folderSortKey(folder) {
            var lower = folder.path.toLowerCase();
            if (folder.path === 'INBOX') return '0';
            if (lower.indexOf('sent') >= 0) return '1';
            if (lower.indexOf('draft') >= 0) return '2';
            return '3' + folder.name.toLowerCase();
        }

        function addSubmenu(label, iconPaths, folders, onPick) {
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
                sub.classList.remove('flip-left');
                var rect = item.getBoundingClientRect();
                var subW = sub.offsetWidth || 212;
                if (rect.right + subW + 8 > window.innerWidth) {
                    sub.classList.add('flip-left');
                }

                sub.style.top = '0';
                var margin = 10;
                var headerEl = sub.querySelector('.context-submenu-header');
                var headerH = headerEl ? headerEl.offsetHeight : 0;
                var maxSubH = Math.min(320, window.innerHeight - margin * 2);
                sub.style.maxHeight = maxSubH + 'px';
                scroll.style.maxHeight = Math.max(120, maxSubH - headerH) + 'px';

                var subRect = sub.getBoundingClientRect();
                var overflowBottom = subRect.bottom - (window.innerHeight - margin);
                var overflowTop = margin - subRect.top;
                if (overflowBottom > 0) {
                    sub.style.top = (-overflowBottom) + 'px';
                } else if (overflowTop > 0) {
                    sub.style.top = overflowTop + 'px';
                }

                updateScrollState();
            }
            item.addEventListener('mouseenter', place);
            item.addEventListener('focus', place);
            menu.appendChild(item);
            return item;
        }

        function addSep() {
            var sep = document.createElement('div');
            sep.className = 'context-menu-sep';
            menu.appendChild(sep);
        }

        function activeFolderPath() {
            var active = document.querySelector('.sidebar-link.active[data-folder-path]');
            return active ? active.getAttribute('data-folder-path') : null;
        }

        function collectMoveFolders() {
            var out = [];
            var current = activeFolderPath();
            document.querySelectorAll('.sidebar-link[data-folder-path]').forEach(function (link) {
                var path = link.getAttribute('data-folder-path');
                if (!path || path === current) return;
                var lower = path.toLowerCase();
                if (lower.indexOf('spam') >= 0 || lower.indexOf('junk') >= 0 || lower.indexOf('trash') >= 0) return;
                var textEl = link.querySelector('.sidebar-link-text');
                out.push({
                    path: path,
                    name: textEl ? textEl.textContent.trim() : path,
                    icon: iconTypeFromSidebarLink(link)
                });
            });
            out.sort(function (a, b) {
                return folderSortKey(a).localeCompare(folderSortKey(b));
            });
            return out;
        }

        function openFor(row, x, y) {
            var uid = row.getAttribute('data-uid');
            if (!uid) return;

            var seen = row.getAttribute('data-seen') === '1';
            var flagged = row.getAttribute('data-flagged') === '1';
            var href = row.getAttribute('data-href') || row.getAttribute('href');
            var replyUrl = row.getAttribute('data-reply-url');
            var replyAllUrl = row.getAttribute('data-reply-all-url');
            var forwardUrl = row.getAttribute('data-forward-url');

            function go(target) { if (target) { showLoading(); window.location = target; } }

            function goCompose(target, label) {
                if (!target) return;
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

            addSep();
            addItem(isTrashFolder() ? 'Delete permanently' : 'Delete', ICONS.trash, function () { dispatchMessageAction('trash', sourceFolderEnc, uid); }, true);

            menu.hidden = false;
            var mw = menu.offsetWidth;
            var mh = menu.offsetHeight;
            var left = (x + mw > window.innerWidth) ? x - mw : x;
            var top = (y + mh > window.innerHeight) ? y - mh : y;
            menu.style.left = Math.max(4, left) + 'px';
            menu.style.top = Math.max(4, top) + 'px';
        }

        openContextMenuFor = openFor;

        document.addEventListener('contextmenu', function (e) {
            var row = e.target.closest('.mail-row, .mail-card');
            if (!row) return;
            e.preventDefault();
            openFor(row, e.clientX, e.clientY);
        });

        document.addEventListener('click', function (e) {
            var kebab = e.target.closest('.mail-kebab');
            if (kebab) {
                e.preventDefault();
                e.stopPropagation();
                var row = kebab.closest('.mail-row, .mail-card');
                if (row) {
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
            if (form.id === 'compose-form') return;

            var submitter = e.submitter;
            var btn = (submitter && submitter.tagName === 'BUTTON')
                ? submitter
                : form.querySelector('button[type="submit"]:not([disabled])');
            if (!btn || btn.classList.contains('is-loading')) return;

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
        var folderEnc = card ? card.getAttribute('data-folder-path') : '';
        var url = apiUrl('mail/bootstrap');
        if (folderEnc) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'folder=' + encodeURIComponent(folderEnc);
        }
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .catch(function () {});
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

    document.addEventListener('DOMContentLoaded', function () {
        initToasts();
        initMailSync();
        initMessageSync();
        initMailCommandBar();
        initRichEditor();
        initRecipientFields();
        initRulesDragDrop();
        initThemeFromSettings();
        initSidebarGroups();
        initFileUpload();
        initPerPageSelect();
        initContextMenu();
        initReadingPane();
        initAjaxFolderNav();
        initStatusPage();
        initMailBootstrap();
        initComposePanel();
        initReadViewActions();
        initGlobalFormLoading();
        initMobileReadSwipe();
        loadAttachmentHints(document);
        requestNotificationPermission();
    });
})();
