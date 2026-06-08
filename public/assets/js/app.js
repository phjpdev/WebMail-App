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

    document.querySelectorAll('.mail-row[data-href]').forEach(function (row) {
        row.addEventListener('click', function () {
            window.location = row.getAttribute('data-href');
        });
    });
})();
