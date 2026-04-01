<?php
if (!defined('ABSPATH')) exit;

class MCEMS_Booking {

    // New: per-exam active exam bookings map exam_id => booking data
    const UM_ACTIVE_BOOKINGS = 'mcems_active_bookings'; // array
    // Legacy single booking
    const UM_ACTIVE_BOOKING  = 'mcems_active_booking';  // array
    const UM_HISTORY         = 'mcems_storico_prenotazioni_slot';

    public static function init(): void {
        add_shortcode('mcems_book_exam', [__CLASS__, 'shortcode_prenota']);
        add_shortcode('mcems_manage_booking', [__CLASS__, 'shortcode_gestisci']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('wp_ajax_mcems_get_slot_per_data', [__CLASS__, 'ajax_get_slots_by_date']);
        add_action('wp_ajax_nopriv_mcems_get_slot_per_data', [__CLASS__, 'ajax_get_slots_by_date']);
        add_action('wp_ajax_mcems_get_booking_calendar', [__CLASS__, 'ajax_get_booking_calendar']);
        add_action('wp_ajax_nopriv_mcems_get_booking_calendar', [__CLASS__, 'ajax_get_booking_calendar']);

        add_action('wp_ajax_mcems_check_active_booking', [__CLASS__, 'ajax_check_active_booking']);

        add_action('wp_ajax_mcems_conferma_prenotazione_slot', [__CLASS__, 'ajax_confirm_booking']);
        add_action('wp_ajax_nopriv_mcems_conferma_prenotazione_slot', [__CLASS__, 'ajax_confirm_booking']);

        add_action('wp_ajax_mcems_cancel_booking', [__CLASS__, 'ajax_cancel_booking']);

        // Cleanup when a session is deleted from admin
        add_action('before_delete_post', [__CLASS__, 'on_before_delete_post'], 10, 1);
        add_action('trashed_post', [__CLASS__, 'on_before_delete_post'], 10, 1);
    }

    /* =========================
       Assets
       ========================= */
    public static function enqueue_assets(): void {
        $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
        $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';

        wp_register_style(
            'mcems-style',
            $url . 'assets/css/style.css',
            [],
            $ver
        );
        wp_enqueue_style('mcems-style');

        wp_register_script(
            'mcems-booking',
            $url . 'assets/js/booking.js',
            [],
            $ver,
            true
        );
        wp_enqueue_script('mcems-booking');

        wp_localize_script('mcems-booking', 'MCEMS_BOOKING', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('mcems_booking'),
            'cancelNonce' => wp_create_nonce('mcems_cancel'),
            'i18n'        => [
                'errorLoadSessions' => __('Error loading sessions.', 'mc-ems-base'),
                'bookingFailed'     => __('Exam booking failed.', 'mc-ems-base'),
                'bookingConfirmed'  => __('Exam booking confirmed!', 'mc-ems-base'),
                'bookingCancelled'  => __('Exam booking cancelled.', 'mc-ems-base'),
                'cancellationFailed' => __('Cancellation failed.', 'mc-ems-base'),
            ],
        ]);
    }

    /* =========================
       Settings
       ========================= */
    private static function get_anticipo_ore(): int {
        return max(0, MCEMS_Settings::get_int('anticipo_ore_prenotazione'));
    }

    private static function get_annullamento_ore(): int {
        return max(0, MCEMS_Settings::get_int('annullamento_ore'));
    }

    private static function is_annullamento_consentito(): bool {
        return MCEMS_Settings::get_int('consenti_annullamento') === 1;
    }

    /**
     * Format local date/time for Google Calendar without shifting timezone.
     * We intentionally use floating local time (no trailing Z) so Google keeps
     * the same wall-clock time selected by the user/site.
     */
    private static function mcems_format_gcal_datetime(string $date, string $time = '00:00:00'): string {
        $dt = trim($date . ' ' . $time);
        $ts = strtotime($dt);
        if (!$ts) return '';
        return gmdate('Ymd\THis', $ts);
    }

    private static function mcems_get_google_calendar_url(int $slot_id, int $exam_id = 0): string {
        if ($slot_id <= 0) return '';

        $date = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $time = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);

        $exam_id = $exam_id > 0
            ? $exam_id
            : (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);

        $exam_title = $exam_id > 0
            ? MCEMS_Tutor::exam_title($exam_id)
            : '';

        if (!$exam_title) {
            $exam_title = 'Exam';
        }

        $start = self::mcems_format_gcal_datetime($date, $time ?: '00:00:00');
        if (!$start) return '';

        // Default event duration: 1 hour
        $end_ts = strtotime(trim($date . ' ' . ($time ?: '00:00:00') . ' +1 hour'));
        $end = $end_ts ? gmdate('Ymd\THis', $end_ts) : '';

        $details = [];
        $details[] = 'Exam booking details';
        $details[] = '';
        $details[] = 'Exam: ' . $exam_title;

        if ($date) {
            $details[] = 'Date: ' . date_i18n('d/m/Y', strtotime($date));
        }

        if ($time) {
            $details[] = 'Time: ' . $time;
        }

        $params = [
            'action'  => 'TEMPLATE',
            'text'    => 'Exam - ' . $exam_title,
            'dates'   => $start . '/' . $end,
            'details' => implode("\n", $details),
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /* =========================
       Active bookings helpers
       ========================= */
    private static function get_active_bookings(int $user_id): array {
        $map = get_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, true);
        if (!is_array($map)) $map = [];

        // One-time migration from legacy single booking (if present)
        $legacy = get_user_meta($user_id, self::UM_ACTIVE_BOOKING, true);
        if (is_array($legacy) && !empty($legacy['slot_id'])) {
            $slot_id = (int) $legacy['slot_id'];
            $exam_id = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);
            if ($exam_id > 0) {
                $map[$exam_id] = [
                    'slot_id'    => $slot_id,
                    'data'       => (string) ($legacy['data'] ?? ''),
                    'orario'     => (string) ($legacy['orario'] ?? ''),
                    'created_at' => (string) ($legacy['created_at'] ?? ''),
                ];
                update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
            }
            delete_user_meta($user_id, self::UM_ACTIVE_BOOKING);
        }

