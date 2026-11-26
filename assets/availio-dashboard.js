/**
 * availio-dashboard.js
 * Tab switching (today / date picker) + per-day AJAX loading + auto-refresh.
 *
 * All human-readable text comes from availioDashConfig, which is localized
 * server-side, so this file contains no translatable strings of its own.
 */
(function () {
    'use strict';

    var cfg = (typeof availioDashConfig !== 'undefined') ? availioDashConfig : {};

    var ajaxUrl        = cfg.ajaxUrl        || '';
    var nonce          = cfg.nonce          || '';
    var todayYmd       = cfg.today          || isoDate(new Date());
    var maxYmd         = cfg.maxDate        || todayYmd;
    var refreshSeconds = cfg.refreshSeconds || 60;
    var i18n           = cfg.i18n           || {};

    var FALLBACK_DAYS   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var FALLBACK_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                           'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var DAY_NAMES   = (cfg.dayNames   && cfg.dayNames.length   === 7)  ? cfg.dayNames   : FALLBACK_DAYS;
    var MONTH_NAMES = (cfg.monthNames && cfg.monthNames.length === 12) ? cfg.monthNames : FALLBACK_MONTHS;

    /* ── Date helpers ──────────────────────────────────────────────────────── */

    function isoDate(d) {
        return d.getFullYear()
            + '-' + pad(d.getMonth() + 1)
            + '-' + pad(d.getDate());
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function addDays(ymd, n) {
        var d = new Date(ymd + 'T12:00:00');
        d.setDate(d.getDate() + n);
        return isoDate(d);
    }

    function formatLabel(ymd) {
        var d = new Date(ymd + 'T12:00:00');
        return DAY_NAMES[d.getDay()]
            + ' ' + d.getDate()
            + '. ' + MONTH_NAMES[d.getMonth()]
            + ' ' + d.getFullYear();
    }

    function cmp(a, b) { return a < b ? -1 : a > b ? 1 : 0; }

    /* Replaces the panel content with a single message, as text (never markup). */
    function showNotice(target, message, className) {
        var box = document.createElement('div');
        box.className = className;
        box.textContent = message;
        target.innerHTML = '';
        target.appendChild(box);
    }

    /* ── Initialize the dashboard ──────────────────────────────────────────── */

    function initAll() {
        document.querySelectorAll('.availio-dashboard').forEach(init);
    }

    function init(dashboard) {
        var todayTab  = dashboard.querySelector('[data-target="availio-day"]');
        var dateTab   = dashboard.querySelector('[data-target="availio-date"]');
        var prevBtn   = dashboard.querySelector('#availio-prev-day');
        var nextBtn   = dashboard.querySelector('#availio-next-day');
        var content   = dashboard.querySelector('#availio-date-content');

        var currentDate = todayYmd;

        /* Show / hide panels */
        function showPanel(id) {
            dashboard.querySelectorAll('.availio-panel').forEach(function (p) {
                p.classList.toggle('availio-panel--hidden', p.id !== id);
            });
        }

        /* Activate tab buttons */
        function activateTab(btn) {
            dashboard.querySelectorAll('.availio-tab').forEach(function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
            }
        }

        /* Update the arrows */
        function refreshArrows() {
            if (prevBtn) prevBtn.disabled = cmp(currentDate, todayYmd) <= 0;
            if (nextBtn) nextBtn.disabled = cmp(currentDate, maxYmd)   >= 0;
        }

        /* Load a day's data via AJAX */
        function loadDate(ymd) {
            currentDate = ymd;
            refreshArrows();
            if (dateTab) dateTab.textContent = formatLabel(ymd);
            if (!content) return;

            showNotice(content, i18n.loading || 'Loading data…', 'availio-loading');

            var fd = new FormData();
            fd.append('action', 'availio_day');
            fd.append('nonce',  nonce);
            fd.append('date',   ymd);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        content.innerHTML = data.data.html;
                    } else {
                        showNotice(
                            content,
                            (i18n.errorPrefix || 'Error:') + ' '
                                + (data.data || i18n.unknownError || 'Unknown error'),
                            'availio-error'
                        );
                    }
                })
                .catch(function () {
                    showNotice(
                        content,
                        i18n.connectionError || 'Connection error.',
                        'availio-error'
                    );
                });
        }

        /* Switch to the date-picker panel */
        function switchToDate() {
            activateTab(dateTab);
            showPanel('availio-date');
        }

        /* Today tab */
        if (todayTab) {
            todayTab.addEventListener('click', function () {
                activateTab(todayTab);
                showPanel('availio-day');
            });
        }

        /* Date-picker tab (click on the label button) */
        if (dateTab) {
            dateTab.addEventListener('click', function () {
                switchToDate();
                /* On first open: if still on today, fetch fresh data via AJAX */
                if (currentDate === todayYmd && content &&
                    !content.querySelector('.availio-day-grid')) {
                    loadDate(todayYmd);
                }
            });
        }

        /* Arrow: backwards */
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (cmp(currentDate, todayYmd) > 0) {
                    switchToDate();
                    loadDate(addDays(currentDate, -1));
                }
            });
        }

        /* Arrow: forwards */
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (cmp(currentDate, maxYmd) < 0) {
                    switchToDate();
                    loadDate(addDays(currentDate, 1));
                }
            });
        }

        refreshArrows();

        /* Auto-refresh: reload the page while the today tab is active,
           otherwise refresh only the selected day via AJAX */
        function scheduleRefresh() {
            setTimeout(function () {
                var datePanel = dashboard.querySelector('#availio-date');
                var dateActive = datePanel &&
                    !datePanel.classList.contains('availio-panel--hidden');

                if (dateActive) {
                    // Date picker active -> reload just this day, then reschedule
                    loadDate(currentDate);
                    scheduleRefresh();
                } else {
                    // Today tab active -> plain page reload
                    location.reload();
                }
            }, refreshSeconds * 1000);
        }
        scheduleRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
}());
