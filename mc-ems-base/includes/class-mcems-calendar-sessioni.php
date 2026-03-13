<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MC_SLOT_ESIGENZE_SPECIALI')) {
    define('MC_SLOT_ESIGENZE_SPECIALI', 'slot_esigenze_speciali');
}

class MCEMS_Calendar_Sessioni {

    const NONCE_ACTION = 'cal_slot_nonce';
    const CRON_HOOK    = 'cal_slot_midnight_check';

    public static function init(): void {
        add_shortcode('mcems_sessions_calendar', [__CLASS__, 'shortcode']);
        add_shortcode('calendario_slot_esame', [__CLASS__, 'shortcode']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('wp_ajax_get_slot_data', [__CLASS__, 'ajax_get_slot_data']);
        add_action('wp_ajax_nopriv_get_slot_data', [__CLASS__, 'ajax_get_slot_data']);

        add_action('wp_ajax_get_user_assigned_slots', [__CLASS__, 'ajax_get_user_assigned_slots']);
        add_action('wp_ajax_get_all_assigned_slots', [__CLASS__, 'ajax_get_all_assigned_slots']);

        add_action('wp_ajax_assegna_sessione_slot', [__CLASS__, 'ajax_assegna_sessione_slot']);
        add_action('wp_ajax_modifica_assegnazione_sessione_slot', [__CLASS__, 'ajax_modifica_assegnazione_sessione_slot']);
        add_action('wp_ajax_elimina_assegnazione_sessione_slot', [__CLASS__, 'ajax_elimina_assegnazione_sessione_slot']);

        add_action('init', [__CLASS__, 'schedule_midnight_event']);
        add_action(self::CRON_HOOK, [__CLASS__, 'midnight_check_unassigned_slots']);
    }

    public static function enqueue_assets(): void {
        $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
        $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';

        wp_register_style('mcems-style', $url . 'assets/css/style.css', [], $ver);
        wp_enqueue_style('mcems-style');

        wp_register_script('mcems-calendar-sessioni', $url . 'assets/js/calendar-sessioni.js', [], $ver, true);
        wp_enqueue_script('mcems-calendar-sessioni');

        wp_localize_script('mcems-calendar-sessioni', 'MCEMS_CAL', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'locale'  => get_locale(),
            'i18n'    => [
                'loading'    => __('Loading…', 'mc-ems'),
                'loadError'  => __('Error loading calendar data.', 'mc-ems'),
                'available'  => __('Available', 'mc-ems'),
                'limited'    => __('Limited', 'mc-ems'),
                'full'       => __('Full', 'mc-ems'),
                'past'       => __('Past', 'mc-ems'),
                'sessions'   => __('Sessions', 'mc-ems'),
                'capacity'   => __('Capacity', 'mc-ems'),
                'booked'     => __('Booked', 'mc-ems'),
                'proctor'    => __('Proctor', 'mc-ems'),
                'candidates' => __('Candidates', 'mc-ems'),
                'mon'        => __('Mon', 'mc-ems'),
                'tue'        => __('Tue', 'mc-ems'),
                'wed'        => __('Wed', 'mc-ems'),
                'thu'        => __('Thu', 'mc-ems'),
                'fri'        => __('Fri', 'mc-ems'),
                'sat'        => __('Sat', 'mc-ems'),
                'sun'        => __('Sun', 'mc-ems'),
            ],
        ]);
    }

    private static function cpt(): string {
        if (class_exists('MCEMS_CPT_Sessioni_Esame') && defined('MCEMS_CPT_Sessioni_Esame::CPT')) {
            return MCEMS_CPT_Sessioni_Esame::CPT;
        }
        return 'mcems_exam_session';
    }

    private static function mk(string $const, string $fallback) {
        $full = 'MCEMS_CPT_Sessioni_Esame::' . $const;
        return defined($full) ? constant($full) : $fallback;
    }

    /**
     * Priorità ai meta originali del calendario.
     * Fallback ai meta MCEMS solo se servono.
     */
    private static function meta_keys(string $type): array {
        $map = [
            'date' => [
                'slot_data',
                self::mk('MK_DATE', 'slot_data'),
                '_mcems_data',
                'data',
                'date',
            ],
            'time' => [
                'slot_orario',
                self::mk('MK_TIME', 'slot_orario'),
                '_mcems_orario',
                'orario',
                'time',
            ],
            'capacity' => [
                'slot_posti_max',
                self::mk('MK_CAPACITY', 'slot_posti_max'),
                '_mcems_capacity',
                'capacity',
            ],
            'occupied' => [
                'slot_posti_occupati',
                self::mk('MK_OCCUPATI', 'slot_posti_occupati'),
                '_mcems_occupati',
                'occupati',
            ],
            'exam_id' => [
                'slot_corso_id',
                self::mk('MK_EXAM_ID', 'slot_corso_id'),
                '_mcems_exam_id',
                'exam_id',
            ],
            'is_special' => [
                MC_SLOT_ESIGENZE_SPECIALI,
                self::mk('MK_IS_SPECIAL', MC_SLOT_ESIGENZE_SPECIALI),
                '_mcems_is_special',
                'is_special',
            ],
            'special_user_id' => [
                'slot_special_user_id',
                self::mk('MK_SPECIAL_USER_ID', 'slot_special_user_id'),
                '_mcems_special_user_id',
                'special_user_id',
            ],
            'assigned' => [
                'slot_assegnato',
            ],
            'assigned_user' => [
                'slot_assegnato_user',
            ],
            'assigned_name' => [
                'slot_assegnato_nome',
            ],
            'assigned_timestamp' => [
                'slot_assegnato_timestamp',
            ],
            'proctor' => [
                'slot_sorvegliante',
                '_mcems_proctor_user_id',
                'mcems_proctor_user_id',
            ],
            'warn_sent_for' => [
                'slot_unassigned_warn_sent_for',
            ],
            'warn_sent_legacy' => [
                'slot_unassigned_warn_sent',
            ],
        ];

        return $map[$type] ?? [];
    }

