<?php
if (!defined('ABSPATH')) exit;

class NFEMS_CPT_Sessioni_Esame {

    const CPT = 'slot_esame'; // keep technical slug for compatibility

    // === Meta keys (COMPAT with your original snippets) ===
    const MK_DATE            = 'slot_data';            // Y-m-d
    const MK_TIME            = 'slot_orario';          // H:i
    const MK_CAPACITY        = 'slot_posti_max';       // int
    const MK_OCCUPATI        = 'slot_posti_occupati';  // array user_id[]
    const MK_PROCTOR_USER_ID = 'slot_sorvegliante';    // int
    const MK_IS_SPECIAL      = 'slot_esigenze_speciali';      // 1|0
    const MK_SPECIAL_USER_ID = 'slot_esigenze_speciali_user'; // int
    const MK_COURSE_ID      = 'slot_corso_id'; // int Tutor LMS course ID

    // === Legacy meta keys from previous NF-EMS builds (auto-migrated) ===
    const L_MK_DATE            = '_nfems_slot_date';
    const L_MK_TIME            = '_nfems_slot_time';
    const L_MK_CAPACITY        = '_nfems_slot_capacity';
    const L_MK_PROCTOR_USER_ID = '_nfems_proctor_user_id';
    const L_MK_IS_SPECIAL      = '_nfems_is_special';
    const L_MK_SPECIAL_USER_ID = '_nfems_special_user_id';
    const L_MK_BOOKINGS        = '_nfems_bookings';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        // Centralize session creation in "Exam Sessions Management".
        add_action('admin_menu', [__CLASS__, 'tweak_admin_menu'], 99);
        add_action('admin_head', [__CLASS__, 'tweak_list_screen_ui']);
        add_action('add_meta_boxes', [__CLASS__, 'add_metaboxes']);
        add_action('save_post', [__CLASS__, 'save_metabox'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_head', [__CLASS__, 'lock_past_session_ui']);

        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column_render'], 10, 2);
    }

    /**
     * Remove the default "Add New" submenu for this CPT.
     */
    public static function tweak_admin_menu(): void {
        remove_submenu_page('edit.php?post_type=' . self::CPT, 'post-new.php?post_type=' . self::CPT);
    }

    /**
     * On sessions list screen, hide the standard "Add New" and replace it
     * with a link to the management screen.
     */
    public static function tweak_list_screen_ui(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if ($screen->id !== 'edit-' . self::CPT) return;

        $manage_url = admin_url('edit.php?post_type=' . self::CPT . '&page=nfems-gestione-sessioni');
        echo '<style>a.page-title-action[href*="post-new.php?post_type=' . esc_attr(self::CPT) . '"]{display:none !important;}</style>';
        echo '<script>(function(){
            var h1=document.querySelector(".wrap h1");
            if(!h1) return;
            var btn=document.createElement("a");
            btn.className="page-title-action";
            btn.href=' . json_encode($manage_url) . ';
            btn.textContent="Add new session";
            h1.appendChild(document.createTextNode(" "));
            h1.appendChild(btn);
        })();</script>';
    }

    public static function register_cpt() {
        $labels = [
            'name'               => 'Exam Management System',
            'singular_name'      => __('Exam session', 'mc-ems'),
            'menu_name'          => 'Exam Management System',
            'all_items'          => __('Sessions list', 'mc-ems'),
            'add_new'            => __('Add session', 'mc-ems'),
            'add_new_item'       => __('Add new session', 'mc-ems'),
            'edit_item'          => __('Edit session', 'mc-ems'),
            'new_item'           => __('New session', 'mc-ems'),
            'view_item'          => __('View session', 'mc-ems'),
            'search_items'       => __('Search sessions', 'mc-ems'),
            'not_found'          => __('No sessions found', 'mc-ems'),
            'not_found_in_trash' => __('No sessions in trash', 'mc-ems'),
        ];

        register_post_type(self::CPT, [
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-calendar-alt',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);
    }

    public static function add_metaboxes() {
        add_meta_box(
            'nfems_session_details',
            __('Session details (MC-EMS)', 'mc-ems'),
            [__CLASS__, 'metabox_html'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function metabox_html($post) {
        wp_nonce_field('nfems_session_save', 'nfems_session_nonce');

        // Prefer canonical keys, fallback legacy
        $date     = (string) get_post_meta($post->ID, self::MK_DATE, true);
        if ($date === '') $date = (string) get_post_meta($post->ID, self::L_MK_DATE, true);

        $time     = (string) get_post_meta($post->ID, self::MK_TIME, true);
        if ($time === '') $time = (string) get_post_meta($post->ID, self::L_MK_TIME, true);

        $capacity = (int) get_post_meta($post->ID, self::MK_CAPACITY, true);
        if ($capacity <= 0) $capacity = (int) get_post_meta($post->ID, self::L_MK_CAPACITY, true);
        if ($capacity <= 0) $capacity = 10;

        $occupati = get_post_meta($post->ID, self::MK_OCCUPATI, true);
        if (!is_array($occupati)) $occupati = [];
        $booked = count($occupati);

        $proctor  = (int) get_post_meta($post->ID, self::MK_PROCTOR_USER_ID, true);
        if (!$proctor) $proctor = (int) get_post_meta($post->ID, self::L_MK_PROCTOR_USER_ID, true);

        $is_spec  = (int) get_post_meta($post->ID, self::MK_IS_SPECIAL, true);
        if (!$is_spec) $is_spec = (int) get_post_meta($post->ID, self::L_MK_IS_SPECIAL, true);

        $spec_uid = (int) get_post_meta($post->ID, self::MK_SPECIAL_USER_ID, true);
        if (!$spec_uid) $spec_uid = (int) get_post_meta($post->ID, self::L_MK_SPECIAL_USER_ID, true);

        $users = get_users(['fields' => ['ID', 'display_name', 'user_email'], 'number' => 500]);
        $courses = NFEMS_Tutor::get_courses();
        $course_pt = NFEMS_Tutor::course_post_type();
        $course_id = (int) get_post_meta($post->ID, self::MK_COURSE_ID, true);
        $is_past = self::is_past_session($date, $time);

        if ($is_past) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Past exam sessions are read-only and cannot be modified from the backend.', 'mc-ems') . '</p></div>';
        }

        $disabled = $is_past ? 'disabled' : '';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label>Tutor LMS course</label></th><td>';
if (!$course_pt) {
    echo '<em>Tutor LMS not detected (course post type not found: <code>courses</code> / <code>tutor_course</code>).</em>';
} else {
    echo '<select name="nfems_course_id" required ' . $disabled . '><option value="0">— Select course —</option>';
    foreach ($courses as $cid => $title) {
        printf('<option value="%d" %s>%s</option>',
            (int)$cid,
            selected($course_id, (int)$cid, false),
            esc_html($title)
        );
    }
    echo '</select>';
}
echo '<p class="description">This session will be bookable only by selecting this course during booking.</p>';
echo '</td></tr>';

        echo '<tr><th><label>Date</label></th><td>';
        printf('<input type="date" id="nfems_date_input" name="nfems_date" value="%s" min="%s" %s />', esc_attr($date), esc_attr(current_time('Y-m-d')), esc_attr($disabled));
        echo '</td></tr>';

        echo '<tr><th><label>Time</label></th><td>';
        printf('<input type="time" id="nfems_time_input" name="nfems_time" value="%s" %s />', esc_attr($time), esc_attr($disabled));
        echo '</td></tr>';

        // Prevent selecting past time when date is today.
        $today = current_time('Y-m-d');
        $now_time = current_time('H:i');
        echo '<script>(function(){try{var d=document.getElementById("nfems_date_input");var t=document.getElementById("nfems_time_input");if(!d||!t)return;var today="'.esc_js($today).'";var now="'.esc_js($now_time).'";function apply(){if(d.value===today){t.min=now;}else{t.removeAttribute("min");}}d.addEventListener("change",apply);apply();}catch(e){}})();</script>';


        echo '<tr><th><label>Max seats</label></th><td>';
        printf('<input type="number" min="1" max="500" name="nfems_capacity" value="%d" %s %s />',
            (int)$capacity,
            ($is_spec ? 'readonly' : ''),
            esc_attr($disabled)
        );
        if ($is_spec) echo '<p class="description"><strong>Forced to 1</strong> because it is a special requirements session.</p>';
        echo '</td></tr>';

        echo '<tr><th><label>Booked</label></th><td>';
        echo '<strong>' . (int)$booked . '</strong>';
        if ($booked) {
            echo '<p class="description">Booked user IDs: ' . esc_html(implode(', ', array_map('intval', $occupati))) . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th><label>Proctor</label></th><td>';
        echo '<select name="nfems_proctor_user_id" ' . $disabled . '><option value="0">— None —</option>';
        foreach ($users as $u) {
            printf(
                '<option value="%d" %s>%s (%s)</option>',
                (int)$u->ID,
                selected($proctor, (int)$u->ID, false),
                esc_html($u->display_name),
                esc_html($u->user_email)
            );
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th><label>Special requirements</label></th><td>';
        printf('<label><input type="checkbox" name="nfems_is_special" value="1" %s %s /> ♿ Session for special requirements</label>',
            checked($is_spec, 1, false),
            esc_attr($disabled)
        );
        echo '</td></tr>';

        echo '<tr><th><label>Associated candidate (only ♿)</label></th><td>';
        echo '<select name="nfems_special_user_id" ' . $disabled . '><option value="0">— None —</option>';
        foreach ($users as $u) {
            printf(
                '<option value="%d" %s>%s (%s)</option>',
                (int)$u->ID,
                selected($spec_uid, (int)$u->ID, false),
                esc_html($u->display_name),
                esc_html($u->user_email)
            );
        }
        echo '</select>';
        echo '<p class="description">If set, the session ♿ can be booked only by this user.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    public static function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['nfems_session_nonce']) || !wp_verify_nonce($_POST['nfems_session_nonce'], 'nfems_session_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $existing_date = (string) get_post_meta($post_id, self::MK_DATE, true);
        $existing_time = (string) get_post_meta($post_id, self::MK_TIME, true);
        if (self::is_past_session($existing_date, $existing_time)) {
            set_transient('mcems_past_session_readonly_notice', 1, 30);
            return;
        }

        $date = isset($_POST['nfems_date']) ? sanitize_text_field($_POST['nfems_date']) : '';
        $time = isset($_POST['nfems_time']) ? sanitize_text_field($_POST['nfems_time']) : '';

        // Block past sessions (date + time).
        if ($date && $time && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time)) {
            $tz = wp_timezone();
            try {
                $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
                $now = new \DateTimeImmutable('now', $tz);
                if ($session_dt < $now) {
                    // Save as draft and show notice.
                    set_transient('mcems_past_session_notice', 1, 30);
                    remove_action('save_post', [__CLASS__, 'save_metabox'], 10);
                    wp_update_post([
                        'ID'          => $post_id,
                        'post_status' => 'draft',
                    ]);
                    add_action('save_post', [__CLASS__, 'save_metabox'], 10, 2);
                    return;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $proctor  = isset($_POST['nfems_proctor_user_id']) ? (int) $_POST['nfems_proctor_user_id'] : 0;
        $is_spec  = !empty($_POST['nfems_is_special']) ? 1 : 0;
        $spec_uid = isset($_POST['nfems_special_user_id']) ? (int) $_POST['nfems_special_user_id'] : 0;

        $course_id = isset($_POST['nfems_course_id']) ? (int) $_POST['nfems_course_id'] : 0;

        $capacity = isset($_POST['nfems_capacity']) ? (int) $_POST['nfems_capacity'] : 10;
        if ($capacity < 1) $capacity = 1;

        // In modalità ♿: capienza sempre 1
        if ($is_spec) $capacity = 1;

        update_post_meta($post_id, self::MK_DATE, $date);
        update_post_meta($post_id, self::MK_TIME, $time);
        update_post_meta($post_id, self::MK_CAPACITY, $capacity);
        update_post_meta($post_id, self::MK_PROCTOR_USER_ID, $proctor);
        update_post_meta($post_id, self::MK_IS_SPECIAL, $is_spec);
        update_post_meta($post_id, self::MK_SPECIAL_USER_ID, $spec_uid);
        update_post_meta($post_id, self::MK_COURSE_ID, $course_id);

        // Se ♿ e candidato impostato: garantisci che sia in lista prenotati
        if ($is_spec && $spec_uid > 0) {
            $occ = get_post_meta($post_id, self::MK_OCCUPATI, true);
            if (!is_array($occ)) $occ = [];
            if (!in_array($spec_uid, $occ, true)) {
                $occ[] = $spec_uid;
                $occ = array_values(array_unique(array_map('intval', $occ)));
                update_post_meta($post_id, self::MK_OCCUPATI, $occ);
            }
        }

        // Auto-title
        if ($date && $time) {
            remove_action('save_post', [__CLASS__, 'save_metabox'], 10);
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => "Session {$date} {$time}",
            ]);
            add_action('save_post', [__CLASS__, 'save_metabox'], 10, 2);
        }
    }

    public static function admin_notices(): void {
        if (get_transient('mcems_past_session_notice')) {
            delete_transient('mcems_past_session_notice');
            echo '<div class="notice notice-error"><p>' . esc_html__('Past sessions cannot be created. Please choose a future date and time.', 'mc-ems') . '</p></div>';
        }
        if (get_transient('mcems_past_session_readonly_notice')) {
            delete_transient('mcems_past_session_readonly_notice');
            echo '<div class="notice notice-warning"><p>' . esc_html__('Past exam sessions are read-only and cannot be modified from the backend.', 'mc-ems') . '</p></div>';
        }
    }



    public static function lock_past_session_ui(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::CPT || $screen->base !== 'post') return;
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$post_id) return;
        $date = (string) get_post_meta($post_id, self::MK_DATE, true);
        $time = (string) get_post_meta($post_id, self::MK_TIME, true);
        if (!self::is_past_session($date, $time)) return;
        echo '<style>#publishing-action .button-primary, #save-post, #minor-publishing-actions, .edit-post-post-status{display:none !important;} #submitdiv .misc-pub-section{pointer-events:none;opacity:.7;}</style>';
    }

    private static function is_past_session($date, $time): bool {
        if (!$date || !$time) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return false;
        try {
            $tz = wp_timezone();
            $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
            $now = new \DateTimeImmutable('now', $tz);
            return $session_dt < $now;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function columns($cols) {
        $new = [];
        $new['cb'] = $cols['cb'] ?? '';
        $new['title'] = __('Session', 'mc-ems');
        // Subito dopo __('Session', 'mc-ems')
        $new['nfems_course'] = 'Course';
        $new['nfems_date'] = __('Date', 'mc-ems');
        $new['nfems_time'] = 'Time';
        $new['nfems_cap']  = __('Seats', 'mc-ems');
        $new['nfems_book'] = __('Booked', 'mc-ems');
        // Niente colonna data di pubblicazione
        return $new;
    }

    public static function column_render($col, $post_id) {
        if ($col === 'nfems_course') {
            $course_id = (int) get_post_meta($post_id, self::MK_COURSE_ID, true);
            echo $course_id ? esc_html(get_the_title($course_id)) : '—';
            return;
        }

        if ($col === 'nfems_date') {
            $raw = (string) get_post_meta($post_id, self::MK_DATE, true);
            $raw = trim($raw);

            if ($raw === '') {
                echo '—';
                return;
            }

            // Se già dd/mm/YYYY
            if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $raw)) {
                echo esc_html($raw);
                return;
            }

            // Se YYYY-MM-DD
            if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $raw, $m)) {
                echo esc_html($m[3] . '/' . $m[2] . '/' . $m[1]);
                return;
            }

            // Fallback
            $ts = strtotime($raw);
            echo $ts ? esc_html(date_i18n('d/m/Y', $ts)) : esc_html($raw);
            return;
        }

        if ($col === 'nfems_time') {
            echo esc_html(get_post_meta($post_id, self::MK_TIME, true));
            return;
        }

        if ($col === 'nfems_cap')  {
            echo (int) get_post_meta($post_id, self::MK_CAPACITY, true);
            return;
        }

        if ($col === 'nfems_book') {
            $occ = get_post_meta($post_id, self::MK_OCCUPATI, true);
            echo is_array($occ) ? count($occ) : 0;
            return;
        }
    }
}
