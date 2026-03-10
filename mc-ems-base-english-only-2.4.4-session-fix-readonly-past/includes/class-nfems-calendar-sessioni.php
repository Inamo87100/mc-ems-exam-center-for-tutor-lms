<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Calendar_Sessioni {

    const NONCE_ACTION = 'nfems_cal_sessioni_nonce';

    public static function init() {
        add_shortcode('mcems_sessions_calendar', [__CLASS__, 'shortcode']);

        add_action('wp_ajax_nfems_cal_get_month', [__CLASS__, 'ajax_get_month']);
        add_action('wp_ajax_nfems_cal_get_my', [__CLASS__, 'ajax_get_my']);
        add_action('wp_ajax_nfems_cal_get_all', [__CLASS__, 'ajax_get_all']);

        add_action('wp_ajax_nfems_cal_assign', [__CLASS__, 'ajax_assign']);
        add_action('wp_ajax_nfems_cal_reassign', [__CLASS__, 'ajax_reassign']);
        add_action('wp_ajax_nfems_cal_unassign', [__CLASS__, 'ajax_unassign']);
    }

    private static function cap_ok(): bool {
        $cap = (string) nfems_settings('cap_assign_proctor', 'nfems_assign_proctor');
        return is_user_logged_in() && (current_user_can($cap) || current_user_can('manage_options'));
    }

    private static function nonce(): string {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    private static function ajax_guard() {
        $nonce = isset($_REQUEST['_ajax_nonce']) ? sanitize_text_field($_REQUEST['_ajax_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Nonce non valido.'], 403);
        }
    }

    public static function shortcode() {
        $nonce = self::nonce();
        $is_logged = is_user_logged_in() ? 'true' : 'false';
        $can_assign = self::cap_ok() ? 'true' : 'false';

        $allow_reassign = nfems_settings('cal_allow_reassign', 1) ? 'true' : 'false';
        $allow_unassign = nfems_settings('cal_allow_unassign', 1) ? 'true' : 'false';

        $curM = (int) wp_date('n');
        $curY = (int) wp_date('Y');

        ob_start(); ?>
        <div class="calendar-wrapper">
            <div class="calendar-nav">
                <button id="prevMonth" aria-label="Previous month">&larr;</button>
                <span id="monthYear"></span>
                <button id="nextMonth" aria-label="Next month">&rarr;</button>
            </div>

            <div class="calendar-legend">
              <span class="legend-item slot-verde">All available</span>
              <span class="legend-item slot-giallo">High availability</span>
              <span class="legend-item slot-arancione">Low availability</span>
              <span class="legend-item slot-rosso">Full</span>
            </div>

            <div class="calendar-header">
                <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
            </div>
            <div id="calendar"></div>

            <div class="my-sessions-wrap">
                <div class="btns-stack">
                    <button id="openMySessions" class="btn-outline">View your assigned sessions</button>
                    <button id="openAllAssignments" class="btn-outline">View all sessions</button>
                </div>
            </div>
        </div>

        <!-- Modale: sessioni per giorno -->
        <div id="slotModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <span class="close" role="button" aria-label="Close">&times;</span>
                <h2 id="modalTitle">Sessions on <span id="modalData"></span></h2>
                <div id="modalSlotInfo" class="scrollable"></div>
            </div>
        </div>

        <!-- Modale: mie sessioni assegnate -->
        <div id="mySessionsModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="mySessionsTitle">
                <span class="close close-my" role="button" aria-label="Close">&times;</span>
                <h2 id="mySessionsTitle">My assigned sessions</h2>
                <div id="mySessionsBody" class="scrollable"></div>
            </div>
        </div>

        <!-- Modale: tutte le sessioni -->
        <div id="allAssignmentsModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="allAssignmentsTitle">
                <span class="close close-all" role="button" aria-label="Close">&times;</span>
                <h2 id="allAssignmentsTitle">All sessions</h2>

                <div class="filters-row">
                    <label>
                        Month
                        <select id="allMonth">
                            <?php
                            $mesi = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                            foreach ($mesi as $num=>$nome) {
                                printf('<option value="%d"%s>%s</option>', $num, selected($curM, $num, false), esc_html($nome));
                            }
                            ?>
                        </select>
                    </label>
                    <label>
                        Year
                        <select id="allYear">
                            <?php for ($y=$curY-2; $y<=$curY+2; $y++): ?>
                                <option value="<?php echo (int)$y; ?>" <?php selected($curY, $y); ?>><?php echo (int)$y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <button id="reloadAllAssignments" class="btn-outline">Refresh</button>
                </div>

                <div id="allAssignmentsBody" class="scrollable"></div>
            </div>
        </div>

        <style>
            .calendar-wrapper{ max-width:980px; margin:0 auto; }
            .calendar-nav{ display:flex; align-items:center; justify-content:space-between; margin:12px 0; }
            .calendar-nav button{ border:1px solid #ddd; background:#fff; padding:8px 12px; border-radius:10px; cursor:pointer; }
            #monthYear{ font-weight:900; }

            .calendar-legend{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:center; margin:10px 0 12px; }
            .legend-item{ display:inline-flex; align-items:center; padding:8px 12px; border-radius:999px; border:1px solid #e5e7eb; font-weight:900; font-size:13px; }

            .calendar-header, #calendar{ display:grid; grid-template-columns:repeat(7,1fr); gap:8px; }
            .calendar-header div{ text-align:center; font-weight:800; padding:8px 0; color:#374151; }
            .calendar-day{ min-height:64px; padding:10px; border-radius:14px; border:1px solid #e5e7eb; background:#fff; cursor:pointer; display:flex; align-items:flex-start; justify-content:flex-start; }
            .calendar-day.no-slot{ background:#f9fafb; color:#9ca3af; cursor:default; }

            .slot-verde{ background:#dcfce7; border-color:#22c55e; }
            .slot-giallo{ background:#fef9c3; border-color:#eab308; }
            .slot-arancione{ background:#ffedd5; border-color:#f97316; }
            .slot-rosso{ background:#fee2e2; border-color:#ef4444; }

            .my-sessions-wrap{ margin-top:14px; display:flex; justify-content:center; }
            .btns-stack{ display:flex; gap:10px; flex-wrap:wrap; }
            .btn-outline{ border:1px solid #cbd5e1; background:#fff; padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:800; }

            .modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:99999; }
            .modal-content{ background:#fff; max-width:860px; margin:6vh auto; padding:18px; border-radius:18px; box-shadow:0 18px 50px rgba(0,0,0,.2); }
            .close{ float:right; font-size:28px; cursor:pointer; }
            .filters-row{ display:flex; gap:10px; align-items:flex-end; margin:10px 0; flex-wrap:wrap; }
            .filters-row label{ display:flex; flex-direction:column; gap:6px; font-weight:800; }
            .filters-row select{ padding:8px; border-radius:10px; border:1px solid #d1d5db; }

            .slot-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; border:1px solid #e5e7eb; border-radius:16px; padding:12px; margin:10px 0; background:#fff; }
            .slot-meta{ display:flex; flex-direction:column; gap:4px; }
            .slot-actions, .actions{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
            .btn-assegna,.btn-modifica,.btn-elimina{ border:1px solid transparent; padding:10px 12px; border-radius:12px; cursor:pointer; font-weight:900; }
            .btn-assegna{ background:#2563eb; border-color:#2563eb; color:#fff; }
            .btn-modifica{ background:#10b981; border-color:#0ea5a4; color:#fff; }
            .btn-elimina{ background:#ef4444; border-color:#dc2626; color:#fff; }
            .btn-assegna:disabled,.btn-modifica:disabled,.btn-elimina:disabled{ opacity:.6; cursor:not-allowed; }

            .badge-soft{ display:inline-block; background:#eff6ff; color:#1d4ed8; border-radius:999px; padding:4px 10px; font-weight:900; font-size:12px; }
            .muted{ color:#6b7280; }
            .notice{ margin:8px 0; font-size:13px; color:#374151; }
            .scrollable{ max-height:64vh; overflow:auto; }

            .slot-id{ margin-left:8px; font-size:12px; font-weight:900; color:#6b7280; }

            .nf-es-badge{
                display:inline-block; border:1px solid #2563eb; color:#2563eb; background:#dbeafe;
                padding:4px 10px; border-radius:999px; font-weight:900; font-size:12px; margin-left:10px; white-space:nowrap;
            }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const calendar = document.getElementById('calendar');
            const monthYear = document.getElementById('monthYear');

            const modal = document.getElementById('slotModal');
            const modalData = document.getElementById('modalData');
            const modalSlotInfo = document.getElementById('modalSlotInfo');
            const closeModal = document.querySelector('.close');

            const myModal = document.getElementById('mySessionsModal');
            const myBody  = document.getElementById('mySessionsBody');
            const closeMy = document.querySelector('.close-my');
            const openMy  = document.getElementById('openMySessions');

            const allModal = document.getElementById('allAssignmentsModal');
            const allBody  = document.getElementById('allAssignmentsBody');
            const closeAll = document.querySelector('.close-all');
            const openAll  = document.getElementById('openAllAssignments');
            const allMonth = document.getElementById('allMonth');
            const allYear  = document.getElementById('allYear');
            const reloadAllBtn = document.getElementById('reloadAllAssignments');

            const AJAX_URL = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const AJAX_NONCE = '<?php echo esc_js($nonce); ?>';

            const IS_LOGGED_IN = <?php echo $is_logged; ?>;
            const CAN_ASSIGN   = <?php echo $can_assign; ?>;
            const ALLOW_REASSIGN = <?php echo $allow_reassign; ?>;
            const ALLOW_UNASSIGN = <?php echo $allow_unassign; ?>;

            const mesi = ['January','February','March','April','May','June','July','August','September','October','November','December'];

            function pad(n){ return String(n).padStart(2,'0'); }
            function formatDate(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
            function formatDateIT(iso){
                const [y,m,d] = iso.split('-'); return d+'/'+m+'/'+y;
            }
            function specialBadgeHTML(on){
                return on ? `<span class="nf-es-badge">♿ ESIGENZE SPECIALI</span>` : '';
            }

            const cache = {};

            function fetchMonthData(year, month){
                const key = year+'-'+pad(month+1);
                if (cache[key]) return Promise.resolve(cache[key]);

                return fetch(`${AJAX_URL}?action=nfems_cal_get_month&year=${encodeURIComponent(year)}&month=${encodeURIComponent(month+1)}&_ajax_nonce=${encodeURIComponent(AJAX_NONCE)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (!json || !json.success) throw new Error(json?.data?.message || __('Loading error', 'mc-ems'));
                        cache[key] = json.data || {};
                        return cache[key];
                    });
            }

            function renderCalendar(year, month){
                calendar.innerHTML = '';
                monthYear.textContent = mesi[month] + ' ' + year;

                const firstDay = new Date(year, month, 1);
                const startDay = (firstDay.getDay() === 0) ? 7 : firstDay.getDay(); // Mon=1..Sun=7
                const daysInMonth = new Date(year, month + 1, 0).getDate();

                for(let i=1;i<startDay;i++){
                    calendar.appendChild(document.createElement('div'));
                }

                for (let day = 1; day <= daysInMonth; day++) {
                    const date = new Date(year, month, day);
                    const dayEl = document.createElement('div');
                    dayEl.classList.add('calendar-day');
                    dayEl.textContent = day;
                    dayEl.dataset.date = formatDate(date);
                    calendar.appendChild(dayEl);
                }

                fetchMonthData(year, month).then(data => {
                    document.querySelectorAll('.calendar-day').forEach(dayEl => {
                        const d = dayEl.dataset.date;
                        if (!data[d]) {
                            dayEl.classList.add('no-slot');
                            return;
                        }

                        const dayObj = data[d];
                        const liberi = (dayObj.totali || 0) - (dayObj.prenotati || 0);

                        if ((dayObj.totali || 0) === 0) dayEl.classList.add('no-slot');
                        else if (liberi === dayObj.totali) dayEl.classList.add('slot-verde');
                        else if (liberi >= dayObj.totali * 0.5) dayEl.classList.add('slot-giallo');
                        else if (liberi > 0) dayEl.classList.add('slot-arancione');
                        else dayEl.classList.add('slot-rosso');

                        dayEl.addEventListener('click', () => {
                            modalData.textContent = formatDateIT(d);

                            const rows = (dayObj.sessioni || [])
                                .sort((a,b) => (a.ora || '').localeCompare(b.ora || ''))
                                .map(s => {
                                    const assigned = (s.assegnato && s.assegnato_nome)
                                        ? `Assegnato a ${s.assegnato_nome}` : null;

                                    const oraHtml = `<strong>${s.ora || ''}</strong>
                                                     <span class="slot-id">ID: ${s.id}</span>
                                                     ${specialBadgeHTML(!!s.speciale)}`;

                                    const btnAssegna = (!assigned && IS_LOGGED_IN && CAN_ASSIGN)
                                        ? `<button class="btn-assegna" data-id="${s.id}">Assign session</button>`
                                        : '';

                                    const btnMod = (assigned && IS_LOGGED_IN && CAN_ASSIGN && ALLOW_REASSIGN)
                                        ? `<button class="btn-modifica" data-id="${s.id}">Modifica assegnazione</button>`
                                        : '';

                                    const btnDel = (assigned && IS_LOGGED_IN && CAN_ASSIGN && ALLOW_UNASSIGN)
                                        ? `<button class="btn-elimina" data-id="${s.id}">Elimina assegnazione</button>`
                                        : '';

                                    const right = assigned
                                        ? (IS_LOGGED_IN && CAN_ASSIGN
                                            ? `<div class="actions">
                                                    <span class="badge-soft">${assigned}</span>
                                                    ${btnMod}
                                                    ${btnDel}
                                               </div>`
                                            : `<span class="badge-soft">${assigned}</span>`)
                                        : (IS_LOGGED_IN
                                            ? `<div class="slot-actions">${btnAssegna}</div>`
                                            : '<span class="muted">Accedi per assegnarti</span>');

                                    return `<div class="slot-row" id="row-${s.id}">
                                        <div class="slot-meta">
                                            <div>${oraHtml}</div>
                                            <div class="muted">${s.prenotati}/${s.totali} seats booked</div>
                                        </div>
                                        ${right}
                                    </div>`;
                                }).join('');

                            modalSlotInfo.innerHTML = rows || '<p class="notice">No sessions on this date.</p>';
                            modal.style.display = 'block';
                            modal.setAttribute('aria-hidden', 'false');
                        });
                    });
                });
            }

            let currentYear = new Date().getFullYear();
            let currentMonth = new Date().getMonth();
            renderCalendar(currentYear, currentMonth);

            document.getElementById('prevMonth').addEventListener('click', () => {
                currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; }
                cache[currentYear+'-'+pad(currentMonth+1)] = null;
                renderCalendar(currentYear, currentMonth);
            });
            document.getElementById('nextMonth').addEventListener('click', () => {
                currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; }
                cache[currentYear+'-'+pad(currentMonth+1)] = null;
                renderCalendar(currentYear, currentMonth);
            });

            closeModal.addEventListener('click', () => { modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); } });

            closeMy.addEventListener('click', () => { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == myModal) { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden','true'); } });

            closeAll.addEventListener('click', () => { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == allModal) { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden','true'); } });

            // My sessions
            openMy.addEventListener('click', function(){
                if (!IS_LOGGED_IN) { alert(__('You must be logged in to view your sessions.', 'mc-ems')); return; }
                myBody.innerHTML = '<p class="notice">Caricamento...</p>';

                fetch(`${AJAX_URL}?action=nfems_cal_get_my&_ajax_nonce=${encodeURIComponent(AJAX_NONCE)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (!json || !json.success) { myBody.innerHTML = `<p class="notice">${(json?.data?.message)||__('Unable to load sessions.', 'mc-ems')}</p>`; return; }
                        const items = json.data || [];
                        if (!items.length) { myBody.innerHTML = '<p class="notice">Non hai sessioni assegnate.</p>'; return; }

                        myBody.innerHTML = items.map(s => `
                            <div class="slot-row" id="my-${s.id}">
                                <div class="slot-meta">
                                    <div><strong>${s.data_it}</strong></div>
                                    <div><strong>${s.ora}</strong> <span class="slot-id">ID: ${s.id}</span> ${s.speciale ? specialBadgeHTML(true) : ''}</div>
                                </div>
                                ${(CAN_ASSIGN && ALLOW_UNASSIGN) ? `<div class="actions">
                                    <button class="btn-elimina" data-id="${s.id}">Elimina assegnazione</button>
                                </div>` : ''}
                            </div>
                        `).join('');
                    })
                    .catch(() => { myBody.innerHTML = '<p class="notice">Errore di rete. Riprova.</p>'; });

                myModal.style.display = 'block';
                myModal.setAttribute('aria-hidden','false');
            });

            // All sessions modal
            function loadAll(){
                allBody.innerHTML = '<p class="notice">Caricamento...</p>';
                const y = allYear.value, m = allMonth.value;
                fetch(`${AJAX_URL}?action=nfems_cal_get_all&year=${encodeURIComponent(y)}&month=${encodeURIComponent(m)}&_ajax_nonce=${encodeURIComponent(AJAX_NONCE)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (!json || !json.success) { allBody.innerHTML = `<p class="notice">${(json?.data?.message)||__('Unable to load sessions.', 'mc-ems')}</p>`; return; }
                        const items = json.data || [];
                        if (!items.length) { allBody.innerHTML = '<p class="notice">No sessions for the selected period.</p>'; return; }

                        allBody.innerHTML = items.map(s => {
                            const assigned = (s.assegnato && s.assegnato_nome) ? `Assegnato a ${s.assegnato_nome}` : null;

                            const btnAssegna = (!assigned && IS_LOGGED_IN && CAN_ASSIGN)
                                ? `<button class="btn-assegna" data-id="${s.id}">Assign session</button>`
                                : '';

                            const btnMod = (assigned && CAN_ASSIGN && ALLOW_REASSIGN)
                                ? `<button class="btn-modifica" data-id="${s.id}">Modifica assegnazione</button>`
                                : '';

                            const btnDel = (assigned && CAN_ASSIGN && ALLOW_UNASSIGN)
                                ? `<button class="btn-elimina" data-id="${s.id}">Elimina assegnazione</button>`
                                : '';

                            const actionHTML = assigned
                                ? (CAN_ASSIGN ? `<div class="actions">
                                        <span class="badge-soft">${assigned}</span>
                                        ${btnMod}
                                        ${btnDel}
                                   </div>` : `<span class="badge-soft">${assigned}</span>`)
                                : (IS_LOGGED_IN && CAN_ASSIGN
                                    ? `<div class="slot-actions">${btnAssegna}</div>`
                                    : '<span class="muted">—</span>');

                            const oraHtml = `<strong>${s.ora || ''}</strong>
                                             <span class="slot-id">ID: ${s.id}</span>
                                             ${specialBadgeHTML(!!s.speciale)}`;

                            return `<div class="slot-row" id="all-${s.id}">
                                <div class="slot-meta">
                                    <div><strong>${s.data_it}</strong></div>
                                    <div>${oraHtml}</div>
                                    <div class="muted">${s.prenotati}/${s.totali} seats booked</div>
                                </div>
                                ${actionHTML}
                            </div>`;
                        }).join('');
                    })
                    .catch(() => { allBody.innerHTML = '<p class="notice">Errore di rete. Riprova.</p>'; });
            }

            openAll.addEventListener('click', function(){
                loadAll();
                allModal.style.display = 'block';
                allModal.setAttribute('aria-hidden','false');
            });
            reloadAllBtn.addEventListener('click', loadAll);

            // Delegated actions
            document.addEventListener('click', function(e){
                const btnA = e.target.closest('.btn-assegna');
                const btnM = e.target.closest('.btn-modifica');
                const btnE = e.target.closest('.btn-elimina');
                if (!btnA && !btnM && !btnE) return;

                if (!IS_LOGGED_IN || !CAN_ASSIGN) {
                    alert('Non autorizzato.');
                    return;
                }
                e.preventDefault();

                const id = (btnA || btnM || btnE).getAttribute('data-id');
                if (!id) return;

                let action = '';
                if (btnA) action = 'nfems_cal_assign';
                if (btnM) action = 'nfems_cal_reassign';
                if (btnE) action = 'nfems_cal_unassign';

                const form = new FormData();
                form.append('action', action);
                form.append('_ajax_nonce', AJAX_NONCE);
                form.append('session_id', id);

                const targetBtn = (btnA || btnM || btnE);
                targetBtn.disabled = true;

                fetch(AJAX_URL, { method:'POST', body: form })
                    .then(r => r.json())
                    .then(json => {
                        targetBtn.disabled = false;
                        if (!json || !json.success) {
                            alert((json?.data?.message) || 'Operazione non riuscita.');
                            return;
                        }

                        if (allModal.style.display === 'block') loadAll();
                        if (myModal.style.display === 'block') openMy.click();

                        cache[currentYear+'-'+pad(currentMonth+1)] = null;
                        renderCalendar(currentYear, currentMonth);
                    })
                    .catch(() => {
                        targetBtn.disabled = false;
                        alert(__('Network error.', 'mc-ems'));
                    });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // ===== AJAX: month aggregate =====
    public static function ajax_get_month() {
        self::ajax_guard();

        $year  = isset($_GET['year'])  ? max(1970, (int) $_GET['year']) : (int) wp_date('Y');
        $month = isset($_GET['month']) ? max(1, min(12, (int) $_GET['month'])) : (int) wp_date('n');

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $posts = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => NFEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
            'fields' => 'ids',
        ]);

        $output = [];

        foreach ($posts as $sid) {
            $sid  = (int) $sid;
            $data = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $ora  = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);

            $totali = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);
            if ($totali <= 0) $totali = 1;

            $bookings = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_BOOKINGS, true);
            $prenotati = is_array($bookings) ? count($bookings) : 0;

            $proctor_id = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            $assegnato_nome = null;
            if ($proctor_id) {
                $u = get_user_by('id', $proctor_id);
                if ($u) $assegnato_nome = $u->display_name;
            }

            $speciale = ((int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);

            if (!isset($output[$data])) {
                $output[$data] = [
                    'totali'    => 0,
                    'prenotati' => 0,
                    'sessioni'  => [],
                ];
            }

            $output[$data]['totali']    += $totali;
            $output[$data]['prenotati'] += $prenotati;
            $output[$data]['sessioni'][] = [
                'id'            => $sid,
                'ora'           => $ora,
                'prenotati'     => $prenotati,
                'totali'        => $totali,
                'assegnato'     => $proctor_id ? 1 : 0,
                'assegnato_nome'=> $assegnato_nome,
                'speciale'      => $speciale,
            ];
        }

        wp_send_json_success($output);
    }

    public static function ajax_get_my() {
        self::ajax_guard();
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Non sei autenticato.'], 403);

        $user_id = get_current_user_id();
        $oggi = wp_date('Y-m-d');

        $posts = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, 'value' => $user_id, 'compare' => '='],
                ['key' => NFEMS_CPT_Sessioni_Esame::MK_DATE, 'value' => $oggi, 'compare' => '>=', 'type'=>'DATE'],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => NFEMS_CPT_Sessioni_Esame::MK_DATE,
            'order'    => 'ASC',
            'fields'   => 'ids',
        ]);

        $out = [];
        foreach ($posts as $sid) {
            $sid  = (int) $sid;
            $data = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $ora  = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $speciale = ((int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);

            $out[] = [
                'id'       => $sid,
                'data'     => $data,
                'data_it'  => $data ? date_i18n('d/m/Y', strtotime($data)) : '',
                'ora'      => $ora ?: '',
                'speciale' => $speciale,
            ];
        }

        wp_send_json_success($out);
    }

    public static function ajax_get_all() {
        self::ajax_guard();

        $year  = isset($_GET['year'])  ? max(1970, (int) $_GET['year']) : (int) wp_date('Y');
        $month = isset($_GET['month']) ? max(1, min(12, (int) $_GET['month'])) : (int) wp_date('n');

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $posts = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => NFEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => NFEMS_CPT_Sessioni_Esame::MK_DATE,
            'order'    => 'ASC',
            'fields'   => 'ids',
        ]);

        $out = [];
        foreach ($posts as $sid) {
            $sid  = (int) $sid;
            $data = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $ora  = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);

            $totali = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);
            if ($totali <= 0) $totali = 1;

            $bookings = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_BOOKINGS, true);
            $prenotati = is_array($bookings) ? count($bookings) : 0;

            $proctor_id = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            $assegnato_nome = null;
            if ($proctor_id) {
                $u = get_user_by('id', $proctor_id);
                if ($u) $assegnato_nome = $u->display_name;
            }

            $speciale = ((int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);

            $out[] = [
                'id'            => $sid,
                'data'          => $data,
                'data_it'       => $data ? date_i18n('d/m/Y', strtotime($data)) : '',
                'ora'           => $ora ?: '',
                'prenotati'     => $prenotati,
                'totali'        => $totali,
                'assegnato'     => $proctor_id ? 1 : 0,
                'assegnato_nome'=> $assegnato_nome,
                'speciale'      => $speciale,
            ];
        }

        wp_send_json_success($out);
    }

    // ===== Actions assign/reassign/unassign =====
    public static function ajax_assign() {
        self::ajax_guard();
        if (!self::cap_ok()) wp_send_json_error(['message'=>'Non autorizzato.'], 403);

        $sid = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        if (!$sid || get_post_type($sid) !== NFEMS_CPT_Sessioni_Esame::CPT) wp_send_json_error(['message'=>__('Invalid exam session.', 'mc-ems')], 400);

        $me = get_current_user_id();

        // Atomic: assign only if meta not already present
        $ok = add_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, $me, true);
        if (!$ok) wp_send_json_error(['message'=>__('Session already assigned.', 'mc-ems')], 409);

        self::maybe_notify_assign($sid, $me);

        wp_send_json_success(['message'=>'Assegnazione effettuata.']);
    }

    public static function ajax_reassign() {
        self::ajax_guard();
        if (!self::cap_ok()) wp_send_json_error(['message'=>'Non autorizzato.'], 403);
        if (!nfems_settings('cal_allow_reassign', 1)) wp_send_json_error(['message'=>'Modifica assegnazione disabilitata.'], 403);

        $sid = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        if (!$sid || get_post_type($sid) !== NFEMS_CPT_Sessioni_Esame::CPT) wp_send_json_error(['message'=>__('Invalid exam session.', 'mc-ems')], 400);

        $me = get_current_user_id();
        update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, $me);

        self::maybe_notify_assign($sid, $me);

        wp_send_json_success(['message'=>'Assegnazione modificata.']);
    }

    public static function ajax_unassign() {
        self::ajax_guard();
        if (!self::cap_ok()) wp_send_json_error(['message'=>'Non autorizzato.'], 403);
        if (!nfems_settings('cal_allow_unassign', 1)) wp_send_json_error(['message'=>__('Delete assignment disabled.', 'mc-ems')], 403);

        $sid = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        if (!$sid || get_post_type($sid) !== NFEMS_CPT_Sessioni_Esame::CPT) wp_send_json_error(['message'=>__('Invalid exam session.', 'mc-ems')], 400);

        delete_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID);

        wp_send_json_success(['message'=>__('Assignment deleted.', 'mc-ems')]);
    }

    private static function maybe_notify_assign(int $session_id, int $proctor_user_id): void {
        if (!nfems_settings('cal_email_on_assign', 0)) return;

        $to_csv = trim((string) nfems_settings('cal_email_notify_to', ''));
        if ($to_csv === '') return;

        $to = array_filter(array_map('trim', explode(',', $to_csv)));
        if (!$to) return;

        $date = (string) get_post_meta($session_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $time = (string) get_post_meta($session_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);

        $u = get_user_by('id', $proctor_user_id);
        $proctor = $u ? ($u->display_name . ' (' . $u->user_email . ')') : ('User#' . $proctor_user_id);

        $course_id = (int) get_post_meta($session_id, NFEMS_CPT_Sessioni_Esame::MK_COURSE_ID, true);
        $course_title = $course_id ? (string) get_the_title($course_id) : '';

        $subj = (string) nfems_settings('cal_email_subject', 'Exam session assigned — {session_date} {session_time}');
        $body = (string) nfems_settings('cal_email_body', "An exam session has been assigned.\n\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nProctor: {proctor_name}\nSession ID: {session_id}");

        $date_label = $date ? date_i18n('d/m/Y', strtotime($date)) : $date;

        $repl = [
            '{course_title}' => (string) $course_title,
            '{session_date}' => (string) $date_label,
            '{session_time}' => (string) $time,
            '{proctor_name}' => (string) $proctor,
            '{session_id}' => (string) $session_id,
        ];

        if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'render_email_template')) {
            $subj = NFEMS_Settings::render_email_template($subj, $repl);
            $body = NFEMS_Settings::render_email_template($body, $repl);
        } else {
            $subj = strtr($subj, $repl);
            $body = strtr($body, $repl);
        }

        wp_mail($to, $subj, $body, NFEMS_Settings::get_mail_headers());
    }
}
