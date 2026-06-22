(function () {
    'use strict';

    var progressEl = document.getElementById('nav-progress');
    var progressBar = progressEl ? progressEl.querySelector('.nav-progress-bar') : null;
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

    // Slim top progress bar (à la Gmail/YouTube) instead of a full-screen overlay.
    var progressTimer = null;
    var progressValue = 0;
    var navActive = false;
    var pendingTasks = 0;

    function setProgressWidth(pct) {
        if (progressBar) progressBar.style.width = pct + '%';
    }

    function startProgress() {
        if (!progressEl || !progressBar) return;
        navActive = true;
        progressEl.classList.remove('is-done');
        progressEl.classList.add('is-active');
        progressEl.setAttribute('aria-hidden', 'false');
        progressValue = 10;
        setProgressWidth(progressValue);
        window.clearInterval(progressTimer);
        progressTimer = window.setInterval(function () {
            // Ease toward 90% so it always feels like it's making progress.
            progressValue += Math.max(0.4, (90 - progressValue) * 0.06);
            if (progressValue >= 90) progressValue = 90;
            setProgressWidth(progressValue);
        }, 180);
    }

    function finishProgress() {
        if (!progressEl || !progressBar) return;
        window.clearInterval(progressTimer);
        if (!navActive && !progressEl.classList.contains('is-active')) {
            return;
        }
        navActive = false;
        progressValue = 100;
        setProgressWidth(100);
        progressEl.classList.add('is-done');
        window.setTimeout(function () {
            progressEl.classList.remove('is-active');
            progressEl.setAttribute('aria-hidden', 'true');
            window.setTimeout(function () {
                progressEl.classList.remove('is-done');
                setProgressWidth(0);
            }, 220);
        }, 220);
    }

    // For async (AJAX) work that shouldn't block the page: ref-count tasks so
    // the bar stays until the last one finishes.
    function beginTask() {
        pendingTasks++;
        startProgress();
    }

    function endTask() {
        pendingTasks = Math.max(0, pendingTasks - 1);
        if (pendingTasks === 0) finishProgress();
    }

    // Back-compat aliases used throughout for full-page navigations.
    function showLoading() { startProgress(); }
    function hideLoading() { finishProgress(); }

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

    function isTyping() {
        var el = document.activeElement;
        if (!el) return false;
        var tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a');
        if (link && link.href && link.origin === window.location.origin && !link.target && !link.hasAttribute('download')) {
            if (link.getAttribute('href').charAt(0) === '#') return;
            showLoading();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented) return;
        if (form && form.method && form.method.toLowerCase() !== 'get') {
            showLoading();
        }
    });

    window.addEventListener('pageshow', hideLoading);
    document.addEventListener('DOMContentLoaded', hideLoading);

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
    var composePanelSeq = 0;
    var composePanelRestoreUid = null;

    function useReadingPane() {
        return PANE_MEDIA.matches && !!document.getElementById('reading-pane');
    }

    function getListCard() {
        return document.querySelector('.mail-list-card[data-folder-b64]');
    }

    function parseMessagePath(pathname) {
        var m = pathname.match(/\/folder\/([^/]+)\/message\/(\d+)\/?$/);
        if (!m) return null;
        return { folderB64: m[1], uid: parseInt(m[2], 10) };
    }

    function paneFetchUrl(uid) {
        var card = getListCard();
        if (!card) return null;
        var b64 = card.getAttribute('data-folder-b64');
        return apiUrl('folder/' + b64 + '/message/' + uid + '/pane');
    }

    function setSelectedRow(uid) {
        document.querySelectorAll('.mail-row.is-selected, .mail-card.is-selected').forEach(function (el) {
            el.classList.remove('is-selected');
        });
        rowsForUid(uid).forEach(function (el) {
            el.classList.add('is-selected');
            el.classList.add('is-focused');
        });
    }

    function setPaneView(state) {
        var loading = document.getElementById('reading-pane-loading');
        var empty = document.getElementById('reading-pane-empty');
        var bodyEl = document.getElementById('reading-pane-body');
        var isLoading = state === 'loading';
        var isEmpty = state === 'empty';
        var isContent = state === 'content';

        if (loading) {
            loading.hidden = !isLoading;
            loading.classList.toggle('is-active', isLoading);
        }
        if (empty) {
            empty.hidden = !isEmpty;
            empty.classList.toggle('is-active', isEmpty);
        }
        if (bodyEl) {
            bodyEl.hidden = !isContent;
            bodyEl.classList.toggle('is-active', isContent);
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
        document.querySelectorAll('.mail-row.is-selected, .mail-card.is-selected').forEach(function (el) {
            el.classList.remove('is-selected');
        });
    }

    function openMessageInPane(uid, pushHistory) {
        if (!uid) return;
        if (!useReadingPane()) {
            var row = rowsForUid(uid)[0];
            if (row) {
                showLoading();
                window.location = row.getAttribute('data-href');
            }
            return;
        }

        var url = paneFetchUrl(uid);
        if (!url) return;

        var seq = ++paneLoadSeq;
        setPaneView('loading');
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

                var bodyEl = document.getElementById('reading-pane-body');
                if (!bodyEl) return;

                bodyEl.innerHTML = data.html;
                setPaneView('content');

                if (data.was_unread || data.seen) {
                    setRowSeen(uid, true);
                }
                if (data.unread_counts) {
                    applyUnreadCounts(data.unread_counts);
                }

                var card = bodyEl.querySelector('.mail-read-card[data-uid]');
                bindReadViewCard(card);
                bindComposeLinks(card);
                bindMessageSyncCard(card);
            })
            .catch(function (err) {
                if (seq !== paneLoadSeq) return;
                setPaneView('empty');
                showToast('error', err.message || 'Could not load message.');
            });
    }

    function initReadingPane() {
        if (!document.getElementById('mail-workspace')) return;

        window.addEventListener('popstate', function () {
            if (!useReadingPane()) return;
            var parsed = parseMessagePath(window.location.pathname);
            if (parsed && parsed.uid) {
                openMessageInPane(parsed.uid, false);
            } else {
                clearReadingPane();
            }
        });

        if (PANE_MEDIA.addEventListener) {
            PANE_MEDIA.addEventListener('change', function () {
                if (!useReadingPane()) {
                    closeComposePanel(false);
                    clearReadingPane();
                }
            });
        }
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

    function openComposePanel(href, title) {
        if (!useReadingPane()) {
            showLoading();
            window.location = href;
            return;
        }

        var selected = document.querySelector('.mail-row.is-selected, .mail-card.is-selected');
        composePanelRestoreUid = selected ? parseInt(selected.getAttribute('data-uid'), 10) : null;

        var path = withEmbedParams(href);
        var seq = ++composePanelSeq;
        setComposeOpen(true);

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
            openMessageInPane(composePanelRestoreUid, false);
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
                openComposePanel(a.getAttribute('href'), linkTitle);
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
            if (!useReadingPane() || !form.closest('#compose-panel')) return;

            e.preventDefault();
            syncComposeEditor(form);

            var submitter = e.submitter;
            var draftAction = submitter && submitter.getAttribute('formaction');
            var actionPath = draftAction ? normalizeComposePath(draftAction) : 'compose/send';

            var returnField = form.querySelector('#return_folder');
            if (returnField && !returnField.value) {
                returnField.value = currentMailFolderEnc();
            }

            var fd = new FormData(form);
            beginTask();
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
                var isDraft = actionPath.indexOf('draft') >= 0;
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
                if (mailPoll) mailPoll();
            }).catch(function (err) {
                showToast('error', err.message || 'Action failed.');
            }).finally(function () { endTask(); });
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
        var closeBtn = document.getElementById('compose-panel-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { closeComposePanel(true); });
        }
    }

    function bindMailRow(row) {
        if (!row || row.dataset.bound) return;
        row.dataset.bound = '1';
        row.addEventListener('click', function (e) {
            if (e.target.closest('.mail-row-check') || e.target.closest('.col-check') || e.target.closest('.mail-kebab')) return;
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            if (useReadingPane() && uid) {
                openMessageInPane(uid, true);
                return;
            }
            showLoading();
            window.location = row.getAttribute('data-href');
        });
        // Keyboard activation for role="link" cards (mobile list a11y).
        if (row.getAttribute('role') === 'link') {
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

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function updateMailCount(total) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        label.textContent = String(total);
        label.title = total + ' message' + (total === 1 ? '' : 's');
    }

    function adjustMailCount(delta) {
        var label = document.getElementById('mail-count-label');
        if (!label) return;
        var n = parseInt(label.textContent, 10) || 0;
        updateMailCount(Math.max(0, n + delta));
    }

    var mailPoll = null;

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
    }

    function setRowFlagged(uid, flagged) {
        rowsForUid(uid).forEach(function (el) { applyFlag(el, flagged); });
    }

    function removeRowByUid(uid) {
        var removed = false;
        rowsForUid(uid).forEach(function (el) {
            removed = true;
            el.classList.add('mail-row-removing');
            window.setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
        });
        if (removed) adjustMailCount(-1);
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
        a.setAttribute('role', 'link');
        a.setAttribute('tabindex', '0');
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

    function applyUnreadCounts(counts) {
        if (!counts) return;
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
                if (data && data.unread_counts) applyUnreadCounts(data.unread_counts);
            }).catch(function () {});
    }

    function initMailSync() {
        var card = document.querySelector('[data-mail-sync="1"]');
        if (!card) return;

        var pollUrl = card.getAttribute('data-poll-url');
        var page = parseInt(card.getAttribute('data-page') || '1', 10);
        var interval = parseInt(card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30', 10) * 1000;
        var polling = false;
        var syncErrorShown = false;

        function poll() {
            if (polling) return;

            polling = true;
            card.classList.add('is-syncing');
            var url = pollUrl + (pollUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + page;

            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) {
                    if (!res.ok) throw new Error('sync failed');
                    return res.json();
                })
                .then(function (data) {
                    if (!data || !Array.isArray(data.messages)) return;
                    updateMailCount(data.total);

                    if (page !== 1) {
                        return;
                    }

                    var known = collectKnownUids(card);
                    var freshUids = {};
                    var newMessages = [];

                    data.messages.forEach(function (m) {
                        var uid = String(m.uid);
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

                    if (data.unread_counts && Object.keys(data.unread_counts).length) {
                        applyUnreadCounts(data.unread_counts);
                    } else {
                        refreshUnreadBadges();
                    }
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
        window.setInterval(poll, interval);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') poll();
        });
        // When returning via Back/Forward (bfcache), the page is restored from a
        // snapshot — re-sync after a short delay so Back feels instant first.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                window.setTimeout(poll, 400);
            }
        });
    }

    var commandBarInitialized = false;
    var lastCheckedRowIndex = -1;

    function initMailCommandBar() {
        var toolbar = document.getElementById('mail-command-bar');
        if (!toolbar) return;

        document.querySelectorAll('.mail-check:not([data-cmd-bound])').forEach(function (cb) {
            cb.setAttribute('data-cmd-bound', '1');
            cb.addEventListener('change', onMailCheckChange);
            cb.addEventListener('click', onMailCheckClick);
        });

        if (!commandBarInitialized) {
            commandBarInitialized = true;

            var selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.mail-check').forEach(function (cb) {
                        cb.checked = selectAll.checked;
                    });
                    updateCommandBar();
                });
            }

            toolbar.querySelectorAll('[data-cmd]').forEach(function (btn) {
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'compose') return;
                btn.addEventListener('click', function () {
                    runBulkCommand(cmd);
                });
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
        var row = e.target.closest('.mail-row--outlook');
        if (row) {
            lastCheckedRowIndex = outlookRows().indexOf(row);
        }
        updateCommandBar();
    }

    function updateCommandBar() {
        var toolbar = document.getElementById('mail-command-bar');
        if (!toolbar) return;

        var uids = selectedMailUids();
        var hasSelection = uids.length > 0;
        var needsSelection = ['delete', 'move', 'mark-read', 'mark-unread', 'flag', 'unflag'];

        needsSelection.forEach(function (cmd) {
            var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (btn) btn.disabled = !hasSelection;
        });

        var moveSelect = document.getElementById('cmd-move-target');
        if (moveSelect) moveSelect.disabled = !hasSelection;

        var countEl = document.getElementById('cmd-selection-count');
        if (countEl) {
            countEl.textContent = hasSelection ? uids.length + ' selected' : '';
            countEl.hidden = !hasSelection;
        }

        var selectAll = document.getElementById('select-all');
        var checks = document.querySelectorAll('.mail-check');
        if (selectAll && checks.length) {
            var checkedCount = document.querySelectorAll('.mail-check:checked').length;
            selectAll.checked = checkedCount > 0 && checkedCount === checks.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checks.length;
        }

        outlookRows().forEach(function (row) {
            var cb = row.querySelector('.mail-check');
            row.classList.toggle('is-checked', !!(cb && cb.checked));
        });
    }

    function runBulkCommand(action) {
        if (action === 'refresh') {
            if (mailPoll) {
                mailPoll();
            } else {
                window.location.reload();
            }
            return;
        }

        var uids = selectedMailUids();
        if (!uids.length) return;

        var listCard = document.querySelector('.mail-list-card[data-folder-path]');
        if (!listCard) return;
        var folderEnc = listCard.getAttribute('data-folder-path');

        var actionPath = '';
        var payload = new URLSearchParams();
        payload.set('_csrf', csrf);
        payload.set('folder', folderEnc);
        uids.forEach(function (uid) { payload.append('uids[]', uid); });

        var successMsg = '';

        if (action === 'delete') {
            if (!window.confirm('Move selected messages to Trash?')) return;
            actionPath = 'message/bulk-trash';
            payload.set('unread_delta', String(countUnreadAmong(uids)));
            uids.forEach(function (uid) { removeRowByUid(uid); });
            successMsg = 'Selected messages moved to Trash.';
        } else if (action === 'move') {
            var target = document.getElementById('cmd-move-target');
            if (!target || !target.value) {
                showToast('error', 'Choose a folder to move to.');
                return;
            }
            actionPath = 'message/bulk-move';
            payload.set('target_folder', target.value);
            payload.set('unread_delta', String(countUnreadAmong(uids)));
            uids.forEach(function (uid) { removeRowByUid(uid); });
            successMsg = 'Selected messages moved.';
        } else if (action === 'mark-read') {
            actionPath = 'message/bulk-mark-read';
            uids.forEach(function (uid) { setRowSeen(uid, true); });
            successMsg = uids.length + ' message(s) marked as read.';
        } else if (action === 'mark-unread') {
            actionPath = 'message/bulk-mark-unread';
            uids.forEach(function (uid) { setRowSeen(uid, false); });
            successMsg = uids.length + ' message(s) marked as unread.';
        } else if (action === 'flag') {
            actionPath = 'message/bulk-flag';
            uids.forEach(function (uid) { setRowFlagged(uid, true); });
            successMsg = uids.length + ' message(s) marked as important.';
        } else if (action === 'unflag') {
            actionPath = 'message/bulk-unflag';
            uids.forEach(function (uid) { setRowFlagged(uid, false); });
            successMsg = uids.length + ' message(s) unflagged.';
        } else {
            return;
        }

        beginTask();
        fetch(apiUrl(actionPath), {
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
        }).then(function (data) {
            if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                applyUnreadCounts(data.unread_counts);
            } else {
                refreshUnreadBadges();
            }
            var selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }
            document.querySelectorAll('.mail-check:checked').forEach(function (cb) { cb.checked = false; });
            var moveSelect = document.getElementById('cmd-move-target');
            if (moveSelect && action === 'move') moveSelect.value = '';
            updateCommandBar();
            if (successMsg) showToast('success', successMsg);
        }).catch(function (err) {
            showToast('error', err.message || 'Action failed.');
            if (mailPoll) mailPoll();
        }).finally(function () { endTask(); });
    }

    function selectedMailUids() {
        return Array.prototype.slice.call(document.querySelectorAll('.mail-check:checked'))
            .map(function (cb) { return cb.value; });
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

    function bindReadViewCard(card) {
        if (!card || card.dataset.actionsBound) return;
        card.dataset.actionsBound = '1';

        var folderEnc = card.getAttribute('data-folder-b64');
        var uid = card.getAttribute('data-uid');

        card.querySelectorAll('[data-mail-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-mail-action');
                if (!action) return;

                if (action === 'trash' && !window.confirm('Move this message to Trash?')) return;
                if (action === 'spam' && !window.confirm('Move this message to Spam?')) return;

                var extra = {};
                if (action === 'move') {
                    var select = card.querySelector('[name="target_folder"]');
                    if (!select || !select.value) {
                        showToast('error', 'Choose a folder to move to.');
                        return;
                    }
                    extra.target_folder = select.value;
                }

                dispatchMessageAction(action, folderEnc, uid, extra).then(function () {
                    if (action === 'mark-read') {
                        showToast('success', 'Marked as read.');
                    } else if (action === 'mark-unread') {
                        showToast('success', 'Marked as unread.');
                    } else if (action === 'flag') {
                        showToast('success', 'Marked as important.');
                    } else if (action === 'unflag') {
                        showToast('success', 'Importance removed.');
                    } else if (action === 'trash') {
                        showToast('success', 'Message moved to Trash.');
                    } else if (action === 'spam') {
                        showToast('success', 'Message moved to Spam.');
                    } else if (action === 'move') {
                        showToast('success', 'Message moved.');
                    }
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

    function initKeyboardShortcuts() {
        var modal = document.getElementById('shortcuts-modal');
        var closeBtn = document.getElementById('shortcuts-close');

        if (closeBtn && modal) {
            closeBtn.addEventListener('click', function () { modal.hidden = true; });
        }

        document.addEventListener('keydown', function (e) {
            if (isTyping()) return;

            if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
                if (modal) modal.hidden = !modal.hidden;
                return;
            }

            if (modal && !modal.hidden) return;

            // Operate on whichever list is currently visible (desktop rows or
            // mobile cards) so shortcuts work in both layouts.
            var selector = '.mail-row, .mail-card';
            var rows = Array.prototype.slice.call(document.querySelectorAll(selector))
                .filter(function (el) { return el.offsetParent !== null; });
            var row = rows.filter(function (el) { return el.classList.contains('is-focused'); })[0] || rows[0];
            var idx = row ? rows.indexOf(row) : -1;

            if (e.key === 'c') {
                e.preventDefault();
                openComposePanel(apiUrl('compose'), 'New message');
            } else if (e.key === '/') {
                e.preventDefault();
                var search = document.getElementById('mail-search');
                if (search) search.focus();
            } else if (e.key === 'j' && idx < rows.length - 1) {
                rows.forEach(function (r) { r.classList.remove('is-focused'); });
                var next = rows[idx + 1];
                next.classList.add('is-focused');
                next.scrollIntoView({ block: 'nearest' });
                if (useReadingPane()) {
                    var nextUid = parseInt(next.getAttribute('data-uid'), 10);
                    if (nextUid) openMessageInPane(nextUid, true);
                }
            } else if (e.key === 'k' && idx > 0) {
                rows.forEach(function (r) { r.classList.remove('is-focused'); });
                var prev = rows[idx - 1];
                prev.classList.add('is-focused');
                prev.scrollIntoView({ block: 'nearest' });
                if (useReadingPane()) {
                    var prevUid = parseInt(prev.getAttribute('data-uid'), 10);
                    if (prevUid) openMessageInPane(prevUid, true);
                }
            } else if (e.key === 'Enter' && row && useReadingPane() && document.getElementById('mail-workspace')) {
                e.preventDefault();
                var enterUid = parseInt(row.getAttribute('data-uid'), 10);
                if (enterUid) openMessageInPane(enterUid, true);
            } else if (e.key === 'r' && row) {
                e.preventDefault();
                var replyHref = row.getAttribute('data-reply-url');
                if (replyHref) openComposePanel(replyHref, 'Reply');
            } else if (e.key === 'a' && row) {
                e.preventDefault();
                var replyAllHref = row.getAttribute('data-reply-all-url');
                if (replyAllHref) openComposePanel(replyAllHref, 'Reply all');
            } else if (e.key === 'e') {
                var paneCard = document.querySelector('#reading-pane-body .mail-read-card[data-uid]');
                var del = paneCard
                    ? paneCard.querySelector('[data-mail-action="trash"]')
                    : document.getElementById('delete-form');
                if (del) del.click();
            } else if (e.key === 'Escape') {
                if (isComposeOpen()) {
                    closeComposePanel(true);
                    return;
                }
                if (useReadingPane() && document.getElementById('reading-pane-body') && !document.getElementById('reading-pane-body').hidden) {
                    clearReadingPane();
                    var listCard = getListCard();
                    var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : null;
                    if (folderOnly && window.history && window.history.replaceState) {
                        window.history.replaceState({}, '', folderOnly);
                    }
                }
            }
        });
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

    function dispatchMessageAction(kind, sourceFolderEnc, uid, extra) {
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
            if (readCard) readCard.setAttribute('data-seen', '1');
        } else if (kind === 'mark-unread') {
            setRowSeen(uid, false);
            if (readCard) readCard.setAttribute('data-seen', '0');
        } else if (kind === 'flag') {
            setRowFlagged(uid, true);
        } else if (kind === 'unflag') {
            setRowFlagged(uid, false);
        } else if (kind === 'spam' || kind === 'trash' || kind === 'move') {
            fields.unread_delta = wasUnread ? 1 : 0;
            removeRowByUid(uid);
        }

        beginTask();
        return ajaxAction('message/' + kind, fields)
            .then(function (data) {
                if (data && data.unread_counts && Object.keys(data.unread_counts).length) {
                    applyUnreadCounts(data.unread_counts);
                } else if (kind !== 'flag' && kind !== 'unflag') {
                    refreshUnreadBadges();
                }
                if ((kind === 'trash' || kind === 'spam' || kind === 'move')) {
                    var paneHost = document.getElementById('reading-pane-body');
                    var inPane = paneHost && paneHost.querySelector('.mail-read-card[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(uid)) : String(uid)) + '"]');
                    if (inPane) {
                        clearReadingPane();
                        var listCard = getListCard();
                        var folderOnly = listCard ? listCard.getAttribute('data-folder-url') : null;
                        if (folderOnly && window.history && window.history.replaceState) {
                            window.history.replaceState({}, '', folderOnly);
                        }
                    } else if (folderUrl) {
                        window.location = folderUrl;
                    }
                }
            })
            .catch(function (err) {
                showToast('error', err.message || 'Action failed.');
                if (mailPoll) mailPoll();
            })
            .finally(function () { endTask(); });
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
                sub.appendChild(b);
            });
            item.appendChild(sub);

            function place() {
                sub.classList.remove('flip-left');
                var rect = item.getBoundingClientRect();
                var subW = sub.offsetWidth || 200;
                if (rect.right + subW + 8 > window.innerWidth) {
                    sub.classList.add('flip-left');
                }
                sub.style.top = '';
                var subH = sub.offsetHeight;
                var overflowBottom = (rect.top + subH + 8) - window.innerHeight;
                if (overflowBottom > 0) {
                    sub.style.top = (-overflowBottom - 4) + 'px';
                }
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
            addItem('Delete', ICONS.trash, function () { dispatchMessageAction('trash', sourceFolderEnc, uid); }, true);

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

    document.addEventListener('DOMContentLoaded', function () {
        initToasts();
        initMailSync();
        initMessageSync();
        initMailCommandBar();
        initRichEditor();
        initRecipientFields();
        initRulesDragDrop();
        initKeyboardShortcuts();
        initThemeFromSettings();
        initSidebarGroups();
        initFileUpload();
        initPerPageSelect();
        initContextMenu();
        initReadingPane();
        initComposePanel();
        initReadViewActions();
        requestNotificationPermission();
    });
})();
