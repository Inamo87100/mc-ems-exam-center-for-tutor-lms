<?php
if (!defined('ABSPATH')) exit;

class MCEMEXCE_CPT_Sessioni_Esame {

    const CPT = 'mcemexce_exam_session';

    // === Meta keys (COMPAT with your original snippets) ===
    const MK_DATE            = 'slot_data';            // Y-m-d
    const MK_TIME            = 'slot_orario';          // H:i
    const MK_CAPACITY        = 'slot_posti_max';       // int
    const MK_OCCUPATI        = 'slot_posti_occupati';  // array user_id[]
    const MK_PROCTOR_USER_ID = 'slot_sorvegliante';    // int
    const MK_IS_SPECIAL      = 'slot_esigenze_speciali';      // 1|0
    const MK_SPECIAL_USER_ID = 'slot_esigenze_speciali_user'; // int
    const MK_EXAM_ID      = 'slot_corso_id'; // int Tutor LMS exam ID

    // === Legacy meta keys from previous NF-EMS builds (auto-migrated) ===
    const L_MK_DATE            = '_mcemexce_slot_date';
    const L_MK_TIME            = '_mcemexce_slot_time';
    const L_MK_CAPACITY        = '_mcemexce_slot_capacity';
    const L_MK_PROCTOR_USER_ID = '_mcemexce_proctor_user_id';
    const L_MK_IS_SPECIAL      = '_mcemexce_is_special';
    const L_MK_SPECIAL_USER_ID = '_mcemexce_special_user_id';
    const L_MK_BOOKINGS        = '_mcemexce_bookings';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        // Centralize session creation in "Exam Sessions Management".
        add_action('admin_menu', [__CLASS__, 'tweak_admin_menu'], 99);
        add_action('add_meta_boxes', [__CLASS__, 'add_metaboxes']);
        add_action('save_post', [__CLASS__, 'save_metabox'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_metabox_scripts']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_list_screen_assets']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column_render'], 10, 2);
    }

    public static function enqueue_metabox_scripts($hook): void {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::CPT) return;

        wp_enqueue_style(
            'mcems-admin-style',
            MCEMEXCE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MCEMEXCE_VERSION
        );

