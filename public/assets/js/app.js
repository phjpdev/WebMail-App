(function () {
    'use strict';

    var overlay = document.getElementById('loading-overlay');
    var sidebar = document.getElementById('sidebar');
    var menuToggle = document.getElementById('menu-toggle');
    var sidebarBackdrop = document.getElementById('sidebar-backdrop');

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
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('is-open');
        if (sidebarBackdrop) sidebarBackdrop.hidden = true;
        document.body.classList.remove('sidebar-open');
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
            if (sidebar && sidebar.classList.contains('is-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeSidebar);
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.sidebar-link') && window.innerWidth < 900) {
            closeSidebar();
        }
    });

    function bindMailRow(row) {
        if (!row || row.dataset.bound) return;
        row.dataset.bound = '1';
        row.addEventListener('click', function () {
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
        if (label) {
            label.textContent = total + ' message' + (total === 1 ? '' : 's');
        }
    }

    function showSyncStatus() {
        var el = document.getElementById('mail-sync-status');
        if (!el) return;
        el.textContent = 'Updated just now';
        el.hidden = false;
        window.setTimeout(function () {
            el.hidden = true;
        }, 3000);
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
            '<td class="col-status">' + (msg.seen ? '' : '<span class="unread-dot"></span>') + '</td>' +
            '<td class="col-from">' + escapeHtml(msg.from) + '</td>' +
            '<td class="col-subject">' + escapeHtml(msg.subject) + '</td>' +
            '<td class="col-date">' + escapeHtml(msg.date) + '</td>';
        bindMailRow(tr);
        if (isNew) {
            window.setTimeout(function () {
                tr.classList.remove('mail-row-new');
            }, 3000);
        }
        return tr;
    }

    function buildMobileCard(msg, isNew) {
        var a = document.createElement('a');
        a.className = 'mail-card' + (msg.seen ? '' : ' mail-unread') + (isNew ? ' mail-row-new' : '');
        a.setAttribute('data-uid', String(msg.uid));
        a.href = msg.url;
        a.innerHTML =
            '<div class="mail-card-top">' +
            '<span class="mail-card-from">' + escapeHtml(msg.from) + '</span>' +
            '<span class="mail-card-date">' + escapeHtml(msg.date) + '</span>' +
            '</div>' +
            '<div class="mail-card-subject">' + escapeHtml(msg.subject) + '</div>';
        if (isNew) {
            window.setTimeout(function () {
                a.classList.remove('mail-row-new');
            }, 3000);
        }
        return a;
    }

    function collectKnownUids(card) {
        var uids = new Set();
        card.querySelectorAll('[data-uid]').forEach(function (el) {
            uids.add(String(el.getAttribute('data-uid')));
        });
        return uids;
    }

    function initMailSync() {
        var card = document.querySelector('[data-mail-sync]');
        if (!card) return;

        var pollUrl = card.getAttribute('data-poll-url');
        var page = parseInt(card.getAttribute('data-page') || '1', 10);
        var interval = parseInt(card.getAttribute('data-poll-interval') || '30', 10) * 1000;
        var polling = false;

        function poll() {
            if (polling) return;
            if (overlay && !overlay.hidden) return;

            polling = true;
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
                        showSyncStatus();
                        return;
                    }

                    var known = collectKnownUids(card);
                    var newMessages = data.messages.filter(function (m) {
                        return !known.has(String(m.uid));
                    });

                    if (newMessages.length === 0) return;

                    ensureListVisible(card);

                    var tbody = document.getElementById('mail-list-body');
                    var mobile = document.getElementById('mail-list-mobile');

                    newMessages.forEach(function (msg) {
                        if (tbody) {
                            tbody.insertBefore(buildDesktopRow(msg, true), tbody.firstChild);
                        }
                        if (mobile) {
                            mobile.insertBefore(buildMobileCard(msg, true), mobile.firstChild);
                        }
                    });

                    showSyncStatus();
                })
                .catch(function () { /* silent — retry on next interval */ })
                .finally(function () {
                    polling = false;
                });
        }

        window.setInterval(poll, interval);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                poll();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initMailSync);
})();
