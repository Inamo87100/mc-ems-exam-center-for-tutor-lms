<?php
if (!defined('ABSPATH')) exit;

/**
 * NF-EMS Tutor LMS time lock (HARD BLOCK - template_redirect)
 *
 * Why: many Tutor/Elementor templates do not use `the_content` in a standard way,
 * so filtering `the_content` may not affect the visible page. This version blocks
 * at routing level and renders a locked page (theme-friendly) before anything else.
 *
 * Data source:
 * - user_meta: nfems_active_bookings[course_id]['slot_id']
 * - session post meta: NFEMS_CPT_Sessioni_Esame::MK_DATE + MK_TIME
 */
class NFEMS_Tutor_Gate {

    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_block_course_page'], 0);
    }

    private static function enabled(): bool {
        if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_int')) {
            return (int) NFEMS_Settings::get_int('tutor_gate_enabled') === 1;
        }
        return true; // default ON
    }

    private static function unlock_lead_minutes(): int {
        if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_int')) {
            return max(0, (int) NFEMS_Settings::get_int('tutor_gate_unlock_lead_minutes'));
        }
        return 0;
    }

    private static function booking_expiry_seconds(): int {
        if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_int')) {
            $v = max(0, (int) NFEMS_Settings::get_int('tutor_gate_booking_expiry_value'));
            if ($v <= 0) return 0;
            $u = 'hours';
            if (method_exists('NFEMS_Settings', 'get_str')) {
                $u = (string) NFEMS_Settings::get_str('tutor_gate_booking_expiry_unit');
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

    private static function course_post_types(): array {
        $pts = ['courses', 'tutor_course'];
        if (class_exists('NFEMS_Tutor') && method_exists('NFEMS_Tutor', 'course_post_type')) {
            $pt = (string) NFEMS_Tutor::course_post_type();
            if ($pt && !in_array($pt, $pts, true)) $pts[] = $pt;
        }
        return array_values(array_unique($pts));
    }

    private static function is_course_page(): bool {
        if (!is_singular()) return false;
        $pt = get_post_type(get_the_ID());
        return in_array($pt, self::course_post_types(), true);
    }

    private static function get_active_slot_id(int $user_id, int $course_id): int {
        $map = get_user_meta($user_id, 'nfems_active_bookings', true);
        $map = is_array($map) ? $map : [];
        $slot_id = (int) ($map[$course_id]['slot_id'] ?? 0);

        $cpt = class_exists('NFEMS_CPT_Sessioni_Esame') ? NFEMS_CPT_Sessioni_Esame::CPT : 'slot_esame';
        if ($slot_id > 0 && get_post_type($slot_id) !== $cpt) {
            unset($map[$course_id]);
            update_user_meta($user_id, 'nfems_active_bookings', $map);
            return 0;
        }
        return $slot_id;
    }

    private static function get_session_ts_from_slot(int $slot_id): int {
        if ($slot_id <= 0) return 0;

        $date = '';
        $time = '';

        if (class_exists('NFEMS_CPT_Sessioni_Esame')) {
            $date = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $time = (string) get_post_meta($slot_id, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
        } else {
            $date = (string) get_post_meta($slot_id, 'data_sessione', true);
            $time = (string) get_post_meta($slot_id, 'orario_sessione', true);
        }

        $date = trim($date);
        $time = trim($time);
        if (!$date || !$time) return 0;

        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $date, $m)) {
            $date = $m[3].'-'.$m[2].'-'.$m[1];
        }

        if (preg_match('~^(\d{1,2}):(\d{2})~', $time, $m)) {
            $time = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
        }

        try {
            $dt = new DateTime($date.' '.$time.':00', wp_timezone());
            return (int) $dt->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function render_locked_page(string $title, string $body_html): void {
        status_header(200);
        nocache_headers();

        // Theme-friendly output
        get_header();
        echo '<div class="nfems-locked-course" style="max-width:820px;margin:28px auto;padding:18px;border-radius:16px;border:1px solid #fda29b;background:#fffbfa;box-shadow:0 10px 30px rgba(16,24,40,.08);">';
        echo '<div style="font-weight:900;color:#b42318;font-size:18px;margin-bottom:8px;">'.esc_html($title).'</div>';
        echo '<div style="color:#7a271a;font-weight:800;font-size:14px;line-height:1.5;">'.$body_html.'</div>';
        echo '</div>';
        get_footer();
        exit;
    }

    public static function maybe_block_course_page(): void {
        if (!self::enabled()) return;
        if (is_admin()) return;
        if (!self::is_course_page()) return;

        $course_id = (int) get_the_ID();

        // Apply gate only to selected courses (if any)
        $gate_courses = [];
        if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_gate_course_ids')) {
            $gate_courses = (array) NFEMS_Settings::get_gate_course_ids();
        } else {
            // Backward compatibility: read raw option key if present
            if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get')) {
                $o = (array) NFEMS_Settings::get();
                $gate_courses = is_array($o['tutor_gate_course_ids'] ?? null) ? $o['tutor_gate_course_ids'] : [];
            }
        }
        $gate_courses = array_values(array_unique(array_filter(array_map('absint', (array)$gate_courses))));
        if (!empty($gate_courses) && !in_array($course_id, $gate_courses, true)) {
            return; // not protected
        }


        $user_id = (int) get_current_user_id();

        // Not logged in -> block
        if ($user_id <= 0) {
            self::render_locked_page('Restricted access', 'You must be logged in to access this course.');
        }

        if (self::bypass_user($user_id)) return;

        $slot_id = self::get_active_slot_id($user_id, $course_id);

        // Debug log to help diagnostics (only in wp-content/debug.log when WP_DEBUG_LOG enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[NFEMS Tutor Gate] course_id='.$course_id.' user_id='.$user_id.' slot_id='.$slot_id.' pt='.get_post_type($course_id));
        }

        if ($slot_id <= 0) {
            $body = esc_html__('To access, you must first create an exam booking for an exam session in this course.', 'mc-ems');
            $mb = '';
            if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_manage_booking_page_url')) {
                $mb = (string) NFEMS_Settings::get_manage_booking_page_url();
            }
            if ($mb) {
                $body .= '<br><br><a href="' . esc_url($mb) . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#1a73e8;color:#fff;text-decoration:none;font-weight:900;">Manage exam booking</a>';
            }
            self::render_locked_page(esc_html__('Course access not available', 'mc-ems'), $body);
        }

        $session_ts = self::get_session_ts_from_slot($slot_id);
        if ($session_ts <= 0) {
            self::render_locked_page(__('Course access not available', 'mc-ems'), __('Your exam booking does not contain a valid date/time. Please contact support.', 'mc-ems'));
        }

        $unlock_ts = max(0, $session_ts - (self::unlock_lead_minutes() * 60));
        $now_ts = (int) current_time('timestamp');

        $expiry = self::booking_expiry_seconds();

        if ($expiry > 0) {
            $expiry_ts = $session_ts + $expiry;
            if ($now_ts > $expiry_ts) {
                // Booking expired for course access -> clear mapping and block
                $map = get_user_meta($user_id, 'nfems_active_bookings', true);
                $map = is_array($map) ? $map : [];
                if (isset($map[$course_id])) {
                    unset($map[$course_id]);
                    update_user_meta($user_id, 'nfems_active_bookings', $map);
                }

                $body = 'Your exam booking has expired and is no longer valid to access this course. Please book a new exam session.';
                $mb = '';
                if (class_exists('NFEMS_Settings') && method_exists('NFEMS_Settings', 'get_manage_booking_page_url')) {
                    $mb = (string) NFEMS_Settings::get_manage_booking_page_url();
                }
                if ($mb) {
                    $body .= '<br><br><a href="' . esc_url($mb) . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#1a73e8;color:#fff;text-decoration:none;font-weight:900;">Manage exam booking</a>';
                }
                self::render_locked_page('Course access not available', $body);
            }
        }

        if ($now_ts >= $unlock_ts) return; // allowed

        $unlock_h = wp_date('m/d/Y \\a\\t H:i', $unlock_ts, wp_timezone());
        self::render_locked_page('🔒 Course locked', 'You can access starting from <strong>'.esc_html($unlock_h).'</strong>.');
    }
}