        // Inline CSS: lock publish controls for past sessions.
        if ($hook === 'post.php') {
            $post_id = absint($_GET['post'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin URL parameter
            if ($post_id) {
                $date = (string) get_post_meta($post_id, self::MK_DATE, true);
                $time = (string) get_post_meta($post_id, self::MK_TIME, true);
                if (self::is_past_session($date, $time)) {
                    wp_add_inline_style('mcems-admin-style', '#publishing-action .button-primary, #save-post, #minor-publishing-actions, .edit-post-post-status{display:none !important;} #submitdiv .misc-pub-section{pointer-events:none;opacity:.7;}');
                }
            }
        }

        wp_enqueue_script(
            'mcems-metabox-user-search',
            MCEMEXCE_PLUGIN_URL . 'assets/js/metabox-user-search.js',
            [],
            MCEMEXCE_VERSION,
            true
        );

        wp_localize_script('mcems-metabox-user-search', 'MCEMEXCE_USER_SEARCH', [
            'restUrl' => esc_url_raw(rest_url('mcemexce/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'i18n'    => [
                'noResults' => __('No users found.', 'mc-ems-exam-center-for-tutor-lms'),
            ],
        ]);

        // Inline JS: prevent selecting a past time when date is today.
        $today    = current_time('Y-m-d');
        $now_time = current_time('H:i');
        wp_add_inline_script(
            'mcems-metabox-user-search',
            '(function(){try{var d=document.getElementById("mcemexce_date_input");var t=document.getElementById("mcemexce_time_input");if(!d||!t)return;var today=' . wp_json_encode($today) . ';var now=' . wp_json_encode($now_time) . ';function apply(){if(d.value===today){t.min=now;}else{t.removeAttribute("min");}}d.addEventListener("change",apply);apply();}catch(e){}})();'
        );
    }

    public static function enqueue_list_screen_assets(string $hook): void {
        if ($hook !== 'edit.php') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-' . self::CPT) return;

        if (!wp_style_is('mcems-admin-style', 'registered')) {
            wp_register_style('mcems-admin-style', MCEMEXCE_PLUGIN_URL . 'assets/css/admin.css', [], MCEMEXCE_VERSION);
        }
        wp_enqueue_style('mcems-admin-style');
        wp_add_inline_style('mcems-admin-style', 'a.page-title-action[href*="post-new.php?post_type=' . esc_attr(self::CPT) . '"]{display:none !important;}');

        if (!wp_script_is('mcems-admin', 'registered')) {
            wp_register_script('mcems-admin', MCEMEXCE_PLUGIN_URL . 'assets/js/admin.js', [], MCEMEXCE_VERSION, true);
        }
        wp_enqueue_script('mcems-admin');

        $manage_url = admin_url('edit.php?post_type=' . self::CPT . '&page=mcems-manage-sessions');
        wp_add_inline_script(
            'mcems-admin',
            '(function(){var h1=document.querySelector(".wrap h1");if(!h1)return;var btn=document.createElement("a");btn.className="page-title-action";btn.href=' . wp_json_encode($manage_url) . ';btn.textContent=' . wp_json_encode(__('Add new session', 'mc-ems-exam-center-for-tutor-lms')) . ';h1.appendChild(document.createTextNode(" "));h1.appendChild(btn);})();'
        );
    }

    public static function register_rest_routes(): void {
        register_rest_route('mcemexce/v1', '/search-proctors', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_search_proctors'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('mcemexce/v1', '/search-candidates', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_search_candidates'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function rest_search_proctors(\WP_REST_Request $request): \WP_REST_Response {
        $q = trim((string) $request->get_param('q'));
        if (strlen($q) < 2) {
            return rest_ensure_response([]);
        }

        $safe_q = self::escape_like($q);

        $by_name = new \WP_User_Query([
            'role'           => 'tutor_instructor',
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['display_name'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $by_email = new \WP_User_Query([
            'role'           => 'tutor_instructor',
            'search'         => '*' . $safe_q . '*',
            'search_columns' => ['user_email'],
            'number'         => 20,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $merged = self::merge_user_results($by_name->get_results(), $by_email->get_results());
        return rest_ensure_response($merged);
    }

    public static function rest_search_candidates(\WP_REST_Request $request): \WP_REST_Response {
        $q = trim((string) $request->get_param('q'));
        if (strlen($q) < 2) {
            return rest_ensure_response([]);
        }

        $safe_q = self::escape_like($q);

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

        $merged = self::merge_user_results($by_name->get_results(), $by_email->get_results());
        return rest_ensure_response($merged);
    }

    /**
     * Escape LIKE wildcard characters in a user-supplied search string so that
     * '%' and '_' in the input are treated as literals, not SQL wildcards.
     *
     * @param string $q
     * @return string
     */
    private static function escape_like(string $q): string {
        global $wpdb;
        return $wpdb->esc_like($q);
    }

    /**
     * Merge two user result arrays, deduplicate by ID, and format for JSON output.
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    private static function merge_user_results(array $a, array $b): array {
        $seen = [];
        $out  = [];
        foreach (array_merge($a, $b) as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'    => $id,
                'name'  => (string) $u->display_name,
                'email' => (string) $u->user_email,
            ];
        }
        return $out;
    }

    /**
     * Remove the default "Add New" submenu for this CPT and reorder submenus
     * so that: Create sessions → Sessions list → Quiz statistics → Settings.
     */
    public static function tweak_admin_menu(): void {
        global $submenu;

        $parent = 'edit.php?post_type=' . self::CPT;
        remove_submenu_page($parent, 'post-new.php?post_type=' . self::CPT);

        if (empty($submenu[$parent])) return;

        $create     = null; // mcems-manage-sessions
        $list       = null; // edit.php?post_type=mcemexce_exam_session  (the CPT "all items" page)
        $quiz_stats = null; // mcems-quiz-stats
        $settings   = null; // mcems-settings-cpt
        $others     = [];

        foreach ($submenu[$parent] as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug === 'mcems-manage-sessions') {
                $create = $item;
            } elseif ($slug === $parent) {
                $list = $item;
            } elseif ($slug === 'mcems-quiz-stats') {
                $quiz_stats = $item;
            } elseif ($slug === 'mcems-settings-cpt') {
                $settings = $item;
            } else {
                $others[] = $item;
            }
        }

        $ordered = [];
        if ($create)     $ordered[] = $create;
        if ($list)       $ordered[] = $list;
        if ($quiz_stats) $ordered[] = $quiz_stats;
        if ($settings)   $ordered[] = $settings;
        foreach ($others as $item) {
            $ordered[] = $item;
        }

        $submenu[$parent] = array_values($ordered);
    }

    /**
     * On sessions list screen, hide the standard "Add New" and replace it
     * with a link to the management screen.
     * Logic moved to enqueue_list_screen_assets() via admin_enqueue_scripts.
     */
    public static function tweak_list_screen_ui(): void {}

    public static function register_cpt() {
        $labels = [
            'name'               => 'Exam Management System',
            'singular_name'      => __('Exam session', 'mc-ems-exam-center-for-tutor-lms'),
            'menu_name'          => 'Exam Management System',
            'all_items'          => __('Sessions list', 'mc-ems-exam-center-for-tutor-lms'),
            'add_new'            => __('Add session', 'mc-ems-exam-center-for-tutor-lms'),
            'add_new_item'       => __('Add new session', 'mc-ems-exam-center-for-tutor-lms'),
            'edit_item'          => __('Edit session', 'mc-ems-exam-center-for-tutor-lms'),
            'new_item'           => __('New session', 'mc-ems-exam-center-for-tutor-lms'),
            'view_item'          => __('View session', 'mc-ems-exam-center-for-tutor-lms'),
            'search_items'       => __('Search sessions', 'mc-ems-exam-center-for-tutor-lms'),
            'not_found'          => __('No sessions found', 'mc-ems-exam-center-for-tutor-lms'),
            'not_found_in_trash' => __('No sessions in trash', 'mc-ems-exam-center-for-tutor-lms'),
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
            'mcemexce_session_details',
            __('Session details (MC-EMS)', 'mc-ems-exam-center-for-tutor-lms'),
            [__CLASS__, 'metabox_html'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function metabox_html($post) {
        wp_nonce_field('mcemexce_session_save', 'mcemexce_session_nonce');

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

        // Resolve display info for pre-selected users.
        $proctor_user   = $proctor   ? get_user_by('id', $proctor)   : null;
        $candidate_user = $spec_uid  ? get_user_by('id', $spec_uid)  : null;

        $exams = MCEMEXCE_Tutor::get_exams();
        $exam_pt = MCEMEXCE_Tutor::exam_post_type();
        $exam_id = (int) get_post_meta($post->ID, self::MK_EXAM_ID, true);
        $is_past = self::is_past_session($date, $time);

        if ($is_past) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Past exam sessions are read-only and cannot be modified from the backend.', 'mc-ems-exam-center-for-tutor-lms') . '</p></div>';
        }

        $disabled = $is_past ? 'disabled' : '';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label>' . esc_html__('Tutor LMS exam', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
if (!$exam_pt) {
    echo '<em>' . esc_html__('Tutor LMS not detected (exam post type not found: courses / tutor_course).', 'mc-ems-exam-center-for-tutor-lms') . '</em>';
} else {
    echo '<select name="mcemexce_exam_id" required ' . esc_attr($disabled) . '><option value="0">' . esc_html__('— Select exam —', 'mc-ems-exam-center-for-tutor-lms') . '</option>';
    foreach ($exams as $cid => $title) {
        printf('<option value="%d" %s>%s</option>',
            (int)$cid,
            selected($exam_id, (int)$cid, false),
            esc_html($title)
        );
    }
    echo '</select>';
}
echo '<p class="description">' . esc_html__('This session will be bookable only by selecting this exam during booking.', 'mc-ems-exam-center-for-tutor-lms') . '</p>';
echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Date', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        printf('<input type="date" id="mcemexce_date_input" name="mcemexce_date" value="%s" min="%s" %s />', esc_attr($date), esc_attr(current_time('Y-m-d')), esc_attr($disabled));
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Time', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        printf('<input type="time" id="mcemexce_time_input" name="mcemexce_time" value="%s" %s />', esc_attr($time), esc_attr($disabled));
        echo '</td></tr>';

        // Prevent selecting past time when date is today — handled via wp_add_inline_script in enqueue_metabox_scripts().


        echo '<tr><th><label>' . esc_html__('Max seats', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        printf('<input type="number" min="1" max="500" name="mcemexce_capacity" value="%d" %s %s />',
            (int) $capacity,
            ($is_spec ? 'readonly' : ''),
            esc_attr($disabled)
        );
        if ($is_spec) echo '<p class="description"><strong>' . esc_html__('Forced to 1', 'mc-ems-exam-center-for-tutor-lms') . '</strong> ' . esc_html__('because it is a special requirements session.', 'mc-ems-exam-center-for-tutor-lms') . '</p>';
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Booked', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        echo '<strong>' . (int)$booked . '</strong>';
        if ($booked) {
            echo '<p class="description">' . esc_html__('Booked user IDs:', 'mc-ems-exam-center-for-tutor-lms') . ' ' . esc_html(implode(', ', array_map('intval', $occupati))) . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Proctor', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        echo '<div class="mcems-user-search-wrap">';
        printf('<input type="hidden" name="mcemexce_proctor_user_id" id="mcemexce_proctor_user_id" value="%d" />', (int) $proctor);
        if (!$is_past) {
            printf(
                '<input type="text" id="mcemexce_proctor_search" placeholder="%s" autocomplete="off" />',
                esc_attr__('Search by name or email…', 'mc-ems-exam-center-for-tutor-lms')
            );
            echo '<div id="mcemexce_proctor_results" class="mcems-user-search-results"></div>';
        }
        echo '<div id="mcemexce_proctor_selected" class="mcems-user-selected">';
        if ($proctor_user) {
            printf(
                '<span class="mcems-user-selected-name">%s</span> <span class="mcems-user-selected-email">(%s)</span>',
                esc_html($proctor_user->display_name),
                esc_html($proctor_user->user_email)
            );
        }
        echo '</div>';
        if (!$is_past) {
            printf(
                '<button type="button" id="mcemexce_proctor_clear" class="mcems-user-search-clear" style="%s">%s</button>',
                $proctor ? '' : 'display:none',
                esc_html__('Clear', 'mc-ems-exam-center-for-tutor-lms')
            );
        }
        echo '</div>';
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Special requirements', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        printf('<label><input type="checkbox" name="mcemexce_is_special" value="1" %s %s /> ♿ %s</label>',
            checked($is_spec, 1, false),
            esc_attr($disabled),
            esc_html__('Session for special requirements', 'mc-ems-exam-center-for-tutor-lms')
        );
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Associated candidate (only ♿)', 'mc-ems-exam-center-for-tutor-lms') . '</label></th><td>';
        echo '<div class="mcems-user-search-wrap">';
        printf('<input type="hidden" name="mcemexce_special_user_id" id="mcemexce_special_user_id" value="%d" />', (int) $spec_uid);
        if (!$is_past) {
            printf(
                '<input type="text" id="mcemexce_candidate_search" placeholder="%s" autocomplete="off" />',
                esc_attr__('Search by name or email…', 'mc-ems-exam-center-for-tutor-lms')
            );
            echo '<div id="mcemexce_candidate_results" class="mcems-user-search-results"></div>';
        }
        echo '<div id="mcemexce_candidate_selected" class="mcems-user-selected">';
        if ($candidate_user) {
            printf(
                '<span class="mcems-user-selected-name">%s</span> <span class="mcems-user-selected-email">(%s)</span>',
                esc_html($candidate_user->display_name),
                esc_html($candidate_user->user_email)
            );
        }
        echo '</div>';
        if (!$is_past) {
            printf(
                '<button type="button" id="mcemexce_candidate_clear" class="mcems-user-search-clear" style="%s">%s</button>',
                $spec_uid ? '' : 'display:none',
                esc_html__('Clear', 'mc-ems-exam-center-for-tutor-lms')
            );
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__('If set, the session ♿ can be booked only by this user.', 'mc-ems-exam-center-for-tutor-lms') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    public static function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['mcemexce_session_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mcemexce_session_nonce'])), 'mcemexce_session_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $existing_date = (string) get_post_meta($post_id, self::MK_DATE, true);
        $existing_time = (string) get_post_meta($post_id, self::MK_TIME, true);
        if (self::is_past_session($existing_date, $existing_time)) {
            set_transient('mcemexce_past_session_readonly_notice', 1, 30);
            return;
        }

        $date = isset($_POST['mcemexce_date']) ? sanitize_text_field(wp_unslash($_POST['mcemexce_date'])) : '';
        $time = isset($_POST['mcemexce_time']) ? sanitize_text_field(wp_unslash($_POST['mcemexce_time'])) : '';

        // Block past sessions (date + time).
        if ($date && $time && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time)) {
            $tz = wp_timezone();
            try {
                $session_dt = new \DateTimeImmutable($date . ' ' . $time . ':00', $tz);
                $now = new \DateTimeImmutable('now', $tz);
                if ($session_dt < $now) {
                    // Save as draft and show notice.
                    set_transient('mcemexce_past_session_notice', 1, 30);
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

        $proctor  = isset($_POST['mcemexce_proctor_user_id']) ? absint(wp_unslash($_POST['mcemexce_proctor_user_id'])) : 0;
        $is_spec  = !empty($_POST['mcemexce_is_special']) ? 1 : 0;
        $spec_uid = isset($_POST['mcemexce_special_user_id']) ? absint(wp_unslash($_POST['mcemexce_special_user_id'])) : 0;

        $exam_id = isset($_POST['mcemexce_exam_id']) ? absint(wp_unslash($_POST['mcemexce_exam_id'])) : 0;

        $capacity = isset($_POST['mcemexce_capacity']) ? absint(wp_unslash($_POST['mcemexce_capacity'])) : 10;
        if ($capacity < 1) $capacity = 1;

        // In modalità ♿: capienza sempre 1
        if ($is_spec) $capacity = 1;

        update_post_meta($post_id, self::MK_DATE, $date);
        update_post_meta($post_id, self::MK_TIME, $time);
        update_post_meta($post_id, self::MK_CAPACITY, $capacity);
        update_post_meta($post_id, self::MK_PROCTOR_USER_ID, $proctor);
        update_post_meta($post_id, self::MK_IS_SPECIAL, $is_spec);
        update_post_meta($post_id, self::MK_SPECIAL_USER_ID, $spec_uid);
        update_post_meta($post_id, self::MK_EXAM_ID, $exam_id);

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
        if (get_transient('mcemexce_past_session_notice')) {
            delete_transient('mcemexce_past_session_notice');
            echo '<div class="notice notice-error"><p>' . esc_html__('Past sessions cannot be created. Please choose a future date and time.', 'mc-ems-exam-center-for-tutor-lms') . '</p></div>';
        }
        if (get_transient('mcemexce_past_session_readonly_notice')) {
            delete_transient('mcemexce_past_session_readonly_notice');
            echo '<div class="notice notice-warning"><p>' . esc_html__('Past exam sessions are read-only and cannot be modified from the backend.', 'mc-ems-exam-center-for-tutor-lms') . '</p></div>';
        }
    }



    public static function lock_past_session_ui(): void {
        // Inline CSS handled in enqueue_metabox_scripts() via admin_enqueue_scripts.
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
        $new['title'] = __('Session', 'mc-ems-exam-center-for-tutor-lms');
        // Subito dopo __('Session', 'mc-ems-exam-center-for-tutor-lms')
        $new['mcemexce_exam'] = __('Exam', 'mc-ems-exam-center-for-tutor-lms');
        $new['mcemexce_date'] = __('Date', 'mc-ems-exam-center-for-tutor-lms');
        $new['mcemexce_time'] = __('Time', 'mc-ems-exam-center-for-tutor-lms');
        $new['mcemexce_cap']  = __('Seats', 'mc-ems-exam-center-for-tutor-lms');
        $new['mcemexce_book'] = __('Booked', 'mc-ems-exam-center-for-tutor-lms');
        // Niente colonna data di pubblicazione
        return $new;
    }

    public static function column_render($col, $post_id) {
        if ($col === 'mcemexce_exam') {
            $exam_id = (int) get_post_meta($post_id, self::MK_EXAM_ID, true);
            echo $exam_id ? esc_html(get_the_title($exam_id)) : '—';
            return;
        }

        if ($col === 'mcemexce_date') {
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

        if ($col === 'mcemexce_time') {
            echo esc_html(get_post_meta($post_id, self::MK_TIME, true));
            return;
        }

        if ($col === 'mcemexce_cap')  {
            echo (int) get_post_meta($post_id, self::MK_CAPACITY, true);
            return;
        }

        if ($col === 'mcemexce_book') {
            $occ = get_post_meta($post_id, self::MK_OCCUPATI, true);
            echo is_array($occ) ? count($occ) : 0;
            return;
        }
    }
}
