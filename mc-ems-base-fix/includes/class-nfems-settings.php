<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Settings {

    const OPTION_KEY = 'nfems_settings';

    public static function defaults(): array {
        return [
            'tutor_gate_enabled' => 1,
            'tutor_gate_unlock_lead_minutes' => 0,
            'tutor_gate_booking_expiry_value' => 0,
            'tutor_gate_booking_expiry_unit'  => 'hours',
            'tutor_gate_course_ids' => [],
            'anticipo_ore_prenotazione' => 48,
            'consenti_annullamento'     => 1,
            'annullamento_ore'          => 48,
            'cap_view_bookings'         => 'nfems_view_bookings',
            'cap_assign_proctor'        => 'nfems_assign_proctor',
            'cap_admin'                 => 'manage_options',

            // Calendar permissions / behaviour
            'cal_allow_reassign'               => 1,
            'cal_allow_unassign'               => 1,

            // Pages
            'booking_page_id'           => 0,
            'manage_booking_page_id'    => 0,

            // Email notifications
            'email_sender_name'                 => get_bloginfo('name'),
            'email_sender_email'                => get_option('admin_email'),
            'email_admin_recipients'            => get_option('admin_email'),
            'email_send_booking_confirmation'   => 1,
            'email_send_booking_cancellation'   => 1,
            'email_send_admin_booking'          => 0,
            'email_send_admin_cancellation'     => 0,

            // Calendar / proctor email settings
            'cal_email_on_assign'               => 0,
            'cal_email_on_unassign'             => 0,
            'cal_email_on_unassigned_warning'   => 1,
            'cal_email_notify_to'               => get_option('admin_email'),

            'email_subject_booking_confirmation' => 'Exam booking confirmed — {course_title}',
            'email_body_booking_confirmation'    => "Hello {candidate_name},\n\nYour exam booking has been confirmed.\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nManage exam booking: {manage_booking_url}",
            'email_subject_booking_cancellation' => 'Exam booking cancelled — {course_title}',
            'email_body_booking_cancellation'    => "Hello {candidate_name},\n\nYour exam booking has been cancelled.\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}",
            'email_subject_admin_booking'        => 'New exam booking — {course_title}',
            'email_body_admin_booking'           => "A new booking has been created.\n\nCandidate: {candidate_name} <{candidate_email}>\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nManage exam booking: {manage_booking_url}",
            'email_subject_admin_cancellation'   => 'Exam booking cancelled — {course_title}',
            'email_body_admin_cancellation'      => "A booking has been cancelled.\n\nCandidate: {candidate_name} <{candidate_email}>\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}",

            'cal_email_subject'                  => 'Exam session assigned — {session_date} {session_time}',
            'cal_email_body'                     => "An exam session has been assigned.\n\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nProctor: {proctor_name}\nSession ID: {session_id}",

            'cal_email_subject_unassign'         => 'Exam session unassigned — {session_date} {session_time}',
            'cal_email_body_unassign'            => "An exam session assignment has been removed.\n\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nPrevious proctor: {proctor_name}\nSession ID: {session_id}",

            'cal_email_subject_warning'          => 'Unassigned exam session reminder — {session_date} {session_time}',
            'cal_email_body_warning'             => "The following exam session is scheduled for tomorrow and still has no assigned proctor.\n\nCourse: {course_title}\nDate: {session_date}\nTime: {session_time}\nSession ID: {session_id}",
        ];
    }

    /**
     * Selected booking page ID (0 if not set).
     */
    public static function get_booking_page_id(): int {
        return max(0, self::get_int('booking_page_id'));
    }

    /**
     * Exam booking page URL (empty string if not set / invalid).
     */
    public static function get_booking_page_url(): string {
        $pid = self::get_booking_page_id();
        if ($pid <= 0) return '';
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'page' || $p->post_status !== 'publish') return '';
        return (string) get_permalink($pid);
    }

    /**
     * Selected manage-booking page ID (0 if not set).
     */
    public static function get_manage_booking_page_id(): int {
        return max(0, self::get_int('manage_booking_page_id'));
    }

    /**
     * Manage exam booking page URL (empty string if not set / invalid).
     */
    public static function get_manage_booking_page_url(): string {
        $pid = self::get_manage_booking_page_id();
        if ($pid <= 0) return '';
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'page' || $p->post_status !== 'publish') return '';
        return (string) get_permalink($pid);
    }

    public static function get(): array {
        $opt = get_option(self::OPTION_KEY, []);
        if (!is_array($opt)) $opt = [];
        return array_merge(self::defaults(), $opt);
    }

    public static function get_int(string $key): int {
        $o = self::get();
        return (int) ($o[$key] ?? 0);
    }

    public static function get_array(string $key): array {
        $o = self::get();
        $v = $o[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public static function get_gate_course_ids(): array {
        $ids = self::get_array('tutor_gate_course_ids');
        $clean = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0) $clean[] = $id;
        }
        return array_values(array_unique($clean));
    }

    public static function get_str(string $key): string {
        $o = self::get();
        return (string) ($o[$key] ?? '');
    }

    public static function get_admin_recipients(): array {
        $raw = self::get_str('email_admin_recipients');
        if ($raw === '') {
            $raw = (string) get_option('admin_email');
        }
        $emails = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw)));
        $out = [];
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && is_email($email)) $out[] = $email;
        }
        return array_values(array_unique($out));
    }

    public static function email_enabled(string $key, int $default = 0): bool {
        $o = self::get();
        if (!array_key_exists($key, $o)) {
            return (bool) $default;
        }
        return (int) ($o[$key] ?? 0) === 1;
    }

    public static function get_mail_headers(): array {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $from_email = sanitize_email(self::get_str('email_sender_email'));
        if (!$from_email || !is_email($from_email)) {
            $from_email = (string) get_option('admin_email');
        }
        $from_name = trim((string) self::get_str('email_sender_name'));
        if ($from_name === '') {
            $from_name = (string) get_bloginfo('name');
        }
        if ($from_email) {
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $from_email . '>';
        }
        return $headers;
    }

    public static function get_email_template(string $key, string $fallback = ''): string {
        $value = trim(self::get_str($key));
        return $value !== '' ? $value : $fallback;
    }

    public static function render_email_template(string $template, array $replacements = []): string {
        $pairs = array_merge(['{site_name}' => (string) get_bloginfo('name')], $replacements);
        $clean = [];
        foreach ($pairs as $k => $v) {
            $clean[(string) $k] = (string) $v;
        }
        return strtr($template, $clean);
    }

    public static function init_admin(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function menu(): void {
        add_options_page(
            'MC-EMS',
            'MC-EMS',
            'manage_options',
            'nfems-settings',
            [__CLASS__, 'render']
        );

        add_submenu_page(
            'edit.php?post_type=' . NFEMS_CPT_Sessioni_Esame::CPT,
            __('MC-EMS Settings', 'mc-ems'),
            __('MC-EMS Settings', 'mc-ems'),
            'manage_options',
            'nfems-settings-cpt',
            [__CLASS__, 'render']
        );
    }

    public static function register(): void {
        add_action('wp_ajax_mcems_search_pages', [__CLASS__, 'ajax_search_pages']);
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [__CLASS__, 'sanitize']);

        add_settings_section('nfems_section_main', __('Bookings', 'mc-ems'), function () {
            echo '<p class="description">Main rules for booking availability and cancellations.</p>';
        }, self::OPTION_KEY);

        add_settings_field('anticipo_ore_prenotazione', __('Booking allowed up to (hours)', 'mc-ems'), [__CLASS__, 'field_number'], self::OPTION_KEY, 'nfems_section_main', [
            'key' => 'anticipo_ore_prenotazione',
            'min' => 0,
            'max' => 720,
            'step'=> 1,
            'desc'=> 'Example: 48 = do not show sessions with notice < 48 hours.'
        ]);

        add_settings_field('consenti_annullamento', __('Allow booking cancellation', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_main', [
            'key' => 'consenti_annullamento',
            'desc'=> 'If disabled, the user will not be able to cancel from the front-end.'
        ]);

        add_settings_field('annullamento_ore', __('Cancellation allowed up to (hours)', 'mc-ems'), [__CLASS__, 'field_number'], self::OPTION_KEY, 'nfems_section_main', [
            'key' => 'annullamento_ore',
            'min' => 0,
            'max' => 720,
            'step'=> 1,
            'desc'=> __('Example: 48 = cancellation allowed only if more than 48 hours remain.', 'mc-ems')
        ]);

        add_settings_section('nfems_section_gate', __('Course access settings', 'mc-ems'), function () {
            echo '<p class="description">Define how long an exam booking remains valid for course access after the exam session time.</p>';
        }, self::OPTION_KEY);

        add_settings_field('tutor_gate_booking_expiry_combo', __('Booking validity after session', 'mc-ems'), [__CLASS__, 'field_booking_expiry_combo'], self::OPTION_KEY, 'nfems_section_gate', [
            'value_key' => 'tutor_gate_booking_expiry_value',
            'unit_key'  => 'tutor_gate_booking_expiry_unit',
            'min'       => 0,
            'max'       => 100000,
            'step'      => 1,
            'desc'      => '0 = never expires.'
        ]);

        add_settings_field('tutor_gate_course_ids', __('Protected courses', 'mc-ems'), [__CLASS__, 'field_course_multiselect'], self::OPTION_KEY, 'nfems_section_gate', [
            'key'  => 'tutor_gate_course_ids',
            'desc' => __('If you select one or more courses, the course access gate will apply only to those courses. If left empty, the gate applies to all Tutor LMS courses.', 'mc-ems'),
        ]);

        add_settings_section('nfems_section_email', __('Email settings', 'mc-ems'), function () {
            echo '<p class="description">Choose which notifications to send and configure sender/recipient settings.</p>';
        }, self::OPTION_KEY);

        add_settings_field('email_sender_name', __('Sender name', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_sender_name',
            'placeholder' => __('Example: MC-EMS Notifications', 'mc-ems'),
            'desc' => __('Name shown as the email sender.', 'mc-ems')
        ]);

        add_settings_field('email_sender_email', __('Sender email', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_sender_email',
            'type' => 'email',
            'placeholder' => __('notifications@example.com', 'mc-ems'),
            'desc' => __('Email address used in the From header.', 'mc-ems')
        ]);

        add_settings_field('email_admin_recipients', __('Admin recipients', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_admin_recipients',
            'placeholder' => __('admin@example.com, exams@example.com', 'mc-ems'),
            'desc' => __('Comma-separated list of recipients for admin notifications.', 'mc-ems')
        ]);

        add_settings_field('email_send_booking_confirmation', __('Exam booking confirmation email', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_send_booking_confirmation',
            'desc'=> __('Send a confirmation email to the candidate after a booking is created.', 'mc-ems')
        ]);

        add_settings_field('email_send_booking_cancellation', __('Exam booking cancellation email', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_send_booking_cancellation',
            'desc'=> __('Send a confirmation email to the candidate after a exam booking is cancelled.', 'mc-ems')
        ]);

        add_settings_field('email_send_admin_booking', __('Admin exam booking notification', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_send_admin_booking',
            'desc'=> __('Notify the configured admin recipients when an exam booking is created.', 'mc-ems')
        ]);

        add_settings_field('email_send_admin_cancellation', __('Admin exam booking cancellation notification', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_send_admin_cancellation',
            'desc'=> __('Notify the configured admin recipients when a exam booking is cancelled.', 'mc-ems')
        ]);

        add_settings_field('cal_allow_reassign', __('Allow proctor reassignment', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_allow_reassign',
            'desc'=> __('Allow replacing the currently assigned proctor from the calendar.', 'mc-ems')
        ]);

        add_settings_field('cal_allow_unassign', __('Allow proctor unassignment', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_allow_unassign',
            'desc'=> __('Allow removing the current proctor assignment from the calendar.', 'mc-ems')
        ]);

        add_settings_field('cal_email_on_assign', __('Proctor assignment email', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_on_assign',
            'desc'=> __('Send an email when a proctor is assigned to an exam session.', 'mc-ems')
        ]);

        add_settings_field('cal_email_on_unassign', __('Proctor unassignment email', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_on_unassign',
            'desc'=> __('Send an email when a proctor assignment is removed from an exam session.', 'mc-ems')
        ]);

        add_settings_field('cal_email_on_unassigned_warning', __('24-hour unassigned session warning', 'mc-ems'), [__CLASS__, 'field_checkbox'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_on_unassigned_warning',
            'desc'=> __('Send a daily warning email for tomorrow\'s sessions that still have no assigned proctor.', 'mc-ems')
        ]);

        add_settings_field('cal_email_notify_to', __('Calendar email recipients', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_notify_to',
            'placeholder' => __('admin@example.com, exams@example.com', 'mc-ems'),
            'desc' => __('Comma-separated list of recipients for calendar assignment, unassignment and warning emails.', 'mc-ems')
        ]);

        add_settings_field('email_subject_booking_confirmation', __('Exam booking confirmation subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_subject_booking_confirmation',
            'placeholder' => __('Exam booking confirmed — {course_title}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {candidate_name}, {candidate_email}, {course_title}, {session_date}, {session_time}, {manage_booking_url}, {booking_page_url}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('email_body_booking_confirmation', __('Exam booking confirmation body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_body_booking_confirmation',
            'rows' => 8,
            'desc' => __('Plain-text email body. Same placeholders as above.', 'mc-ems')
        ]);

        add_settings_field('email_subject_booking_cancellation', __('Exam booking cancellation subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_subject_booking_cancellation',
            'placeholder' => __('Exam booking cancelled — {course_title}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {candidate_name}, {candidate_email}, {course_title}, {session_date}, {session_time}, {manage_booking_url}, {booking_page_url}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('email_body_booking_cancellation', __('Exam booking cancellation body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_body_booking_cancellation',
            'rows' => 8,
            'desc' => __('Plain-text email body. Same placeholders as above.', 'mc-ems')
        ]);

        add_settings_field('email_subject_admin_booking', __('Admin exam booking subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_subject_admin_booking',
            'placeholder' => __('New exam booking — {course_title}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {candidate_name}, {candidate_email}, {course_title}, {session_date}, {session_time}, {manage_booking_url}, {booking_page_url}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('email_body_admin_booking', __('Admin exam booking body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_body_admin_booking',
            'rows' => 8,
            'desc' => __('Plain-text email body. Same placeholders as above.', 'mc-ems')
        ]);

        add_settings_field('email_subject_admin_cancellation', __('Admin cancellation subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_subject_admin_cancellation',
            'placeholder' => __('Exam booking cancelled — {course_title}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {candidate_name}, {candidate_email}, {course_title}, {session_date}, {session_time}, {manage_booking_url}, {booking_page_url}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('email_body_admin_cancellation', __('Admin cancellation body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'email_body_admin_cancellation',
            'rows' => 8,
            'desc' => __('Plain-text email body. Same placeholders as above.', 'mc-ems')
        ]);

        add_settings_field('cal_email_subject', __('Proctor assignment subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_subject',
            'placeholder' => __('Exam session assigned — {session_date} {session_time}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {proctor_name}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('cal_email_body', __('Proctor assignment body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_body',
            'rows' => 8,
            'desc' => __('Plain-text email body. Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {proctor_name}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('cal_email_subject_unassign', __('Proctor unassignment subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_subject_unassign',
            'placeholder' => __('Exam session unassigned — {session_date} {session_time}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {proctor_name}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('cal_email_body_unassign', __('Proctor unassignment body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_body_unassign',
            'rows' => 8,
            'desc' => __('Plain-text email body. Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {proctor_name}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('cal_email_subject_warning', __('Unassigned session warning subject', 'mc-ems'), [__CLASS__, 'field_text'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_subject_warning',
            'placeholder' => __('Unassigned exam session reminder — {session_date} {session_time}', 'mc-ems'),
            'desc' => __('Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {session_id}', 'mc-ems')
        ]);

        add_settings_field('cal_email_body_warning', __('Unassigned session warning body', 'mc-ems'), [__CLASS__, 'field_textarea'], self::OPTION_KEY, 'nfems_section_email', [
            'key' => 'cal_email_body_warning',
            'rows' => 8,
            'desc' => __('Plain-text email body. Placeholders: {site_name}, {course_title}, {session_date}, {session_time}, {session_id}', 'mc-ems')
        ]);

        add_settings_section('nfems_section_pages', __('Pages', 'mc-ems'), function () {
            echo '<p class="description">Select the pages used by MC-EMS for front-end navigation.</p>';
        }, self::OPTION_KEY);

        add_settings_field('booking_page_id', __('Exam booking page (calendar)', 'mc-ems'), [__CLASS__, 'field_page_dropdown_clear'], self::OPTION_KEY, 'nfems_section_pages', [
            'key'  => 'booking_page_id',
            'desc' => __('Choose the page where you placed the [mcems_book_exam] shortcode. The plugin will use this to generate dynamic links to the exam booking calendar.', 'mc-ems'),
        ]);

        add_settings_field('manage_booking_page_id', __('Manage exam booking page', 'mc-ems'), [__CLASS__, 'field_page_dropdown_clear'], self::OPTION_KEY, 'nfems_section_pages', [
            'key'  => 'manage_booking_page_id',
            'desc' => __('Choose the page where you placed the [mcems_manage_booking] shortcode. The plugin will use this to generate dynamic links to the “Manage exam booking” page.', 'mc-ems'),
        ]);
    }

    /**
     * AJAX: search pages by title (admin only).
     * Returns: {success:true,data:[{id,title,status}]}
     */
    public static function ajax_search_pages(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('mcems_search_pages', 'nonce');

        $q = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
        $q = trim($q);

        $results = [];
        if ($q !== '') {
            $posts = get_posts([
                'post_type'      => 'page',
                'post_status'    => ['publish','private','draft'],
                's'              => $q,
                'posts_per_page' => 20,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            foreach ($posts as $p) {
                $title = trim((string) get_the_title($p));
                if ($title === '') $title = '(no title)';
                $results[] = [
                    'id'     => (int) $p->ID,
                    'title'  => $title,
                    'status' => (string) $p->post_status,
                ];
            }
        }

        wp_send_json_success($results);
    }

    public static function sanitize($input): array {
        $out = self::get();
        $tab = isset($_POST['nfems_current_tab']) ? sanitize_key((string) $_POST['nfems_current_tab']) : '';

        if ($tab === 'course_access' || array_key_exists('tutor_gate_enabled', $input)) {
            $out['tutor_gate_enabled'] = !empty($input['tutor_gate_enabled']) ? 1 : 0;
        }
        if (isset($input['tutor_gate_unlock_lead_minutes'])) {
            $out['tutor_gate_unlock_lead_minutes'] = max(0, min(1440, (int) $input['tutor_gate_unlock_lead_minutes']));
        }
        if (isset($input['tutor_gate_booking_expiry_value'])) {
            $out['tutor_gate_booking_expiry_value'] = max(0, min(100000, (int) $input['tutor_gate_booking_expiry_value']));
        }
        if (isset($input['tutor_gate_booking_expiry_unit'])) {
            $u = (string) $input['tutor_gate_booking_expiry_unit'];
            $out['tutor_gate_booking_expiry_unit'] = in_array($u, ['minutes','hours'], true) ? $u : 'hours';
        }
        if (isset($input['tutor_gate_course_ids'])) {
            $ids = $input['tutor_gate_course_ids'];
            if (!is_array($ids)) $ids = [];
            $clean = [];
            foreach ($ids as $id) {
                $id = absint($id);
                if ($id > 0) $clean[] = $id;
            }
            $clean = array_values(array_unique($clean));
            if (class_exists('NFEMS_Tutor') && method_exists('NFEMS_Tutor', 'get_courses')) {
                $valid = array_map('intval', array_keys(NFEMS_Tutor::get_courses()));
                $clean = array_values(array_intersect($clean, $valid));
            }
            $out['tutor_gate_course_ids'] = $clean;
        }

        if (isset($input['anticipo_ore_prenotazione'])) $out['anticipo_ore_prenotazione'] = max(0, min(720, (int) $input['anticipo_ore_prenotazione']));
        if ($tab === 'bookings' || array_key_exists('consenti_annullamento', $input)) {
            $out['consenti_annullamento'] = !empty($input['consenti_annullamento']) ? 1 : 0;
        }
        if (isset($input['annullamento_ore'])) $out['annullamento_ore'] = max(0, min(720, (int) $input['annullamento_ore']));

        if (isset($input['booking_page_id'])) {
            $pid = absint($input['booking_page_id']);
            if ($pid > 0) {
                $p = get_post($pid);
                $out['booking_page_id'] = ($p && $p->post_type === 'page') ? $pid : 0;
            } else {
                $out['booking_page_id'] = 0;
            }
        }

        if (isset($input['manage_booking_page_id'])) {
            $pid = absint($input['manage_booking_page_id']);
            if ($pid > 0) {
                $p = get_post($pid);
                $out['manage_booking_page_id'] = ($p && $p->post_type === 'page') ? $pid : 0;
            } else {
                $out['manage_booking_page_id'] = 0;
            }
        }

        if (isset($input['email_sender_name'])) {
            $out['email_sender_name'] = sanitize_text_field($input['email_sender_name']);
        }
        if (isset($input['email_sender_email'])) {
            $email = sanitize_email($input['email_sender_email']);
            $out['email_sender_email'] = ($email && is_email($email)) ? $email : '';
        }

        if (isset($input['email_admin_recipients'])) {
            $raw = sanitize_textarea_field($input['email_admin_recipients']);
            $emails = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw)));
            $valid = [];
            foreach ($emails as $email) {
                $email = sanitize_email($email);
                if ($email && is_email($email)) $valid[] = $email;
            }
            $out['email_admin_recipients'] = implode(', ', array_values(array_unique($valid)));
        }

        if (isset($input['cal_email_notify_to'])) {
            $raw = sanitize_textarea_field($input['cal_email_notify_to']);
            $emails = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw)));
            $valid = [];
            foreach ($emails as $email) {
                $email = sanitize_email($email);
                if ($email && is_email($email)) $valid[] = $email;
            }
            $out['cal_email_notify_to'] = implode(', ', array_values(array_unique($valid)));
        }

        if ($tab === 'email' || array_key_exists('email_send_booking_confirmation', $input)) {
            $out['email_send_booking_confirmation'] = !empty($input['email_send_booking_confirmation']) ? 1 : 0;
            $out['email_send_booking_cancellation'] = !empty($input['email_send_booking_cancellation']) ? 1 : 0;
            $out['email_send_admin_booking'] = !empty($input['email_send_admin_booking']) ? 1 : 0;
            $out['email_send_admin_cancellation'] = !empty($input['email_send_admin_cancellation']) ? 1 : 0;
            $out['cal_email_on_assign'] = !empty($input['cal_email_on_assign']) ? 1 : 0;
            $out['cal_email_on_unassign'] = !empty($input['cal_email_on_unassign']) ? 1 : 0;
            $out['cal_email_on_unassigned_warning'] = !empty($input['cal_email_on_unassigned_warning']) ? 1 : 0;
            $out['cal_allow_reassign'] = !empty($input['cal_allow_reassign']) ? 1 : 0;
            $out['cal_allow_unassign'] = !empty($input['cal_allow_unassign']) ? 1 : 0;
        }

        foreach ([
            'email_subject_booking_confirmation',
            'email_subject_booking_cancellation',
            'email_subject_admin_booking',
            'email_subject_admin_cancellation',
            'cal_email_subject',
            'cal_email_subject_unassign',
            'cal_email_subject_warning'
        ] as $k) {
            if (isset($input[$k])) $out[$k] = sanitize_text_field($input[$k]);
        }

        foreach ([
            'email_body_booking_confirmation',
            'email_body_booking_cancellation',
            'email_body_admin_booking',
            'email_body_admin_cancellation',
            'cal_email_body',
            'cal_email_body_unassign',
            'cal_email_body_warning'
        ] as $k) {
            if (isset($input[$k])) $out[$k] = sanitize_textarea_field($input[$k]);
        }

        return $out;
    }

    private static function render_only_sections(array $section_ids): void {
        global $wp_settings_sections, $wp_settings_fields;

        $page = self::OPTION_KEY;
        if (empty($wp_settings_sections[$page])) return;

        foreach ($wp_settings_sections[$page] as $section_id => $section) {
            if (!in_array($section_id, $section_ids, true)) continue;

            if ($section['title']) {
                echo '<h2>' . esc_html($section['title']) . '</h2>';
            }
            if ($section['callback']) {
                call_user_func($section['callback'], $section);
            }

            echo '<table class="form-table" role="presentation">';
            if (!empty($wp_settings_fields[$page][$section_id])) {
                foreach ((array) $wp_settings_fields[$page][$section_id] as $field) {
                    echo '<tr>';
                    echo '<th scope="row">';
                    if (!empty($field['args']['label_for'])) {
                        echo '<label for="' . esc_attr($field['args']['label_for']) . '">' . esc_html($field['title']) . '</label>';
                    } else {
                        echo esc_html($field['title']);
                    }
                    echo '</th>';
                    echo '<td>';
                    call_user_func($field['callback'], $field['args']);
                    echo '</td>';
                    echo '</tr>';
                }
            }
            echo '</table>';
        }
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mc-ems'), 403);
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'shortcodes';
        $allowed = ['shortcodes','bookings','course_access','email','pages'];
        if (!in_array($tab, $allowed, true)) $tab = 'shortcodes';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('MC-EMS Settings', 'mc-ems') . '</h1>';

        $tabs = [
            'shortcodes'    => __('Shortcodes', 'mc-ems'),
            'bookings'      => __('Exam booking settings', 'mc-ems'),
            'course_access' => __('Course access settings', 'mc-ems'),
            'email'         => __('Email settings', 'mc-ems'),
            'pages'         => __('Pages', 'mc-ems'),
        ];

        echo '<h2 class="nav-tab-wrapper" style="margin-top:12px;">';
        foreach ($tabs as $key => $label) {
            $url = esc_url(add_query_arg(['page' => 'nfems-settings', 'tab' => $key], admin_url('options-general.php')));
            $cls = ($tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="' . esc_attr($cls) . '" href="' . $url . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'shortcodes') {
            $premium_active = defined('EMS_PREMIUM_VERSION') || class_exists('EMS_Premium_Bootstrap');

            echo '<div style="margin:16px 0;padding:14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;">';
            echo '<h2 style="margin:0 0 10px 0;">' . esc_html__('Available shortcodes', 'mc-ems') . '</h2>';
            echo '<table class="widefat striped" style="margin:0;">';
            echo '<thead><tr><th style="width:260px;">Shortcode</th><th>' . esc_html__('Description', 'mc-ems') . '</th><th style="width:140px;">' . esc_html__('Availability', 'mc-ems') . '</th></tr></thead><tbody>';

            echo '<tr><td><code>[mcems_book_exam]</code></td><td>' . esc_html__('Exam booking (select course → calendar → choose exam session).', 'mc-ems') . '</td><td><span style="font-weight:800;color:#067647;">' . esc_html__('Base', 'mc-ems') . '</span></td></tr>';
            echo '<tr><td><code>[mcems_manage_booking]</code></td><td>' . esc_html__('Shows the logged-in user exam bookings and allows cancellation.', 'mc-ems') . '</td><td><span style="font-weight:800;color:#067647;">' . esc_html__('Base', 'mc-ems') . '</span></td></tr>';
            echo '<tr><td><code>[mcems_sessions_calendar]</code></td><td>' . esc_html__('Calendar to assign proctors to exam sessions.', 'mc-ems') . '</td><td><span style="font-weight:800;color:#067647;">' . esc_html__('Base', 'mc-ems') . '</span></td></tr>';

            $tag = $premium_active
                ? '<span style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;background:#e8f0fe;color:#1a73e8;font-weight:900;font-size:12px;">' . esc_html__('Premium active', 'mc-ems') . '</span>'
                : '<span style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;background:#fffbfa;color:#b42318;font-weight:900;font-size:12px;">' . esc_html__('🔒 Premium', 'mc-ems') . '</span>';

            echo '<tr><td><code>[mcems_bookings_list]</code></td><td>' . esc_html__('Full exam bookings list (with filters/search). Available only with MC-EMS Premium.', 'mc-ems') . '</td><td>' . $tag . '</td></tr>';

            echo '</tbody></table>';
            echo '</div>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        echo '<input type="hidden" name="nfems_current_tab" value="' . esc_attr($tab) . '">';

        if ($tab === 'bookings') {
            self::render_only_sections(['nfems_section_main']);
        } elseif ($tab === 'course_access') {
            self::render_only_sections(['nfems_section_gate']);
        } elseif ($tab === 'email') {
            self::render_only_sections(['nfems_section_email']);
        } elseif ($tab === 'pages') {
            self::render_only_sections(['nfems_section_pages']);
        }

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function field_course_multiselect(array $args): void {
        $key  = (string) ($args['key'] ?? '');
        $desc = (string) ($args['desc'] ?? '');
        $opt  = self::get();
        $sel  = $opt[$key] ?? [];
        $sel  = is_array($sel) ? array_map('absint', $sel) : [];
        $sel  = array_values(array_unique(array_filter($sel)));

        $courses = [];
        if (class_exists('NFEMS_Tutor') && method_exists('NFEMS_Tutor', 'get_courses')) {
            $courses = NFEMS_Tutor::get_courses();
        }

        $id_filter = 'nfems_course_filter_' . $key;
        $id_select = 'nfems_course_select_' . $key;

        echo '<div style="max-width:560px">';
        echo '<input type="text" id="'.esc_attr($id_filter).'" placeholder="Search course…" style="width:100%;max-width:520px;padding:8px 10px;border-radius:10px;border:1px solid #d0d5dd;margin-bottom:8px;">';

        if (!$courses) {
            echo '<p><em>No published Tutor LMS course found.</em></p>';
        } else {
            echo '<select id="'.esc_attr($id_select).'" name="'.esc_attr(self::OPTION_KEY).'['.esc_attr($key).'][]" multiple size="10" style="width:100%;max-width:520px;border-radius:10px;border:1px solid #d0d5dd;padding:6px;">';
            foreach ($courses as $cid => $title) {
                $cid = (int) $cid;
                $label = $title . ' (#' . $cid . ')';
                printf(
                    '<option value="%d"%s>%s</option>',
                    $cid,
                    in_array($cid, $sel, true) ? ' selected' : '',
                    esc_html($label)
                );
            }
            echo '</select>';
            if ($desc) {
                echo '<p class="description" style="margin-top:8px;">'.esc_html($desc).'</p>';
            }
            echo '<p class="description">Tip: hold CTRL / ⌘ to select multiple courses.</p>';
        }

        echo '<script>(function(){var input=document.getElementById("'.esc_js($id_filter).'");var sel=document.getElementById("'.esc_js($id_select).'");if(!input||!sel)return;var opts=Array.prototype.slice.call(sel.options).map(function(o){return {el:o,text:(o.text||"").toLowerCase()};});input.addEventListener("input",function(){var q=(input.value||"").toLowerCase().trim();opts.forEach(function(o){o.el.style.display=(!q||o.text.indexOf(q)!==-1)?"":"none";});});})();</script>';

        echo '</div>';
    }

    public static function field_page_dropdown_clear($args): void {
        $o    = self::get();
        $key  = (string) ($args['key'] ?? '');
        $desc = (string) ($args['desc'] ?? '');
        $val  = absint($o[$key] ?? 0);

        $name = self::OPTION_KEY . '[' . $key . ']';
        $select_id = 'mcems_page_select_' . $key;

        echo '<div style="max-width:520px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';

        wp_dropdown_pages([
            'name'              => $name,
            'id'                => $select_id,
            'selected'          => $val,
            'show_option_none'  => '— Select page —',
            'option_none_value' => '0',
            'post_status'       => ['publish','private','draft'],
        ]);

        echo '<button type="button" class="button" onclick="(function(){var s=document.getElementById(\'' . esc_js($select_id) . '\'); if(s){s.value=\'0\'; if(document.createEvent){var e=document.createEvent(\'HTMLEvents\'); e.initEvent(\'change\',true,false); s.dispatchEvent(e);} }})();">Clear</button>';

        echo '</div>';

        if ($desc) {
            echo '<p class="description" style="margin-top:8px;">' . esc_html($desc) . '</p>';
        }
    }

    public static function field_booking_expiry_combo(array $args): void {
        $o = self::get();
        $value_key = (string) ($args['value_key'] ?? '');
        $unit_key  = (string) ($args['unit_key'] ?? '');
        $min = (int) ($args['min'] ?? 0);
        $max = (int) ($args['max'] ?? 100000);
        $step= (int) ($args['step'] ?? 1);
        $desc= (string) ($args['desc'] ?? '');

        $val = isset($o[$value_key]) ? (int) $o[$value_key] : 0;
        $unit = isset($o[$unit_key]) ? (string) $o[$unit_key] : 'hours';
        if (!in_array($unit, ['minutes','hours'], true)) $unit = 'hours';

        echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
        printf(
            '<input type="number" name="%s[%s]" value="%d" min="%d" max="%d" step="%d" style="width:120px;" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($value_key),
            (int) $val,
            (int) $min,
            (int) $max,
            (int) $step
        );
        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($unit_key) . ']" style="min-width:160px;">';
        echo '<option value="minutes"' . selected($unit, 'minutes', false) . '>Minutes</option>';
        echo '<option value="hours"' . selected($unit, 'hours', false) . '>Hours</option>';
        echo '</select>';
        echo '</div>';
        if ($desc) {
            echo '<p class="description" style="margin-top:6px;">' . esc_html($desc) . '</p>';
        }
    }

    public static function field_number($args): void {
        $o = self::get();
        $key = $args['key'];
        $min = (int) ($args['min'] ?? 0);
        $max = (int) ($args['max'] ?? 9999);
        $step= (int) ($args['step'] ?? 1);
        $desc= (string) ($args['desc'] ?? '');
        $val = (int) ($o[$key] ?? 0);

        printf(
            '<input type="number" name="%s[%s]" value="%d" min="%d" max="%d" step="%d" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $val,
            $min,
            $max,
            $step
        );
        if ($desc) echo '<p class="description">'.esc_html($desc).'</p>';
    }

    public static function field_select($args): void {
        $o = self::get();
        $key = (string) ($args['key'] ?? '');
        $options = (array) ($args['options'] ?? []);
        $desc= (string) ($args['desc'] ?? '');
        $val = (string) ($o[$key] ?? '');

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']">';
        foreach ($options as $k => $label) {
            $k = (string) $k;
            $label = (string) $label;
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
        }
        echo '</select>';
        if ($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
    }

    public static function field_text($args): void {
        $o = self::get();
        $key = (string) ($args['key'] ?? '');
        $type = (string) ($args['type'] ?? 'text');
        $desc = (string) ($args['desc'] ?? '');
        $placeholder = (string) ($args['placeholder'] ?? '');
        $val = (string) ($o[$key] ?? '');

        printf(
            '<input type="%s" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />',
            esc_attr($type),
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr($val),
            esc_attr($placeholder)
        );
        if ($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
    }

    public static function field_textarea($args): void {
        $o = self::get();
        $key = (string) ($args['key'] ?? '');
        $desc = (string) ($args['desc'] ?? '');
        $placeholder = (string) ($args['placeholder'] ?? '');
        $rows = (int) ($args['rows'] ?? 3);
        if ($rows < 2) $rows = 3;
        $val = (string) ($o[$key] ?? '');

        printf(
            '<textarea name="%s[%s]" rows="%d" class="large-text" placeholder="%s">%s</textarea>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $rows,
            esc_attr($placeholder),
            esc_textarea($val)
        );
        if ($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
    }

    public static function field_checkbox($args): void {
        $o = self::get();
        $key = $args['key'];
        $desc= (string) ($args['desc'] ?? '');
        $val = !empty($o[$key]) ? 1 : 0;

        printf(
            '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            checked($val, 1, false),
            esc_html($desc)
        );
    }
}