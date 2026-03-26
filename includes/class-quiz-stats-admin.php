<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

/**
 * Admin page for TutorLMS Quiz Stats
 */
class MCEMS_Quiz_Stats_Admin {

    public static function init() {
        // Add admin menu
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        // Ensure DB table on init
        add_action('init', [__CLASS__, 'ensure_stats_table']);
        // Handle recalc
        add_action('admin_post_mcems_recalc_quiz_stats', [__CLASS__, 'handle_recalc']);
    }

    public static function add_admin_menu() {
        add_menu_page(
            'Quiz Stats',
            'Quiz Stats',
            'manage_options',
            'mcems-quiz-stats',
            [__CLASS__, 'render_admin_page'],
            'dashicons-chart-bar',
            65
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
            // Save table creation status
            if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                update_option('quiz_stats_table_created', 'yes');
            }
        }
    }

    // Entry point for manual calculation (POST)
    public static function handle_recalc() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mcems_recalc_quiz_stats');
        self::recalculate_stats();
        wp_redirect(admin_url('admin.php?page=mcems-quiz-stats&updated=1'));
        exit;
    }

    // Update all questions stats for all Tutor LMS "courses"
    public static function recalculate_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'quiz_stats_cache';

        // Find all published Tutor LMS courses
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

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'quiz_stats_cache';

        $updated = isset($_GET['updated']);
        ?>
        <div class="wrap">
            <h1>Quiz Question Stats</h1>
            <?php if($updated) : ?>
                <div class="notice notice-success inline"><p>Stats updated successfully!</p></div>
            <?php endif; ?>
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" style="margin-bottom: 1em;">
                <?php wp_nonce_field('mcems_recalc_quiz_stats'); ?>
                <input type="hidden" name="action" value="mcems_recalc_quiz_stats" />
                <button class="button button-secondary" type="submit">Recalculate Stats</button>
                <span class="description" style="margin-left: 12px;">Update all quiz question stats from scratch.</span>
            </form>
        <?php

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo '<div class="notice notice-warning"><p>The stats table does not exist. It will be created automatically on next reload.</p></div>';
            echo '</div>';
            return;
        }

        // Show most recent 50 questions with highest error %
        $results = $wpdb->get_results("
            SELECT * FROM $table_name
            ORDER BY error_percentage DESC, total_answers DESC
            LIMIT 50
        ");

        if (empty($results)) {
            echo '<p>No quiz stats available. Click on "Recalculate Stats" to update.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width: 1200px">';
        echo '<thead><tr>';
        echo '<th>ID</th>
            <th>Course</th>
            <th>Quiz</th>
            <th>Question</th>
            <th>Total Answers</th>
            <th>Wrong Answers</th>
            <th>Error %</th>
            <th>Last Updated</th>';
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
        echo '<p class="description" style="margin-top: 1em;">Showing the top 50 questions with the highest error rate.</p>';
        echo '</div>';
    }
}

// Bootstrap!
if (defined('WPINC')) {
    MCEMS_Quiz_Stats_Admin::init();
}