        // Clean invalid bookings (deleted sessions)
        $changed = false;
        foreach ($map as $cid => $b) {
            $sid = isset($b['slot_id']) ? (int) $b['slot_id'] : 0;
            if ($sid <= 0 || get_post_type($sid) !== MCEMS_CPT_Sessioni_Esame::CPT) {
                unset($map[$cid]);
                $changed = true;
                continue;
            }

            $occ = get_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            $occ = is_array($occ) ? $occ : [];
            if (!in_array($user_id, $occ, true)) {
                unset($map[$cid]);
                $changed = true;
            }
        }

        if ($changed) {
            update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
        }

        return $map;
    }

    private static function get_active_booking_for_exam(int $user_id, int $exam_id): array {
        $map = self::get_active_bookings($user_id);
        return isset($map[$exam_id]) && is_array($map[$exam_id]) ? $map[$exam_id] : [];
    }

    private static function set_active_booking_for_exam(int $user_id, int $exam_id, array $booking): void {
        $map = self::get_active_bookings($user_id);
        $map[$exam_id] = $booking;
        update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
    }

    private static function remove_active_booking_for_exam(int $user_id, int $exam_id): void {
        $map = self::get_active_bookings($user_id);
        if (isset($map[$exam_id])) {
            unset($map[$exam_id]);
            update_user_meta($user_id, self::UM_ACTIVE_BOOKINGS, $map);
        }
    }

    /* =========================
       History
       ========================= */
    private static function add_history(int $user_id, int $slot_id, string $azione): void {
        $storico = get_user_meta($user_id, self::UM_HISTORY, true);
        if (!is_array($storico)) $storico = [];

        $data   = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $corso  = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);

        $storico[] = [
            'slot_id'   => $slot_id,
            'data'      => $data,
            'orario'    => $orario,
            'corso_id'  => $corso,
            'azione'    => $azione,
            'timestamp' => (int) current_time('timestamp'),
        ];

        update_user_meta($user_id, self::UM_HISTORY, $storico);
    }

    /* =========================
       Shortcodes
       ========================= */

    public static function shortcode_prenota(): string {
        if (!MCEMS_Settings::user_can_view_shortcode('mcems_book_exam')) {
            return '<p>' . esc_html__('Insufficient permissions.', 'mc-ems-base') . '</p>';
        }

        $user_id   = (int) get_current_user_id();
        $exams   = MCEMS_Tutor::get_exams();
        $booking_exam_ids = MCEMS_Settings::get_booking_exam_ids();
        if (!empty($booking_exam_ids)) {
            $exams = array_intersect_key($exams, array_flip($booking_exam_ids));
        }
        $exam_pt = MCEMS_Tutor::exam_post_type();

        ob_start();
        ?>
        <div id="prenotazione-esame" style="max-width: 640px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;">
            <h2 style="text-align: center; font-size: 1.5rem; margin-bottom: 8px;"><?php echo esc_html__('Book your exam', 'mc-ems-base'); ?></h2>

            <?php if (!$user_id): ?>
                <p style="text-align:center; color:#f44336; font-weight:bold;"><?php echo esc_html__('You must be logged in to book an exam.', 'mc-ems-base'); ?></p>
            <?php else: ?>
                <p style="text-align:center; margin:0 0 16px; font-size: 0.9rem; color:#666;">
                    <?php echo sprintf(
                        /* translators: %d: number of hours before the exam session by which booking must be made */
                        esc_html__('You can book up to %d hours before the exam session time.', 'mc-ems-base'),
                        (int) self::get_anticipo_ore()
                    ); ?>
                </p>

                <label for="mcems_exam_select" style="font-weight:bold; display:block; margin-bottom:8px;"><?php echo esc_html__('Choose the exam:', 'mc-ems-base'); ?></label>
                <?php if (!$exam_pt): ?>
                    <p style="color:#f44336; font-weight:bold;"><?php echo esc_html__('Tutor LMS not detected (exam post type not found).', 'mc-ems-base'); ?></p>
                <?php elseif (!$exams): ?>
                    <p style="color:#f44336; font-weight:bold;"><?php echo esc_html__('No published Tutor LMS exam found.', 'mc-ems-base'); ?></p>
                <?php else: ?>
                    <select id="mcems_exam_select" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ccc; margin-bottom:20px;">
                        <option value=""><?php echo esc_html__('— Select exam —', 'mc-ems-base'); ?></option>
                        <?php foreach ($exams as $cid => $title): ?>
                            <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <input type="hidden" id="data_esame" name="data_esame" value="" />

                <div id="mcems-booking-message" style="display:none; margin-bottom:20px; padding:10px; text-align:center;"></div>

                <div id="mcems-booking-calendar-wrap" style="display:none; margin-bottom:20px;">
                    <label style="font-weight:bold; display:block; margin-bottom:8px; text-align:center;">Choose a date:</label>

                    <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin-bottom:10px;">
                        <button type="button" id="mcems-prev-month" style="background:none; border:none; font-size:18px; cursor:pointer; padding:5px 7px; border-radius:50%;">&larr;</button>
                        <span id="mcems-month-year" style="font-weight:700; font-size:17px; text-transform:capitalize;"></span>
                        <button type="button" id="mcems-next-month" style="background:none; border:none; font-size:18px; cursor:pointer; padding:5px 7px; border-radius:50%;">&rarr;</button>
                    </div>

                    <div style="display:grid; grid-template-columns:repeat(7,1fr); max-width:360px; margin:0 auto 4px; gap:4px;">
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Mon', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Tue', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Wed', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Thu', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Fri', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Sat', 'mc-ems-base'); ?></div>
                        <div style="text-align:center; font-weight:700; font-size:12px; padding:3px 0;"><?php echo esc_html__('Sun', 'mc-ems-base'); ?></div>
                    </div>

                    <div id="mcems-booking-calendar" style="display:grid; grid-template-columns:repeat(7,1fr); max-width:360px; margin:0 auto; gap:4px;"></div>

                    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px; margin:12px auto 0; font-size:12px; color:#555; max-width:420px;">
                        <span style="display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:#4caf50; display:inline-block;"></span>
                            <?php echo esc_html__('High availability', 'mc-ems-base'); ?>
                        </span>
                        <span style="display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:#ffeb3b; display:inline-block; border:1px solid #d4c600;"></span>
                            <?php echo esc_html__('Medium availability', 'mc-ems-base'); ?>
                        </span>
                        <span style="display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:#ff9800; display:inline-block;"></span>
                            <?php echo esc_html__('Low availability', 'mc-ems-base'); ?>
                        </span>
                        <span style="display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:#f44336; display:inline-block;"></span>
                            <?php echo esc_html__('Full', 'mc-ems-base'); ?>
                        </span>
                    </div>
                </div>

                <div id="slot-container" style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px;"></div>

                <div id="confirm-container" style="display:none; text-align:center; margin-top:20px;">
                    <button id="confirm-button" style="background-color:#4CAF50; color:#fff; padding:12px 24px; border:none; border-radius:5px; cursor:pointer;"><?php echo esc_html__('Confirm booking', 'mc-ems-base'); ?></button>
                </div>

                <script>
                document.addEventListener("DOMContentLoaded", function () {
                    const mcemsNonce = '<?php echo esc_js(wp_create_nonce('mcems_booking')); ?>';
                    const examSelect = document.getElementById('mcems_exam_select');
                    const dateInput = document.getElementById('data_esame');
                    const slotContainer = document.getElementById('slot-container');
                    const confirmContainer = document.getElementById('confirm-container');
                    const confirmButton = document.getElementById('confirm-button');
                    const calendarWrap = document.getElementById('mcems-booking-calendar-wrap');
                    const calendarEl = document.getElementById('mcems-booking-calendar');
                    const monthYearEl = document.getElementById('mcems-month-year');
                    const prevMonthBtn = document.getElementById('mcems-prev-month');
                    const nextMonthBtn = document.getElementById('mcems-next-month');

                    let selectedSlot = null;
                    let currentMonthDate = new Date();
                    currentMonthDate.setDate(1);

                    const monthCache = {};
                    const manageBookingUrl = <?php echo json_encode(MCEMS_Settings::get_manage_booking_page_url() ?: ''); ?>;

                    function formatDate(date) {
                        const y = date.getFullYear();
                        const m = String(date.getMonth() + 1).padStart(2, '0');
                        const d = String(date.getDate()).padStart(2, '0');
                        return `${y}-${m}-${d}`;
                    }

                    function resetSlots(msgHtml) {
                        selectedSlot = null;
                        if (confirmContainer) confirmContainer.style.display = 'none';
                        if (slotContainer) slotContainer.innerHTML = msgHtml || '';
                    }

                    function showCalendar(show) {
                        if (calendarWrap) {
                            calendarWrap.style.display = show ? 'block' : 'none';
                        }
                    }

                    function showBookingMessage(msg) {
                        const messageEl = document.getElementById('mcems-booking-message');
                        if (messageEl) {
                            messageEl.innerHTML = msg;
                            messageEl.style.display = 'block';
                        }
                        showCalendar(false);
                        resetSlots('');
                    }

                    function hideBookingMessage() {
                        const messageEl = document.getElementById('mcems-booking-message');
                        if (messageEl) {
                            messageEl.style.display = 'none';
                            messageEl.innerHTML = '';
                        }
                    }

                    function checkExistingBooking() {
                        const examId = examSelect ? examSelect.value : '';
                        if (!examId) {
                            hideBookingMessage();
                            showCalendar(false);
                            return;
                        }

                        const url = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=mcems_check_active_booking&exam_id='
                            + encodeURIComponent(examId)
                            + '&nonce=' + encodeURIComponent(mcemsNonce);

                        fetch(url)
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.has_booking) {
                                    let msg = '<p style="text-align:center;">'
                                        + '<?php echo esc_js(__('You already have an active booking for this exam.', 'mc-ems-base')); ?>'
                                        + '<br>';
                                    if (manageBookingUrl) {
                                        msg += '<?php echo esc_js(__('Go to', 'mc-ems-base')); ?> <a href="' + manageBookingUrl + '">'
                                            + '<?php echo esc_js(__('Manage exam booking', 'mc-ems-base')); ?>'
                                            + '</a> <?php echo esc_js(__('to cancel it.', 'mc-ems-base')); ?>';
                                    } else {
                                        msg += '<?php echo esc_js(__('Please open the Manage exam booking page to cancel it.', 'mc-ems-base')); ?>';
                                    }
                                    msg += '</p>';
                                    showBookingMessage(msg);
                                } else {
                                    hideBookingMessage();
                                    renderBookingCalendar();
                                }
                            })
                            .catch(() => {
                                hideBookingMessage();
                                renderBookingCalendar();
                            });
                    }

                    function ensureExamSelected() {
                        if (!examSelect || !examSelect.value) {
                            if (dateInput) dateInput.value = '';
                            showCalendar(false);
                            resetSlots('<p style="color:#888;"><?php echo esc_js(__('Select an exam first.', 'mc-ems-base')); ?></p>');
                            return false;
                        }
                        showCalendar(true);
                        return true;
                    }

                    function calendarDayClass(dayObj) {
                        if (!dayObj || Number(dayObj.totali || 0) === 0) return 'no-slot';

                        const total = Number(dayObj.totali || 0);
                        const booked = Number(dayObj.prenotati || 0);
                        const free = Math.max(0, total - booked);

                        if (free === total) return 'slot-verde';
                        if (free >= total * 0.5) return 'slot-giallo';
                        if (free > 0) return 'slot-arancione';
                        return 'slot-rosso';
                    }

                    function fetchCalendarMonth(year, month) {
                        const key = `${year}-${month}`;
                        if (monthCache[key]) {
                            return Promise.resolve(monthCache[key]);
                        }

                        const url = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=mcems_get_booking_calendar&year='
                            + encodeURIComponent(year)
                            + '&month=' + encodeURIComponent(month + 1)
                            + '&exam_id=' + encodeURIComponent(examSelect ? examSelect.value : '')
                            + '&nonce=' + encodeURIComponent(mcemsNonce);

                        return fetch(url)
                            .then(r => r.json())
                            .then(data => {
                                monthCache[key] = data || {};
                                return monthCache[key];
                            });
                    }

                    function loadSlotsForDate(dateValue) {
                        if (!dateValue) {
                            resetSlots('<p style="color:#888;"><?php echo esc_js(__('Select a date from the calendar.', 'mc-ems-base')); ?></p>');
                            return;
                        }

                        resetSlots('<p style="color:#666;"><?php echo esc_js(__('Loading available sessions...', 'mc-ems-base')); ?></p>');

                        const url = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=mcems_get_slot_per_data&data='
                            + encodeURIComponent(dateValue)
                            + '&exam_id=' + encodeURIComponent(examSelect ? examSelect.value : '')
                            + '&nonce=' + encodeURIComponent(mcemsNonce);

                        fetch(url)
                            .then(response => response.json())
                            .then(data => {
                                if (data && data.error) {
                                    resetSlots('<p style="color:#f44336; font-weight:bold;">' + data.error + '</p>');
                                    return;
                                }

                                if (!Array.isArray(data) || !data.length) {
                                    resetSlots('<p style="color:#888;"><?php echo esc_js(__('No sessions available for this exam and date.', 'mc-ems-base')); ?></p>');
                                    return;
                                }

                                resetSlots('');

                                data.forEach(slot => {
                                    const btn = document.createElement('button');
                                    btn.type = 'button';
                                    btn.textContent = `${slot.orario}`;
                                    btn.style.margin = '5px';
                                    btn.style.padding = '10px 20px';
                                    btn.style.borderRadius = '5px';
                                    btn.style.border = '2px solid #4CAF50';
                                    btn.style.backgroundColor = '#fff';
                                    btn.style.color = '#4CAF50';
                                    btn.style.cursor = 'pointer';

                                    if (slot.occupati >= slot.max) {
                                        btn.disabled = true;
                                        btn.style.backgroundColor = '#eee';
                                        btn.style.color = '#999';
                                        btn.style.borderColor = '#ccc';
                                        btn.style.cursor = 'not-allowed';
                                    } else {
                                        btn.addEventListener('click', function () {
                                            document.querySelectorAll('#slot-container button').forEach(b => {
                                                b.style.backgroundColor = '#fff';
                                                b.style.color = '#4CAF50';
                                                b.style.borderColor = '#4CAF50';
                                            });

                                            this.style.backgroundColor = '#4CAF50';
                                            this.style.color = '#fff';
                                            selectedSlot = slot.id;

                                            if (confirmContainer) {
                                                confirmContainer.style.display = 'block';
                                            }
                                        });
                                    }

                                    slotContainer.appendChild(btn);
                                });
                            })
                            .catch(() => {
                                resetSlots('<p style="color:#f44336;"><?php echo esc_js(__('Error loading sessions. Please try again.', 'mc-ems-base')); ?></p>');
                            });
                    }

                    function renderBookingCalendar() {
                        if (!ensureExamSelected() || !calendarEl || !monthYearEl) return;

                        const year = currentMonthDate.getFullYear();
                        const month = currentMonthDate.getMonth();

                        monthYearEl.textContent = new Date(year, month, 1).toLocaleString('en-US', {
                            month: 'long',
                            year: 'numeric'
                        });

                        calendarEl.innerHTML = '';

                        const firstDay = new Date(year, month, 1);
                        let startDay = firstDay.getDay();
                        startDay = (startDay === 0) ? 6 : startDay - 1;

                        const daysInMonth = new Date(year, month + 1, 0).getDate();

                        for (let i = 0; i < startDay; i++) {
                            const spacer = document.createElement('div');
                            calendarEl.appendChild(spacer);
                        }

                        for (let day = 1; day <= daysInMonth; day++) {
                            const dayDate = new Date(year, month, day);
                            const dayEl = document.createElement('button');

                            dayEl.type = 'button';
                            dayEl.textContent = day;
                            dayEl.dataset.date = formatDate(dayDate);
                            dayEl.style.border = '1px solid #ddd';
                            dayEl.style.padding = '7px';
                            dayEl.style.aspectRatio = '1';
                            dayEl.style.display = 'flex';
                            dayEl.style.alignItems = 'center';
                            dayEl.style.justifyContent = 'center';
                            dayEl.style.borderRadius = '9px';
                            dayEl.style.fontSize = '13px';
                            dayEl.style.cursor = 'pointer';
                            dayEl.style.background = '#eee';
                            dayEl.style.color = '#777';

                            calendarEl.appendChild(dayEl);
                        }

                        fetchCalendarMonth(year, month)
                            .then(data => {
                                if (data && data.error) {
                                    resetSlots('<p style="color:#f44336;">' + data.error + '</p>');
                                    return;
                                }

                                calendarEl.querySelectorAll('button[data-date]').forEach(dayEl => {
                                    const dateKey = dayEl.dataset.date;
                                    const dayObj = data && data[dateKey] ? data[dateKey] : null;
                                    const cls = calendarDayClass(dayObj);

                                    if (cls === 'no-slot') {
                                        dayEl.style.background = '#eee';
                                        dayEl.style.color = '#777';
                                        dayEl.style.cursor = 'not-allowed';
                                        return;
                                    }

                                    if (cls === 'slot-verde') {
                                        dayEl.style.background = '#4caf50';
                                        dayEl.style.color = '#fff';
                                    } else if (cls === 'slot-giallo') {
                                        dayEl.style.background = '#ffeb3b';
                                        dayEl.style.color = '#000';
                                    } else if (cls === 'slot-arancione') {
                                        dayEl.style.background = '#ff9800';
                                        dayEl.style.color = '#fff';
                                    } else if (cls === 'slot-rosso') {
                                        dayEl.style.background = '#f44336';
                                        dayEl.style.color = '#fff';
                                    }

                                    dayEl.addEventListener('click', function () {
                                        calendarEl.querySelectorAll('button[data-date]').forEach(btn => {
                                            btn.style.outline = 'none';
                                        });

                                        this.style.outline = '2px solid rgba(0,0,0,.18)';

                                        if (dateInput) {
                                            dateInput.value = dateKey;
                                        }

                                        loadSlotsForDate(dateKey);
                                    });
                                });
                            })
                            .catch(() => {
                                resetSlots('<p style="color:#f44336;"><?php echo esc_js(__('Unable to load calendar availability. Please try again.', 'mc-ems-base')); ?></p>');
                            });
                    }

                    if (examSelect) {
                        examSelect.addEventListener('change', function () {
                            Object.keys(monthCache).forEach(key => delete monthCache[key]);

                            if (dateInput) {
                                dateInput.value = '';
                            }

                            resetSlots('<p style="color:#888;"><?php echo esc_js(__('Select a date from the calendar.', 'mc-ems-base')); ?></p>');
                            checkExistingBooking();
                        });
                    }

                    try {
                        const params = new URLSearchParams(window.location.search);
                        const pre = params.get('exam_id');

                        if (pre && examSelect && !examSelect.value) {
                            const exists = Array.from(examSelect.options).some(o => o.value === pre);
                            if (exists) {
                                examSelect.value = pre;
                            }
                        }
                    } catch (e) {}

                    if (prevMonthBtn) {
                        prevMonthBtn.addEventListener('click', function () {
                            currentMonthDate.setMonth(currentMonthDate.getMonth() - 1);
                            renderBookingCalendar();
                        });
                    }

                    if (nextMonthBtn) {
                        nextMonthBtn.addEventListener('click', function () {
                            currentMonthDate.setMonth(currentMonthDate.getMonth() + 1);
                            renderBookingCalendar();
                        });
                    }

                    if (confirmButton) {
                        confirmButton.addEventListener('click', function () {
                            if (!selectedSlot) {
                                return alert('<?php echo esc_js(__('Select an exam session before confirming.', 'mc-ems-base')); ?>');
                            }

                            const formData = new FormData();
                            formData.append('action', 'mcems_conferma_prenotazione_slot');
                            formData.append('slot_id', selectedSlot);

                            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(html => {
                                document.getElementById('prenotazione-esame').innerHTML = html;
                            })
                            .catch(() => {
                                alert('<?php echo esc_js(__('An error occurred. Please try again.', 'mc-ems-base')); ?>');
                            });
                        });
                    }

                    if (examSelect && examSelect.value) {
                        resetSlots('<p style="color:#888;"><?php echo esc_js(__('Select a date from the calendar.', 'mc-ems-base')); ?></p>');
                        renderBookingCalendar();
                    } else {
                        showCalendar(false);
                        resetSlots('<p style="color:#888;"><?php echo esc_js(__('Select an exam first.', 'mc-ems-base')); ?></p>');
                    }
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shortcode_gestisci(): string {
        if (!MCEMS_Settings::user_can_view_shortcode('mcems_manage_booking')) {
            return '<p>' . esc_html__('Insufficient permissions.', 'mc-ems-base') . '</p>';
        }

        $user_id = (int) get_current_user_id();
        if (!$user_id) return '<p>' . esc_html__('You must be logged in.', 'mc-ems-base') . '</p>';

        $map = self::get_active_bookings($user_id);
        if (!$map) {
            $url = MCEMS_Settings::get_booking_page_url();
            if ($url) {
                $btn = '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open exam booking calendar', 'mc-ems-base') . '</a></p>';
                return '<p>' . esc_html__('No active exam booking.', 'mc-ems-base') . '</p>' . $btn;
            }
            return '<p>' . esc_html__('No active exam booking.', 'mc-ems-base') . '</p>';
        }

        uasort($map, function($a, $b) {
            $ka = (string) ($a['data'] ?? '') . ' ' . (string) ($a['orario'] ?? '');
            $kb = (string) ($b['data'] ?? '') . ' ' . (string) ($b['orario'] ?? '');
            return strcmp($ka, $kb);
        });

        ob_start();
        ?>
        <style>
            .mcems-wrap{max-width:980px;margin:0 auto;}
            .mcems-h3{margin:0 0 10px;font-size:1.25rem;}
            .mcems-sub{margin:0 0 18px;color:#667085;font-size:.95rem;}
            .mcems-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;}
            .mcems-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 14px 12px;box-shadow:0 1px 2px rgba(16,24,40,.06);}
            .mcems-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
            .mcems-exam{font-weight:800;font-size:1rem;line-height:1.3;}
            .mcems-meta{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;}
            .mcems-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px;font-weight:700;}
            .mcems-pill strong{font-weight:900;}
            .mcems-actions{margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
            .mcems-btn{appearance:none;border:1px solid #d0d5dd;background:#fff;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block;}
            .mcems-btn:hover{background:#f9fafb;}
            .mcems-muted{color:#667085;font-size:12px;font-weight:700;}
            .mcems-note{margin-top:14px;color:#667085;font-size:12px;}
        </style>

        <div class="mcems-wrap">
            <div style="padding:16px;border:1px solid #e5e7eb;border-radius:16px;background:linear-gradient(180deg,#ffffff 0%, #fbfcff 100%);">
                <div class="mcems-row">
                    <div>
                        <h3 class="mcems-h3"><?php echo esc_html__('My exam bookings', 'mc-ems-base'); ?></h3>
                        <p class="mcems-sub"><?php echo esc_html__('Here you can find your active exam bookings (one per exam). You can cancel them according to the notice rules.', 'mc-ems-base'); ?></p>
                    </div>
                </div>

                <div class="mcems-grid">
                    <?php foreach ($map as $exam_id => $b): ?>
                        <?php
                        $exam_id = (int) $exam_id;
                        $slot_id   = (int) ($b['slot_id'] ?? 0);
                        $data      = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
                        $orario    = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);

                        $slot_ts = strtotime($data . ' ' . $orario);
                        $now_ts  = (int) current_time('timestamp');
                        $cancel_window = (int) self::get_annullamento_ore() * HOUR_IN_SECONDS;

                        $can_cancel = self::is_annullamento_consentito() && (
                            $slot_ts <= $now_ts || ($slot_ts - $now_ts) > $cancel_window
                        );

                        $data_h   = $data ? date_i18n('d/m/Y', strtotime($data)) : '';
                        $gcal_url = self::mcems_get_google_calendar_url($slot_id, $exam_id);
                        ?>
                        <div class="mcems-card">
                            <div class="mcems-row">
                                <div class="mcems-exam"><?php echo esc_html(MCEMS_Tutor::exam_title($exam_id)); ?></div>
                                <div class="mcems-muted">ID: <?php echo (int) $slot_id; ?></div>
                            </div>

                            <div class="mcems-meta">
                                <span class="mcems-pill">📅 <strong><?php echo esc_html($data_h); ?></strong></span>
                                <span class="mcems-pill">⏰ <strong><?php echo esc_html($orario); ?></strong></span>
                            </div>

                            <div class="mcems-actions">
                                <?php if ($gcal_url): ?>
                                    <a class="mcems-btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($gcal_url); ?>"><?php echo esc_html__('Add to Google Calendar', 'mc-ems-base'); ?></a>
                                <?php endif; ?>

                                <?php if ($can_cancel): ?>
                                    <button class="mcems-btn mcems-cancel" data-slot="<?php echo (int) $slot_id; ?>" data-exam="<?php echo (int) $exam_id; ?>"><?php echo esc_html__('Cancel exam booking', 'mc-ems-base'); ?></button>
                                    <?php if ($slot_ts > $now_ts): ?>
                                        <span class="mcems-muted"><?php echo esc_html(sprintf(
                                            /* translators: %d: number of hours before the exam session by which cancellation is allowed */
                                            __('Exam booking cancellation deadline: %dh before the exam session', 'mc-ems-base'),
                                            (int) self::get_annullamento_ore()
                                        )); ?></span>
                                    <?php else: ?>
                                        <span class="mcems-muted"><?php echo esc_html__('Past exam session — cancellation allowed', 'mc-ems-base'); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="mcems-muted"><?php echo esc_html(sprintf(
                                        /* translators: %d: number of hours before the exam session within which cancellation is not allowed */
                                        __('Cancellation is allowed only up to %dh before the exam session.', 'mc-ems-base'),
                                        (int) self::get_annullamento_ore()
                                    )); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="mcems-cancel-msg" class="mcems-note"></div>
            </div>
        </div>

        <script>
        (function(){
            const msg = document.getElementById('mcems-cancel-msg');
            const mcemsBooking = (typeof MCEMS_BOOKING !== 'undefined') ? MCEMS_BOOKING : {};
            const cancelNonce = mcemsBooking.cancelNonce || '';
            const ajaxUrl = mcemsBooking.ajaxUrl || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';

            document.querySelectorAll('.mcems-cancel').forEach(btn => {
                btn.addEventListener('click', function(){
                    if (!confirm('<?php echo esc_js(__('Confirm exam booking cancellation?', 'mc-ems-base')); ?>')) return;

                    const fd = new FormData();
                    fd.append('action','mcems_cancel_booking');
                    fd.append('slot_id', this.dataset.slot || '');
                    fd.append('exam_id', this.dataset.exam || '');
                    fd.append('nonce', cancelNonce);

                    fetch(ajaxUrl, { method:'POST', body: fd })
                        .then(r => r.json())
                        .then(j => {
                            if (j && j.success) {
                                msg.textContent = '✅ <?php echo esc_js(__('Exam booking cancelled.', 'mc-ems-base')); ?>';
                                location.reload();
                            } else {
                                msg.textContent = '⚠️ ' + ((j && j.data) ? j.data : '<?php echo esc_js(__('Error.', 'mc-ems-base')); ?>');
                            }
                        })
                        .catch(() => {
                            msg.textContent='⚠️ <?php echo esc_js(__('Network error', 'mc-ems-base')); ?>';
                        });
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* =========================
       AJAX
       ========================= */

    public static function ajax_check_active_booking(): void {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mcems_booking')) {
            wp_send_json(['has_booking' => false]);
            return;
        }

        $user_id   = (int) get_current_user_id();
        $exam_id = absint($_GET['exam_id'] ?? 0);

        if (!$user_id || $exam_id <= 0) {
            wp_send_json(['has_booking' => false]);
        }

        $active = self::get_active_booking_for_exam($user_id, $exam_id);
        wp_send_json(['has_booking' => !empty($active['slot_id'])]);
    }

    public static function ajax_get_booking_calendar(): void {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mcems_booking')) {
            wp_send_json([]);
            return;
        }

        $exam_id = absint($_GET['exam_id'] ?? 0);
        $year    = absint($_GET['year'] ?? 0);
        $month   = absint($_GET['month'] ?? 0);

        if ($exam_id <= 0) wp_send_json(['error' => 'Select an exam.']);
        if ($year <= 0 || $month < 1 || $month > 12) wp_send_json([]);

        $user_id = (int) get_current_user_id();

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = gmdate('Y-m-t', strtotime($start));

        $slots = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID,
                    'value'   => $exam_id,
                    'compare' => '=',
                ],
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'type'    => 'DATE',
                    'compare' => 'BETWEEN',
                ],
            ],
            'orderby'        => 'meta_value',
            'meta_key'       => MCEMS_CPT_Sessioni_Esame::MK_DATE,
            'order'          => 'ASC',
        ]);

        $out          = [];
        $now_ts       = (int) current_time('timestamp');
        $anticipo_sec = (int) self::get_anticipo_ore() * HOUR_IN_SECONDS;

        foreach ($slots as $slot) {
            $data      = (string) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $orario    = (string) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $max_posti = (int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);
            $occupati  = get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            $occupati  = is_array($occupati) ? $occupati : [];

            $slot_ts = strtotime($data . ' ' . $orario);
            if (($slot_ts - $now_ts) <= $anticipo_sec) continue;

            $is_special = ((int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
            $spec_uid   = (int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);

            if ($is_special && $spec_uid > 0 && $user_id > 0 && $user_id !== $spec_uid) continue;
            if ($is_special && $spec_uid > 0 && $user_id <= 0) continue;

            if (!isset($out[$data])) {
                $out[$data] = [
                    'totali'    => 0,
                    'prenotati' => 0,
                ];
            }

            $out[$data]['totali'] += max(0, $max_posti);
            $out[$data]['prenotati'] += min(max(0, count($occupati)), max(0, $max_posti));
        }

        wp_send_json($out);
    }

    public static function ajax_get_slots_by_date(): void {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mcems_booking')) {
            wp_send_json([]);
            return;
        }

        $data    = isset($_GET['data']) ? sanitize_text_field(wp_unslash($_GET['data'])) : '';
        $exam_id = absint($_GET['exam_id'] ?? 0);

        if (!$data) wp_send_json([]);
        if ($exam_id <= 0) wp_send_json(['error' => 'Select an exam.']);

        $user_id = (int) get_current_user_id();

        $meta_query = [
            [
                'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                'value'   => $data,
                'compare' => '=',
            ],
            [
                'key'     => MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID,
                'value'   => $exam_id,
                'compare' => '=',
            ],
        ];

        $slots = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
            'orderby'        => 'meta_value',
            'meta_key'       => MCEMS_CPT_Sessioni_Esame::MK_TIME,
            'order'          => 'ASC',
        ]);

        $risultati    = [];
        $now_ts       = (int) current_time('timestamp');
        $anticipo_sec = (int) self::get_anticipo_ore() * HOUR_IN_SECONDS;

        foreach ($slots as $slot) {
            $orario    = (string) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $max_posti = (int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);
            $occupati  = get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            $occupati  = is_array($occupati) ? $occupati : [];

            if (count($occupati) >= $max_posti) continue;

            $slot_ts = strtotime($data . ' ' . $orario);
            if (($slot_ts - $now_ts) <= $anticipo_sec) continue;

            $is_special = ((int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
            $spec_uid   = (int) get_post_meta($slot->ID, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);

            if ($is_special && $spec_uid > 0 && $user_id > 0 && $user_id !== $spec_uid) continue;

            $risultati[] = [
                'id'       => (int) $slot->ID,
                'orario'   => $orario,
                'max'      => $max_posti,
                'occupati' => count($occupati),
            ];
        }

        wp_send_json($risultati);
    }

    public static function ajax_confirm_booking(): void {
        $user_id = (int) get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => esc_html__('You must be logged in to book.', 'mc-ems-base')], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mcems_booking')) {
            wp_send_json_error(['message' => esc_html__('Invalid nonce.', 'mc-ems-base')], 400);
        }

        $slot_id = absint($_POST['slot_id'] ?? 0);
        if ($slot_id <= 0 || get_post_type($slot_id) !== MCEMS_CPT_Sessioni_Esame::CPT) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('Invalid exam session.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        $exam_id = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);
        if ($exam_id <= 0) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('Exam session is not associated with an exam.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        $active_for_exam = self::get_active_booking_for_exam($user_id, $exam_id);
        if (!empty($active_for_exam['slot_id']) && (int) $active_for_exam['slot_id'] !== $slot_id) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('You already have an active booking for this exam.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        $lock_key = '_mcems_lock';
        if (get_post_meta($slot_id, $lock_key, true)) {
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('Exam session is being updated, please try again.', 'mc-ems-base') . '</p>';
            wp_die();
        }
        update_post_meta($slot_id, $lock_key, time());

        $data   = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $max    = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);

        $occupati = get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        if (!is_array($occupati)) $occupati = [];

        $is_special = ((int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
        $spec_uid   = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
        if ($is_special && $spec_uid > 0 && $user_id !== $spec_uid) {
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('This exam session is reserved.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        if (in_array($user_id, $occupati, true)) {
            self::set_active_booking_for_exam($user_id, $exam_id, [
                'slot_id'    => $slot_id,
                'data'       => $data,
                'orario'     => $orario,
                'created_at' => current_time('mysql'),
            ]);
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="text-align:center; color:#4CAF50; font-weight:bold;">' . esc_html__('Exam booking already exists.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        if (count($occupati) >= $max) {
            delete_post_meta($slot_id, $lock_key);
            echo '<p style="color:#f44336; font-weight:bold; text-align:center;">' . esc_html__('This exam session is full.', 'mc-ems-base') . '</p>';
            wp_die();
        }

        $occupati[] = $user_id;
        $occupati = array_values(array_unique(array_map('intval', $occupati)));
        update_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, $occupati);

        self::set_active_booking_for_exam($user_id, $exam_id, [
            'slot_id'    => $slot_id,
            'data'       => $data,
            'orario'     => $orario,
            'created_at' => current_time('mysql'),
        ]);

        self::add_history($user_id, $slot_id, 'prenotata');
        delete_post_meta($slot_id, $lock_key);
        self::maybe_send_booking_notifications($user_id, $slot_id, $exam_id, 'booked');

        $exam_title = MCEMS_Tutor::exam_title($exam_id);

        echo '<p style="text-align:center; color:#4CAF50; font-weight:bold;">' . esc_html__('Exam booking confirmed!', 'mc-ems-base') . '</p>';
        if ($exam_title) {
            echo '<p style="text-align:center;">' . esc_html__('Exam:', 'mc-ems-base') . ' <strong>' . esc_html($exam_title) . '</strong></p>';
        }
        echo '<p style="text-align:center;">' . esc_html__('Exam session:', 'mc-ems-base') . ' <strong>' . esc_html(date_i18n('d/m/Y', strtotime($data))) . '</strong> ' . esc_html__('at', 'mc-ems-base') . ' <strong>' . esc_html($orario) . '</strong></p>';

        $gcal_url = self::mcems_get_google_calendar_url($slot_id, $exam_id);
        if ($gcal_url) {
            echo '<p style="text-align:center; margin-top:14px;">';
            echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url($gcal_url) . '">' . esc_html__('Add to Google Calendar', 'mc-ems-base') . '</a>';
            echo '</p>';
        }

        $manage_url = MCEMS_Settings::get_manage_booking_page_url();
        if ($manage_url) {
            $manage_link = add_query_arg(['exam_id' => $exam_id], $manage_url);
            echo '<p style="text-align:center; margin-top:14px;"><a class="button button-primary" href="' . esc_url($manage_link) . '">' . esc_html__('Manage exam booking', 'mc-ems-base') . '</a></p>';
        }

        wp_die();
    }

    public static function ajax_cancel_booking(): void {
        $user_id = (int) get_current_user_id();
        if (!$user_id) wp_send_json_error(__('You must be logged in.', 'mc-ems-base'));

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mcems_cancel')) {
            wp_send_json_error(__('Invalid nonce.', 'mc-ems-base'));
        }

        if (!self::is_annullamento_consentito()) {
            wp_send_json_error(__('Cancellation is disabled.', 'mc-ems-base'));
        }

        $slot_id = absint($_POST['slot_id'] ?? 0);
        $exam_id = absint($_POST['exam_id'] ?? 0);
        if ($slot_id <= 0) wp_send_json_error(__('Invalid exam session.', 'mc-ems-base'));

        if (get_post_type($slot_id) !== MCEMS_CPT_Sessioni_Esame::CPT) {
            if ($exam_id > 0) self::remove_active_booking_for_exam($user_id, $exam_id);
            wp_send_json_success(true);
        }

        $data        = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $orario      = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $slot_exam = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);
        if ($exam_id <= 0) $exam_id = $slot_exam;

        $slot_ts = strtotime($data . ' ' . $orario);
        $now_ts  = (int) current_time('timestamp');

        if ($slot_ts > $now_ts) {
            if (($slot_ts - $now_ts) <= (self::get_annullamento_ore() * HOUR_IN_SECONDS)) {
                wp_send_json_error(__('Too late to cancel.', 'mc-ems-base'));
            }
        }

        $occupati = get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        if (!is_array($occupati)) $occupati = [];

        if (!in_array($user_id, $occupati, true)) {
            if ($exam_id > 0) self::remove_active_booking_for_exam($user_id, $exam_id);
            wp_send_json_error(__('You are not booked on this session (meta realigned).', 'mc-ems-base'));
        }

        $is_special = ((int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true) === 1);
        $spec_uid   = (int) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
        if ($is_special && $spec_uid > 0) {
            wp_send_json_error(__('This session is reserved and cannot be cancelled from the front-end.', 'mc-ems-base'));
        }

        $occupati = array_values(array_filter(array_map('intval', $occupati), function($id) use ($user_id) {
            return (int) $id !== (int) $user_id;
        }));
        update_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, $occupati);

        if ($exam_id > 0) self::remove_active_booking_for_exam($user_id, $exam_id);
        self::add_history($user_id, $slot_id, 'cancelled');
        if ($exam_id > 0) self::maybe_send_booking_notifications($user_id, $slot_id, $exam_id, 'cancelled');

        wp_send_json_success(true);
    }

    private static function email_placeholders($user, $slot_id, $exam_id): array {
        $date         = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
        $time         = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
        $exam_title = MCEMS_Tutor::exam_title($exam_id);
        $date_label   = $date ? date_i18n('d/m/Y', strtotime($date)) : '';

        $manage_url = MCEMS_Settings::get_manage_booking_page_url();
        if ($manage_url) $manage_url = add_query_arg(['exam_id' => $exam_id], $manage_url);

        $booking_url = MCEMS_Settings::get_booking_page_url();
        if ($booking_url) $booking_url = add_query_arg(['exam_id' => $exam_id], $booking_url);

        return [
            '{candidate_name}'     => $user ? (string) $user->display_name : '',
            '{candidate_email}'    => $user ? (string) $user->user_email : '',
            '{exam_title}'       => (string) $exam_title,
            '{session_date}'       => (string) $date_label,
            '{session_time}'       => (string) $time,
            '{manage_booking_url}' => (string) $manage_url,
            '{booking_page_url}'   => (string) $booking_url,
            '{session_id}'         => (string) $slot_id,
        ];
    }

    private static function maybe_send_booking_notifications($user_id, $slot_id, $exam_id, $action): void {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $headers = MCEMS_Settings::get_mail_headers();
        $ph = self::email_placeholders($user, $slot_id, $exam_id);

        if ($action === 'booked') {
            if (MCEMS_Settings::email_enabled('email_send_booking_confirmation', 1) && $user->user_email) {
                $subject = MCEMS_Settings::get_email_template('email_subject_booking_confirmation', 'Exam booking confirmed — {exam_title}');
                $body    = MCEMS_Settings::get_email_template('email_body_booking_confirmation', "Hello {candidate_name}

Your exam booking has been confirmed.
Exam: {exam_title}
Date: {session_date}
Time: {session_time}
Manage exam booking: {manage_booking_url}");

                wp_mail(
                    $user->user_email,
                    MCEMS_Settings::render_email_template($subject, $ph),
                    MCEMS_Settings::render_email_template($body, $ph),
                    $headers
                );
            }

            if (MCEMS_Settings::email_enabled('email_send_admin_booking', 0)) {
                $to = MCEMS_Settings::get_admin_recipients();
                if ($to) {
                    $subject = MCEMS_Settings::get_email_template('email_subject_admin_booking', 'New exam booking — {exam_title}');
                    $body    = MCEMS_Settings::get_email_template('email_body_admin_booking', "A new booking has been created.

Candidate: {candidate_name} <{candidate_email}>
Exam: {exam_title}
Date: {session_date}
Time: {session_time}
Manage exam booking: {manage_booking_url}");

                    wp_mail(
                        $to,
                        MCEMS_Settings::render_email_template($subject, $ph),
                        MCEMS_Settings::render_email_template($body, $ph),
                        $headers
                    );
                }
            }
        }

        if ($action === 'cancelled') {
            if (MCEMS_Settings::email_enabled('email_send_booking_cancellation', 1) && $user->user_email) {
                $subject = MCEMS_Settings::get_email_template('email_subject_booking_cancellation', 'Exam booking cancelled — {exam_title}');
                $body    = MCEMS_Settings::get_email_template('email_body_booking_cancellation', "Hello {candidate_name}

Your exam booking has been cancelled.
Exam: {exam_title}
Date: {session_date}
Time: {session_time}");

                wp_mail(
                    $user->user_email,
                    MCEMS_Settings::render_email_template($subject, $ph),
                    MCEMS_Settings::render_email_template($body, $ph),
                    $headers
                );
            }

            if (MCEMS_Settings::email_enabled('email_send_admin_cancellation', 0)) {
                $to = MCEMS_Settings::get_admin_recipients();
                if ($to) {
                    $subject = MCEMS_Settings::get_email_template('email_subject_admin_cancellation', 'Exam booking cancelled — {exam_title}');
                    $body    = MCEMS_Settings::get_email_template('email_body_admin_cancellation', "A booking has been cancelled.

Candidate: {candidate_name} <{candidate_email}>
Exam: {exam_title}
Date: {session_date}
Time: {session_time}");

                    wp_mail(
                        $to,
                        MCEMS_Settings::render_email_template($subject, $ph),
                        MCEMS_Settings::render_email_template($body, $ph),
                        $headers
                    );
                }
            }
        }
    }

    /* =========================
       Deletion cleanup
       ========================= */
    public static function on_before_delete_post(int $post_id): void {
        if (get_post_type($post_id) !== MCEMS_CPT_Sessioni_Esame::CPT) return;

        $exam_id = (int) get_post_meta($post_id, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, true);
        $occ       = get_post_meta($post_id, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
        $occ       = is_array($occ) ? $occ : [];

        if ($exam_id > 0 && $occ) {
            foreach ($occ as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0) continue;

                $b = self::get_active_booking_for_exam($uid, $exam_id);
                if (!empty($b['slot_id']) && (int) $b['slot_id'] === (int) $post_id) {
                    self::remove_active_booking_for_exam($uid, $exam_id);
                }
            }
        }
    }
}