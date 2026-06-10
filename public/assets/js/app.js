(function () {
    'use strict';

    var overlay = document.getElementById('loading-overlay');
    var sidebar = document.getElementById('sidebar');
    var menuToggle = document.getElementById('menu-toggle');
    var sidebarBackdrop = document.getElementById('sidebar-backdrop');
    var body = document.body;
    var csrf = body.getAttribute('data-csrf') || '';

    function showLoading() {
        if (overlay) {
            overlay.hidden = false;
            overlay.setAttribute('aria-busy', 'true');
        }
    }

    function hideLoading() {
        if (overlay) {
            overlay.hidden = true;
            overlay.setAttribute('aria-busy', 'false');
        }
    }

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
            if (e.target.closest('.col-check')) return;
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
        if (label) label.textContent = total + ' message' + (total === 1 ? '' : 's');
    }

    function showSyncStatus(text) {
        var el = document.getElementById('mail-sync-status');
        if (!el) return;
        var isChecking = text && text.indexOf('Checking') === 0;
        el.textContent = text || 'Updated just now';
        el.hidden = false;
        if (isChecking) {
            el.classList.add('is-checking');
            window.setTimeout(function () {
                el.classList.remove('is-checking');
                el.hidden = true;
            }, 600);
        } else {
            el.classList.remove('is-checking');
        }
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
        tr.className = 'mail-row' + (msg.seen ? '' : ' mail-unread') + (isNew ? ' mail-row-new' : '');
        tr.setAttribute('data-uid', String(msg.uid));
        tr.setAttribute('data-href', msg.url);
        tr.innerHTML =
            '<td class="col-check" onclick="event.stopPropagation()"><input type="checkbox" class="mail-check" value="' + msg.uid + '"></td>' +
            '<td class="col-status">' + (msg.seen ? '' : '<span class="unread-dot"></span>') + '</td>' +
            '<td class="col-from">' + escapeHtml(msg.from) + '</td>' +
            '<td class="col-subject">' + escapeHtml(msg.subject) + '</td>' +
            '<td class="col-date">' + escapeHtml(msg.date) + '</td>';
        bindMailRow(tr);
        initBulkSelect();
        if (isNew) window.setTimeout(function () { tr.classList.remove('mail-row-new'); }, 3000);
        return tr;
    }

    function buildMobileCard(msg, isNew) {
        var a = document.createElement('a');
        a.className = 'mail-card' + (msg.seen ? '' : ' mail-unread') + (isNew ? ' mail-row-new' : '');
        a.setAttribute('data-uid', String(msg.uid));
        a.href = msg.url;
        a.innerHTML =
            '<div class="mail-card-top"><span class="mail-card-from">' + escapeHtml(msg.from) +
            '</span><span class="mail-card-date">' + escapeHtml(msg.date) + '</span></div>' +
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
        fetch(document.baseURI.replace(/\/$/, '') + '/folders/unread', {
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

        showSyncStatus('Updated just now');

        function poll() {
            if (polling) return;
            if (overlay && !overlay.hidden) return;

            polling = true;
            showSyncStatus('Checking…');
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
                        showSyncStatus('Updated just now');
                        return;
                    }

                    var known = collectKnownUids(card);
                    var newMessages = data.messages.filter(function (m) {
                        return !known.has(String(m.uid));
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

                    showSyncStatus('Updated just now');
                    refreshUnreadBadges();
                })
                .catch(function () {})
                .finally(function () { polling = false; });
        }

        window.setInterval(poll, interval);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') poll();
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

        var base = document.baseURI.replace(/\/$/, '');
        var fd = new FormData();
        fd.append('_csrf', csrf);

        fetch(base + '/filter/run', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
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

            ['bulk-trash-uids', 'bulk-move-uids'].forEach(function (id) {
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
        requestNotificationPermission();
    });
})();
