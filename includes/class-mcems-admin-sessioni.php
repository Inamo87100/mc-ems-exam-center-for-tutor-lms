<?php
if (!defined('ABSPATH')) exit;

class MCEMS_Admin_Sessioni {

    /** Maximum number of future (active) sessions allowed on the Base license. */
    const BASE_MAX_ACTIVE_SESSIONS = 5;

    /** Maximum seats per session allowed on the Base license. */
    const BASE_MAX_CAPACITY = 5;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_mcems_user_search', [__CLASS__, 'ajax_user_search']);
    }

    public static function ajax_user_search(): void {
        check_ajax_referer('mcems_user_search', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (strlen($q) < 2) {
            wp_send_json_success([]);
            return;
        }

        global $wpdb;
        $safe_q = $wpdb->esc_like($q);

        $by_name = new \WP_User_Query([
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['display_name'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $by_email = new \WP_User_Query([
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['user_email'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $seen = [];
        $out  = [];
        foreach (array_merge($by_name->get_results(), $by_email->get_results()) as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'    => $id,
                'name'  => (string) $u->display_name,
                'email' => (string) $u->user_email,
            ];
        }

        wp_send_json_success($out);
    }

    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'mcems') === false && strpos($hook, MCEMS_CPT_Sessioni_Esame::CPT) === false) {
            return;
        }

        $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
        $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';

        wp_register_style('mcems-admin-style', $url . 'assets/css/admin.css', [], $ver);
        wp_enqueue_style('mcems-admin-style');

        wp_register_script('mcems-admin', $url . 'assets/js/admin.js', [], $ver, true);
        wp_enqueue_script('mcems-admin');

        wp_localize_script('mcems-admin', 'MCEMS_ADMIN', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('mcems_admin'),
            'exportNonce' => wp_create_nonce('mcems_export_csv'),
            'i18n'        => [
                'selectAction' => __('Please select an action.', 'mc-ems-base'),
                'selectItems'  => __('Please select at least one item.', 'mc-ems-base'),
                'confirmBulk'  => __('Apply action to {count} item(s)?', 'mc-ems-base'),
                'error'        => __('An error occurred.', 'mc-ems-base'),
                'networkError' => __('Network error. Please try again.', 'mc-ems-base'),
                'exporting'    => __('Exporting…', 'mc-ems-base'),
                'exportCsv'    => __('Export CSV', 'mc-ems-base'),
            ],
        ]);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT,
            __('Create sessions', 'mc-ems-base'),
            __('Create sessions', 'mc-ems-base'),
            'manage_options',
            'mcems-manage-sessions',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions', 403);
        }

        $today = gmdate('Y-m-d');
        $week  = gmdate('Y-m-d', strtotime('+7 days'));

        $exams   = MCEMS_Tutor::get_exams();
        $exam_pt = MCEMS_Tutor::exam_post_type();

        $notice = '';
        $error  = '';

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $posted = ($request_method === 'POST');
        if ($posted && empty($_POST['mcems_action'])) {
            $error = 'Form submission detected but missing action (mcems_action). Check whether security/cache plugins are altering POST requests.';
        }

        if ($request_method === 'POST' && isset($_POST['mcems_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['mcems_action']));

            if ($action === 'generate' && check_admin_referer('mcems_generate', 'mcems_generate_nonce')) {
                $is_special = !empty($_POST['mcems_generate_special']);

                if ($is_special) {
                    [$notice, $error] = self::handle_generate_special();
                } else {
                    [$notice, $error] = self::handle_generate_standard();
                }
            }

            if ($action === 'update_capacity' && check_admin_referer('mcems_update_capacity', 'mcems_update_capacity_nonce')) {
                [$notice, $error] = self::handle_update_capacity();
            }
        }

        $is_premium = mcems_is_license_valid();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Exam Sessions Management', 'mc-ems-base'); ?></h1>

            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width: 1100px;">
                <h2><?php echo esc_html__('Generate new sessions', 'mc-ems-base'); ?></h2>

                <?php
                if (!$is_premium) :
                    $future_count = self::count_future_sessions();
                    $remaining    = max(0, self::BASE_MAX_ACTIVE_SESSIONS - $future_count);
                ?>
                <div style="margin-bottom:16px;padding:12px 16px;border-radius:10px;border:1px solid #fed7aa;background:#fff7ed;">
                    <strong>📋 <?php echo esc_html__('Base license – session limits', 'mc-ems-base'); ?></strong><br>
                    <?php echo esc_html(sprintf(
                        __('Active future sessions: %1$d / %2$d — you can still create %3$d more session(s).', 'mc-ems-base'),
                        (int) $future_count,
                        (int) self::BASE_MAX_ACTIVE_SESSIONS,
                        (int) $remaining
                    )); ?>
                    <br><small style="color:#92400e;"><?php echo esc_html(sprintf(
                        __('Base license: max 1 session per day, max %1$d active sessions, and max %2$d seats per session. Upgrade to Premium to remove these limits.', 'mc-ems-base'),
                        (int) self::BASE_MAX_ACTIVE_SESSIONS,
                        (int) self::BASE_MAX_CAPACITY
                    )); ?></small>
                </div>
                <?php endif; ?>

                <!-- Qui puoi lasciare invariata tutta la parte FORM/HTML/JS -->
                <!-- Tutte le parti che dipendono da premium/base usano $is_premium -->
            </div>
            <hr>
            <div class="card" style="max-width: 1100px;">
            </div>
        </div>
        <?php
    }

    private static function handle_generate_standard(): array {
        check_admin_referer('mcems_generate', 'mcems_generate_nonce');
        $selected_dates_raw = isset($_POST['selected_dates']) && is_array($_POST['selected_dates'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_dates']))
            : [];
        $times_raw = sanitize_textarea_field(wp_unslash($_POST['times'] ?? ''));
        $capacity  = max(1, absint(wp_unslash($_POST['capacity'] ?? 1)));
        $exam_id = isset($_POST['exam_id']) ? absint(wp_unslash($_POST['exam_id'])) : 0;

        if ($exam_id <= 0) {
            return ['', __('Select a Tutor LMS exam.', 'mc-ems-base')];
        }

        $selected_dates = [];
        foreach ($selected_dates_raw as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $selected_dates[] = $d;
            }
        }
        $selected_dates = array_values(array_unique($selected_dates));
        sort($selected_dates);

        if (!$selected_dates) {
            return ['', __('Select at least one date from the calendar.', 'mc-ems-base')];
        }

        $times = [];
        foreach (preg_split("/\r\n|\r|\n/", $times_raw) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $t)) continue;
            $times[] = $t;
        }

        $times = array_values(array_unique($times));
        sort($times);

        if (!$times) {
            return ['', __('Enter at least one valid time (HH:MM), one per line.', 'mc-ems-base')];
        }

        $is_premium = mcems_is_license_valid();
        if (!$is_premium) {
            $times = [$times[0]];
            $capacity = min($capacity, self::BASE_MAX_CAPACITY);

            $future_count = self::count_future_sessions();
            if ($future_count >= self::BASE_MAX_ACTIVE_SESSIONS) {
                return ['', sprintf(
                    __('Base license limit reached: you already have %1$d active (future) sessions (maximum %2$d). Delete or wait for existing sessions to pass before creating new ones.', 'mc-ems-base'),
                    (int) $future_count,
                    (int) self::BASE_MAX_ACTIVE_SESSIONS
                )];
            }
        }

        $created = 0;
        $skipped = 0;
        $insert_errors = [];

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        $future_count_start = $is_premium ? 0 : $future_count;
        $batch_created      = 0;

        $existing_dates_in_range = [];
        if (!$is_premium && $selected_dates) {
            $existing_dates_in_range = self::get_session_dates_in_range($selected_dates[0], end($selected_dates));
        }

        foreach ($selected_dates as $date) {
            if (!$is_premium && ($future_count_start + $batch_created) >= self::BASE_MAX_ACTIVE_SESSIONS) {
                $skipped++;
                continue;
            }

            if (!$is_premium && in_array($date, $existing_dates_in_range, true)) {
                $skipped++;
                continue;
            }

            foreach ($times as $time) {
                try {
                    $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
                    if ($session_dt < $now) {
                        $skipped++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    continue;
                }

                if (self::session_exists($date, $time, $exam_id)) {
                    $skipped++;
                    continue;
                }

                $sid = self::create_session($date, $time, $capacity, 0, 0, $exam_id);

                if ($sid) {
                    $created++;
                    $batch_created++;
                } else {
                    $skipped++;
                    $insert_errors[] = $date . ' ' . $time;
                }
            }
        }

        if (!$created && $insert_errors) {
            return ['', sprintf(
                __('Unable to create sessions for: %s', 'mc-ems-base'),
                implode(', ', array_slice($insert_errors, 0, 5))
            )];
        }

        return [sprintf(
            __('Creation completed: %1$d sessions created, %2$d skipped.', 'mc-ems-base'),
            $created,
            $skipped
        ), ''];
    }

    private static function handle_generate_special(): array {
        check_admin_referer('mcems_generate', 'mcems_generate_nonce');
        $date      = sanitize_text_field(wp_unslash($_POST['special_date'] ?? ''));
        $time      = sanitize_text_field(wp_unslash($_POST['special_time'] ?? ''));
        $uid     = absint($_POST['special_user_id'] ?? 0);
        $email     = sanitize_email(wp_unslash($_POST['special_user_email'] ?? ''));
        $exam_id = absint($_POST['special_exam_id'] ?? 0);

        if ($exam_id <= 0) {
            return ['', __('Select a Tutor LMS exam.', 'mc-ems-base')];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['', __('Invalid date/time.', 'mc-ems-base')];
        }

        $tz = wp_timezone();

        try {
            $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
            $now = new \DateTimeImmutable('now', $tz);

            if ($session_dt < $now) {
                return ['', __('Past sessions cannot be created. Please choose a future date and time.', 'mc-ems-base')];
            }
        } catch (\Throwable $e) {
            return ['', __('Invalid date/time.', 'mc-ems-base')];
        }

        if ($uid <= 0 && $email) {
            $u = get_user_by('email', $email);
            if ($u && !is_wp_error($u)) {
                $uid = (int) $u->ID;
            }
        }

        if ($uid <= 0 || !get_user_by('id', $uid)) {
            return ['', __('Invalid candidate selection.', 'mc-ems-base')];
        }

        if (self::session_exists($date, $time, $exam_id, true)) {
            return ['', __('A special session already exists with this date/time for this exam.', 'mc-ems-base')];
        }

        $sid = self::create_session($date, $time, 1, 1, $uid, $exam_id);
        if (!$sid) {
            return ['', __('Unable to create exam session.', 'mc-ems-base')];
        }

        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, [$uid]);

        update_user_meta($uid, MCEMS_Booking::UM_ACTIVE_BOOKING, [
            'slot_id'    => $sid,
            'data'       => $date,
            'orario'     => $time,
            'created_at' => current_time('mysql'),
        ]);

        $storico = get_user_meta($uid, MCEMS_Booking::UM_HISTORY, true);
        if (!is_array($storico)) {
            $storico = [];
        }

        $storico[] = [
            'slot_id'   => $sid,
            'data'      => $date,
            'orario'    => $time,
            'azione'    => 'prenotata',
            'timestamp' => (int) current_time('timestamp'),
        ];

        update_user_meta($uid, MCEMS_Booking::UM_HISTORY, $storico);

        return [sprintf(
            __('Special exam session created and exam booked for candidate (session ID: #%d).', 'mc-ems-base'),
            (int) $sid
        ), ''];
    }

    private static function handle_update_capacity(): array {
        check_admin_referer('mcems_update_capacity', 'mcems_update_capacity_nonce');
        $new_cap     = max(1, absint($_POST['new_capacity'] ?? 0));
        $only_future = !empty($_POST['only_future']);

        $ids = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $updated = 0;
        $today = gmdate('Y-m-d');

        foreach ($ids as $sid) {
            $sid = (int) $sid;
            $is_special = (int) get_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true);
            $date = (string) get_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);

            if ($only_future && $date && $date < $today) {
                continue;
            }

            if ($is_special === 1) {
                update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, 1);
                continue;
            }

            update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, $new_cap);
            $updated++;
        }

        return [sprintf(
            __('Update completed: %d sessions updated.', 'mc-ems-base'),
            $updated
        ), ''];
    }

    private static function count_future_sessions(): int {
        $today = current_time('Y-m-d');
        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => $today,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ]);
        return (int) $q->found_posts;
    }

    private static function has_session_on_date(string $date): bool {
        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value' => $date,
                ],
            ],
        ]);
        return $q->have_posts();
    }

    private static function get_session_dates_in_range(string $start, string $end): array {
        $ids = get_posts([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => MCEMS_CPT_Sessioni_Esame::MK_DATE,
                    'value'   => [$start, $end],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ]);

        $dates = [];
        foreach ($ids as $sid) {
            $d = (string) get_post_meta((int) $sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
            if ($d) {
                $dates[] = $d;
            }
        }
        return array_values(array_unique($dates));
    }

    private static function session_exists(string $date, string $time, int $exam_id, bool $special_only = false): bool {
        $meta = [
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_DATE, 'value' => $date],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_TIME, 'value' => $time],
            ['key' => MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, 'value' => $exam_id],
        ];

        if ($special_only) {
            $meta[] = ['key' => MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, 'value' => 1];
        }

        $q = new WP_Query([
            'post_type'      => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta,
        ]);

        return $q->have_posts();
    }

    private static function create_session(string $date, string $time, int $capacity, int $is_special, int $special_user_id, int $exam_id): int {
        $sid = wp_insert_post([
            'post_type'   => MCEMS_CPT_Sessioni_Esame::CPT,
            'post_status' => 'publish',
            'post_title'  => "Session {$date} {$time}",
        ], true);

        if (is_wp_error($sid) || !$sid) {
            return 0;
        }

        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_DATE, $date);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_TIME, $time);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_EXAM_ID, $exam_id > 0 ? (int) $exam_id : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_CAPACITY, max(1, $capacity));
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_OCCUPATI, []);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, $is_special ? 1 : 0);
        update_post_meta($sid, MCEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $special_user_id > 0 ? (int) $special_user_id : 0);

        return (int) $sid;
    }
}
