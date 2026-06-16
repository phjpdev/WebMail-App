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

    function apiUrl(path) {
        return appBase + '/' + String(path).replace(/^\//, '');
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

    function bindMailRow(row) {
        if (!row || row.dataset.bound) return;
        row.dataset.bound = '1';
        row.addEventListener('click', function (e) {
            if (e.target.closest('.col-check') || e.target.closest('.mail-kebab')) return;
            showLoading();
            window.location = row.getAttribute('data-href');
        });
    }

    document.querySelectorAll('.mail-row[data-href]').forEach(bindMailRow);

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
        var desktop = card.querySelector('.mail-list-desktop');
        var mobile = document.getElementById('mail-list-mobile');
        if (desktop) desktop.hidden = false;
        if (mobile) mobile.hidden = false;
    }

    function buildDesktopRow(msg, isNew) {
        var tr = document.createElement('tr');
        tr.className = 'mail-row' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (isNew ? ' mail-row-new' : '');
        tr.setAttribute('data-uid', String(msg.uid));
        tr.setAttribute('data-seen', msg.seen ? '1' : '0');
        tr.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        tr.setAttribute('data-href', msg.url);
        tr.innerHTML =
            '<td class="col-check" onclick="event.stopPropagation()"><input type="checkbox" class="mail-check" value="' + msg.uid + '"></td>' +
            '<td class="col-status">' + (msg.seen ? '' : '<span class="unread-dot"></span>') + (msg.flagged ? '<span class="flag-dot" title="Important">\u2605</span>' : '') + '</td>' +
            '<td class="col-from">' + escapeHtml(msg.from) + '</td>' +
            '<td class="col-subject">' + escapeHtml(msg.subject) + '</td>' +
            '<td class="col-date"><span class="col-date-text">' + escapeHtml(msg.date) + '</span>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button></td>';
        bindMailRow(tr);
        initBulkSelect();
        if (isNew) window.setTimeout(function () { tr.classList.remove('mail-row-new'); }, 3000);
        return tr;
    }

    function buildMobileCard(msg, isNew) {
        var a = document.createElement('a');
        a.className = 'mail-card' + (msg.seen ? '' : ' mail-unread') + (msg.flagged ? ' mail-flagged' : '') + (isNew ? ' mail-row-new' : '');
        a.setAttribute('data-uid', String(msg.uid));
        a.setAttribute('data-seen', msg.seen ? '1' : '0');
        a.setAttribute('data-flagged', msg.flagged ? '1' : '0');
        a.href = msg.url;
        a.innerHTML =
            '<div class="mail-card-top"><span class="mail-card-from">' + (msg.flagged ? '<span class="flag-dot" title="Important">\u2605</span> ' : '') + escapeHtml(msg.from) +
            '</span><span class="mail-card-date">' + escapeHtml(msg.date) + '</span>' +
            '<button type="button" class="mail-kebab" aria-label="Message actions" title="Actions">\u22EE</button></div>' +
            '<div class="mail-card-subject">' + escapeHtml(msg.subject) + '</div>';
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

    function refreshUnreadBadges() {
        fetch(apiUrl('folders/unread'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.unread_counts) return;
                Object.keys(data.unread_counts).forEach(function (path) {
                    var link = document.querySelector('.sidebar-link[data-folder-path="' + CSS.escape(path) + '"]');
                    if (!link) return;
                    var badge = link.querySelector('.folder-badge');
                    var n = data.unread_counts[path];
                    if (n > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'folder-badge';
                            link.appendChild(badge);
                        }
                        badge.textContent = n > 99 ? '99+' : n;
                    } else if (badge) badge.remove();
                });
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

                    refreshUnreadBadges();
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
        // snapshot — re-sync so seen/flagged state and the list are up to date.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) poll();
        });
    }

    function initMessageSync() {
        var card = document.querySelector('[data-message-sync]');
        if (!card) return;

        var syncUrl = card.getAttribute('data-sync-url');
        var folderUrl = card.getAttribute('data-folder-url');
        var interval = parseInt(card.getAttribute('data-poll-interval') || body.getAttribute('data-poll-interval') || '30', 10) * 1000;

        function check() {
            fetch(syncUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.exists === false) {
                        window.location = folderUrl;
                    }
                }).catch(function () {});
        }

        window.setInterval(check, interval);
    }

    function initFilterProgress() {
        if (body.getAttribute('data-filter-pending') !== '1') return;
        var el = document.getElementById('filter-progress');
        if (!el) return;
        el.hidden = false;

        var fd = new FormData();
        fd.append('_csrf', csrf);

        fetch(apiUrl('filter/run'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                el.hidden = true;
                if (data && data.moved > 0) {
                    showToast('success', 'Organized ' + data.processed + ' message(s), ' + data.moved + ' moved.');
                }
                refreshUnreadBadges();
            })
            .catch(function () { el.hidden = true; });
    }

    function initBulkSelect() {
        var toolbar = document.getElementById('bulk-toolbar');
        var selectAll = document.getElementById('select-all');
        if (!toolbar) return;

        function updateToolbar() {
            var checked = document.querySelectorAll('.mail-check:checked');
            toolbar.hidden = checked.length === 0;
            var count = document.getElementById('bulk-count');
            if (count) count.textContent = checked.length + ' selected';

            ['bulk-trash-uids', 'bulk-move-uids', 'bulk-read-uids', 'bulk-unread-uids'].forEach(function (id) {
                var container = document.getElementById(id);
                if (!container) return;
                container.innerHTML = '';
                checked.forEach(function (cb) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'uids[]';
                    input.value = cb.value;
                    container.appendChild(input);
                });
            });
        }

        document.querySelectorAll('.mail-check').forEach(function (cb) {
            cb.addEventListener('change', updateToolbar);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.mail-check').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                updateToolbar();
            });
        }
    }

    function initRichEditor() {
        var editor = document.getElementById('body-editor');
        var bodyField = document.getElementById('body');
        var htmlField = document.getElementById('body_html');
        var form = document.getElementById('compose-form');
        var toolbar = document.getElementById('rich-toolbar');
        if (!editor || !form) return;

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

    function initCcBccToggle() {
        var btn = document.getElementById('toggle-cc-bcc');
        var fields = document.getElementById('cc-bcc-fields');
        if (!btn || !fields) return;
        btn.addEventListener('click', function () {
            var open = fields.hidden;
            fields.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) fields.querySelector('input')?.focus();
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

            var row = document.querySelector('.mail-row.is-focused') || document.querySelector('.mail-row');
            var rows = Array.prototype.slice.call(document.querySelectorAll('.mail-row'));
            var idx = row ? rows.indexOf(row) : -1;

            if (e.key === 'c') {
                var compose = document.getElementById('compose-link');
                if (compose) window.location = compose.href;
            } else if (e.key === '/') {
                e.preventDefault();
                var search = document.getElementById('mail-search');
                if (search) search.focus();
            } else if (e.key === 'j' && idx < rows.length - 1) {
                rows.forEach(function (r) { r.classList.remove('is-focused'); });
                rows[idx + 1].classList.add('is-focused');
            } else if (e.key === 'k' && idx > 0) {
                rows.forEach(function (r) { r.classList.remove('is-focused'); });
                rows[idx - 1].classList.add('is-focused');
            } else if (e.key === 'r' && row) {
                window.location = row.getAttribute('data-reply-url') || row.getAttribute('data-href');
            } else if (e.key === 'a' && row) {
                window.location = row.getAttribute('data-reply-all-url') || row.getAttribute('data-href');
            } else if (e.key === 'e') {
                var del = document.getElementById('delete-form');
                if (del) del.requestSubmit();
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
        document.querySelectorAll('.sidebar-group-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.sidebar-group');
                if (!group) return;
                var open = group.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    }

    function initFileUpload() {
        var wrap = document.getElementById('file-upload');
        var input = document.getElementById('attachments');
        var list = document.getElementById('file-upload-list');
        if (!wrap || !input) return;

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

        if (kind === 'mark-read') {
            setRowSeen(uid, true);
        } else if (kind === 'mark-unread') {
            setRowSeen(uid, false);
        } else if (kind === 'flag') {
            setRowFlagged(uid, true);
        } else if (kind === 'unflag') {
            setRowFlagged(uid, false);
        } else if (kind === 'spam' || kind === 'trash' || kind === 'move') {
            removeRowByUid(uid);
        }

        beginTask();
        return ajaxAction('message/' + kind, fields)
            .then(function () { refreshUnreadBadges(); })
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

        function addItem(label, handler, danger) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'context-menu-item' + (danger ? ' is-danger' : '');
            item.textContent = label;
            item.addEventListener('click', function (e) {
                e.preventDefault();
                hide();
                handler();
            });
            menu.appendChild(item);
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
                out.push({ path: path, name: textEl ? textEl.textContent.trim() : path });
            });
            return out;
        }

        function openFor(row, x, y) {
            var uid = row.getAttribute('data-uid');
            if (!uid) return;

            var seen = row.getAttribute('data-seen') === '1';
            var flagged = row.getAttribute('data-flagged') === '1';
            var href = row.getAttribute('data-href') || row.getAttribute('href');

            menu.innerHTML = '';

            addItem('Open', function () { if (href) { showLoading(); window.location = href; } });
            addSep();

            if (seen) {
                addItem('Mark as unread', function () { dispatchMessageAction('mark-unread', sourceFolderEnc, uid); });
            } else {
                addItem('Mark as read', function () { dispatchMessageAction('mark-read', sourceFolderEnc, uid); });
            }

            if (flagged) {
                addItem('Remove importance', function () { dispatchMessageAction('unflag', sourceFolderEnc, uid); });
            } else {
                addItem('Mark as important', function () { dispatchMessageAction('flag', sourceFolderEnc, uid); });
            }

            addSep();
            addItem('Move to Spam', function () { dispatchMessageAction('spam', sourceFolderEnc, uid); });

            var folders = collectMoveFolders();
            if (folders.length) {
                var header = document.createElement('div');
                header.className = 'context-menu-header';
                header.textContent = 'Move to';
                menu.appendChild(header);
                folders.forEach(function (f) {
                    addItem(f.name, function () {
                        dispatchMessageAction('move', sourceFolderEnc, uid, { target_folder: f.path });
                    });
                });
            }

            addSep();
            addItem('Delete', function () { dispatchMessageAction('trash', sourceFolderEnc, uid); }, true);

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
        window.addEventListener('scroll', hide, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initToasts();
        initMailSync();
        initMessageSync();
        initFilterProgress();
        initBulkSelect();
        initRichEditor();
        initCcBccToggle();
        initRulesDragDrop();
        initKeyboardShortcuts();
        initThemeFromSettings();
        initSidebarGroups();
        initFileUpload();
        initPerPageSelect();
        initContextMenu();
        requestNotificationPermission();
    });
})();
