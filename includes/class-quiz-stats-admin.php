<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

class MCEMS_Quiz_Stats_Admin {

    const ITEMS_PER_PAGE = 25;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('init', [__CLASS__, 'ensure_stats_table']);
        add_action('admin_post_mcems_recalc_quiz_stats', [__CLASS__, 'handle_recalc']);
        add_action('admin_post_mcems_download_quiz_stats_csv', [__CLASS__, 'handle_csv_download']);
    }

    // Attach as submenu of MC-EMS (parent slug = 'mc-ems')
    public static function add_admin_menu() {
        add_submenu_page(
            'mc-ems', // <--- Main plugin menu slug, change if your MC-EMS menu has a different slug!
            'Quiz Stats',
            'Quiz Stats',
            'manage_options',
            'mcems-quiz-stats',
            [__CLASS__, 'render_admin_page']
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
            if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                update_option('quiz_stats_table_created', 'yes');
            }
        }
    }

    public static function handle_recalc() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mcems_recalc_quiz_stats');
        self::recalculate_stats();
        wp_redirect(admin_url('admin.php?page=mcems-quiz-stats&updated=1'));
        exit;
    }

    public static function recalculate_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'quiz_stats_cache';

        $courses = $wpdb->get_results("
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'courses' AND post_status = 'publish'
        ");
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
                        'question_id' => $q->question_id,
                        'course_id' => $q->course_id,
                        'course_title' => $q->course_title,
                        'question_title' => $q->question_title,
                        'quiz_title' => $q->quiz_title,
                        'topic_title' => $q->topic_title,
                        'total_answers' => $total,
                        'wrong_answers' => $wrong,
                        'error_percentage' => $percentage,
                        'last_updated' => current_time('mysql')
                    ]
                );
            }
        }
    }

    /**
     * Filters and paginated question stats.
     */
    protected static function get_filtered_stats($args, $do_count = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'quiz_stats_cache';
        $where = array();
        $params = array();

        if (!empty($args['course_id'])) {
            $where[] = "course_id = %d";
            $params[] = $args['course_id'];
        }
        if (!empty($args['quiz_title'])) {
            $where[] = "quiz_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['quiz_title']) . '%';
        }
        if (!empty($args['question_text'])) {
            $where[] = "question_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['question_text']) . '%';
        }
        if (!empty($args['min_error']) && is_numeric($args['min_error'])) {
            $where[] = "error_percentage >= %f";
            $params[] = floatval($args['min_error']);
        }
        if (!empty($args['max_error']) && is_numeric($args['max_error'])) {
            $where[] = "error_percentage <= %f";
            $params[] = floatval($args['max_error']);
        }

        $sql_where = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        if ($do_count) {
            $sql = "SELECT COUNT(*) FROM $table $sql_where";
            return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        }

        // Sorting
        $sort = 'error_percentage';
        $dir = (isset($args['order']) && strtolower($args['order']) === 'asc') ? 'ASC' : 'DESC';
        $allowed_sort = ['question_id','quiz_title','course_title','topic_title','total_answers','wrong_answers','error_percentage','last_updated'];
        if (!empty($args['order_by']) && in_array($args['order_by'], $allowed_sort, true)) {
            $sort = $args['order_by'];
        }

        // Paging
        $limit = !empty($args['per_page']) ? intval($args['per_page']) : self::ITEMS_PER_PAGE;
        $offset = !empty($args['offset']) ? intval($args['offset']) : 0;
        $sql = "SELECT * FROM $table $sql_where ORDER BY $sort $dir LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'quiz_stats_cache';

        $updated = isset($_GET['updated']);

        // === Filter Params
        $courses = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'courses' AND post_status = 'publish' ORDER BY post_title");
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : ($courses[0]->ID ?? 0);
        $quiz_title = isset($_GET['quiz_title']) ? sanitize_text_field($_GET['quiz_title']) : '';
        $question_text = isset($_GET['question_text']) ? sanitize_text_field($_GET['question_text']) : '';
        $min_error = isset($_GET['min_error']) ? floatval($_GET['min_error']) : '';
        $max_error = isset($_GET['max_error']) ? floatval($_GET['max_error']) : '';
        $order_by = isset($_GET['order_by']) ? sanitize_key($_GET['order_by']) : 'error_percentage';
        $order = isset($_GET['order']) ? (strtolower($_GET['order']) === 'asc' ? 'asc' : 'desc') : 'desc';

        // Paging
        $per_page = self::ITEMS_PER_PAGE;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        $args = [
            'course_id'     => $course_id,
            'quiz_title'    => $quiz_title,
            'question_text' => $question_text,
            'min_error'     => $min_error,
            'max_error'     => $max_error,
            'order_by'      => $order_by,
            'order'         => $order,
            'per_page'      => $per_page,
            'offset'        => $offset,
        ];

        $total_found = self::get_filtered_stats($args, true);
        $results = self::get_filtered_stats($args, false);

        $query_vars = $_GET;
        unset($query_vars['paged']); // for paging links

        ?>
        <div class="wrap">
            <h1>Quiz Question Stats</h1>
            <?php if($updated) : ?>
                <div class="notice notice-success inline"><p>Stats updated successfully!</p></div>
            <?php endif; ?>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" style="margin-bottom: 1em; display:inline-block;">
                <?php wp_nonce_field('mcems_recalc_quiz_stats'); ?>
                <input type="hidden" name="action" value="mcems_recalc_quiz_stats" />
                <button class="button button-secondary" type="submit">Recalculate Stats</button>
                <span class="description" style="margin-left: 12px;">Update all quiz question stats from scratch.</span>
            </form>

            <form method="get" style="margin:16px 0;padding:10px;background:#f8f8fa;border-radius:8px;display:flex;gap:16px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="mcems-quiz-stats" />
                <label>Course:
                    <select name="course_id" onchange="this.form.submit()">
                        <?php foreach ($courses as $c): ?>
                        <option value="<?php echo esc_attr($c->ID); ?>" <?php selected($course_id,$c->ID); ?>><?php echo esc_html($c->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Quiz Title: <input name="quiz_title" value="<?php echo esc_attr($quiz_title); ?>" placeholder="Quiz title..." /></label>
                <label>Question text: <input name="question_text" value="<?php echo esc_attr($question_text); ?>" placeholder="Question..." /></label>
                <label>Min. Error % <input name="min_error" type="number" value="<?php echo esc_attr($min_error); ?>" min="0" max="100" step="0.01" style="width:90px" /></label>
                <label>Max. Error % <input name="max_error" type="number" value="<?php echo esc_attr($max_error); ?>" min="0" max="100" step="0.01" style="width:90px" /></label>
                <button class="button" type="submit">Filter</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em;display:inline-block;">
                <?php wp_nonce_field('mcems_download_quiz_stats_csv'); ?>
                <input type="hidden" name="action" value="mcems_download_quiz_stats_csv" />
                <input type="hidden" name="course_id" value="<?php echo esc_attr($course_id); ?>" />
                <input type="hidden" name="quiz_title" value="<?php echo esc_attr($quiz_title); ?>" />
                <input type="hidden" name="question_text" value="<?php echo esc_attr($question_text); ?>" />
                <input type="hidden" name="min_error" value="" />
                <input type="hidden" name="max_error" value="" />
                <button class="button button-small" name="csv_type" value="all" type="submit">Download all as CSV</button>
                <button class="button button-small" name="csv_type" value="err_50" type="submit">Download errors ≥ 50% CSV</button>
                <button class="button button-small" name="csv_type" value="err_3" type="submit">Download errors ≤ 3% CSV</button>
            </form>
        <?php

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            echo '<div class="notice notice-warning"><p>The stats table does not exist. It will be created automatically on next reload.</p></div>';
            echo '</div>';
            return;
        }

        // Table header with sortable links
        $columns = [
            'question_id'     => 'ID',
            'course_title'    => 'Course',
            'quiz_title'      => 'Quiz',
            'question_title'  => 'Question',
            'total_answers'   => 'Total Answers',
            'wrong_answers'   => 'Wrong Answers',
            'error_percentage'=> 'Error %',
            'last_updated'    => 'Last Updated',
        ];
        $dir_switch = ($order === 'asc') ? 'desc' : 'asc';

        // Print Table
        if (empty($results)) {
            echo '<p>No quiz stats matching this criteria. Try another filter or click "Recalculate Stats".</p>';
        } else {
            echo '<table class="widefat striped" style="max-width: 1200px;font-size:14px">';
            echo '<thead><tr>';
            foreach ($columns as $col_key=>$col_name) {
                $qry = $query_vars;
                $qry['order_by'] = $col_key;
                $qry['order'] = ($order_by === $col_key) ? $dir_switch : 'desc';
                $link = esc_url(add_query_arg($qry, admin_url('admin.php')));
                echo "<th><a style='text-decoration:none' href='$link'>{$col_name}";
                if ($order_by === $col_key) echo $order === 'desc' ? ' <span>&darr;</span>' : ' <span>&uarr;</span>';
                echo '</a></th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($results as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->question_id) . '</td>';
                echo '<td>' . esc_html($row->course_title) . '</td>';
                echo '<td>' . esc_html($row->quiz_title) . '</td>';
                echo '<td>' . esc_html($row->question_title) . '</td>';
                echo '<td style="text-align:right">' . intval($row->total_answers) . '</td>';
                echo '<td style="text-align:right">' . intval($row->wrong_answers) . '</td>';
                echo '<td style="text-align:right">' . number_format((float)$row->error_percentage,2) . '%</td>';
                echo '<td>' . esc_html($row->last_updated) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Pagination
            $total_pages = ceil($total_found / $per_page);
            if ($total_pages > 1) {
                $base_url = add_query_arg($query_vars, admin_url('admin.php?page=mcems-quiz-stats'));
                echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;">';
                for ($i = 1; $i <= $total_pages; $i++) {
                    $link = esc_url(add_query_arg('paged', $i, $base_url));
                    $current = ($i === $page) ? "style='font-weight:bold;background:#007cba;color:#fff;border-radius:3px;padding:3px 7px;'" : "";
                    echo "<a $current href='$link'>$i</a> ";
                }
                echo "</div></div>";
            }
            echo "<p class='description'>Displaying {$total_found} questions — page {$page} of {$total_pages}.</p>";
        }
        echo '</div>';
    }

    public static function handle_csv_download() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('mcems_download_quiz_stats_csv');
        $args = [
            'course_id'     => isset($_POST['course_id']) ? intval($_POST['course_id']) : 0,
            'quiz_title'    => isset($_POST['quiz_title']) ? sanitize_text_field($_POST['quiz_title']) : '',
            'question_text' => isset($_POST['question_text']) ? sanitize_text_field($_POST['question_text']) : ''
        ];
        $csv_type = $_POST['csv_type'] ?? 'all';
        if ($csv_type === 'err_50') {
            $args['min_error'] = 50;
        } elseif ($csv_type === 'err_3') {
            $args['max_error'] = 3;
        }

        // Download filtered (all, ≥50%, ≤3%)
        $questions = self::get_filtered_stats(array_merge($args,[
            'order_by' => 'error_percentage',
            'order'    => ($csv_type === 'err_3') ? 'asc' : 'desc',
            'per_page' => 5000,
            'offset'   => 0,
        ]), false);

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        $filename = 'quiz-stats-' . date('Y-m-d-His') . '-' . $csv_type . '.csv';
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM for UTF-8
        fputcsv($out, [
            'ID',
            'Course',
            'Quiz',
            'Question',
            'Topic',
            'Total Answers',
            'Wrong Answers',
            'Error %',
            'Last Updated'
        ]);
        foreach ($questions as $row) {
            fputcsv($out, [
                $row->question_id,
                $row->course_title,
                $row->quiz_title,
                $row->question_title,
                $row->topic_title,
                $row->total_answers,
                $row->wrong_answers,
                $row->error_percentage,
                $row->last_updated,
            ]);
        }
        fclose($out);
        exit;
    }
}

// Bootstrap!
if (defined('WPINC')) {
    MCEMS_Quiz_Stats_Admin::init();
}
