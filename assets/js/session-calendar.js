/**
 * MC-EMS Base – Session Assignment Calendar
 *
 * Handles the interactive session calendar rendered by the
 * [mcemexce_sessions_calendar] shortcode, including modal slot details,
 * proctor assignment, and the "all sessions" overview.
 *
 * Depends on: MCEMEXCE_CAL (localised via wp_localize_script)
 *   - MCEMEXCE_CAL.ajaxUrl
 *   - MCEMEXCE_CAL.nonce
 *   - MCEMEXCE_CAL.isLoggedIn
 *   - MCEMEXCE_CAL.i18n.*
 */
/* global MCEMEXCE_CAL */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof MCEMEXCE_CAL === 'undefined') return;

    var calendar       = document.getElementById('calendar');
    var modal          = document.getElementById('slotModal');
    var modalData      = document.getElementById('modalData');
    var modalSlotInfo  = document.getElementById('modalSlotInfo');
    var closeModal     = document.querySelector('.close');

    var myModal  = document.getElementById('mySessionsModal');
    var myBody   = document.getElementById('mySessionsBody');
    var closeMy  = document.querySelector('.close-my');
    var openMy   = document.getElementById('openMySessions');

    var allModal      = document.getElementById('allAssignmentsModal');
    var allBody       = document.getElementById('allAssignmentsBody');
    var closeAll      = document.querySelector('.close-all');
    var openAll       = document.getElementById('openAllAssignments');
    var allMonth      = document.getElementById('allMonth');
    var allYear       = document.getElementById('allYear');
    var reloadAllBtn  = document.getElementById('reloadAllAssignments');

    if (!calendar) return;

    var AJAX_URL    = MCEMEXCE_CAL.ajaxUrl;
    var AJAX_NONCE  = MCEMEXCE_CAL.nonce;
    var IS_LOGGED_IN = !!MCEMEXCE_CAL.isLoggedIn;

    var today        = new Date();
    var currentMonth = today.getMonth();
    var currentYear  = today.getFullYear();
    var cacheSlots   = {};

    function formatDate(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function formatDateIT(dateStr) {
        var parts = dateStr.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function dmyToISO(dmy) {
        var parts = dmy.split('/');
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    function isoFromModal() {
        var el  = document.getElementById('modalData');
        var txt = (el ? el.textContent : '').trim();
        return (txt && txt.indexOf('/') !== -1) ? dmyToISO(txt) : null;
    }

    function monthKeyFromISO(dateISO) {
        var parts = dateISO.split('-');
        return parseInt(parts[0], 10) + '-' + (parseInt(parts[1], 10) - 1);
    }

    function updateCacheAssign(slotId, dateISO, name) {
        if (!dateISO) return;
        var key   = monthKeyFromISO(dateISO);
        var cache = cacheSlots[key];
        if (!cache || !cache[dateISO] || !Array.isArray(cache[dateISO].slots)) return;
        var item = cache[dateISO].slots.find(function (s) { return String(s.id) === String(slotId); });
        if (item) {
            item.assegnato = true;
            item.assegnato_nome = name || '';
            item.assegnato_user = 'me';
        }
    }

    function updateCacheUnassign(slotId, dateISO) {
        if (!dateISO) return;
        var key   = monthKeyFromISO(dateISO);
        var cache = cacheSlots[key];
        if (!cache || !cache[dateISO] || !Array.isArray(cache[dateISO].slots)) return;
        var item = cache[dateISO].slots.find(function (s) { return String(s.id) === String(slotId); });
        if (item) {
            item.assegnato = false;
            item.assegnato_nome = null;
            item.assegnato_user = null;
        }
    }

    function specialBadgeHTML(isSpecial) {
        return isSpecial ? '<span class="nf-es-badge">&#9855;&nbsp;Yes</span>' : '';
    }

    function fetchMonthData(year, month) {
        var key = year + '-' + month;
        if (cacheSlots[key]) return Promise.resolve(cacheSlots[key]);
        return fetch(AJAX_URL + '?action=mcemexce_get_slot_data&year=' + year + '&month=' + (month + 1) + '&_ajax_nonce=' + encodeURIComponent(AJAX_NONCE))
            .then(function (r) { return r.json(); })
            .then(function (data) { cacheSlots[key] = data || {}; return cacheSlots[key]; });
    }

    function renderCalendar(year, month) {
        var monthYearEl = document.getElementById('monthYear');
        if (monthYearEl) {
            monthYearEl.textContent = new Date(year, month).toLocaleString('en-US', { month: 'long', year: 'numeric' });
        }

        calendar.innerHTML = '';

        var firstDay = new Date(year, month, 1);
        var startDay = firstDay.getDay();
        startDay = (startDay === 0) ? 6 : startDay - 1;
        var daysInMonth = new Date(year, month + 1, 0).getDate();

        for (var i = 0; i < startDay; i++) calendar.appendChild(document.createElement('div'));

        for (var day = 1; day <= daysInMonth; day++) {
            var date  = new Date(year, month, day);
            var dayEl = document.createElement('div');
            dayEl.classList.add('calendar-day');
            dayEl.textContent = day;
            dayEl.dataset.date = formatDate(date);
            calendar.appendChild(dayEl);
        }

        fetchMonthData(year, month).then(function (data) {
            document.querySelectorAll('.calendar-day').forEach(function (dayEl) {
                var d = dayEl.dataset.date;
                if (data[d]) {
                    var dayObj = data[d];
                    var liberi = dayObj.totali - dayObj.prenotati;

                    if (dayObj.totali === 0)                    dayEl.classList.add('no-slot');
                    else if (liberi === dayObj.totali)          dayEl.classList.add('slot-verde');
                    else if (liberi >= dayObj.totali * 0.5)    dayEl.classList.add('slot-giallo');
                    else if (liberi > 0)                        dayEl.classList.add('slot-arancione');
                    else                                        dayEl.classList.add('slot-rosso');

                    dayEl.addEventListener('click', function () {
                        if (modalData) modalData.textContent = formatDateIT(d);

                        var rows = (dayObj.slots || [])
                            .slice()
                            .sort(function (a, b) { return (a.ora || '').localeCompare(b.ora || ''); })
                            .map(function (s) {
                                var assigned = (s.assegnato && s.assegnato_nome)
                                    ? MCEMEXCE_CAL.i18n.assignedTo + ' ' + s.assegnato_nome
                                    : null;

                                var btnAssegna = (!assigned && IS_LOGGED_IN)
                                    ? '<button class="btn-assegna" data-slot="' + s.id + '" data-data="' + d + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.assignSession + '</button>'
                                    : '';

                                var buttonsIfAssigned = (assigned && IS_LOGGED_IN)
                                    ? '<div class="actions">'
                                        + '<span class="badge-soft">' + assigned + '</span>'
                                        + '<button class="btn-modifica" data-slot="' + s.id + '" data-data="' + d + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.reassign + '</button>'
                                        + '<button class="btn-elimina" data-slot="' + s.id + '" data-data="' + d + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.removeAssignment + '</button>'
                                        + '</div>'
                                    : '';

                                var right = assigned
                                    ? buttonsIfAssigned
                                    : (IS_LOGGED_IN ? '<div class="slot-actions">' + btnAssegna + '</div>' : '<span class="muted">Log in to assign yourself</span>');

                                var oraHtml = '<strong>' + (s.ora || '') + '</strong>'
                                    + '<span class="slot-id">ID: ' + s.id + '</span>'
                                    + specialBadgeHTML(!!s.speciale);

                                return '<div class="slot-row" id="slot-row-' + s.id + '">'
                                    + '<div class="slot-meta">'
                                    + '<div>' + oraHtml + '</div>'
                                    + (s.exam_title ? '<div class="muted"><strong>' + MCEMEXCE_CAL.i18n.examLabel + '</strong> ' + s.exam_title + '</div>' : '')
                                    + '<div class="muted">' + s.prenotati + '/' + s.totali + ' ' + MCEMEXCE_CAL.i18n.seatsOccupied + '</div>'
                                    + '</div>'
                                    + right
                                    + '</div>';
                            }).join('');

                        if (modalSlotInfo) modalSlotInfo.innerHTML = rows || '<p class="notice">' + MCEMEXCE_CAL.i18n.noSessionsOnDate + '</p>';
                        if (modal) { modal.style.display = 'block'; modal.setAttribute('aria-hidden', 'false'); }
                    });
                } else {
                    dayEl.classList.add('no-slot');
                }
            });
        });
    }

    renderCalendar(currentYear, currentMonth);

    var prevBtn = document.getElementById('prevMonth');
    var nextBtn = document.getElementById('nextMonth');

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            currentMonth--;
            if (currentMonth < 0) { currentMonth = 11; currentYear--; }
            renderCalendar(currentYear, currentMonth);
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            currentMonth++;
            if (currentMonth > 11) { currentMonth = 0; currentYear++; }
            renderCalendar(currentYear, currentMonth);
        });
    }

    if (closeModal) {
        closeModal.addEventListener('click', function () {
            if (modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
        });
    }
    window.addEventListener('click', function (e) {
        if (e.target === modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
    });

    if (openMy) {
        openMy.addEventListener('click', function () {
            if (!IS_LOGGED_IN) { alert(MCEMEXCE_CAL.i18n.mustBeLoggedInView); return; }
            if (myBody) myBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.loadingDots + '</p>';
            fetch(AJAX_URL + '?action=mcemexce_get_user_assigned_slots&_ajax_nonce=' + encodeURIComponent(AJAX_NONCE))
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json || !json.success) {
                        if (myBody) myBody.innerHTML = '<p class="notice">' + ((json && json.data && json.data.message) ? json.data.message : MCEMEXCE_CAL.i18n.unableToLoad) + '</p>';
                        return;
                    }
                    var items = json.data || [];
                    if (!items.length) {
                        if (myBody) myBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.noAssignedSessions + '</p>';
                        return;
                    }
                    if (myBody) {
                        myBody.innerHTML = items.map(function (s) {
                            return '<div class="slot-row" id="myslot-' + s.id + '" data-date="' + s.data + '">'
                                + '<div class="slot-meta">'
                                + '<div><strong>' + s.data_it + '</strong></div>'
                                + '<div><strong>' + s.ora + '</strong> ' + (s.speciale ? specialBadgeHTML(true) : '') + '</div>'
                                + (s.exam_title ? '<div class="muted"><strong>' + MCEMEXCE_CAL.i18n.examLabel + '</strong> ' + s.exam_title + '</div>' : '')
                                + '</div>'
                                + '<div class="actions"><button class="btn-elimina" data-slot="' + s.id + '" data-data="' + s.data + '">' + MCEMEXCE_CAL.i18n.removeAssignment + '</button></div>'
                                + '</div>';
                        }).join('');
                    }
                })
                .catch(function () {
                    if (myBody) myBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.networkError + '</p>';
                });

            if (myModal) { myModal.style.display = 'block'; myModal.setAttribute('aria-hidden', 'false'); }
        });
    }

    if (closeMy) {
        closeMy.addEventListener('click', function () {
            if (myModal) { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden', 'true'); }
        });
    }
    window.addEventListener('click', function (e) {
        if (e.target === myModal) { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden', 'true'); }
    });

    function loadAllAssignments() {
        if (allBody) allBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.loadingDots + '</p>';
        var y = allYear ? allYear.value : '';
        var m = allMonth ? allMonth.value : '';
        fetch(AJAX_URL + '?action=mcemexce_get_all_assigned_slots&year=' + encodeURIComponent(y) + '&month=' + encodeURIComponent(m) + '&_ajax_nonce=' + encodeURIComponent(AJAX_NONCE))
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    if (allBody) allBody.innerHTML = '<p class="notice">' + ((json && json.data && json.data.message) ? json.data.message : MCEMEXCE_CAL.i18n.unableToLoad) + '</p>';
                    return;
                }
                var items = json.data || [];
                if (!items.length) {
                    if (allBody) allBody.innerHTML = '<p class="notice">No sessions found for the selected period.</p>';
                    return;
                }
                if (allBody) {
                    allBody.innerHTML = items.map(function (s) {
                        var actionHTML = s.assegnato
                            ? '<div class="actions">'
                                + '<span class="badge-soft">' + MCEMEXCE_CAL.i18n.assignedTo + ' ' + s.assegnato_nome + '</span>'
                                + '<button class="btn-modifica" data-slot="' + s.id + '" data-data="' + s.data + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.reassign + '</button>'
                                + '<button class="btn-elimina" data-slot="' + s.id + '" data-data="' + s.data + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.removeAssignment + '</button>'
                                + '</div>'
                            : '<div class="slot-actions"><button class="btn-assegna" data-slot="' + s.id + '" data-data="' + s.data + '" data-ora="' + s.ora + '">' + MCEMEXCE_CAL.i18n.assignSession + '</button></div>';

                        return '<div class="slot-row" id="allslot-' + s.id + '">'
                            + '<div class="slot-meta">'
                            + '<div><strong>' + s.data_it + '</strong></div>'
                            + '<div><strong>' + s.ora + '</strong> <span class="slot-id">ID: ' + s.id + '</span> ' + (s.speciale ? specialBadgeHTML(true) : '') + '</div>'
                            + (s.exam_title ? '<div class="muted"><strong>' + MCEMEXCE_CAL.i18n.examLabel + '</strong> ' + s.exam_title + '</div>' : '')
                            + '</div>'
                            + actionHTML
                            + '</div>';
                    }).join('');
                }
            })
            .catch(function () {
                if (allBody) allBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.networkError + '</p>';
            });
    }

    if (openAll) {
        openAll.addEventListener('click', function () {
            loadAllAssignments();
            if (allModal) { allModal.style.display = 'block'; allModal.setAttribute('aria-hidden', 'false'); }
        });
    }
    if (reloadAllBtn) reloadAllBtn.addEventListener('click', loadAllAssignments);
    if (closeAll) {
        closeAll.addEventListener('click', function () {
            if (allModal) { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden', 'true'); }
        });
    }
    window.addEventListener('click', function (e) {
        if (e.target === allModal) { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden', 'true'); }
    });

    /* ---- Delegate: Assign (btn-assegna) ---- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-assegna');
        if (!btn) return;
        e.preventDefault();

        if (!IS_LOGGED_IN) { alert(MCEMEXCE_CAL.i18n.mustBeLoggedInAssign); return; }
        var slotId = btn.getAttribute('data-slot');

        btn.disabled = true; btn.textContent = MCEMEXCE_CAL.i18n.assigning;
        var form = new FormData();
        form.append('action', 'mcemexce_assegna_sessione_slot');
        form.append('slot_id', slotId);
        form.append('_ajax_nonce', AJAX_NONCE);

        fetch(AJAX_URL, { method: 'POST', body: form })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    var nome    = (json.data && json.data.assegnato_nome) ? json.data.assegnato_nome : 'assigned';
                    var dateISO = btn.getAttribute('data-data') || isoFromModal();

                    var row = document.getElementById('slot-row-' + slotId);
                    if (row) {
                        var sa = row.querySelector('.slot-actions');
                        if (sa) sa.remove();
                        row.insertAdjacentHTML('beforeend',
                            '<div class="actions">'
                            + '<span class="badge-soft">' + MCEMEXCE_CAL.i18n.assignedTo + ' ' + nome + '</span>'
                            + '<button class="btn-modifica" data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.reassign + '</button>'
                            + '<button class="btn-elimina"  data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.removeAssignment + '</button>'
                            + '</div>');
                    }

                    var rowAll = document.getElementById('allslot-' + slotId);
                    if (rowAll) {
                        var meta = rowAll.querySelector('.slot-meta');
                        rowAll.innerHTML = '';
                        rowAll.appendChild(meta.cloneNode(true));
                        rowAll.insertAdjacentHTML('beforeend',
                            '<div class="actions">'
                            + '<span class="badge-soft">' + MCEMEXCE_CAL.i18n.assignedTo + ' ' + nome + '</span>'
                            + '<button class="btn-modifica" data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.reassign + '</button>'
                            + '<button class="btn-elimina"  data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.removeAssignment + '</button>'
                            + '</div>');
                    }

                    updateCacheAssign(slotId, dateISO, nome);
                } else {
                    alert((json && json.data && json.data.message) ? json.data.message : MCEMEXCE_CAL.i18n.unableToAssign);
                    btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.assignSession;
                }
            })
            .catch(function () {
                alert(MCEMEXCE_CAL.i18n.networkError);
                btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.assignSession;
            });
    });

    /* ---- Delegate: Reassign (btn-modifica) ---- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-modifica');
        if (!btn) return;
        e.preventDefault();

        if (!IS_LOGGED_IN) { alert(MCEMEXCE_CAL.i18n.mustBeLoggedInModify); return; }
        var slotId = btn.getAttribute('data-slot');
        if (!confirm(MCEMEXCE_CAL.i18n.confirmReassign)) return;

        btn.disabled = true; btn.textContent = MCEMEXCE_CAL.i18n.reassigning;
        var form = new FormData();
        form.append('action', 'mcemexce_modifica_assegnazione_sessione_slot');
        form.append('slot_id', slotId);
        form.append('_ajax_nonce', AJAX_NONCE);

        fetch(AJAX_URL, { method: 'POST', body: form })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    var nome    = (json.data && json.data.nuovo_nome) ? json.data.nuovo_nome : 'assigned';
                    var dateISO = btn.getAttribute('data-data') || isoFromModal();

                    var row = document.getElementById('slot-row-' + slotId);
                    if (row) {
                        var ac = row.querySelector('.actions');
                        if (ac) {
                            var badge = ac.querySelector('.badge-soft');
                            if (badge) badge.textContent = MCEMEXCE_CAL.i18n.assignedTo + ' ' + nome;
                        }
                    }

                    var rowAll = document.getElementById('allslot-' + slotId);
                    if (rowAll) {
                        var acAll = rowAll.querySelector('.actions');
                        if (acAll) {
                            var badgeAll = acAll.querySelector('.badge-soft');
                            if (badgeAll) badgeAll.textContent = MCEMEXCE_CAL.i18n.assignedTo + ' ' + nome;
                        }
                    }

                    updateCacheAssign(slotId, dateISO, nome);
                    btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.reassign;
                } else {
                    alert((json && json.data && json.data.message) ? json.data.message : MCEMEXCE_CAL.i18n.unableToReassign);
                    btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.reassign;
                }
            })
            .catch(function () {
                alert(MCEMEXCE_CAL.i18n.networkError);
                btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.reassign;
            });
    });

    /* ---- Delegate: Remove assignment (btn-elimina) ---- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-elimina');
        if (!btn) return;
        e.preventDefault();

        if (!IS_LOGGED_IN) { alert(MCEMEXCE_CAL.i18n.mustBeLoggedInRemove); return; }
        var slotId = btn.getAttribute('data-slot');
        if (!confirm(MCEMEXCE_CAL.i18n.confirmRemove)) return;

        btn.disabled = true; btn.textContent = MCEMEXCE_CAL.i18n.removing;
        var form = new FormData();
        form.append('action', 'mcemexce_elimina_assegnazione_sessione_slot');
        form.append('slot_id', slotId);
        form.append('_ajax_nonce', AJAX_NONCE);

        fetch(AJAX_URL, { method: 'POST', body: form })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    var dateISO = btn.getAttribute('data-data') || isoFromModal();

                    var row1 = document.getElementById('slot-row-' + slotId);
                    if (row1) {
                        var ac = row1.querySelector('.actions');
                        if (ac) ac.remove();
                        row1.insertAdjacentHTML('beforeend',
                            '<div class="slot-actions"><button class="btn-assegna" data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.assignSession + '</button></div>');
                    }

                    var row2 = document.getElementById('myslot-' + slotId);
                    if (row2) {
                        row2.remove();
                        if (myBody && !myBody.querySelector('.slot-row')) {
                            myBody.innerHTML = '<p class="notice">' + MCEMEXCE_CAL.i18n.noAssignedSessions + '</p>';
                        }
                    }

                    var row3 = document.getElementById('allslot-' + slotId);
                    if (row3) {
                        var meta = row3.querySelector('.slot-meta');
                        row3.innerHTML = '';
                        row3.appendChild(meta.cloneNode(true));
                        row3.insertAdjacentHTML('beforeend',
                            '<div class="slot-actions"><button class="btn-assegna" data-slot="' + slotId + '" data-data="' + dateISO + '">' + MCEMEXCE_CAL.i18n.assignSession + '</button></div>');
                    }

                    updateCacheUnassign(slotId, dateISO);
                } else {
                    alert((json && json.data && json.data.message) ? json.data.message : MCEMEXCE_CAL.i18n.unableToRemove);
                    btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.removeAssignment;
                }
            })
            .catch(function () {
                alert(MCEMEXCE_CAL.i18n.networkError);
                btn.disabled = false; btn.textContent = MCEMEXCE_CAL.i18n.removeAssignment;
            });
    });
});
