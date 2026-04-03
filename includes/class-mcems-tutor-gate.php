<?php
if (!defined('ABSPATH')) exit;

/**
 * MC-EMS Tutor LMS time lock (HARD BLOCK - template_redirect)
 *
 * Why: many Tutor/Elementor templates do not use `the_content` in a standard way,
 * so filtering `the_content` may not affect the visible page. This version blocks
 * at routing level and renders a locked page (theme-friendly) before anything else.
 *
 * Data source:
 * - user_meta: mcems_active_bookings[exam_id]['slot_id']
 * - session post meta: MCEMS_CPT_Sessioni_Esame::MK_DATE + MK_TIME
 */
class MCEMS_Tutor_Gate {

    const SIDEBAR_SELECTOR      = '.tutor-card.tutor-card-md.tutor-sidebar-card';
    const DETAILS_TAB_SELECTOR  = '.tutor-course-details-tab.tutor-mt-32';
    const CURRICULUM_SELECTOR   = '.tutor-course-single-curriculum';
    const LESSON_SELECTOR       = '.tutor-course-lesson';
    const QUIZ_SELECTOR         = '.tutor-course-quiz';

    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_block_exam_page'], 0);
    }

    private static function enabled(): bool {
        if (class_exists('MCEMS_Settings')) {

            if (method_exists('MCEMS_Settings', 'get_int')) {
                $v = MCEMS_Settings::get_int('tutor_gate_enabled');

                if ($v !== null && $v !== '') {
                    return (int) $v === 1;
                }
            }

            if (method_exists('MCEMS_Settings', 'get')) {
                $o = (array) MCEMS_Settings::get();

                if (array_key_exists('tutor_gate_enabled', $o)) {
                    return (int) $o['tutor_gate_enabled'] === 1;
                }
            }
        }

        return true; // default ON
    }

    private static function unlock_lead_minutes(): int {
        if (class_exists('MCEMS_Settings') && method_exists('MCEMS_Settings', 'get_int')) {
            return max(0, (int) MCEMS_Settings::get_int('tutor_gate_unlock_lead_minutes'));
        }
        return 0;
    }

    private static function booking_expiry_seconds(): int {
        if (class_exists('MCEMS_Settings') && method_exists('MCEMS_Settings', 'get_int')) {
            $v = max(0, (int) MCEMS_Settings::get_int('tutor_gate_booking_expiry_value'));
            if ($v <= 0) return 0;

            $u = 'hours';
            if (method_exists('MCEMS_Settings', 'get_str')) {
                $u = (string) MCEMS_Settings::get_str('tutor_gate_booking_expiry_unit');
            }

            return ($u === 'minutes') ? ($v * 60) : ($v * 3600);
        }
        return 0;
    }

    private static function bypass_user(int $user_id): bool {
        if ($user_id <= 0) return false;
        if (user_can($user_id, 'manage_options')) return true;
        if (user_can($user_id, 'tutor_instructor') || user_can($user_id, 'tutor_instructor_manager')) return true;
        return false;
    }

    private static function exam_post_types(): array {
        $pts = ['courses', 'tutor_course'];

        if (class_exists('MCEMS_Tutor') && method_exists('MCEMS_Tutor', 'exam_post_type')) {
            $pt = (string) MCEMS_Tutor::exam_post_type();
            if ($pt && !in_array($pt, $pts, true)) {
                $pts[] = $pt;
            }
        }

        return array_values(array_unique($pts));
    }

    private static function current_object_id(): int {
        $id = (int) get_queried_object_id();

        if ($id > 0) {
            return $id;
        }

        $qo = get_queried_object();
        if (is_object($qo) && !empty($qo->ID)) {
            return (int) $qo->ID;
        }

        return 0;
    }

    private static function is_exam_page(?int $object_id = null): bool {
        if (!is_singular()) return false;

        $object_id = $object_id ?: self::current_object_id();
        if ($object_id <= 0) return false;

        $pt = get_post_type($object_id);
        return in_array($pt, self::exam_post_types(), true);
    }

    private static function get_active_slot_id(int $user_id, int $exam_id): int {
        $map = get_user_meta($user_id, 'mcems_active_bookings', true);
        $map = is_array($map) ? $map : [];

        $slot_id = (int) ($map[$exam_id]['slot_id'] ?? 0);

        // backward compatibility: legacy single booking
        if ($slot_id <= 0) {
            $legacy = get_user_meta($user_id, 'mcems_active_booking', true);
            if (is_array($legacy)) {
                $legacy_exam_id = (int) ($legacy['exam_id'] ?? 0);
                $legacy_slot_id   = (int) ($legacy['slot_id'] ?? 0);

                if ($legacy_exam_id === $exam_id && $legacy_slot_id > 0) {
                    $slot_id = $legacy_slot_id;
                }
            }
        }

        $cpt = class_exists('MCEMS_CPT_Sessioni_Esame')
            ? MCEMS_CPT_Sessioni_Esame::CPT
            : 'mcems_exam_session';

        if ($slot_id > 0 && get_post_type($slot_id) !== $cpt) {
            unset($map[$exam_id]);
            update_user_meta($user_id, 'mcems_active_bookings', $map);
            return 0;
        }

        return $slot_id;
    }

    private static function get_session_ts_from_slot(int $slot_id): int {
        if ($slot_id <= 0) return 0;

        $date = '';
        $time = '';

        if (class_exists('MCEMS_CPT_Sessioni_Esame')) {
            $date = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $time = (string) get_post_meta($slot_id, MCEMS_CPT_Sessioni_Esame::MK_TIME, true);
        } else {
            $date = (string) get_post_meta($slot_id, 'data_sessione', true);
            $time = (string) get_post_meta($slot_id, 'orario_sessione', true);
        }

        $date = trim($date);
        $time = trim($time);

        if (!$date || !$time) {
            return 0;
        }

        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $date, $m)) {
            $date = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            return 0;
        }

        if (preg_match('~^(\d{1,2}):(\d{2})~', $time, $m)) {
            $time = str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        } else {
            return 0;
        }

        try {
            $dt = new DateTime($date . ' ' . $time . ':00', wp_timezone());
            return (int) $dt->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function render_locked_page(string $title, string $body_html): void {
        status_header(200);
        nocache_headers();

        get_header();

        $allowed_html = [
            'br'     => [],
            'strong' => [],
            'a'      => [
                'href'  => [],
                'style' => [],
            ],
        ];

        echo '<div class="mcems-locked-exam" style="max-width:820px;margin:28px auto;padding:18px;border-radius:16px;border:1px solid #fda29b;background:#fffbfa;box-shadow:0 10px 30px rgba(16,24,40,.08);">';
        echo '<div style="font-weight:900;color:#b42318;font-size:18px;margin-bottom:8px;">' . esc_html($title) . '</div>';
        echo '<div style="color:#7a271a;font-weight:800;font-size:14px;line-height:1.5;">' . wp_kses($body_html, $allowed_html) . '</div>';
        echo '</div>';

        get_footer();
        exit;
    }

    /**
     * Instead of replacing the entire page, hide the Tutor LMS sidebar via CSS
     * and inject the block message box in its place using JavaScript.
     */
    private static function inject_sidebar_block(string $title, string $body_html): void {
        $css = self::SIDEBAR_SELECTOR . '{display:none!important}'
            . self::DETAILS_TAB_SELECTOR . '{display:none!important}'
            . self::CURRICULUM_SELECTOR . '{display:none!important}'
            . self::LESSON_SELECTOR . '{display:none!important}'
            . self::QUIZ_SELECTOR . '{display:none!important}'
            . '.mcems-locked-exam{max-width:820px;margin:28px auto;padding:18px;border-radius:16px;border:1px solid #fda29b;background:#fffbfa;box-shadow:0 10px 30px rgba(16,24,40,.08);}'
            . '.mcems-locked-exam__title{font-weight:900;color:#b42318;font-size:18px;margin-bottom:8px;}'
            . '.mcems-locked-exam__body{color:#7a271a;font-weight:800;font-size:14px;line-height:1.5;}';

        add_action('wp_enqueue_scripts', static function () use ($css) {
            if (!wp_style_is('mcems-style', 'registered')) {
                $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';
                $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
                wp_register_style('mcems-style', $url . 'assets/css/style.css', [], $ver);
            }
            wp_enqueue_style('mcems-style');
            wp_add_inline_style('mcems-style', $css);
        }, 20);

        $allowed_html = [
            'br'     => [],
            'strong' => [],
            'a'      => [
                'href'  => [],
                'style' => [],
            ],
        ];

        add_action('wp_enqueue_scripts', static function () use ($title, $body_html, $allowed_html) {
            if (!wp_script_is('mcems-booking', 'registered')) {
                $url = defined('MCEMS_PLUGIN_URL') ? MCEMS_PLUGIN_URL : '';
                $ver = defined('MCEMS_VERSION') ? MCEMS_VERSION : '1.0.0';
                wp_register_script('mcems-booking', $url . 'assets/js/booking.js', [], $ver, true);
            }
            wp_enqueue_script('mcems-booking');

            $sidebar_selector = MCEMS_Tutor_Gate::SIDEBAR_SELECTOR;
            $js = '(function(){'
                . 'var sidebar=document.querySelector(' . wp_json_encode($sidebar_selector) . ');'
                . 'if(!sidebar)return;'
                . 'var box=document.createElement("div");'
                . 'box.className="mcems-locked-exam";'
                . 'box.innerHTML=\'<div class="mcems-locked-exam__title">\'+' . wp_json_encode(esc_html($title)) . '+\'</div>\''
                . '+\'<div class="mcems-locked-exam__body">\'+' . wp_json_encode(wp_kses($body_html, $allowed_html)) . '+\'</div>\';'
                . 'sidebar.parentNode.insertBefore(box,sidebar);'
                . '})();';
            wp_add_inline_script('mcems-booking', $js);
        }, 20);
    }

    private static function get_manage_booking_url(): string {
        $mb = '';

        if (class_exists('MCEMS_Settings') && method_exists('MCEMS_Settings', 'get_manage_booking_page_url')) {
            $mb = (string) MCEMS_Settings::get_manage_booking_page_url();
        }

        return $mb;
    }

    private static function get_gate_exam_ids(): array {
        $gate_exams = [];

        if (class_exists('MCEMS_Settings') && method_exists('MCEMS_Settings', 'get_gate_exam_ids')) {
            $gate_exams = (array) MCEMS_Settings::get_gate_exam_ids();
        } else {
            if (class_exists('MCEMS_Settings') && method_exists('MCEMS_Settings', 'get')) {
                $o = (array) MCEMS_Settings::get();
                $gate_exams = is_array($o['tutor_gate_exam_ids'] ?? null) ? $o['tutor_gate_exam_ids'] : [];
            }
        }

        return array_values(array_unique(array_filter(array_map('absint', (array) $gate_exams))));
    }

    public static function maybe_block_exam_page(): void {
        if (!self::enabled()) return;
        if (is_admin()) return;

        $exam_id = self::current_object_id();
        if ($exam_id <= 0) return;

        if (!self::is_exam_page($exam_id)) return;

        $gate_exams = self::get_gate_exam_ids();
        if (!empty($gate_exams) && !in_array($exam_id, $gate_exams, true)) {
            return;
        }

        $user_id = (int) get_current_user_id();

        if ($user_id <= 0) {
            self::inject_sidebar_block(
                __('Restricted access', 'mc-ems-exam-center-for-tutor-lms'),
                esc_html__('You must be logged in to access this exam.', 'mc-ems-exam-center-for-tutor-lms')
            );
            return;
        }

        if (self::bypass_user($user_id)) {
            return;
        }

        $slot_id = self::get_active_slot_id($user_id, $exam_id);

        if ($slot_id <= 0) {
            $body = esc_html__('To access, you must first create a booking for an exam session.', 'mc-ems-exam-center-for-tutor-lms');

            $mb = self::get_manage_booking_url();
            if ($mb) {
                $body .= '<br><br><a href="' . esc_url($mb) . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#1a73e8;color:#fff;text-decoration:none;font-weight:900;">' . esc_html__('Manage exam booking', 'mc-ems-exam-center-for-tutor-lms') . '</a>';
            }

            self::inject_sidebar_block(
                esc_html__('Exam access not available', 'mc-ems-exam-center-for-tutor-lms'),
                $body
            );
            return;
        }

        $session_ts = self::get_session_ts_from_slot($slot_id);

        if ($session_ts <= 0) {
            self::inject_sidebar_block(
                __('Exam access not available', 'mc-ems-exam-center-for-tutor-lms'),
                esc_html__('Your exam booking does not contain a valid date/time. Please contact support.', 'mc-ems-exam-center-for-tutor-lms')
            );
            return;
        }

        $unlock_ts = max(0, $session_ts - (self::unlock_lead_minutes() * 60));
        $now_ts    = (int) current_time('timestamp');
        $expiry    = self::booking_expiry_seconds();

        if ($expiry > 0) {
            $expiry_ts = $session_ts + $expiry;

            if ($now_ts > $expiry_ts) {
                $map = get_user_meta($user_id, 'mcems_active_bookings', true);
                $map = is_array($map) ? $map : [];

                if (isset($map[$exam_id])) {
                    unset($map[$exam_id]);
                    update_user_meta($user_id, 'mcems_active_bookings', $map);
                }

                $body = esc_html__('Your exam booking has expired and is no longer valid to access this exam. Please book a new exam session.', 'mc-ems-exam-center-for-tutor-lms');

                $mb = self::get_manage_booking_url();
                if ($mb) {
                    $body .= '<br><br><a href="' . esc_url($mb) . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#1a73e8;color:#fff;text-decoration:none;font-weight:900;">' . esc_html__('Manage exam booking', 'mc-ems-exam-center-for-tutor-lms') . '</a>';
                }

                self::inject_sidebar_block(
                    esc_html__('Exam access not available', 'mc-ems-exam-center-for-tutor-lms'),
                    $body
                );
                return;
            }
        }

        if ($now_ts >= $unlock_ts) {
            return;
        }

        $unlock_h = wp_date('d/m/Y \a\t H:i', $unlock_ts, wp_timezone());

        self::inject_sidebar_block(
            esc_html__('Exam locked', 'mc-ems-exam-center-for-tutor-lms'),
            sprintf(
                '%s <strong>%s</strong>.',
                esc_html__('You can access starting from', 'mc-ems-exam-center-for-tutor-lms'),
                esc_html($unlock_h)
            )
        );
    }
}
