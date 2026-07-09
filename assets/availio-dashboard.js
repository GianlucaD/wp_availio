/**
 * availio-dashboard.js
 * Tab-Umschaltung (Heute / Datepicker) + AJAX-Laden pro Tag + Auto-Refresh.
 */
(function () {
    'use strict';

    var cfg = (typeof availioDashConfig !== 'undefined') ? availioDashConfig : {};

    var ajaxUrl        = cfg.ajaxUrl        || '';
    var nonce          = cfg.nonce          || '';
    var todayYmd       = cfg.today          || isoDate(new Date());
    var maxYmd         = cfg.maxDate        || todayYmd;
    var refreshSeconds = cfg.refreshSeconds || 60;

    var DAY_NAMES   = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    var MONTH_NAMES = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun',
                       'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /* ── Datums-Hilfsfunktionen ────────────────────────────────────────────── */

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

    /* ── Dashboard initialisieren ──────────────────────────────────────────── */

    function initAll() {
        document.querySelectorAll('.availio-dashboard').forEach(init);
    }

    function init(dashboard) {
        var heuteTab  = dashboard.querySelector('[data-target="availio-day"]');
        var dateTab   = dashboard.querySelector('[data-target="availio-date"]');
        var prevBtn   = dashboard.querySelector('#availio-prev-day');
        var nextBtn   = dashboard.querySelector('#availio-next-day');
        var content   = dashboard.querySelector('#availio-date-content');

        var currentDate = todayYmd;

        /* Panels ein-/ausblenden */
        function showPanel(id) {
            dashboard.querySelectorAll('.availio-panel').forEach(function (p) {
                p.classList.toggle('availio-panel--hidden', p.id !== id);
            });
        }

        /* Tab-Buttons aktivieren */
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

        /* Pfeile aktualisieren */
        function refreshArrows() {
            if (prevBtn) prevBtn.disabled = cmp(currentDate, todayYmd) <= 0;
            if (nextBtn) nextBtn.disabled = cmp(currentDate, maxYmd)   >= 0;
        }

        /* Tages-Daten via AJAX laden */
        function loadDate(ymd) {
            currentDate = ymd;
            refreshArrows();
            if (dateTab) dateTab.textContent = formatLabel(ymd);
            if (!content) return;

            content.innerHTML =
                '<div style="padding:2em;text-align:center;color:#888;">Lade Daten…</div>';

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
                        content.innerHTML =
                            '<div class="availio-error">Fehler: '
                            + (data.data || 'Unbekannt') + '</div>';
                    }
                })
                .catch(function () {
                    content.innerHTML =
                        '<div class="availio-error">Verbindungsfehler.</div>';
                });
        }

        /* Zum Datepicker-Panel wechseln */
        function switchToDate() {
            activateTab(dateTab);
            showPanel('availio-date');
        }

        /* Heute-Tab */
        if (heuteTab) {
            heuteTab.addEventListener('click', function () {
                activateTab(heuteTab);
                showPanel('availio-day');
            });
        }

        /* Datepicker-Tab (Klick auf die Label-Schaltfläche) */
        if (dateTab) {
            dateTab.addEventListener('click', function () {
                switchToDate();
                /* Beim ersten Öffnen: falls noch auf heute, AJAX für frische Daten */
                if (currentDate === todayYmd && content &&
                    !content.querySelector('.availio-day-grid')) {
                    loadDate(todayYmd);
                }
            });
        }

        /* Pfeil: zurück */
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (cmp(currentDate, todayYmd) > 0) {
                    switchToDate();
                    loadDate(addDays(currentDate, -1));
                }
            });
        }

        /* Pfeil: vorwärts */
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (cmp(currentDate, maxYmd) < 0) {
                    switchToDate();
                    loadDate(addDays(currentDate, 1));
                }
            });
        }

        refreshArrows();

        /* Auto-Refresh: Seite neu laden wenn «Heute» aktiv,
           sonst nur den aktuellen Tag per AJAX aktualisieren */
        function scheduleRefresh() {
            setTimeout(function () {
                var datePanel = dashboard.querySelector('#availio-date');
                var dateActive = datePanel &&
                    !datePanel.classList.contains('availio-panel--hidden');

                if (dateActive) {
                    // Datepicker aktiv → nur diesen Tag neu laden, dann neu planen
                    loadDate(currentDate);
                    scheduleRefresh();
                } else {
                    // Heute-Tab aktiv → normaler Seiten-Reload
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