    private static function get_first_meta(int $post_id, string $type, $default = '') {
        foreach (self::meta_keys($type) as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }
        return $default;
    }

    private static function update_first_meta(int $post_id, string $type, $value): void {
        $keys = self::meta_keys($type);
        if (!empty($keys[0])) {
            update_post_meta($post_id, $keys[0], $value);
        }
    }

    private static function delete_meta_group(int $post_id, string $type): void {
        foreach (self::meta_keys($type) as $key) {
            delete_post_meta($post_id, $key);
        }
    }

    private static function normalize_time($time): string {
        $time = (string) $time;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        return $time;
    }

    private static function current_user_display_name(int $user_id): string {
        $u = get_userdata($user_id);
        if (!$u) {
            return 'user_' . $user_id;
        }

        $first = get_user_meta($user_id, 'first_name', true);
        $last  = get_user_meta($user_id, 'last_name', true);
        $full  = trim(($first ?: '') . ' ' . ($last ?: ''));

        return $full !== '' ? $full : ($u->display_name ?: $u->user_login);
    }

    private static function get_proctor_user_id(int $slot_id): int {
        $raw = self::get_first_meta($slot_id, 'proctor', 0);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    private static function is_slot_assigned(int $slot_id): bool {
        return self::get_proctor_user_id($slot_id) > 0;
    }

    private static function get_slot_assigned_name(int $slot_id): string {
        $proctor_id = self::get_proctor_user_id($slot_id);
        if ($proctor_id > 0) {
            return self::current_user_display_name($proctor_id);
        }
        return '';
    }

    private static function get_calendar_recipients(): array {
        if (!class_exists('MCEMS_Settings')) {
            $fallback = sanitize_email((string) get_option('admin_email'));
            return $fallback ? [$fallback] : [];
        }

        $raw = trim((string) MCEMS_Settings::get_str('cal_email_notify_to'));
        if ($raw === '') {
            $raw = trim((string) MCEMS_Settings::get_str('email_admin_recipients'));
        }
        if ($raw === '') {
            $raw = (string) get_option('admin_email');
        }

        $emails = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw)));
        $out = [];

        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && is_email($email)) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    private static function get_slot_placeholders(int $slot_id, string $proctor_name = ''): array {
        $date      = (string) self::get_first_meta($slot_id, 'date', '');
        $time      = self::normalize_time(self::get_first_meta($slot_id, 'time', ''));
        $exam_id = (int) self::get_first_meta($slot_id, 'exam_id', 0);

        $exam_title = $exam_id > 0 ? get_the_title($exam_id) : '';
        if (!$exam_title) {
            $exam_title = $exam_id > 0 ? ('Exam #' . $exam_id) : '';
        }

        return [
            '{site_name}'     => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{exam_title}'  => $exam_title,
            '{session_date}'  => $date,
            '{session_time}'  => $time,
            '{proctor_name}'  => $proctor_name,
            '{session_id}'    => (string) $slot_id,
        ];
    }

    private static function send_calendar_mail(string $subject_key, string $body_key, array $placeholders = [], ?array $recipients = null): void {
        if (!class_exists('MCEMS_Settings')) {
            return;
        }

        $to = $recipients ?: self::get_calendar_recipients();
        if (empty($to)) {
            return;
        }

        $default_subjects = [
            'cal_email_subject'          => 'Exam session assigned — {session_date} {session_time}',
            'cal_email_subject_unassign' => 'Exam session unassigned — {session_date} {session_time}',
            'cal_email_subject_warning'  => 'Unassigned exam session reminder — {session_date} {session_time}',
        ];

        $default_bodies = [
            'cal_email_body' => "An exam session has been assigned.\n\nExam: {exam_title}\nDate: {session_date}\nTime: {session_time}\nProctor: {proctor_name}\nSession ID: {session_id}",
            'cal_email_body_unassign' => "An exam session assignment has been removed.\n\nExam: {exam_title}\nDate: {session_date}\nTime: {session_time}\nPrevious proctor: {proctor_name}\nSession ID: {session_id}",
            'cal_email_body_warning' => "The following exam session is scheduled for tomorrow and still has no assigned proctor.\n\nExam: {exam_title}\nDate: {session_date}\nTime: {session_time}\nSession ID: {session_id}",
        ];

        $subject = MCEMS_Settings::get_email_template($subject_key, $default_subjects[$subject_key] ?? '');
        $body    = MCEMS_Settings::get_email_template($body_key, $default_bodies[$body_key] ?? '');
        $headers = MCEMS_Settings::get_mail_headers();

        wp_mail(
            $to,
            MCEMS_Settings::render_email_template($subject, $placeholders),
            MCEMS_Settings::render_email_template($body, $placeholders),
            $headers
        );
    }

    public static function shortcode(): string {
        if (!MCEMS_Settings::user_can_view_shortcode('mcems_sessions_calendar')) {
            return '<p>Insufficient permissions.</p>';
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);

        ob_start();
        ?>
        <div class="calendar-wrapper">
            <div class="calendar-nav">
                <button id="prevMonth" aria-label="Previous month">&larr;</button>
                <span id="monthYear"></span>
                <button id="nextMonth" aria-label="Next month">&rarr;</button>
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

        <div id="slotModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <span class="close" role="button" aria-label="Close">&times;</span>
                <h2 id="modalTitle">Sessions on <span id="modalData"></span></h2>
                <div id="modalSlotInfo"></div>
            </div>
        </div>

        <div id="mySessionsModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="mySessionsTitle">
                <span class="close close-my" role="button" aria-label="Close">&times;</span>
                <h2 id="mySessionsTitle">Your assigned sessions</h2>
                <div id="mySessionsBody"></div>
            </div>
        </div>

        <div id="allAssignmentsModal" class="modal" aria-hidden="true">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="allAssignmentsTitle">
                <span class="close close-all" role="button" aria-label="Close">&times;</span>
                <h2 id="allAssignmentsTitle">All sessions</h2>

                <div class="filters-row">
                    <label>
                        Month
                        <select id="allMonth">
                            <?php
                            $mesi = [
                                1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                                7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
                            ];
                            $curM = (int) wp_date('n');
                            foreach ($mesi as $num=>$nome) {
                                printf(
                                    '<option value="%d"%s>%s</option>',
                                    $num,
                                    selected($curM, $num, false),
                                    esc_html($nome)
                                );
                            }
                            ?>
                        </select>
                    </label>
                    <label>
                        Year
                        <select id="allYear">
                            <?php
                            $curY = (int) wp_date('Y');
                            for ($y=$curY-2; $y<=$curY+2; $y++) {
                                printf('<option value="%d"%s>%d</option>', $y, selected($curY,$y,false), $y);
                            }
                            ?>
                        </select>
                    </label>
                    <button id="reloadAllAssignments" class="btn-outline small tight">Search</button>
                </div>

                <div id="allAssignmentsBody" class="scrollable"></div>
            </div>
        </div>

        <style>
            .calendar-wrapper { text-align:center; font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
            .calendar-nav { display:flex; justify-content:center; align-items:center; gap:10px; margin-bottom:10px; }
            .calendar-nav button { background:none; border:none; font-size:20px; cursor:pointer; padding:5px; border-radius:50%; transition:background .2s; }
            .calendar-nav button:hover { background:#e0e0e0; }
            #monthYear { font-weight:700; font-size:18px; text-transform:capitalize; }

            .calendar-header, #calendar {
                display:grid; grid-template-columns: repeat(7, 1fr);
                max-width: 520px; margin: 0 auto; gap:4px;
            }
            .calendar-header div { text-align:center; font-weight:700; font-size:13px; padding:4px 0; }
            .calendar-day {
                border:1px solid #ddd; padding:8px; aspect-ratio:1;
                display:flex; align-items:center; justify-content:center;
                cursor:pointer; border-radius:10px; margin:0 auto; font-size:13px;
                transition: transform .12s, box-shadow .12s;
            }
            .calendar-day:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.06); }
            .no-slot { background-color:#eee; color:#777; cursor:not-allowed; }
            .slot-verde { background-color:#4caf50; color:#fff; }
            .slot-giallo { background-color:#ffeb3b; color:#000; }
            .slot-arancione { background-color:#ff9800; color:#fff; }
            .slot-rosso { background-color:#f44336; color:#fff; }

            .my-sessions-wrap { margin: 18px auto 0; max-width:520px; text-align:center; }
            .btns-stack { display:flex; flex-direction:column; gap:10px; align-items:stretch; }
            .btn-outline {
                appearance:none; background:#fff; color:#111827; border:1px solid #d1d5db; border-radius:12px;
                padding:10px 14px; font-weight:700; cursor:pointer; transition: background .2s, transform .06s, border-color .2s;
            }
            .btn-outline:hover { background:#f9fafb; transform: translateY(-1px); border-color:#9ca3af; }
            .btn-outline.small { padding:8px 12px; font-weight:600; }
            .btn-outline.small.tight{
                padding:4px 8px; font-size:13px; line-height:1.1; width:auto; min-width:unset; white-space:nowrap;
            }

            .filters-row { display:flex; gap:10px; align-items:end; margin: 8px 0 12px; flex-wrap:wrap; }
            .filters-row label { display:flex; flex-direction:column; font-size:14px; gap:4px; color:#374151; }
            .filters-row select { padding:6px 8px; border:1px solid #d1d5db; border-radius:8px; }

            .modal { display:none; position:fixed; z-index:9999; inset:0; background: rgba(0,0,0,.45); }
            .modal-content {
                background:#fff; margin: 6% auto; padding:20px; border-radius:12px; width: 92%; max-width: 720px;
                box-shadow: 0 16px 50px rgba(0,0,0,.25); max-height: 82vh; overflow:auto;
            }
            .close { float:right; font-size:24px; cursor:pointer; }

            #modalSlotInfo .slot-row,
            #mySessionsBody .slot-row,
            #allAssignmentsBody .slot-row {
                display:flex; align-items:center; justify-content:space-between;
                border:1px solid #eee; border-radius:10px; padding:10px 12px; margin:10px 0;
                background:#fafafa;
                gap:12px;
            }
            .slot-meta { font-size:14px; }
            .slot-meta strong { font-size:16px; }

            .actions { display:flex; flex-direction:column; align-items:stretch; gap:8px; min-width: 180px; }
            .slot-actions .btn-assegna,
            .actions .btn-modifica, .actions .btn-elimina { width:100%; }

            .btn-assegna, .btn-modifica, .btn-elimina {
                appearance:none; border:1px solid; border-radius:8px; padding:8px 12px; font-weight:700; cursor:pointer;
                transition: background .2s, transform .06s, opacity .2s, border-color .2s;
            }
            .btn-assegna { background:#2563eb; border-color:#2563eb; color:#fff; }
            .btn-assegna:hover { background:#1d4ed8; transform: translateY(-1px); }

            .btn-modifica { background:#10b981; border-color:#0ea5a4; color:#fff; }
            .btn-modifica:hover { background:#059669; transform: translateY(-1px); }

            .btn-elimina { background:#ef4444; border-color:#dc2626; color:#fff; }
            .btn-elimina:hover { background:#dc2626; transform: translateY(-1px); }

            .btn-assegna:disabled, .btn-modifica:disabled, .btn-elimina:disabled { opacity:.6; cursor:not-allowed; transform:none; }

            .badge-soft { display:inline-block; background:#eff6ff; color:#1d4ed8; border-radius:999px; padding:4px 10px; font-weight:700; font-size:12px; }
            .muted { color:#6b7280; }
            .notice { margin:8px 0; font-size:13px; color:#374151; }
            .scrollable { max-height: 64vh; overflow:auto; }

            .slot-id{
                margin-left:8px;
                font-size:12px;
                font-weight:700;
                color:#6b7280;
            }

            .nf-es-badge{
                display:inline-block;
                border:1px solid #2563eb;
                color:#2563eb;
                background:#dbeafe;
                padding:4px 10px;
                border-radius:999px;
                font-weight:900;
                font-size:12px;
                line-height:1;
                margin-left:10px;
                vertical-align:middle;
                white-space:nowrap;
            }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const calendar = document.getElementById('calendar');
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

            const AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const AJAX_NONCE = <?php echo wp_json_encode($nonce); ?>;
            const IS_LOGGED_IN = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;

            let today = new Date();
            let currentMonth = today.getMonth();
            let currentYear = today.getFullYear();
            let cacheSlots = {};

            function formatDate(date) {
                const y = date.getFullYear();
                const m = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }
            function formatDateIT(dateStr) {
                const [y, m, d] = dateStr.split('-');
                return `${d}/${m}/${y}`;
            }
            function dmyToISO(dmy) {
                const [d,m,y] = dmy.split('/');
                return `${y}-${m}-${d}`;
            }
            function isoFromModal() {
                const el = document.getElementById('modalData');
                const txt = (el?.textContent || '').trim();
                return (txt && txt.includes('/')) ? dmyToISO(txt) : null;
            }
            function monthKeyFromISO(dateISO) {
                const [y,m] = dateISO.split('-');
                return `${parseInt(y,10)}-${parseInt(m,10)-1}`;
            }
            function updateCacheAssign(slotId, dateISO, name) {
                if (!dateISO) return;
                const key = monthKeyFromISO(dateISO);
                const cache = cacheSlots[key];
                if (!cache || !cache[dateISO] || !Array.isArray(cache[dateISO].slots)) return;
                const item = cache[dateISO].slots.find(s => String(s.id) === String(slotId));
                if (item) {
                    item.assegnato = true;
                    item.assegnato_nome = name || '';
                    item.assegnato_user = 'me';
                }
            }
            function updateCacheUnassign(slotId, dateISO) {
                if (!dateISO) return;
                const key = monthKeyFromISO(dateISO);
                const cache = cacheSlots[key];
                if (!cache || !cache[dateISO] || !Array.isArray(cache[dateISO].slots)) return;
                const item = cache[dateISO].slots.find(s => String(s.id) === String(slotId));
                if (item) {
                    item.assegnato = false;
                    item.assegnato_nome = null;
                    item.assegnato_user = null;
                }
            }
            function specialBadgeHTML(isSpecial){
                return isSpecial ? '<span class="nf-es-badge">♿&nbsp;Yes</span>' : '';
            }

            function fetchMonthData(year, month) {
                const key = `${year}-${month}`;
                if (cacheSlots[key]) return Promise.resolve(cacheSlots[key]);
                return fetch(`${AJAX_URL}?action=get_slot_data&year=${year}&month=${month+1}`)
                    .then(r => r.json())
                    .then(data => { cacheSlots[key] = data || {}; return cacheSlots[key]; });
            }

            function renderCalendar(year, month) {
                document.getElementById('monthYear').textContent = new Date(year, month)
                    .toLocaleString('en-US', { month: 'long', year: 'numeric' });

                calendar.innerHTML = '';

                const firstDay = new Date(year, month, 1);
                let startDay = firstDay.getDay();
                startDay = (startDay === 0) ? 6 : startDay - 1;
                const daysInMonth = new Date(year, month + 1, 0).getDate();

                for(let i = 0; i < startDay; i++) calendar.appendChild(document.createElement('div'));

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
                        if (data[d]) {
                            const dayObj = data[d];
                            const liberi = dayObj.totali - dayObj.prenotati;

                            if (dayObj.totali === 0) dayEl.classList.add('no-slot');
                            else if (liberi === dayObj.totali) dayEl.classList.add('slot-verde');
                            else if (liberi >= dayObj.totali * 0.5) dayEl.classList.add('slot-giallo');
                            else if (liberi > 0) dayEl.classList.add('slot-arancione');
                            else dayEl.classList.add('slot-rosso');

                            dayEl.addEventListener('click', () => {
                                modalData.textContent = formatDateIT(d);

                                const rows = (dayObj.slots || [])
                                    .sort((a,b) => (a.ora || '').localeCompare(b.ora || ''))
                                    .map(s => {
                                        const assigned = (s.assegnato && s.assegnato_nome)
                                            ? `Assigned to ${s.assegnato_nome}`
                                            : null;

                                        const btnAssegna = (!assigned && IS_LOGGED_IN)
                                            ? `<button class="btn-assegna" data-slot="${s.id}" data-data="${d}" data-ora="${s.ora}">Assign session</button>`
                                            : '';

                                        const buttonsIfAssigned = (assigned && IS_LOGGED_IN)
                                            ? `<div class="actions">
                                                <span class="badge-soft">${assigned}</span>
                                                <button class="btn-modifica" data-slot="${s.id}" data-data="${d}" data-ora="${s.ora}">Reassign</button>
                                                <button class="btn-elimina" data-slot="${s.id}" data-data="${d}" data-ora="${s.ora}">Remove assignment</button>
                                               </div>`
                                            : '';

                                        const right = assigned
                                            ? buttonsIfAssigned
                                            : (IS_LOGGED_IN ? `<div class="slot-actions">${btnAssegna}</div>` : '<span class="muted">Log in to assign yourself</span>');

                                        const oraHtml = `<strong>${s.ora || ''}</strong>
                                                         <span class="slot-id">ID: ${s.id}</span>
                                                         ${specialBadgeHTML(!!s.speciale)}`;

                                        return `<div class="slot-row" id="slot-row-${s.id}">
                                            <div class="slot-meta">
                                                <div>${oraHtml}</div>
                                                ${s.exam_title ? `<div class="muted"><strong>Exam:</strong> ${s.exam_title}</div>` : ''}
                                                <div class="muted">${s.prenotati}/${s.totali} seats occupied</div>
                                            </div>
                                            ${right}
                                        </div>`;
                                    }).join('');

                                modalSlotInfo.innerHTML = rows || '<p class="notice">No sessions on this date.</p>';
                                modal.style.display = 'block';
                                modal.setAttribute('aria-hidden', 'false');
                            });
                        } else {
                            dayEl.classList.add('no-slot');
                        }
                    });
                });
            }

            renderCalendar(currentYear, currentMonth);

            document.getElementById('prevMonth').addEventListener('click', () => {
                currentMonth--;
                if (currentMonth < 0) { currentMonth = 11; currentYear--; }
                renderCalendar(currentYear, currentMonth);
            });
            document.getElementById('nextMonth').addEventListener('click', () => {
                currentMonth++;
                if (currentMonth > 11) { currentMonth = 0; currentYear++; }
                renderCalendar(currentYear, currentMonth);
            });

            closeModal.addEventListener('click', () => { modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); } });

            openMy.addEventListener('click', function(){
                if (!IS_LOGGED_IN) { alert('You must be logged in to view your sessions.'); return; }
                myBody.innerHTML = '<p class="notice">Loading...</p>';
                fetch(`${AJAX_URL}?action=get_user_assigned_slots&_ajax_nonce=${encodeURIComponent(AJAX_NONCE)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (!json || !json.success) { myBody.innerHTML = `<p class="notice">${(json && json.data && json.data.message) ? json.data.message : 'Unable to load sessions.'}</p>`; return; }
                        const items = json.data || [];
                        if (!items.length) { myBody.innerHTML = '<p class="notice">You have no assigned sessions.</p>'; return; }
                        myBody.innerHTML = items.map(s => `
                            <div class="slot-row" id="myslot-${s.id}" data-date="${s.data}">
                                <div class="slot-meta">
                                    <div><strong>${s.data_it}</strong></div>
                                    <div><strong>${s.ora}</strong> ${s.speciale ? specialBadgeHTML(true) : ''}</div>
                                    ${s.exam_title ? `<div class="muted"><strong>Exam:</strong> ${s.exam_title}</div>` : ''}
                                </div>
                                <div class="actions">
                                    <button class="btn-elimina" data-slot="${s.id}" data-data="${s.data}">Remove assignment</button>
                                </div>
                            </div>
                        `).join('');
                    })
                    .catch(() => { myBody.innerHTML = '<p class="notice">Network error. Please try again.</p>'; });

                myModal.style.display = 'block';
                myModal.setAttribute('aria-hidden','false');
            });

            closeMy.addEventListener('click', () => { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == myModal) { myModal.style.display = 'none'; myModal.setAttribute('aria-hidden','true'); } });

            function loadAllAssignments(){
                allBody.innerHTML = '<p class="notice">Loading...</p>';
                const y = allYear.value, m = allMonth.value;
                fetch(`${AJAX_URL}?action=get_all_assigned_slots&year=${encodeURIComponent(y)}&month=${encodeURIComponent(m)}&_ajax_nonce=${encodeURIComponent(AJAX_NONCE)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (!json || !json.success) { allBody.innerHTML = `<p class="notice">${(json && json.data && json.data.message) ? json.data.message : 'Unable to load sessions.'}</p>`; return; }
                        const items = json.data || [];
                        if (!items.length) { allBody.innerHTML = '<p class="notice">No sessions found for the selected period.</p>'; return; }

                        allBody.innerHTML = items.map(s => {
                            const actionHTML = s.assegnato
                                ? `<div class="actions">
                                       <span class="badge-soft">Assigned to ${s.assegnato_nome}</span>
                                       <button class="btn-modifica" data-slot="${s.id}" data-data="${s.data}" data-ora="${s.ora}">Reassign</button>
                                       <button class="btn-elimina"  data-slot="${s.id}" data-data="${s.data}" data-ora="${s.ora}">Remove assignment</button>
                                   </div>`
                                : `<div class="slot-actions">
                                       <button class="btn-assegna" data-slot="${s.id}" data-data="${s.data}" data-ora="${s.ora}">Assign session</button>
                                   </div>`;

                            return `<div class="slot-row" id="allslot-${s.id}">
                                <div class="slot-meta">
                                    <div><strong>${s.data_it}</strong></div>
                                    <div><strong>${s.ora}</strong> <span class="slot-id">ID: ${s.id}</span> ${s.speciale ? specialBadgeHTML(true) : ''}</div>
                                    ${s.exam_title ? `<div class="muted"><strong>Exam:</strong> ${s.exam_title}</div>` : ''}
                                </div>
                                ${actionHTML}
                            </div>`;
                        }).join('');
                    })
                    .catch(() => { allBody.innerHTML = '<p class="notice">Network error. Please try again.</p>'; });
            }

            openAll.addEventListener('click', function(){
                loadAllAssignments();
                allModal.style.display = 'block';
                allModal.setAttribute('aria-hidden','false');
            });
            reloadAllBtn.addEventListener('click', loadAllAssignments);
            closeAll.addEventListener('click', () => { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden','true'); });
            window.addEventListener('click', e => { if (e.target == allModal) { allModal.style.display = 'none'; allModal.setAttribute('aria-hidden','true'); } });

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-assegna');
                if (!btn) return;
                e.preventDefault();

                if (!IS_LOGGED_IN) { alert('You must be logged in to assign a session.'); return; }
                const slotId = btn.getAttribute('data-slot');

                btn.disabled = true; btn.textContent = 'Assigning...';
                const form = new FormData();
                form.append('action', 'assegna_sessione_slot');
                form.append('slot_id', slotId);
                form.append('_ajax_nonce', AJAX_NONCE);

                fetch(AJAX_URL, { method: 'POST', body: form })
                    .then(r => r.json())
                    .then(json => {
                        if (json && json.success) {
                            const nome = json.data.assegnato_nome || 'assigned';
                            const dateISO = btn.getAttribute('data-data') || isoFromModal();

                            const row = document.getElementById(`slot-row-${slotId}`);
                            if (row) {
                                row.querySelector('.slot-actions')?.remove();
                                row.insertAdjacentHTML('beforeend',
                                  `<div class="actions">
                                      <span class="badge-soft">Assigned to ${nome}</span>
                                      <button class="btn-modifica" data-slot="${slotId}" data-data="${dateISO}">Reassign</button>
                                      <button class="btn-elimina"  data-slot="${slotId}" data-data="${dateISO}">Remove assignment</button>
                                   </div>`);
                            }

                            const rowAll = document.getElementById(`allslot-${slotId}`);
                            if (rowAll) {
                                const meta = rowAll.querySelector('.slot-meta'); rowAll.innerHTML = '';
                                rowAll.appendChild(meta.cloneNode(true));
                                rowAll.insertAdjacentHTML('beforeend',
                                  `<div class="actions">
                                      <span class="badge-soft">Assigned to ${nome}</span>
                                      <button class="btn-modifica" data-slot="${slotId}" data-data="${dateISO}">Reassign</button>
                                      <button class="btn-elimina"  data-slot="${slotId}" data-data="${dateISO}">Remove assignment</button>
                                   </div>`);
                            }

                            updateCacheAssign(slotId, dateISO, nome);
                        } else {
                            alert((json && json.data && json.data.message) ? json.data.message : 'Unable to assign the session.');
                            btn.disabled = false; btn.textContent = 'Assign session';
                        }
                    })
                    .catch(() => { alert('Network error. Please try again.'); btn.disabled = false; btn.textContent = 'Assign session'; });
            });

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-modifica');
                if (!btn) return;
                e.preventDefault();

                if (!IS_LOGGED_IN) { alert('You must be logged in to reassign a session.'); return; }
                const slotId = btn.getAttribute('data-slot');
                if (!confirm('Do you want to replace the current assignee and assign this session to yourself?')) return;

                btn.disabled = true; btn.textContent = 'Reassigning...';
                const form = new FormData();
                form.append('action', 'modifica_assegnazione_sessione_slot');
                form.append('slot_id', slotId);
                form.append('_ajax_nonce', AJAX_NONCE);

                fetch(AJAX_URL, { method: 'POST', body: form })
                    .then(r => r.json())
                    .then(json => {
                        if (json && json.success) {
                            const nome = json.data.nuovo_nome || 'assigned';
                            const dateISO = btn.getAttribute('data-data') || isoFromModal();

                            const row = document.getElementById(`slot-row-${slotId}`);
                            if (row) {
                                const actionsWrap = row.querySelector('.actions');
                                if (actionsWrap) {
                                    actionsWrap.innerHTML =
                                      `<span class="badge-soft">Assigned to ${nome}</span>
                                       <button class="btn-modifica" data-slot="${slotId}" data-data="${dateISO}">Reassign</button>
                                       <button class="btn-elimina"  data-slot="${slotId}" data-data="${dateISO}">Remove assignment</button>`;
                                }
                            }

                            const rowAll = document.getElementById(`allslot-${slotId}`);
                            if (rowAll) {
                                const actionsWrap = rowAll.querySelector('.actions');
                                if (actionsWrap) {
                                    actionsWrap.innerHTML =
                                      `<span class="badge-soft">Assigned to ${nome}</span>
                                       <button class="btn-modifica" data-slot="${slotId}" data-data="${dateISO}">Reassign</button>
                                       <button class="btn-elimina"  data-slot="${slotId}" data-data="${dateISO}">Remove assignment</button>`;
                                }
                            }

                            updateCacheAssign(slotId, dateISO, nome);
                        } else {
                            alert((json && json.data && json.data.message) ? json.data.message : 'Unable to reassign the session.');
                            btn.disabled = false; btn.textContent = 'Reassign';
                        }
                    })
                    .catch(() => { alert('Network error. Please try again.'); btn.disabled = false; btn.textContent = 'Reassign'; });
            });

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-elimina');
                if (!btn) return;
                e.preventDefault();

                if (!IS_LOGGED_IN) { alert('You must be logged in to remove an assignment.'); return; }
                const slotId = btn.getAttribute('data-slot');
                if (!confirm('Are you sure you want to remove the current assignment?')) return;

                btn.disabled = true; btn.textContent = 'Removing...';
                const form = new FormData();
                form.append('action', 'elimina_assegnazione_sessione_slot');
                form.append('slot_id', slotId);
                form.append('_ajax_nonce', AJAX_NONCE);

                fetch(AJAX_URL, { method: 'POST', body: form })
                    .then(r => r.json())
                    .then(json => {
                        if (json && json.success) {
                            const dateISO = btn.getAttribute('data-data') || isoFromModal();

                            const row1 = document.getElementById(`slot-row-${slotId}`);
                            if (row1) {
                                row1.querySelector('.actions')?.remove();
                                row1.insertAdjacentHTML('beforeend',
                                  `<div class="slot-actions">
                                     <button class="btn-assegna" data-slot="${slotId}" data-data="${dateISO}">Assign session</button>
                                   </div>`);
                            }

                            const row2 = document.getElementById(`myslot-${slotId}`);
                            if (row2) { row2.remove(); if (!myBody.querySelector('.slot-row')) myBody.innerHTML = '<p class="notice">You have no assigned sessions.</p>'; }

                            const row3 = document.getElementById(`allslot-${slotId}`);
                            if (row3) {
                                const meta = row3.querySelector('.slot-meta'); row3.innerHTML = '';
                                row3.appendChild(meta.cloneNode(true));
                                row3.insertAdjacentHTML('beforeend',
                                  `<div class="slot-actions">
                                     <button class="btn-assegna" data-slot="${slotId}" data-data="${dateISO}">Assign session</button>
                                   </div>`);
                            }

                            updateCacheUnassign(slotId, dateISO);
                        } else {
                            alert((json && json.data && json.data.message) ? json.data.message : 'Unable to remove the assignment.');
                            btn.disabled = false; btn.textContent = 'Remove assignment';
                        }
                    })
                    .catch(() => { alert('Network error. Please try again.'); btn.disabled = false; btn.textContent = 'Remove assignment'; });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public static function ajax_get_slot_data(): void {
        $year  = isset($_GET['year'])  ? intval($_GET['year'])  : 0;
        $month = isset($_GET['month']) ? intval($_GET['month']) : 0;

        $args = [
            'post_type'      => self::cpt(),
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_key'       => self::meta_keys('date')[0],
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ];

        if ($year > 0 && $month >= 1 && $month <= 12) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
            $args['meta_query'] = [[
                'key'     => self::meta_keys('date')[0],
                'value'   => [$start, $end],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]];
        }

        $posts = get_posts($args);
        $output = [];

        foreach ($posts as $post) {
            $data           = (string) self::get_first_meta($post->ID, 'date', '');
            $ora            = self::normalize_time(self::get_first_meta($post->ID, 'time', ''));
            $posti_max      = intval(self::get_first_meta($post->ID, 'capacity', 0));
            $posti_occupati = self::get_first_meta($post->ID, 'occupied', true);
            $prenotati      = is_array($posti_occupati) ? count($posti_occupati) : (is_numeric($posti_occupati) ? intval($posti_occupati) : 0);

            $assegnato_user = self::get_proctor_user_id($post->ID);
            $assegnato      = $assegnato_user > 0;
            $assegnato_nome = $assegnato ? self::current_user_display_name($assegnato_user) : null;

            $speciale = ((int) self::get_first_meta($post->ID, 'is_special', 0) === 1);

            $exam_id = (int) self::get_first_meta($post->ID, 'exam_id', 0);
            $exam_title = $exam_id > 0 ? get_the_title($exam_id) : '';
            if (!$exam_title) {
                $exam_title = $exam_id > 0 ? ('Exam #' . $exam_id) : '';
            }

            if (!isset($output[$data])) {
                $output[$data] = [ 'totali'=>0, 'prenotati'=>0, 'slots'=>[] ];
            }

            $output[$data]['totali']    += $posti_max;
            $output[$data]['prenotati'] += $prenotati;
            $output[$data]['slots'][] = [
                'id'              => $post->ID,
                'ora'             => $ora,
                'prenotati'       => $prenotati,
                'totali'          => $posti_max,
                'assegnato'       => $assegnato,
                'assegnato_user'  => $assegnato_user ? intval($assegnato_user) : null,
                'assegnato_nome'  => $assegnato_nome ?: null,
                'speciale'        => $speciale,
                'exam_id'       => $exam_id,
                'exam_title'    => $exam_title,
            ];
        }

        wp_send_json($output);
    }

    public static function ajax_get_user_assigned_slots(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'You are not authenticated.'], 403);

        $oggi = wp_date('Y-m-d');

        $posts = get_posts([
            'post_type'      => self::cpt(),
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                ['key'=>self::meta_keys('proctor')[0],'value'=>$user_id,'compare'=>'='],
                ['key'=>self::meta_keys('date')[0],'value'=>$oggi,'compare'=>'>=','type'=>'DATE'],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => self::meta_keys('date')[0],
            'order'    => 'ASC',
            'fields'   => 'ids',
        ]);

        $out = [];
        foreach ($posts as $slot_id) {
            $data = (string) self::get_first_meta($slot_id, 'date', '');
            $ora  = self::normalize_time(self::get_first_meta($slot_id, 'time', ''));
            $speciale = ((int) self::get_first_meta($slot_id, 'is_special', 0) === 1);

            $exam_id = (int) self::get_first_meta($slot_id, 'exam_id', 0);
            $exam_title = $exam_id > 0 ? get_the_title($exam_id) : '';
            if (!$exam_title) {
                $exam_title = $exam_id > 0 ? ('Exam #' . $exam_id) : '';
            }

            $out[] = [
                'id'           => $slot_id,
                'data'         => $data,
                'data_it'      => $data ? date_i18n('d/m/Y', strtotime($data)) : '',
                'ora'          => $ora ?: '',
                'speciale'     => $speciale,
                'exam_id'    => $exam_id,
                'exam_title' => $exam_title,
            ];
        }

        wp_send_json_success($out);
    }

    public static function ajax_get_all_assigned_slots(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $year  = isset($_GET['year'])  ? max(1970, intval($_GET['year']))  : (int) wp_date('Y');
        $month = isset($_GET['month']) ? max(1, min(12, intval($_GET['month']))) : (int) wp_date('n');

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $posts = get_posts([
            'post_type'      => self::cpt(),
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [[
                'key'     => self::meta_keys('date')[0],
                'value'   => [$start, $end],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]],
            'orderby'  => 'meta_value',
            'meta_key' => self::meta_keys('date')[0],
            'order'    => 'ASC',
            'fields'   => 'ids',
        ]);

        $out = [];
        foreach ($posts as $slot_id) {
            $data = (string) self::get_first_meta($slot_id, 'date', '');
            $ora  = self::normalize_time(self::get_first_meta($slot_id, 'time', ''));
            $ass  = self::is_slot_assigned($slot_id);
            $nome = $ass ? self::get_slot_assigned_name($slot_id) : '';
            $speciale = ((int) self::get_first_meta($slot_id, 'is_special', 0) === 1);

            $exam_id = (int) self::get_first_meta($slot_id, 'exam_id', 0);
            $exam_title = $exam_id > 0 ? get_the_title($exam_id) : '';
            if (!$exam_title) {
                $exam_title = $exam_id > 0 ? ('Exam #' . $exam_id) : '';
            }

            $out[] = [
                'id'             => $slot_id,
                'data'           => $data,
                'data_it'        => $data ? date_i18n('d/m/Y', strtotime($data)) : '',
                'ora'            => $ora ?: '',
                'assegnato'      => $ass,
                'assegnato_nome' => $ass ? ($nome ?: '') : '',
                'speciale'       => $speciale,
                'exam_id'      => $exam_id,
                'exam_title'   => $exam_title,
            ];
        }

        wp_send_json_success($out);
    }

    public static function ajax_assegna_sessione_slot(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'You must be logged in to assign a session.'], 403);

        $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
        if (!$slot_id || get_post_type($slot_id) !== self::cpt()) wp_send_json_error(['message'=>'Invalid session.'],400);

        if (self::is_slot_assigned($slot_id)) {
            $name = self::get_slot_assigned_name($slot_id);
            wp_send_json_error(['message' => 'This session is already assigned' . ($name ? " to $name" : '') . '.'], 409);
        }

        $display = self::current_user_display_name($user_id);

        self::update_first_meta($slot_id, 'assigned', 1);
        self::update_first_meta($slot_id, 'assigned_user', $user_id);
        self::update_first_meta($slot_id, 'assigned_name', $display);
        self::update_first_meta($slot_id, 'assigned_timestamp', current_time('timestamp'));

        self::update_first_meta($slot_id, 'proctor', $user_id);

        self::delete_meta_group($slot_id, 'warn_sent_legacy');
        self::delete_meta_group($slot_id, 'warn_sent_for');

        if (class_exists('MCEMS_Settings') && MCEMS_Settings::email_enabled('cal_email_on_assign', 0)) {
            self::send_calendar_mail(
                'cal_email_subject',
                'cal_email_body',
                self::get_slot_placeholders($slot_id, $display)
            );
        }

        wp_send_json_success(['assegnato_nome' => $display]);
    }

    public static function ajax_modifica_assegnazione_sessione_slot(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'You must be logged in to reassign a session.'], 403);

        $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
        if (!$slot_id || get_post_type($slot_id) !== self::cpt()) wp_send_json_error(['message'=>'Invalid session.'],400);

        $old_user_id  = self::get_proctor_user_id($slot_id);
        $old_assigned = $old_user_id > 0;
        $old_nome     = $old_assigned ? self::current_user_display_name($old_user_id) : '';

        $display = self::current_user_display_name($user_id);

        self::update_first_meta($slot_id, 'assigned', 1);
        self::update_first_meta($slot_id, 'assigned_user', $user_id);
        self::update_first_meta($slot_id, 'assigned_name', $display);
        self::update_first_meta($slot_id, 'assigned_timestamp', current_time('timestamp'));

        self::update_first_meta($slot_id, 'proctor', $user_id);

        self::delete_meta_group($slot_id, 'warn_sent_legacy');
        self::delete_meta_group($slot_id, 'warn_sent_for');

        if (class_exists('MCEMS_Settings') && MCEMS_Settings::email_enabled('cal_email_on_assign', 0)) {
            self::send_calendar_mail(
                'cal_email_subject',
                'cal_email_body',
                self::get_slot_placeholders($slot_id, $display)
            );
        }

        wp_send_json_success([
            'nuovo_nome'   => $display,
            'vecchio_nome' => $old_nome ?: null,
            'vecchio_user' => $old_user_id ? intval($old_user_id) : null,
        ]);
    }

    public static function ajax_elimina_assegnazione_sessione_slot(): void {
        check_ajax_referer(self::NONCE_ACTION);

        $request_user = get_current_user_id();
        if (!$request_user) wp_send_json_error(['message' => 'You must be logged in to remove an assignment.'], 403);

        $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
        if (!$slot_id || get_post_type($slot_id) !== self::cpt()) wp_send_json_error(['message'=>'Invalid session.'],400);

        if (!self::is_slot_assigned($slot_id)) {
            wp_send_json_error(['message' => 'This session is not currently assigned.'], 409);
        }

        $old_user_id = self::get_proctor_user_id($slot_id);
        $old_nome    = $old_user_id > 0 ? self::current_user_display_name($old_user_id) : '';

        self::delete_meta_group($slot_id, 'assigned');
        self::delete_meta_group($slot_id, 'assigned_user');
        self::delete_meta_group($slot_id, 'assigned_name');
        self::delete_meta_group($slot_id, 'assigned_timestamp');

        self::delete_meta_group($slot_id, 'proctor');

        self::delete_meta_group($slot_id, 'warn_sent_legacy');
        self::delete_meta_group($slot_id, 'warn_sent_for');

        if (class_exists('MCEMS_Settings') && MCEMS_Settings::email_enabled('cal_email_on_unassign', 0)) {
            self::send_calendar_mail(
                'cal_email_subject_unassign',
                'cal_email_body_unassign',
                self::get_slot_placeholders($slot_id, $old_nome)
            );
        }

        wp_send_json_success(['message' => 'Assignment removed.']);
    }

    public static function schedule_midnight_event(): void {
        if (wp_next_scheduled(self::CRON_HOOK)) return;

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
        $now = new DateTime('now', $tz);

        $midnight = (clone $now)->setTime(0, 0, 0);
        if ($now >= $midnight) {
            $midnight->modify('+1 day');
        }

        wp_schedule_event($midnight->getTimestamp(), 'daily', self::CRON_HOOK);
    }

    public static function midnight_check_unassigned_slots(): void {
        $target_iso = wp_date('Y-m-d', strtotime('+1 day'));

        $slots = get_posts([
            'post_type'      => self::cpt(),
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [[
                'key'     => self::meta_keys('date')[0],
                'value'   => $target_iso,
                'compare' => '=',
                'type'    => 'DATE',
            ]],
            'fields'         => 'ids',
        ]);

        if (empty($slots)) return;

        foreach ($slots as $slot_id) {
            if (self::is_slot_assigned($slot_id)) {
                $sent_for = self::get_first_meta($slot_id, 'warn_sent_for', true);
                if ($sent_for && $sent_for !== $target_iso) {
                    self::delete_meta_group($slot_id, 'warn_sent_for');
                }
                continue;
            }

            $already_sent_for = self::get_first_meta($slot_id, 'warn_sent_for', true);
            if ($already_sent_for === $target_iso) continue;

            if (class_exists('MCEMS_Settings') && MCEMS_Settings::email_enabled('cal_email_on_unassigned_warning', 1)) {
                self::send_calendar_mail(
                    'cal_email_subject_warning',
                    'cal_email_body_warning',
                    self::get_slot_placeholders($slot_id, ''),
                    self::get_calendar_recipients()
                );
            }

            self::update_first_meta($slot_id, 'warn_sent_for', $target_iso);
        }
    }
}

MCEMS_Calendar_Sessioni::init();