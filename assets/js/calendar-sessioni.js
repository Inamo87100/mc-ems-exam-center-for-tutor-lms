/**
 * MC-EMS Base – Calendar Sessioni (Proctor Calendar)
 *
 * Provides a lightweight month-view calendar for admin/proctor users to
 * visualise exam sessions, colour-coded by status, with a popup showing
 * booked candidates when a session cell is clicked.
 *
 * Depends on: MCEMEXCE_CAL (localised via wp_localize_script)
 *   - MCEMEXCE_CAL.ajaxUrl
 *   - MCEMEXCE_CAL.nonce
 *   - MCEMEXCE_CAL.i18n.*
 */
/* global MCEMEXCE_CAL */
(function () {
    'use strict';

    /* ----------------------------------------------------------
       Helpers
       ---------------------------------------------------------- */
    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function i18n(key, fallback) {
        if (typeof MCEMEXCE_CAL !== 'undefined' && MCEMEXCE_CAL.i18n && MCEMEXCE_CAL.i18n[key]) {
            return MCEMEXCE_CAL.i18n[key];
        }
        return fallback || key;
    }

    function ajaxGet(params) {
        if (typeof MCEMEXCE_CAL === 'undefined') {
            return Promise.reject(new Error('MCEMEXCE_CAL not defined'));
        }
        var qs_str = new URLSearchParams(
            Object.assign({ _ajax_nonce: MCEMEXCE_CAL.nonce }, params)
        ).toString();
        return fetch(MCEMEXCE_CAL.ajaxUrl + '?' + qs_str, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    /* ----------------------------------------------------------
       State
       ---------------------------------------------------------- */
    var state = {
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,  // 1-based
        sessions: [],                       // raw data from AJAX
        filters: { exam: '', status: '', proctor: '' }
    };

    /* ----------------------------------------------------------
       Calendar rendering
       ---------------------------------------------------------- */
    var WEEKDAYS = [
        i18n('mon', 'Mon'), i18n('tue', 'Tue'), i18n('wed', 'Wed'),
        i18n('thu', 'Thu'), i18n('fri', 'Fri'), i18n('sat', 'Sat'), i18n('sun', 'Sun')
    ];

    /** Map session status → CSS class suffix */
    var STATUS_CLASS = {
        available: 'available',
        limited:   'limited',
        full:      'full',
        past:      'past'
    };

    /** Build a YYYY-MM-DD key for a Date object */
    function dateKey(y, m, d) {
        return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    /** Group sessions by date key */
    function groupByDate(sessions) {
        var map = {};
        sessions.forEach(function (s) {
            var k = (s.date || '').substring(0, 10);
            if (!map[k]) map[k] = [];
            map[k].push(s);
        });
        return map;
    }

    /** Decide availability status for a group of sessions on one day */
    function dayStatus(sessions) {
        if (!sessions || !sessions.length) return null;
        var now = Date.now();
        var allPast = sessions.every(function (s) {
            return s.timestamp && s.timestamp * 1000 < now;
        });
        if (allPast) return 'past';

        var totalCap = sessions.reduce(function (acc, s) { return acc + (s.capacity || 0); }, 0);
        var booked   = sessions.reduce(function (acc, s) { return acc + (s.booked || 0); }, 0);

        if (totalCap === 0) return 'full';
        var pct = booked / totalCap;
        if (pct >= 1)    return 'full';
        if (pct >= 0.75) return 'limited';
        return 'available';
    }

    function renderCalendar(container, byDate) {
        container.innerHTML = '';

        // Header: weekdays
        var header = document.createElement('div');
        header.className = 'mcemexce-cal-weekdays';
        WEEKDAYS.forEach(function (day) {
            var cell = document.createElement('div');
            cell.className = 'mcemexce-cal-weekday';
            cell.textContent = day;
            header.appendChild(cell);
        });
        container.appendChild(header);

        // Grid
        var grid = document.createElement('div');
        grid.className = 'mcemexce-cal-grid';
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:4px;max-width:420px;margin:0 auto;';

        var firstDay = new Date(state.year, state.month - 1, 1).getDay(); // 0=Sun
        // Convert to Mon-based (0=Mon)
        var offset = (firstDay + 6) % 7;

        var daysInMonth = new Date(state.year, state.month, 0).getDate();

        // Empty cells before first day
        for (var i = 0; i < offset; i++) {
            grid.appendChild(document.createElement('div'));
        }

        var today = new Date();
        var todayKey = dateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());

        for (var d = 1; d <= daysInMonth; d++) {
            var key = dateKey(state.year, state.month, d);
            var dayData = byDate[key] || [];
            var status = dayStatus(dayData);

            var cell = document.createElement('div');
            cell.className = 'mcemexce-cal-day-cell';
            cell.textContent = d;

            if (status) {
                cell.classList.add('mcemexce-cal-' + (STATUS_CLASS[status] || status));
            }

            if (key === todayKey) {
                cell.style.outline = '2px solid #2271b1';
            }

            if (dayData.length) {
                cell.style.cursor = 'pointer';
                cell.setAttribute('role', 'button');
                cell.setAttribute('tabindex', '0');
                cell.setAttribute('aria-label', i18n('sessions', 'Sessions') + ': ' + dayData.length + ' – ' + key);
                (function (data) {
                    cell.addEventListener('click', function () { openPopup(data); });
                    cell.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPopup(data); }
                    });
                })(dayData);
            }

            grid.appendChild(cell);
        }

        container.appendChild(grid);
    }

    /* ----------------------------------------------------------
       Popup (session detail)
       ---------------------------------------------------------- */
    function openPopup(sessions) {
        var overlay = qs('#mcemexce-cal-popup-overlay');
        var body    = qs('#mcemexce-cal-popup-body');
        if (!overlay || !body) return;

        body.innerHTML = '';

        sessions.forEach(function (s) {
            var item = document.createElement('div');
            item.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:10px;';

            var title = document.createElement('strong');
            title.textContent = (s.time || '') + (s.exam_title ? ' — ' + s.exam_title : '');
            item.appendChild(title);

            var meta = document.createElement('p');
            meta.style.margin = '6px 0 0';
            meta.style.fontSize = '13px';
            meta.innerHTML =
                i18n('capacity', 'Capacity') + ': <strong>' + (s.capacity || 0) + '</strong> &nbsp;|&nbsp; ' +
                i18n('booked',   'Booked')   + ': <strong>' + (s.booked   || 0) + '</strong>';
            item.appendChild(meta);

            if (s.proctor_name) {
                var proctor = document.createElement('p');
                proctor.style.margin = '4px 0 0';
                proctor.style.fontSize = '12px';
                proctor.style.color = '#667085';
                proctor.textContent = i18n('proctor', 'Proctor') + ': ' + s.proctor_name;
                item.appendChild(proctor);
            }

            if (s.candidates && s.candidates.length) {
                var listTitle = document.createElement('p');
                listTitle.style.cssText = 'margin:8px 0 4px;font-size:12px;font-weight:600;color:#3c434a;';
                listTitle.textContent = i18n('candidates', 'Candidates') + ':';
                item.appendChild(listTitle);

                var ul = document.createElement('ul');
                ul.style.cssText = 'margin:0;padding-left:18px;font-size:12px;';
                s.candidates.forEach(function (c) {
                    var li = document.createElement('li');
                    li.textContent = (c.name || '') + (c.email ? ' <' + c.email + '>' : '');
                    ul.appendChild(li);
                });
                item.appendChild(ul);
            }

            body.appendChild(item);
        });

        overlay.classList.add('open');
    }

    /* ----------------------------------------------------------
       Popup close
       ---------------------------------------------------------- */
    function initPopup() {
        var overlay = qs('#mcemexce-cal-popup-overlay');
        if (!overlay) return;

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });

        var closeBtn = qs('.mcemexce-cal-popup-close', overlay);
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                overlay.classList.remove('open');
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') overlay.classList.remove('open');
        });
    }

    /* ----------------------------------------------------------
       Filters
       ---------------------------------------------------------- */
    function initFilters(onFilter) {
        var examSelect  = qs('#mcemexce-cal-filter-exam');
        var statusSelect  = qs('#mcemexce-cal-filter-status');
        var proctorSelect = qs('#mcemexce-cal-filter-proctor');

        function applyFilter() {
            state.filters.exam  = examSelect  ? examSelect.value  : '';
            state.filters.status  = statusSelect  ? statusSelect.value  : '';
            state.filters.proctor = proctorSelect ? proctorSelect.value : '';
            onFilter();
        }

        [examSelect, statusSelect, proctorSelect].forEach(function (el) {
            if (el) el.addEventListener('change', applyFilter);
        });

        var clearBtn = qs('#mcemexce-cal-filter-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (examSelect)  examSelect.selectedIndex  = 0;
                if (statusSelect)  statusSelect.selectedIndex  = 0;
                if (proctorSelect) proctorSelect.selectedIndex = 0;
                state.filters = { exam: '', status: '', proctor: '' };
                onFilter();
            });
        }
    }

    /* ----------------------------------------------------------
       Filter sessions list based on current filters
       ---------------------------------------------------------- */
    function filterSessions(sessions) {
        return sessions.filter(function (s) {
            if (state.filters.exam  && String(s.exam_id)  !== state.filters.exam)  return false;
            if (state.filters.status  && s.status             !== state.filters.status)  return false;
            if (state.filters.proctor && String(s.proctor_id) !== state.filters.proctor) return false;
            return true;
        });
    }

    /* ----------------------------------------------------------
       Navigation
       ---------------------------------------------------------- */
    function initNav(onNavChange) {
        var prevBtn     = qs('#mcemexce-sessioni-prev');
        var nextBtn     = qs('#mcemexce-sessioni-next');
        var monthLabel  = qs('#mcemexce-sessioni-month-year');

        function updateLabel() {
            if (!monthLabel) return;
            var d = new Date(state.year, state.month - 1, 1);
            var locale = (typeof MCEMEXCE_CAL !== 'undefined' && MCEMEXCE_CAL.locale) ? MCEMEXCE_CAL.locale : undefined;
            monthLabel.textContent = d.toLocaleDateString(
                locale || undefined,
                { month: 'long', year: 'numeric' }
            );
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                state.month--;
                if (state.month < 1) { state.month = 12; state.year--; }
                updateLabel();
                onNavChange();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                state.month++;
                if (state.month > 12) { state.month = 1; state.year++; }
                updateLabel();
                onNavChange();
            });
        }

        updateLabel();
    }

    /* ----------------------------------------------------------
       Load data and refresh
       ---------------------------------------------------------- */
    function loadAndRender(container) {
        container.innerHTML = '<div style="text-align:center;padding:20px;"><span class="mcemexce-spinner"></span> ' + i18n('loading', 'Loading…') + '</div>';

        ajaxGet({
            action: 'mcemexce_get_all_assigned_slots',
            year:   state.year,
            month:  state.month
        }).then(function (res) {
            if (!res || !res.success) {
                container.innerHTML = '<p style="color:#f44336;">' + i18n('loadError', 'Error loading calendar data.') + '</p>';
                return;
            }
            state.sessions = res.data || [];
            var filtered = filterSessions(state.sessions);
            renderCalendar(container, groupByDate(filtered));
        }).catch(function () {
            container.innerHTML = '<p style="color:#f44336;">' + i18n('loadError', 'Error loading calendar data.') + '</p>';
        });
    }

    /* ----------------------------------------------------------
       Legend
       ---------------------------------------------------------- */
    function renderLegend(legendEl) {
        if (!legendEl) return;
        var items = [
            { cls: 'available', label: i18n('available', 'Available') },
            { cls: 'limited',   label: i18n('limited',   'Limited')   },
            { cls: 'full',      label: i18n('full',      'Full')      },
            { cls: 'past',      label: i18n('past',      'Past')      }
        ];
        legendEl.innerHTML = '';
        items.forEach(function (item) {
            var span = document.createElement('span');
            span.className = 'mcemexce-cal-legend-item';
            span.innerHTML =
                '<span class="mcemexce-cal-legend-dot mcemexce-cal-' + item.cls + '"></span>' +
                ' ' + item.label;
            legendEl.appendChild(span);
        });
    }

    /* ----------------------------------------------------------
       Init
       ---------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        var wrap      = qs('#mcemexce-sessioni-calendar-wrap');
        var container = qs('#mcemexce-sessioni-calendar');
        var legendEl  = qs('#mcemexce-sessioni-legend');

        if (!container) return;

        renderLegend(legendEl);
        initPopup();

        initNav(function () { loadAndRender(container); });
        initFilters(function () {
            var filtered = filterSessions(state.sessions);
            renderCalendar(container, groupByDate(filtered));
        });

        loadAndRender(container);
    });

})();
