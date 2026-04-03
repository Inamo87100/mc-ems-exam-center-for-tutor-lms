<?php
/**
 * MCEMEXCE_Quiz_Stats – Admin page and data-layer for per-question quiz statistics.
 *
 * Provides a course-filtered view of question error/success rates computed from
 * Tutor LMS quiz attempt answers, with pagination, sortable columns, and CSV export.
 *
 * Requires Tutor LMS to be active (enforced by the bootstrap in mc-ems.php).
 * Custom table: {$wpdb->prefix}mcemexce_quiz_stats_cache
 *
 * Hooks registered (all admin-only):
 *  - admin_menu                                       → register submenu page
 *  - init                                             → ensure stats table exists
 *  - admin_post_mcemexce_recalc_quiz_stats               → POST: recalculate stats
 *  - admin_post_mcemexce_download_quiz_stats_csv         → POST: download CSV
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MCEMEXCE_Quiz_Stats {

    const ITEMS_PER_PAGE   = 25;
    const PARENT_POST_TYPE = 'mcemexce_session';
    const PAGE_SLUG        = 'mcemexce-quiz-stats';

    /** In-request cache for question answer options. */
    protected static $question_options_cache = [];
    protected static $question_columns_cache = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'init',       [ __CLASS__, 'ensure_stats_table' ] );
        add_action( 'admin_post_mcemexce_recalc_quiz_stats',       [ __CLASS__, 'handle_recalc' ] );
        add_action( 'admin_post_mcemexce_download_quiz_stats_csv', [ __CLASS__, 'handle_csv_download' ] );
    }

    // -------------------------------------------------------------------------
    // Object-cache helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the current stats-cache version number.
     * All stats cache keys are prefixed with this version so that a single
     * wp_cache_incr() call invalidates the entire logical "group" without
     * requiring wp_cache_flush_group() (available only from WP 6.1+).
     * Uses wp_cache_add() to avoid race conditions on first initialization.
     */
    protected static function stats_cache_version(): int {
        $ver = wp_cache_get( 'mcemexce_stats_ver', 'mcems' );
        if ( false !== $ver ) {
            return (int) $ver;
        }
        // Only sets the value if the key does not already exist (atomic, avoids race conditions).
        wp_cache_add( 'mcemexce_stats_ver', 1, 'mcems', 0 );
        return (int) wp_cache_get( 'mcemexce_stats_ver', 'mcems' );
    }

    /**
     * Bumps the stats-cache version, effectively invalidating all stats caches.
     * Ensures the key exists first so wp_cache_incr() does not fail silently.
     */
    protected static function invalidate_stats_cache(): void {
        // Guarantee the key exists before incrementing.
        self::stats_cache_version();
        wp_cache_incr( 'mcemexce_stats_ver', 1, 'mcems' );
    }

    // -------------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------------

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . self::PARENT_POST_TYPE,
            __( 'Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render' ]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'mcemexce_quiz_stats_cache';
    }

    protected static function get_page_url( array $args = [] ): string {
        return add_query_arg(
            array_merge(
                [
                    'post_type' => self::PARENT_POST_TYPE,
                    'page'      => self::PAGE_SLUG,
                ],
                $args
            ),
            admin_url( 'edit.php' )
        );
    }

    protected static function get_courses(): array {
        global $wpdb;

        $cache_key = 'mcemexce_quiz_stats_courses';
        $cached    = wp_cache_get( $cache_key, 'mcems' );
        if ( false !== $cached ) {
            return (array) $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title
                   FROM {$wpdb->posts}
                  WHERE post_type = %s
                    AND post_status = %s
                  ORDER BY post_title",
                'courses',
                'publish'
            )
        );

        wp_cache_set( $cache_key, $results, 'mcems', 300 );
        return $results;
    }

    // -------------------------------------------------------------------------
    // Table creation
    // -------------------------------------------------------------------------

    public static function ensure_stats_table(): void {
        global $wpdb;

        $table = self::table_name();

        if ( get_option( 'mcemexce_quiz_stats_cache_created' ) === 'yes' ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                mediumint(9)    NOT NULL AUTO_INCREMENT,
            question_id       int(11)         NOT NULL,
            course_id         int(11)         NOT NULL,
            course_title      varchar(255)    NOT NULL DEFAULT '',
            question_title    text            NOT NULL,
            quiz_title        varchar(255)    NOT NULL DEFAULT '',
            topic_title       varchar(255)    NOT NULL DEFAULT '',
            total_answers     int(11)         NOT NULL DEFAULT 0,
            wrong_answers     int(11)         NOT NULL DEFAULT 0,
            error_percentage  decimal(5,2)    NOT NULL DEFAULT 0.00,
            last_updated      datetime        NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY question_course (question_id, course_id),
            KEY course_id (course_id),
            KEY error_percentage (error_percentage)
        ) {$charset_collate};";

        dbDelta( $sql );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            update_option( 'mcemexce_quiz_stats_cache_created', 'yes' );
        }
    }

    // -------------------------------------------------------------------------
    // POST handler: recalculate
    // -------------------------------------------------------------------------

    public static function handle_recalc(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms' ), 403 );
        }

        check_admin_referer( 'mcemexce_recalc_quiz_stats' );

        $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
        self::recalculate_stats( $course_id );

        $redirect_args = [ 'updated' => '1' ];
        if ( $course_id > 0 ) {
            $redirect_args['course_id'] = $course_id;
        }

        wp_safe_redirect( self::get_page_url( $redirect_args ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST handler: CSV download
    // -------------------------------------------------------------------------

    public static function handle_csv_download(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms' ), 403 );
        }

        check_admin_referer( 'mcemexce_download_quiz_stats_csv' );

        $args = [
            'course_id' => isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0,
        ];

        $csv_type = isset( $_POST['csv_type'] ) ? sanitize_key( wp_unslash( $_POST['csv_type'] ) ) : 'all';
        if ( $csv_type === 'err_50' ) {
            $args['min_error'] = 50;
        } elseif ( $csv_type === 'err_3' ) {
            $args['max_error'] = 3;
        }

        $questions = self::get_filtered_stats(
            array_merge( $args, [
                'order_by' => 'error_percentage',
                'order'    => ( $csv_type === 'err_3' ) ? 'asc' : 'desc',
                'per_page' => 5000,
                'offset'   => 0,
            ] ),
            false
        );

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/csv; charset=UTF-8' );

        $date_suffix = gmdate( 'Y-m-d' );
        switch ( $csv_type ) {
            case 'err_50':
                $filename = 'report-errors-min50pct-' . $date_suffix . '.csv';
                break;
            case 'err_3':
                $filename = 'report-errors-max3pct-' . $date_suffix . '.csv';
                break;
            default:
                $filename = 'report-all-questions-' . $date_suffix . '.csv';
                break;
        }

        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'wb' );

        // UTF-8 BOM for Excel compatibility.
        fprintf( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        fputcsv( $out, [
            __( 'ID',              'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Question',        'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Correct Answer',  'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Answer 1',        'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Answer 2',        'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Answer 3',        'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Quiz',            'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Total Responses', 'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Correct Answers', 'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Wrong Answers',   'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Error Rate',      'mc-ems-exam-center-for-tutor-lms' ),
            __( 'Success Rate',    'mc-ems-exam-center-for-tutor-lms' ),
        ] );

        if ( $questions ) {
            foreach ( $questions as $row ) {
                $options        = self::get_question_options( (int) $row->question_id );
                $answers        = [];
                $correct_answer = '';

                foreach ( $options as $option ) {
                    $answer_text = isset( $option['text'] ) ? trim( (string) $option['text'] ) : '';
                    if ( $answer_text === '' ) {
                        continue;
                    }
                    $answers[] = $answer_text;
                    if ( $correct_answer === '' && ! empty( $option['is_correct'] ) ) {
                        $correct_answer = $answer_text;
                    }
                }

                $answers         = array_values( array_slice( $answers, 0, 3 ) );
                $correct_answers = max( 0, (int) $row->total_answers - (int) $row->wrong_answers );
                $success_rate    = max( 0, min( 100, 100 - (float) $row->error_percentage ) );

                fputcsv( $out, [
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
                ] );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // Data: recalculate stats
    // -------------------------------------------------------------------------

    public static function recalculate_stats( int $course_id = 0 ): void {
        global $wpdb;

        $table_name = self::table_name();

        if ( $course_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $courses = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title
                   FROM {$wpdb->posts}
                  WHERE post_type = 'courses'
                    AND post_status = 'publish'
                    AND ID = %d",
                $course_id
            ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $courses = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title
                       FROM {$wpdb->posts}
                      WHERE post_type = %s
                        AND post_status = %s",
                    'courses',
                    'publish'
                )
            );
        }

        if ( empty( $courses ) ) {
            return;
        }

        foreach ( $courses as $course ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $questions = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    qq.question_id,
                    qq.question_title,
                    quiz.ID          AS quiz_id,
                    quiz.post_title  AS quiz_title,
                    topic.ID         AS topic_id,
                    topic.post_title AS topic_title,
                    c.ID             AS course_id,
                    c.post_title     AS course_title
                FROM {$wpdb->prefix}tutor_quiz_questions qq
                INNER JOIN {$wpdb->posts} quiz  ON qq.quiz_id       = quiz.ID
                INNER JOIN {$wpdb->posts} topic ON quiz.post_parent  = topic.ID
                INNER JOIN {$wpdb->posts} c     ON topic.post_parent = c.ID
                WHERE c.ID = %d",
                $course->ID
            ) );

            if ( empty( $questions ) ) {
                continue;
            }

            foreach ( $questions as $q ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $total = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempt_answers WHERE question_id = %d",
                    $q->question_id
                ) );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wrong = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempt_answers WHERE question_id = %d AND is_correct = 0",
                    $q->question_id
                ) );

                $percentage = ( $total > 0 ) ? round( ( $wrong / $total ) * 100, 2 ) : 0.00;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->replace(
                    $table_name,
                    [
                        'question_id'      => (int) $q->question_id,
                        'course_id'        => (int) $q->course_id,
                        'course_title'     => (string) $q->course_title,
                        'question_title'   => (string) $q->question_title,
                        'quiz_title'       => (string) $q->quiz_title,
                        'topic_title'      => (string) $q->topic_title,
                        'total_answers'    => $total,
                        'wrong_answers'    => $wrong,
                        'error_percentage' => $percentage,
                        'last_updated'     => current_time( 'mysql' ),
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s' ]
                );
            }
        }

        // Invalidate object-cache entries so the next page load reflects fresh data.
        // Bumping the version key invalidates all versioned stats cache entries.
        self::invalidate_stats_cache();
    }

    // -------------------------------------------------------------------------
    // Data: filtered / paginated query
    // -------------------------------------------------------------------------

    protected static function get_filtered_stats( array $args, bool $do_count = false ) {
        global $wpdb;
        $table   = self::table_name();
        $where   = [];
        $params  = [];
        // Fetch the version once to avoid multiple cache round-trips for key computation.
        $ver = self::stats_cache_version();

        if ( ! empty( $args['course_id'] ) ) {
            $where[]  = 'course_id = %d';
            $params[] = $args['course_id'];
        }
        if ( isset( $args['min_error'] ) && is_numeric( $args['min_error'] ) ) {
            $where[]  = 'error_percentage >= %f';
            $params[] = (float) $args['min_error'];
        }
        if ( isset( $args['max_error'] ) && is_numeric( $args['max_error'] ) ) {
            $where[]  = 'error_percentage <= %f';
            $params[] = (float) $args['max_error'];
        }

        $sql_where = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        if ( $do_count ) {
            $cache_key = 'cnt_' . $ver . '_' . md5( wp_json_encode( $args ) );
            $cached    = wp_cache_get( $cache_key, 'mcems' );
            if ( false !== $cached ) {
                return (int) $cached;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql   = "SELECT COUNT(*) FROM {$table} {$sql_where}";
            $count = empty( $params )
                ? (int) $wpdb->get_var( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                : (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            wp_cache_set( $cache_key, $count, 'mcems', 300 );
            return $count;
        }

        $allowed_sort = [ 'question_id', 'quiz_title', 'question_title', 'total_answers', 'wrong_answers', 'error_percentage', 'last_updated' ];
        $sort         = 'error_percentage';
        $dir          = ( isset( $args['order'] ) && strtolower( $args['order'] ) === 'asc' ) ? 'ASC' : 'DESC';

        if ( ! empty( $args['order_by'] ) && in_array( $args['order_by'], $allowed_sort, true ) ) {
            $sort = $args['order_by'];
        }

        $limit  = ! empty( $args['per_page'] ) ? absint( $args['per_page'] ) : self::ITEMS_PER_PAGE;
        $offset = ! empty( $args['offset'] )   ? absint( $args['offset'] )   : 0;

        // $sort and $dir are derived from $args, so only $args, $limit, and $offset are needed for uniqueness.
        $cache_key = 'rows_' . $ver . '_' . md5( wp_json_encode( $args ) . $limit . $offset );
        $cached    = wp_cache_get( $cache_key, 'mcems' );
        if ( false !== $cached ) {
            return (array) $cached;
        }

        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql     = "SELECT * FROM {$table} {$sql_where} ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d";
        $results = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        wp_cache_set( $cache_key, $results, 'mcems', 300 );
        return $results;
    }

    protected static function get_last_updated( int $course_id ): string {
        global $wpdb;
        $table = self::table_name();
        if ( $course_id <= 0 ) {
            return '';
        }
        $cache_key = 'lu_' . self::stats_cache_version() . '_' . $course_id;
        $cached    = wp_cache_get( $cache_key, 'mcems' );
        if ( false !== $cached ) {
            return (string) $cached;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(last_updated) FROM {$table} WHERE course_id = %d",
            $course_id
        ) );
        wp_cache_set( $cache_key, $value, 'mcems', 300 );
        return $value;
    }

    // -------------------------------------------------------------------------
    // Question options helpers
    // -------------------------------------------------------------------------

    protected static function get_question_row( int $question_id ): array {
        global $wpdb;
        if ( $question_id <= 0 ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tutor_quiz_questions WHERE question_id = %d LIMIT 1",
                $question_id
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : [];
    }

    protected static function normalize_bool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value === 1;
        }
        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'correct', 'right' ], true );
        }
        return false;
    }

    protected static function normalize_option_item( $item ): ?array {
        if ( is_scalar( $item ) ) {
            $text = trim( (string) $item );
            return $text !== '' ? [ 'text' => $text, 'is_correct' => false ] : null;
        }
        if ( ! is_array( $item ) ) {
            return null;
        }

        $text_keys    = [ 'option_title', 'title', 'answer_option', 'answer_text', 'text', 'name', 'value', 'option_name' ];
        $correct_keys = [ 'is_correct', 'correct', 'is_true', 'answer_is_correct', 'right' ];

        $text = '';
        foreach ( $text_keys as $key ) {
            if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && trim( (string) $item[ $key ] ) !== '' ) {
                $text = trim( (string) $item[ $key ] );
                break;
            }
        }
        if ( $text === '' && isset( $item['answer'] ) && is_scalar( $item['answer'] ) ) {
            $text = trim( (string) $item['answer'] );
        }
        if ( $text === '' ) {
            foreach ( $item as $value ) {
                if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
                    $text = trim( (string) $value );
                    break;
                }
            }
        }
        if ( $text === '' ) {
            return null;
        }

        $is_correct = false;
        foreach ( $correct_keys as $key ) {
            if ( array_key_exists( $key, $item ) ) {
                $is_correct = self::normalize_bool( $item[ $key ] );
                break;
            }
        }

        return [ 'text' => $text, 'is_correct' => $is_correct ];
    }

    protected static function maybe_decode_data( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || $value === '' ) {
            return null;
        }
        if ( function_exists( 'maybe_unserialize' ) ) {
            $unserialized = maybe_unserialize( $value );
            if ( $unserialized !== $value || is_array( $unserialized ) || is_object( $unserialized ) ) {
                return $unserialized;
            }
        }
        $json = json_decode( $value, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $json;
        }
        return null;
    }

    protected static function dedupe_options( array $options ): array {
        $seen  = [];
        $clean = [];
        foreach ( $options as $option ) {
            $text = isset( $option['text'] ) ? trim( wp_strip_all_tags( (string) $option['text'] ) ) : '';
            if ( $text === '' ) {
                continue;
            }
            $key = strtolower( $text );
            if ( isset( $seen[ $key ] ) ) {
                if ( ! empty( $option['is_correct'] ) ) {
                    $clean[ $seen[ $key ] ]['is_correct'] = true;
                }
                continue;
            }
            $seen[ $key ] = count( $clean );
            $clean[]      = [ 'text' => $text, 'is_correct' => ! empty( $option['is_correct'] ) ];
        }
        return $clean;
    }

    protected static function extract_options_from_mixed( $value ): array {
        $decoded = self::maybe_decode_data( $value );
        if ( $decoded === null ) {
            return [];
        }
        $source = is_object( $decoded ) ? (array) $decoded : $decoded;
        if ( ! is_array( $source ) ) {
            return [];
        }

        $options = [];

        if ( array_values( $source ) === $source ) {
            foreach ( $source as $item ) {
                $normalized = self::normalize_option_item( is_object( $item ) ? (array) $item : $item );
                if ( $normalized ) {
                    $options[] = $normalized;
                    continue;
                }
                if ( is_array( $item ) || is_object( $item ) ) {
                    $options = array_merge( $options, self::extract_options_from_mixed( $item ) );
                }
            }
            return self::dedupe_options( $options );
        }

        $container_keys = [
            'answers', 'answer', 'options', 'option', 'items', 'choices', 'choice',
            'answer_options', 'question_answers', 'question_options', '_tutor_quiz_question_answers',
        ];

        foreach ( $container_keys as $key ) {
            if ( isset( $source[ $key ] ) ) {
                $options = array_merge( $options, self::extract_options_from_mixed( $source[ $key ] ) );
            }
        }

        $normalized = self::normalize_option_item( $source );
        if ( $normalized ) {
            $options[] = $normalized;
        }

        foreach ( $source as $key => $item ) {
            if ( in_array( $key, $container_keys, true ) ) {
                continue;
            }
            if ( is_array( $item ) || is_object( $item ) ) {
                $options = array_merge( $options, self::extract_options_from_mixed( $item ) );
            }
        }

        return self::dedupe_options( $options );
    }

    protected static function extract_options_from_postmeta( int $question_id ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $meta_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $question_id
        ), ARRAY_A );

        if ( empty( $meta_rows ) ) {
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
        foreach ( $preferred_keys as $wanted_key ) {
            foreach ( $meta_rows as $row ) {
                if ( $row['meta_key'] === $wanted_key ) {
                    $ordered[] = $row;
                }
            }
        }
        foreach ( $meta_rows as $row ) {
            if ( ! in_array( $row['meta_key'], $preferred_keys, true ) ) {
                $ordered[] = $row;
            }
        }

        $options = [];
        foreach ( $ordered as $row ) {
            $meta_key = (string) $row['meta_key'];
            $decoded  = self::maybe_decode_data( $row['meta_value'] );
            if ( $decoded === null ) {
                continue;
            }

            $looks_promising = in_array( $meta_key, $preferred_keys, true )
                || strpos( $meta_key, 'answer' ) !== false
                || strpos( $meta_key, 'option' ) !== false;

            if ( ! $looks_promising ) {
                continue;
            }

            $found = self::extract_options_from_mixed( $decoded );
            if ( ! empty( $found ) ) {
                $options = array_merge( $options, $found );
                if ( in_array( $meta_key, $preferred_keys, true ) ) {
                    break;
                }
            }
        }

        return self::dedupe_options( $options );
    }

    protected static function get_question_options( int $question_id ): array {
        global $wpdb;

        if ( $question_id <= 0 ) {
            return [];
        }
        if ( isset( self::$question_options_cache[ $question_id ] ) ) {
            return self::$question_options_cache[ $question_id ];
        }

        $options      = [];
        $question_row = self::get_question_row( $question_id );

        if ( ! empty( $question_row ) ) {
            $candidate_cols = [ 'answer_options', 'answer_option', 'question_options', 'options', 'answers' ];
            foreach ( $candidate_cols as $col ) {
                if ( ! empty( $question_row[ $col ] ) ) {
                    $options = self::extract_options_from_mixed( $question_row[ $col ] );
                    if ( ! empty( $options ) ) {
                        break;
                    }
                }
            }

            if ( empty( $options ) ) {
                foreach ( $question_row as $col => $value ) {
                    if ( $value === '' || $value === null ) {
                        continue;
                    }
                    if ( strpos( (string) $col, 'answer' ) === false && strpos( (string) $col, 'option' ) === false ) {
                        continue;
                    }
                    $options = array_merge( $options, self::extract_options_from_mixed( $value ) );
                }
                $options = self::dedupe_options( $options );
            }
        }

        if ( empty( $options ) ) {
            $options = self::extract_options_from_postmeta( $question_id );
        }

        if ( empty( $options ) ) {
            $fallback_tables = [
                $wpdb->prefix . 'tutor_quiz_question_answers',
                $wpdb->prefix . 'tutor_quiz_answers',
            ];

            foreach ( $fallback_tables as $fb_table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fb_table ) );
                if ( $exists !== $fb_table ) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $table_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$fb_table}" );
                if ( ! is_array( $table_cols ) || empty( $table_cols ) ) {
                    continue;
                }

                $q_col = null;
                foreach ( [ 'belongs_question_id', 'question_id', 'quiz_question_id' ] as $c ) {
                    if ( in_array( $c, $table_cols, true ) ) {
                        $q_col = $c;
                        break;
                    }
                }
                $text_col = null;
                foreach ( [ 'answer_title', 'answer_option', 'option_title', 'answer_text', 'title', 'text', 'name', 'value' ] as $c ) {
                    if ( in_array( $c, $table_cols, true ) ) {
                        $text_col = $c;
                        break;
                    }
                }

                if ( ! $q_col || ! $text_col ) {
                    continue;
                }

                $correct_col = null;
                foreach ( [ 'is_correct', 'correct', 'is_true', 'answer_is_correct', 'right' ] as $c ) {
                    if ( in_array( $c, $table_cols, true ) ) {
                        $correct_col = $c;
                        break;
                    }
                }
                $order_col = null;
                foreach ( [ 'answer_order', 'sort_order', 'order', 'answer_id', 'id' ] as $c ) {
                    if ( in_array( $c, $table_cols, true ) ) {
                        $order_col = $c;
                        break;
                    }
                }

                $select = "`{$text_col}` AS option_text";
                if ( $correct_col ) {
                    $select .= ", `{$correct_col}` AS is_correct";
                }
                $sql = "SELECT {$select} FROM {$fb_table} WHERE `{$q_col}` = %d";
                $sql .= $order_col ? " ORDER BY `{$order_col}` ASC" : ' ORDER BY 1 ASC';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                $fb_rows = $wpdb->get_results( $wpdb->prepare( $sql, $question_id ), ARRAY_A );

                if ( ! empty( $fb_rows ) ) {
                    foreach ( $fb_rows as $fb_row ) {
                        if ( ! empty( $fb_row['option_text'] ) ) {
                            $options[] = [
                                'text'       => trim( (string) $fb_row['option_text'] ),
                                'is_correct' => ! empty( $correct_col ) ? self::normalize_bool( $fb_row['is_correct'] ) : false,
                            ];
                        }
                    }
                    $options = self::dedupe_options( $options );
                }

                if ( ! empty( $options ) ) {
                    break;
                }
            }
        }

        self::$question_options_cache[ $question_id ] = self::dedupe_options( $options );
        return self::$question_options_cache[ $question_id ];
    }

    protected static function render_options_html( int $question_id ): string {
        $options = self::get_question_options( $question_id );
        if ( empty( $options ) ) {
            return '<span class="mcems-options-empty">&#8212;</span>';
        }
        $html = '<ol class="mcems-options-list">';
        foreach ( $options as $option ) {
            $text = isset( $option['text'] ) ? trim( (string) $option['text'] ) : '';
            if ( $text === '' ) {
                continue;
            }
            $cell = esc_html( $text );
            if ( ! empty( $option['is_correct'] ) ) {
                $cell = '<strong>' . $cell . '</strong>';
            }
            $html .= '<li>' . $cell . '</li>';
        }
        $html .= '</ol>';

        return wp_kses( $html, [
            'ol'     => [ 'class' => true ],
            'li'     => [],
            'strong' => [],
            'span'   => [ 'class' => true ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Inline styles
    // -------------------------------------------------------------------------

    protected static function render_styles(): void {
        ?>
        <style>
            .mcems-stats-shell { max-width: 1600px; }

            .mcems-stats-toolbar {
                display: flex;
                align-items: flex-end;
                gap: 14px;
                flex-wrap: wrap;
                margin: 18px 0;
                padding: 16px 18px;
                background: #ffffff;
                border: 1px solid #d6d6d6;
                border-radius: 6px;
            }
            .mcems-stats-toolbar__field { min-width: 340px; }
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
            .mcems-stats-table tbody tr:nth-child(even) td { background: #f7f7f7; }
            .mcems-stats-table tbody tr:hover td { background: #eef6ff; }

            .mcems-stats-col-id       { width: 58px; }
            .mcems-stats-col-question { width: 24%; }
            .mcems-stats-col-options  { width: 30%; }
            .mcems-stats-col-quiz     { width: 15%; }
            .mcems-stats-col-small    { width: 88px; text-align: right; white-space: nowrap; }

            .mcems-question-cell,
            .mcems-quiz-cell,
            .mcems-stats-table tbody td { word-break: break-word; white-space: normal; }

            .mcems-options-list { margin: 0; padding-left: 22px; }
            .mcems-options-list li { margin: 0 0 4px; }
            .mcems-options-list li:last-child { margin-bottom: 0; }
            .mcems-options-empty { color: #7a7a7a; }

            .mcems-error-rate,
            .mcems-success-rate { font-weight: 700; font-size: 14px; }
            .mcems-error-rate   { color: #c00000; }
            .mcems-success-rate { color: #008000; }

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
            .mcems-pagination { display: flex; gap: 6px; flex-wrap: wrap; }
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

    // -------------------------------------------------------------------------
    // Admin page render
    // -------------------------------------------------------------------------

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms' ), 403 );
        }

        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $updated        = ! empty( $_GET['updated'] );
        $auto_refreshed = false;

        $courses   = self::get_courses();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_by  = isset( $_GET['order_by'] ) ? sanitize_key( wp_unslash( $_GET['order_by'] ) ) : 'error_percentage';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order     = ( isset( $_GET['order'] ) && strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) === 'asc' ) ? 'asc' : 'desc';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $auto_refresh = isset( $_GET['auto_refresh'] ) ? absint( $_GET['auto_refresh'] ) : 0;

        if ( $course_id > 0 && $auto_refresh === 1 ) {
            self::recalculate_stats( $course_id );
            $auto_refreshed = true;
        }

        $per_page = self::ITEMS_PER_PAGE;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset = ( $page - 1 ) * $per_page;

        $query_args = [
            'course_id' => $course_id,
            'order_by'  => $order_by,
            'order'     => $order,
        ];

        $total_found  = 0;
        $results      = [];
        $last_updated = '';

        if ( $course_id > 0 ) {
            $fetch_args  = array_merge( $query_args, [ 'per_page' => $per_page, 'offset' => $offset ] );
            $total_found = self::get_filtered_stats( $fetch_args, true );
            $results     = self::get_filtered_stats( $fetch_args, false );
            $last_updated = self::get_last_updated( $course_id );
        }

        self::render_styles();
        ?>
        <div class="wrap mcems-stats-shell">
            <h1><?php esc_html_e( 'Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms' ); ?></h1>

            <?php if ( $updated ) : ?>
                <div class="notice notice-success inline is-dismissible">
                    <p><?php esc_html_e( 'Stats updated successfully.', 'mc-ems-exam-center-for-tutor-lms' ); ?></p>
                </div>
            <?php endif; ?>
            <?php if ( $auto_refreshed ) : ?>
                <div class="notice notice-info inline is-dismissible">
                    <p><?php esc_html_e( 'The selected course was refreshed before loading the statistics.', 'mc-ems-exam-center-for-tutor-lms' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="mcems-stats-toolbar">
                <input type="hidden" name="post_type"    value="<?php echo esc_attr( self::PARENT_POST_TYPE ); ?>" />
                <input type="hidden" name="page"         value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                <input type="hidden" name="auto_refresh" value="1" />

                <div class="mcems-stats-toolbar__field">
                    <label for="mcems-course-filter">
                        <?php esc_html_e( 'Course', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                    </label>
                    <select id="mcems-course-filter" name="course_id" onchange="this.form.submit()">
                        <option value="0"><?php esc_html_e( 'Select a Course...', 'mc-ems-exam-center-for-tutor-lms' ); ?></option>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $course_id, $c->ID ); ?>>
                                <?php echo esc_html( $c->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mcems-stats-toolbar__meta">
                    <?php if ( $course_id > 0 ) : ?>
                        <span class="mcems-stats-pill">
                            <strong><?php esc_html_e( 'Rows:', 'mc-ems-exam-center-for-tutor-lms' ); ?></strong>
                            <?php echo esc_html( number_format_i18n( $total_found ) ); ?>
                        </span>
                        <span class="mcems-stats-pill">
                            <strong><?php esc_html_e( 'Last update:', 'mc-ems-exam-center-for-tutor-lms' ); ?></strong>
                            <?php echo $last_updated
                                ? esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $last_updated ) ) )
                                : esc_html( '—' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="mcems-stats-pill">
                            <?php esc_html_e( 'Select a Course to load the statistics.', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ( $course_id > 0 ) : ?>
            <div class="mcems-actions-row">
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin:0;display:inline-block;">
                    <?php wp_nonce_field( 'mcemexce_recalc_quiz_stats' ); ?>
                    <input type="hidden" name="action"    value="mcemexce_recalc_quiz_stats" />
                    <input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>" />
                    <button class="button button-secondary" type="submit">
                        <?php esc_html_e( 'Recalculate Stats', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;display:inline-block;">
                    <?php wp_nonce_field( 'mcemexce_download_quiz_stats_csv' ); ?>
                    <input type="hidden" name="action"    value="mcemexce_download_quiz_stats_csv" />
                    <input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>" />
                    <button class="button button-small" name="csv_type" value="all"    type="submit">
                        <?php esc_html_e( 'Download all as CSV', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                    </button>
                    <button class="button button-small" name="csv_type" value="err_50" type="submit">
                        <?php esc_html_e( 'Download error rate ≥ 50% CSV', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                    </button>
                    <button class="button button-small" name="csv_type" value="err_3"  type="submit">
                        <?php esc_html_e( 'Download error rate ≤ 3% CSV', 'mc-ems-exam-center-for-tutor-lms' ); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                echo '<div class="notice notice-warning"><p>';
                esc_html_e( 'The stats table does not exist. It will be created automatically on the next reload.', 'mc-ems-exam-center-for-tutor-lms' );
                echo '</p></div></div>';
                return;
            }

            if ( $course_id <= 0 ) {
                echo '<div class="mcems-empty-state"><p><strong>';
                esc_html_e( 'No Course selected.', 'mc-ems-exam-center-for-tutor-lms' );
                echo '</strong></p><p>';
                esc_html_e( 'Choose a Course from the dropdown above to update and display the question statistics.', 'mc-ems-exam-center-for-tutor-lms' );
                echo '</p></div></div>';
                return;
            }

            $dir_switch = ( $order === 'asc' ) ? 'desc' : 'asc';

            $columns = [
                'question_id'      => __( 'ID',             'mc-ems-exam-center-for-tutor-lms' ),
                'question_title'   => __( 'Question',        'mc-ems-exam-center-for-tutor-lms' ),
                'options'          => __( 'Options',         'mc-ems-exam-center-for-tutor-lms' ),
                'quiz_title'       => __( 'Quiz',            'mc-ems-exam-center-for-tutor-lms' ),
                'total_answers'    => __( 'Total Responses', 'mc-ems-exam-center-for-tutor-lms' ),
                'correct_answers'  => __( 'Correct Answers', 'mc-ems-exam-center-for-tutor-lms' ),
                'wrong_answers'    => __( 'Wrong Answers',   'mc-ems-exam-center-for-tutor-lms' ),
                'error_percentage' => __( 'Error Rate',      'mc-ems-exam-center-for-tutor-lms' ),
                'success_rate'     => __( 'Success Rate',    'mc-ems-exam-center-for-tutor-lms' ),
            ];

            if ( empty( $results ) ) {
                echo '<div class="mcems-empty-state"><p><strong>';
                esc_html_e( 'No statistics available for this Course.', 'mc-ems-exam-center-for-tutor-lms' );
                echo '</strong></p><p>';
                esc_html_e( 'Try recalculating the stats or check whether the selected Course already has question attempts.', 'mc-ems-exam-center-for-tutor-lms' );
                echo '</p></div></div>';
                return;
            }
            ?>

            <div class="mcems-stats-table-wrap">
                <table class="mcems-stats-table">
                    <thead>
                        <tr>
                        <?php foreach ( $columns as $col_key => $col_name ) :
                            $th_class = 'mcems-stats-col-question';
                            if ( $col_key === 'question_id' ) {
                                $th_class = 'mcems-stats-col-id';
                            } elseif ( $col_key === 'options' ) {
                                $th_class = 'mcems-stats-col-options';
                                echo '<th class="' . esc_attr( $th_class ) . '">' . esc_html( $col_name ) . '</th>';
                                continue;
                            } elseif ( $col_key === 'quiz_title' ) {
                                $th_class = 'mcems-stats-col-quiz';
                            } elseif ( in_array( $col_key, [ 'total_answers', 'correct_answers', 'wrong_answers', 'error_percentage', 'success_rate' ], true ) ) {
                                $th_class = 'mcems-stats-col-small';
                            }

                            $sortable_key  = ( $col_key === 'success_rate' ) ? 'error_percentage' : $col_key;
                            $is_virtual    = in_array( $col_key, [ 'options', 'correct_answers' ], true );
                            $sort_url_args = array_merge( $query_args, [
                                'order_by' => $sortable_key,
                                'order'    => ( $col_key === 'success_rate' )
                                    ? ( ( $order_by === 'error_percentage' ) ? $dir_switch : 'asc' )
                                    : ( ( $order_by === $sortable_key ) ? $dir_switch : 'desc' ),
                                'paged'    => 1,
                            ] );
                            $link     = esc_url( self::get_page_url( $sort_url_args ) );
                            $is_active = ! $is_virtual && ( $order_by === $sortable_key );
                            ?>
                            <th class="<?php echo esc_attr( $th_class ); ?>">
                                <?php if ( ! $is_virtual ) : ?>
                                    <a href="<?php echo esc_url( $link ); ?>">
                                        <?php echo esc_html( $col_name ); ?>
                                        <?php if ( $is_active ) : ?>
                                            <?php echo esc_html( $order === 'desc' ? '↓' : '↑' ); ?>
                                        <?php endif; ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $col_name ); ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $results as $row ) :
                        $success         = max( 0, min( 100, 100 - (float) $row->error_percentage ) );
                        $correct_answers = max( 0, (int) $row->total_answers - (int) $row->wrong_answers );
                        ?>
                        <tr>
                            <td class="mcems-stats-col-id"><?php echo absint( $row->question_id ); ?></td>
                            <td class="mcems-stats-col-question">
                                <div class="mcems-question-cell"><?php echo esc_html( $row->question_title ); ?></div>
                            </td>
                            <td class="mcems-stats-col-options">
                                <?php echo self::render_options_html( (int) $row->question_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                            <td class="mcems-stats-col-quiz">
                                <div class="mcems-quiz-cell"><?php echo esc_html( $row->quiz_title ); ?></div>
                            </td>
                            <td class="mcems-stats-col-small"><?php echo absint( $row->total_answers ); ?></td>
                            <td class="mcems-stats-col-small"><?php echo absint( $correct_answers ); ?></td>
                            <td class="mcems-stats-col-small"><?php echo absint( $row->wrong_answers ); ?></td>
                            <td class="mcems-stats-col-small">
                                <span class="mcems-error-rate">
                                    <?php echo esc_html( number_format( (float) $row->error_percentage, 2 ) ); ?>%
                                </span>
                            </td>
                            <td class="mcems-stats-col-small">
                                <span class="mcems-success-rate">
                                    <?php echo esc_html( number_format( $success, 2 ) ); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $total_pages = (int) ceil( $total_found / $per_page );
            echo '<div class="mcems-stats-footer">';
            echo '<p class="description">';
            printf(
                /* translators: 1: total questions, 2: current page, 3: total pages */
                esc_html__( 'Displaying %1$s questions — page %2$s of %3$s.', 'mc-ems-exam-center-for-tutor-lms' ),
                esc_html( number_format_i18n( $total_found ) ),
                esc_html( $page ),
                esc_html( max( 1, $total_pages ) )
            );
            echo '</p>';

            if ( $total_pages > 1 ) {
                $base_url = self::get_page_url( $query_args );
                echo '<div class="mcems-pagination">';
                for ( $i = 1; $i <= $total_pages; $i++ ) {
                    if ( $i === $page ) {
                        echo '<span class="is-current">' . esc_html( $i ) . '</span>';
                    } else {
                        $p_link = esc_url( add_query_arg( 'paged', $i, $base_url ) );
                        echo '<a href="' . esc_url( $p_link ) . '">' . esc_html( $i ) . '</a>';
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            ?>
        </div>
        <?php
    }
}
