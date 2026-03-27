<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

class MCEMS_Quiz_Stats_Admin {

    const ITEMS_PER_PAGE = 25;
    const PARENT_POST_TYPE = 'mcems_exam_session';
    const PAGE_SLUG = 'mcems-quiz-stats';

    protected static $question_options_cache = [];
    protected static $question_columns_cache = null;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('init', [__CLASS__, 'ensure_stats_table']);
        add_action('admin_post_mcems_recalc_quiz_stats', [__CLASS__, 'handle_recalc']);
        add_action('admin_post_mcems_download_quiz_stats_csv', [__CLASS__, 'handle_csv_download']);
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . self::PARENT_POST_TYPE,
            'Quiz Stats',
            'Quiz Stats',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_admin_page']
        );
    }

    protected static function get_page_url(array $args = []) {
        $base_args = [
            'post_type' => self::PARENT_POST_TYPE,
            'page'      => self::PAGE_SLUG,
        ];

        return add_query_arg(array_merge($base_args, $args), admin_url('edit.php'));
    }

    protected static function get_exams() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'courses' AND post_status = 'publish' ORDER BY post_title"
        );
    }

    public static function ensure_stats_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'quiz_stats_cache';
        if (get_option('quiz_stats_table_created') !== 'yes') {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                question_id int(11) NOT NULL,
                course_id int(11) NOT NULL,
                course_title varchar(255) NOT NULL,
                question_title text NOT NULL,
                quiz_title varchar(255) NOT NULL,
                topic_title varchar(255) NOT NULL,
                total_answers int(11) DEFAULT 0,
                wrong_answers int(11) DEFAULT 0,
                error_percentage decimal(5,2) DEFAULT 0,
                last_updated datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY question_course (question_id, course_id),
                KEY course_id (course_id),
                KEY error_percentage (error_percentage)
            ) $charset_collate;";
            dbDelta($sql);

            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                update_option('quiz_stats_table_created', 'yes');
            }
        }
    }

    public static function handle_recalc() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('mcems_recalc_quiz_stats');

        $course_id = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        self::recalculate_stats($course_id);

        $redirect_args = ['updated' => 1];
        if ($course_id > 0) {
            $redirect_args['course_id'] = $course_id;
        }

        wp_safe_redirect(self::get_page_url($redirect_args));
        exit;
    }

    public static function recalculate_stats($course_id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'quiz_stats_cache';

        $courses_sql = "
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'courses' AND post_status = 'publish'
        ";

        if ($course_id > 0) {
            $courses_sql .= $wpdb->prepare(' AND ID = %d', $course_id);
        }

        $courses = $wpdb->get_results($courses_sql);

        foreach ($courses as $course) {
            $questions = $wpdb->get_results($wpdb->prepare("
                SELECT
                    qq.question_id,
                    qq.question_title,
                    quiz.ID as quiz_id,
                    quiz.post_title as quiz_title,
                    topic.ID as topic_id,
                    topic.post_title as topic_title,
                    c.ID as course_id,
                    c.post_title as course_title
                FROM {$wpdb->prefix}tutor_quiz_questions qq
                INNER JOIN {$wpdb->posts} quiz ON qq.quiz_id = quiz.ID
                INNER JOIN {$wpdb->posts} topic ON quiz.post_parent = topic.ID
                INNER JOIN {$wpdb->posts} c ON topic.post_parent = c.ID
                WHERE c.ID = %d
                    AND quiz.post_title NOT LIKE '%%Live Zoom%%'
            ", $course->ID));

            foreach ($questions as $q) {
                $total = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempt_answers
                    WHERE question_id = %d
                ", $q->question_id));

                $wrong = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempt_answers
                    WHERE question_id = %d AND is_correct = 0
                ", $q->question_id));

                $percentage = ($total > 0) ? round(($wrong / $total) * 100, 2) : 0;

                $wpdb->replace(
                    $table_name,
                    [
                        'question_id'      => $q->question_id,
                        'course_id'        => $q->course_id,
                        'course_title'     => $q->course_title,
                        'question_title'   => $q->question_title,
                        'quiz_title'       => $q->quiz_title,
                        'topic_title'      => $q->topic_title,
                        'total_answers'    => $total,
                        'wrong_answers'    => $wrong,
                        'error_percentage' => $percentage,
                        'last_updated'     => current_time('mysql'),
                    ]
                );
            }
        }
    }

    protected static function get_last_updated($course_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'quiz_stats_cache';

        if ($course_id <= 0) {
            return '';
        }

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(last_updated) FROM $table WHERE course_id = %d",
            $course_id
        ));
    }

    protected static function get_question_table_columns() {
        global $wpdb;

        if (self::$question_columns_cache !== null) {
            return self::$question_columns_cache;
        }

        $table = $wpdb->prefix . 'tutor_quiz_questions';
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        self::$question_columns_cache = is_array($cols) ? $cols : [];

        return self::$question_columns_cache;
    }

    protected static function get_question_row($question_id) {
        global $wpdb;

        $question_id = (int) $question_id;
        if ($question_id <= 0) {
            return [];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tutor_quiz_questions WHERE question_id = %d LIMIT 1", $question_id),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    protected static function dedupe_options(array $options) {
        $seen = [];
        $clean = [];

        foreach ($options as $option) {
            $text = isset($option['text']) ? trim(wp_strip_all_tags((string) $option['text'])) : '';
            if ($text === '') {
                continue;
            }

            $key = strtolower($text);
            if (isset($seen[$key])) {
                if (!empty($option['is_correct'])) {
                    $clean[$seen[$key]]['is_correct'] = true;
                }
                continue;
            }

            $seen[$key] = count($clean);
            $clean[] = [
                'text'       => $text,
                'is_correct' => !empty($option['is_correct']),
            ];
        }

        return $clean;
    }

    protected static function extract_options_from_postmeta($question_id) {
        global $wpdb;

        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $question_id
        ), ARRAY_A);

        if (empty($meta_rows)) {
            return [];
        }

        $preferred_keys = [
            '_tutor_quiz_question_answers',
            '_tutor_quiz_question_answer',
            '_tutor_question_answers',
            '_tutor_question_answer',
            '_tutor_quiz_answer_options',
            '_tutor_answer_options',
            '_answer_options',
            'answer_options',
            'question_options',
            'options',
            'answers',
        ];

        $ordered = [];
        foreach ($preferred_keys as $wanted_key) {
            foreach ($meta_rows as $row) {
                if ($row['meta_key'] === $wanted_key) {
                    $ordered[] = $row;
                }
            }
        }
        foreach ($meta_rows as $row) {
            if (!in_array($row['meta_key'], $preferred_keys, true)) {
                $ordered[] = $row;
            }
        }

        $options = [];
        foreach ($ordered as $row) {
            $meta_key = (string) $row['meta_key'];
            $decoded = self::maybe_decode_data($row['meta_value']);
            if ($decoded === null) {
                continue;
            }

            $looks_promising = in_array($meta_key, $preferred_keys, true)
                || strpos($meta_key, 'answer') !== false
                || strpos($meta_key, 'option') !== false;

            if (!$looks_promising) {
                continue;
            }

            $found = self::extract_options_from_mixed($decoded);
            if (!empty($found)) {
                $options = array_merge($options, $found);
                if (in_array($meta_key, $preferred_keys, true)) {
                    break;
                }
            }
        }

        return self::dedupe_options($options);
    }

    protected static function maybe_decode_data($value) {
        if (is_array($value) || is_object($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        if (function_exists('maybe_unserialize')) {
            $unserialized = maybe_unserialize($value);
            if ($unserialized !== $value || is_array($unserialized) || is_object($unserialized)) {
                return $unserialized;
            }
        }

        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        return null;
    }

    protected static function normalize_bool($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'correct', 'right'], true);
        }

        return false;
    }

    protected static function normalize_option_item($item) {
        if (is_scalar($item)) {
            $text = trim((string) $item);
            return $text !== '' ? ['text' => $text, 'is_correct' => false] : null;
        }

        if (!is_array($item)) {
            return null;
        }

        $text_keys = ['option_title', 'title', 'answer_option', 'answer_text', 'text', 'name', 'value', 'option_name'];
        $correct_keys = ['is_correct', 'correct', 'is_true', 'answer_is_correct', 'right'];

        $text = '';
        foreach ($text_keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                $text = trim((string) $item[$key]);
                break;
            }
        }

        if ($text === '' && isset($item['answer']) && is_scalar($item['answer'])) {
            $text = trim((string) $item['answer']);
        }

        if ($text === '') {
            foreach ($item as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $text = trim((string) $value);
                    break;
                }
            }
        }

        if ($text === '') {
            return null;
        }

        $is_correct = false;
        foreach ($correct_keys as $key) {
            if (array_key_exists($key, $item)) {
                $is_correct = self::normalize_bool($item[$key]);
                break;
            }
        }

        return [
            'text'       => $text,
            'is_correct' => $is_correct,
        ];
    }

    protected static function extract_options_from_mixed($value) {
        $decoded = self::maybe_decode_data($value);
        if ($decoded === null) {
            return [];
        }

        $source = is_object($decoded) ? (array) $decoded : $decoded;
        if (!is_array($source)) {
            return [];
        }

        $options = [];

        if (array_values($source) === $source) {
            foreach ($source as $item) {
                $normalized = self::normalize_option_item(is_object($item) ? (array) $item : $item);
                if ($normalized) {
                    $options[] = $normalized;
                    continue;
                }

                if (is_array($item) || is_object($item)) {
                    $options = array_merge($options, self::extract_options_from_mixed($item));
                }
            }

            return self::dedupe_options($options);
        }

        $option_container_keys = [
            'answers', 'answer', 'options', 'option', 'items', 'choices', 'choice',
            'answer_options', 'question_answers', 'question_options', '_tutor_quiz_question_answers',
        ];

        foreach ($option_container_keys as $key) {
            if (isset($source[$key])) {
                $options = array_merge($options, self::extract_options_from_mixed($source[$key]));
            }
        }

        $normalized = self::normalize_option_item($source);
        if ($normalized) {
            $options[] = $normalized;
        }

        foreach ($source as $key => $item) {
            if (in_array($key, $option_container_keys, true)) {
                continue;
            }
            if (is_array($item) || is_object($item)) {
                $options = array_merge($options, self::extract_options_from_mixed($item));
            }
        }

        return self::dedupe_options($options);
    }

    protected static function get_question_options($question_id) {
        global $wpdb;

        $question_id = (int) $question_id;
        if ($question_id <= 0) {
            return [];
        }

        if (isset(self::$question_options_cache[$question_id])) {
            return self::$question_options_cache[$question_id];
        }

        $options = [];
        $question_row = self::get_question_row($question_id);

        if (!empty($question_row)) {
            $candidate_columns = ['answer_options', 'answer_option', 'question_options', 'options', 'answers'];
            foreach ($candidate_columns as $column) {
                if (!empty($question_row[$column])) {
                    $options = self::extract_options_from_mixed($question_row[$column]);
                    if (!empty($options)) {
                        break;
                    }
                }
            }

            if (empty($options)) {
                foreach ($question_row as $column => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    if (strpos((string) $column, 'answer') === false && strpos((string) $column, 'option') === false) {
                        continue;
                    }
                    $options = array_merge($options, self::extract_options_from_mixed($value));
                }
                $options = self::dedupe_options($options);
            }
        }

        if (empty($options)) {
            $options = self::extract_options_from_postmeta($question_id);
        }

        if (empty($options)) {
            $fallback_tables = [
                $wpdb->prefix . 'tutor_quiz_question_answers',
                $wpdb->prefix . 'tutor_quiz_answers',
            ];

            foreach ($fallback_tables as $table_name) {
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
                if ($exists !== $table_name) {
                    continue;
                }

                $table_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
                if (!is_array($table_columns) || empty($table_columns)) {
                    continue;
                }

                $question_link_column = null;
                foreach (['belongs_question_id', 'question_id', 'quiz_question_id'] as $candidate) {
                    if (in_array($candidate, $table_columns, true)) {
                        $question_link_column = $candidate;
                        break;
                    }
                }

                if (!$question_link_column) {
                    continue;
                }

                $text_column = null;
                foreach (['answer_title', 'answer_option', 'option_title', 'answer_text', 'title', 'text', 'name', 'value'] as $candidate) {
                    if (in_array($candidate, $table_columns, true)) {
                        $text_column = $candidate;
                        break;
                    }
                }

                if (!$text_column) {
                    continue;
                }

                $correct_column = null;
                foreach (['is_correct', 'correct', 'is_true', 'answer_is_correct', 'right'] as $candidate) {
                    if (in_array($candidate, $table_columns, true)) {
                        $correct_column = $candidate;
                        break;
                    }
                }

                $order_column = null;
                foreach (['answer_order', 'sort_order', 'order', 'answer_id', 'id'] as $candidate) {
                    if (in_array($candidate, $table_columns, true)) {
                        $order_column = $candidate;
                        break;
                    }
                }

                $select = "`{$text_column}` AS option_text";
                if ($correct_column) {
                    $select .= ", `{$correct_column}` AS is_correct";
                }

                $sql = "SELECT {$select} FROM {$table_name} WHERE `{$question_link_column}` = %d";
                if ($order_column) {
                    $sql .= " ORDER BY `{$order_column}` ASC";
                } else {
                    $sql .= " ORDER BY 1 ASC";
                }

                $rows = $wpdb->get_results($wpdb->prepare($sql, $question_id), ARRAY_A);

                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        if (!empty($row['option_text'])) {
                            $options[] = [
                                'text'       => trim((string) $row['option_text']),
                                'is_correct' => !empty($correct_column) ? self::normalize_bool($row['is_correct']) : false,
                            ];
                        }
                    }
                    $options = self::dedupe_options($options);
                }

                if (!empty($options)) {
                    break;
                }
            }
        }

        self::$question_options_cache[$question_id] = self::dedupe_options($options);
        return self::$question_options_cache[$question_id];
    }

    protected static function render_options_html($question_id) {
        $options = self::get_question_options($question_id);

        if (empty($options)) {
            return '<span class="mcems-options-empty">—</span>';
        }

        $html = '<ol class="mcems-options-list">';
        foreach ($options as $option) {
            $text = isset($option['text']) ? trim((string) $option['text']) : '';
            if ($text === '') {
                continue;
            }

            $escaped = esc_html($text);
            if (!empty($option['is_correct'])) {
                $escaped = '<strong>' . $escaped . '</strong>';
            }

            $html .= '<li>' . $escaped . '</li>';
        }
        $html .= '</ol>';

        return $html;
    }

    /**
     * Filters and paginated question stats.
     */
    protected static function get_filtered_stats($args, $do_count = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'quiz_stats_cache';
        $where = [];
        $params = [];

        if (!empty($args['course_id'])) {
            $where[] = 'course_id = %d';
            $params[] = $args['course_id'];
        }
        if (isset($args['min_error']) && $args['min_error'] !== '' && is_numeric($args['min_error'])) {
            $where[] = 'error_percentage >= %f';
            $params[] = (float) $args['min_error'];
        }
        if (isset($args['max_error']) && $args['max_error'] !== '' && is_numeric($args['max_error'])) {
            $where[] = 'error_percentage <= %f';
            $params[] = (float) $args['max_error'];
        }

        $sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        if ($do_count) {
            $sql = "SELECT COUNT(*) FROM $table $sql_where";
            return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        }

        $sort = 'error_percentage';
        $dir = (isset($args['order']) && strtolower($args['order']) === 'asc') ? 'ASC' : 'DESC';
        $allowed_sort = ['question_id', 'quiz_title', 'question_title', 'total_answers', 'wrong_answers', 'error_percentage', 'last_updated'];
        if (!empty($args['order_by']) && in_array($args['order_by'], $allowed_sort, true)) {
            $sort = $args['order_by'];
        }

        $limit = !empty($args['per_page']) ? (int) $args['per_page'] : self::ITEMS_PER_PAGE;
        $offset = !empty($args['offset']) ? (int) $args['offset'] : 0;

        $sql = "SELECT * FROM $table $sql_where ORDER BY $sort $dir LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    protected static function render_styles() {
        ?>
        <style>
            .mcems-stats-shell {
                max-width: 1600px;
            }
            .mcems-stats-toolbar {
                display: flex;
                align-items: flex-end;
                gap: 14px;
                flex-wrap: wrap;
                margin: 18px 0 18px;
                padding: 16px 18px;
                background: #ffffff;
                border: 1px solid #d6d6d6;
                border-radius: 6px;
            }
            .mcems-stats-toolbar__field {
                min-width: 340px;
            }
            .mcems-stats-toolbar__field label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #1d2327;
            }
            .mcems-stats-toolbar__field select {
                width: 100%;
                max-width: 100%;
                min-height: 38px;
            }
            .mcems-stats-toolbar__meta {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin-left: auto;
            }
            .mcems-stats-pill {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                border: 1px solid #d6d6d6;
                border-radius: 999px;
                background: #f8f8f8;
                color: #2c3338;
                font-size: 12px;
            }
            .mcems-actions-row {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin: 0 0 14px;
            }
            .mcems-stats-table-wrap {
                border: 1px solid #cfcfcf;
                background: #fff;
                overflow-x: hidden;
            }
            .mcems-stats-table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }
            .mcems-stats-table thead th {
                background: #efefef;
                color: #1f5f9a;
                font-size: 13px;
                font-weight: 600;
                text-align: left;
                padding: 9px 8px;
                border-bottom: 1px solid #d0d0d0;
            }
            .mcems-stats-table thead th a {
                color: inherit;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .mcems-stats-table tbody td {
                padding: 7px 8px;
                vertical-align: top;
                border-bottom: 1px solid #e5e5e5;
                color: #2c3e50;
                font-size: 14px;
                line-height: 1.45;
                background: #ffffff;
            }
            .mcems-stats-table tbody tr:nth-child(even) td {
                background: #f7f7f7;
            }
            .mcems-stats-table tbody tr:hover td {
                background: #eef6ff;
            }
            .mcems-stats-col-id {
                width: 58px;
            }
            .mcems-stats-col-question {
                width: 24%;
            }
            .mcems-stats-col-options {
                width: 30%;
            }
            .mcems-stats-col-quiz {
                width: 15%;
            }
            .mcems-stats-col-small {
                width: 88px;
                text-align: right;
                white-space: nowrap;
            }
            .mcems-question-cell,
            .mcems-quiz-cell,
            .mcems-stats-table tbody td {
                word-break: break-word;
                white-space: normal;
            }
            .mcems-options-list {
                margin: 0;
                padding-left: 22px;
            }
            .mcems-options-list li {
                margin: 0 0 4px;
            }
            .mcems-options-list li:last-child {
                margin-bottom: 0;
            }
            .mcems-options-empty {
                color: #7a7a7a;
            }
            .mcems-error-rate,
            .mcems-success-rate {
                font-weight: 700;
                font-size: 14px;
            }
            .mcems-error-rate {
                color: #c00000;
            }
            .mcems-success-rate {
                color: #008000;
            }
            .mcems-empty-state {
                padding: 40px 24px;
                text-align: center;
                border: 1px dashed #c8c8c8;
                background: #fff;
                color: #50575e;
            }
            .mcems-stats-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
                margin-top: 14px;
            }
            .mcems-pagination {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }
            .mcems-pagination a,
            .mcems-pagination span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 34px;
                height: 34px;
                padding: 0 10px;
                border-radius: 4px;
                border: 1px solid #d0d0d0;
                background: #fff;
                text-decoration: none;
                color: #1d2327;
            }
            .mcems-pagination .is-current {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
                font-weight: 600;
            }
        </style>
        <?php
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'quiz_stats_cache';
        $updated = isset($_GET['updated']);
        $auto_refreshed = false;

        $courses = self::get_exams();
        $course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
        $order_by = isset($_GET['order_by']) ? sanitize_key($_GET['order_by']) : 'error_percentage';
        $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'asc' : 'desc';
        $auto_refresh = isset($_GET['auto_refresh']) ? (int) $_GET['auto_refresh'] : 0;

        if ($course_id > 0 && $auto_refresh === 1) {
            self::recalculate_stats($course_id);
            $auto_refreshed = true;
        }

        $per_page = self::ITEMS_PER_PAGE;
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($page - 1) * $per_page;

        $args = [
            'course_id' => $course_id,
            'order_by'  => $order_by,
            'order'     => $order,
            'per_page'  => $per_page,
            'offset'    => $offset,
        ];

        $total_found = 0;
        $results = [];
        $last_updated = '';

        if ($course_id > 0) {
            $total_found = self::get_filtered_stats($args, true);
            $results = self::get_filtered_stats($args, false);
            $last_updated = self::get_last_updated($course_id);
        }

        $query_vars = [
            'post_type' => self::PARENT_POST_TYPE,
            'page'      => self::PAGE_SLUG,
            'course_id' => $course_id,
            'order_by'  => $order_by,
            'order'     => $order,
        ];

        self::render_styles();
        ?>
        <div class="wrap mcems-stats-shell">
            <h1>Quiz Question Stats</h1>
            <?php if ($updated) : ?>
                <div class="notice notice-success inline"><p>Stats updated successfully.</p></div>
            <?php endif; ?>
            <?php if ($auto_refreshed) : ?>
                <div class="notice notice-info inline"><p>The selected Exam was refreshed before loading the statistics.</p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" class="mcems-stats-toolbar">
                <input type="hidden" name="post_type" value="<?php echo esc_attr(self::PARENT_POST_TYPE); ?>" />
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <input type="hidden" name="auto_refresh" value="1" />

                <div class="mcems-stats-toolbar__field">
                    <label for="mcems-course-filter">Exam</label>
                    <select id="mcems-course-filter" name="course_id" onchange="this.form.submit()">
                        <option value="0">Select an Exam…</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?php echo esc_attr($c->ID); ?>" <?php selected($course_id, $c->ID); ?>><?php echo esc_html($c->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mcems-stats-toolbar__meta">
                    <?php if ($course_id > 0): ?>
                        <span class="mcems-stats-pill"><strong>Rows:</strong> <?php echo esc_html(number_format_i18n($total_found)); ?></span>
                        <span class="mcems-stats-pill"><strong>Last update:</strong> <?php echo $last_updated ? esc_html(wp_date('Y-m-d H:i:s', strtotime($last_updated))) : '—'; ?></span>
                    <?php else: ?>
                        <span class="mcems-stats-pill">Select an Exam to load the statistics.</span>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($course_id > 0): ?>
                <div class="mcems-actions-row">
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin:0; display:inline-block;">
                        <?php wp_nonce_field('mcems_recalc_quiz_stats'); ?>
                        <input type="hidden" name="action" value="mcems_recalc_quiz_stats" />
                        <input type="hidden" name="course_id" value="<?php echo esc_attr($course_id); ?>" />
                        <button class="button button-secondary" type="submit">Recalculate Stats</button>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;display:inline-block;">
                        <?php wp_nonce_field('mcems_download_quiz_stats_csv'); ?>
                        <input type="hidden" name="action" value="mcems_download_quiz_stats_csv" />
                        <input type="hidden" name="course_id" value="<?php echo esc_attr($course_id); ?>" />
                        <button class="button button-small" name="csv_type" value="all" type="submit">Download all as CSV</button>
                        <button class="button button-small" name="csv_type" value="err_50" type="submit">Download error rate ≥ 50% CSV</button>
                        <button class="button button-small" name="csv_type" value="err_3" type="submit">Download error rate ≤ 3% CSV</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            echo '<div class="notice notice-warning"><p>The stats table does not exist. It will be created automatically on next reload.</p></div>';
            echo '</div>';
            return;
        }

        if ($course_id <= 0) {
            echo '<div class="mcems-empty-state"><p><strong>No Exam selected.</strong></p><p>Choose an Exam from the dropdown above to update and display the question statistics.</p></div>';
            echo '</div>';
            return;
        }

        $columns = [
            'question_id'      => 'ID',
            'question_title'   => 'Question',
            'options'          => 'Options',
            'quiz_title'       => 'Quiz',
            'total_answers'    => 'Total Responses',
            'correct_answers'  => 'Correct Answers',
            'wrong_answers'    => 'Wrong Answers',
            'error_percentage' => 'Error Rate',
            'success_rate'     => 'Success Rate',
        ];
        $dir_switch = ($order === 'asc') ? 'desc' : 'asc';

        if (empty($results)) {
            echo '<div class="mcems-empty-state"><p><strong>No statistics available for this Exam.</strong></p><p>Try recalculating the stats or check whether the selected Exam already has question attempts.</p></div>';
        } else {
            echo '<div class="mcems-stats-table-wrap">';
            echo '<table class="mcems-stats-table">';
            echo '<thead><tr>';
            foreach ($columns as $col_key => $col_name) {
                $th_class = 'mcems-stats-col-question';
                if ($col_key === 'question_id') {
                    $th_class = 'mcems-stats-col-id';
                } elseif ($col_key === 'options') {
                    $th_class = 'mcems-stats-col-options';
                    echo "<th class='" . esc_attr($th_class) . "'>" . esc_html($col_name) . '</th>';
                    continue;
                } elseif ($col_key === 'quiz_title') {
                    $th_class = 'mcems-stats-col-quiz';
                } elseif (in_array($col_key, ['total_answers', 'correct_answers', 'wrong_answers', 'error_percentage', 'success_rate'], true)) {
                    $th_class = 'mcems-stats-col-small';
                }

                $sortable_key = $col_key === 'success_rate' ? 'error_percentage' : $col_key;
                $qry = $query_vars;
                $qry['order_by'] = $sortable_key;
                if ($col_key === 'success_rate') {
                    $qry['order'] = ($order_by === 'error_percentage') ? $dir_switch : 'asc';
                } else {
                    $qry['order'] = ($order_by === $sortable_key) ? $dir_switch : 'desc';
                }
                $qry['paged'] = 1;
                $link = esc_url(self::get_page_url($qry));

                echo "<th class='" . esc_attr($th_class) . "'><a href='" . $link . "'>" . esc_html($col_name);
                if (($col_key !== 'options') && (($order_by === $sortable_key && $col_key !== 'success_rate') || ($col_key === 'success_rate' && $order_by === 'error_percentage'))) {
                    echo $order === 'desc' ? ' <span>&darr;</span>' : ' <span>&uarr;</span>';
                }
                echo '</a></th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($results as $row) {
                $success = max(0, min(100, 100 - (float) $row->error_percentage));
                $correct_answers = max(0, (int) $row->total_answers - (int) $row->wrong_answers);
                echo '<tr>';
                echo '<td class="mcems-stats-col-id">' . esc_html($row->question_id) . '</td>';
                echo '<td class="mcems-stats-col-question"><div class="mcems-question-cell">' . esc_html($row->question_title) . '</div></td>';
                echo '<td class="mcems-stats-col-options">' . self::render_options_html($row->question_id) . '</td>';
                echo '<td class="mcems-stats-col-quiz"><div class="mcems-quiz-cell">' . esc_html($row->quiz_title) . '</div></td>';
                echo '<td class="mcems-stats-col-small">' . (int) $row->total_answers . '</td>';
                echo '<td class="mcems-stats-col-small">' . $correct_answers . '</td>';
                echo '<td class="mcems-stats-col-small">' . (int) $row->wrong_answers . '</td>';
                echo '<td class="mcems-stats-col-small"><span class="mcems-error-rate">' . esc_html(number_format((float) $row->error_percentage, 2)) . '%</span></td>';
                echo '<td class="mcems-stats-col-small"><span class="mcems-success-rate">' . esc_html(number_format($success, 2)) . '%</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';

            $total_pages = (int) ceil($total_found / $per_page);
            echo '<div class="mcems-stats-footer">';
            echo '<p class="description">Displaying ' . esc_html(number_format_i18n($total_found)) . ' questions — page ' . esc_html($page) . ' of ' . esc_html(max(1, $total_pages)) . '.</p>';

            if ($total_pages > 1) {
                $base_url = self::get_page_url($query_vars);
                echo '<div class="mcems-pagination">';
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i === $page) {
                        echo '<span class="is-current">' . esc_html($i) . '</span>';
                    } else {
                        $link = esc_url(add_query_arg('paged', $i, $base_url));
                        echo '<a href="' . $link . '">' . esc_html($i) . '</a>';
                    }
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    public static function handle_csv_download() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('mcems_download_quiz_stats_csv');

        $args = [
            'course_id' => isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0,
        ];

        $csv_type = isset($_POST['csv_type']) ? sanitize_key(wp_unslash($_POST['csv_type'])) : 'all';
        if ($csv_type === 'err_50') {
            $args['min_error'] = 50;
        } elseif ($csv_type === 'err_3') {
            $args['max_error'] = 3;
        }

        $questions = self::get_filtered_stats(array_merge($args, [
            'order_by' => 'error_percentage',
            'order'    => ($csv_type === 'err_3') ? 'asc' : 'desc',
            'per_page' => 5000,
            'offset'   => 0,
        ]), false);

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');

        $date_suffix = date('Y-m-d');
        switch ($csv_type) {
            case 'err_50':
                $filename = 'report-errors-min50pct-' . $date_suffix . '.csv';
                break;
            case 'err_3':
                $filename = 'report-errors-max3pct-' . $date_suffix . '.csv';
                break;
            case 'all':
            default:
                $filename = 'report-all-questions-' . $date_suffix . '.csv';
                break;
        }

        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID',
            'Question',
            'Correct Answer',
            'Answer 1',
            'Answer 2',
            'Answer 3',
            'Quiz',
            'Total Responses',
            'Correct Answers',
            'Wrong Answers',
            'Error Rate',
            'Success Rate',
        ]);

        foreach ($questions as $row) {
            $options = self::get_question_options((int) $row->question_id);
            $answers = [];
            $correct_answer = '';

            foreach ($options as $option) {
                $answer_text = isset($option['text']) ? trim((string) $option['text']) : '';
                if ($answer_text === '') {
                    continue;
                }
                $answers[] = $answer_text;
                if ($correct_answer === '' && !empty($option['is_correct'])) {
                    $correct_answer = $answer_text;
                }
            }

            $answers = array_values(array_slice($answers, 0, 3));

            $correct_answers = max(0, (int) $row->total_answers - (int) $row->wrong_answers);
            $success_rate = max(0, min(100, 100 - (float) $row->error_percentage));

            fputcsv($out, [
                $row->question_id,
                $row->question_title,
                $correct_answer,
                $answers[0] ?? '',
                $answers[1] ?? '',
                $answers[2] ?? '',
                $row->quiz_title,
                $row->total_answers,
                $correct_answers,
                $row->wrong_answers,
                $row->error_percentage,
                $success_rate,
            ]);
        }

        fclose($out);
        exit;
    }
}

if (defined('WPINC')) {
    MCEMS_Quiz_Stats_Admin::init();
}
