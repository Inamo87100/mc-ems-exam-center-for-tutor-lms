<?php
/**
 * MCEMS_Quiz_Stats – Admin page and data-layer for Tutor LMS quiz statistics.
 *
 * Requires Tutor LMS to be active (enforced by the bootstrap in mc-ems.php).
 * Custom table: {$wpdb->prefix}mcems_quiz_stats
 *
 * Hooks registered (all admin-only):
 *  - admin_menu                                    → register submenu page
 *  - admin_post_mcems_recalculate_quiz_stats       → POST: recalculate stats
 *  - admin_post_mcems_export_quiz_stats_csv        → GET:  download CSV
 */
if (!defined('ABSPATH')) exit;

class MCEMS_Quiz_Stats {

    /** Full table name (with WP prefix). */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'mcems_quiz_stats';
    }

    /** Register hooks (called only when Tutor LMS is active). */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_mcems_recalculate_quiz_stats',  [__CLASS__, 'handle_recalculate']);
        add_action('admin_post_mcems_export_quiz_stats_csv',   [__CLASS__, 'handle_export_csv']);
    }

    /** Add the "Quiz Statistics" submenu under the MC-EMS CPT. */
    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT,
            __('Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms'),
            __('Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms'),
            'manage_options',
            'mcems-quiz-stats',
            [__CLASS__, 'render']
        );
    }

    // -------------------------------------------------------------------------
    // POST handler: recalculate
    // -------------------------------------------------------------------------

    public static function handle_recalculate(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms'), 403);
        }

        check_admin_referer('mcems_recalculate_quiz_stats');

        $count = self::recalculate();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'mcems-quiz-stats',
                    'recalculated'=> '1',
                    'quiz_count'  => $count,
                ],
                admin_url('edit.php?post_type=' . MCEMS_CPT_Sessioni_Esame::CPT)
            )
        );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST/GET handler: CSV export
    // -------------------------------------------------------------------------

    public static function handle_export_csv(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms'), 403);
        }

        check_admin_referer('mcems_export_quiz_stats_csv');

        self::output_csv();
        exit;
    }

    // -------------------------------------------------------------------------
    // Data: recalculate stats from Tutor LMS attempts table
    // -------------------------------------------------------------------------

    /**
     * Aggregate quiz attempt data from Tutor LMS and upsert into the stats table.
     *
     * @return int Number of quiz rows processed (0 if Tutor LMS table not found).
     */
    public static function recalculate(): int {
        global $wpdb;

        $table          = self::table_name();
        $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';

        // Guard: source table must exist.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $attempts_table)) !== $attempts_table) {
            return 0;
        }

        // Aggregate stats per quiz from completed attempts only.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT
                qa.quiz_id,
                COUNT(*)                                                                                              AS total_attempts,
                COUNT(DISTINCT qa.user_id)                                                                            AS unique_students,
                ROUND(AVG(CASE WHEN qa.total_marks > 0 THEN (qa.earned_marks / qa.total_marks * 100) ELSE 0 END), 2) AS avg_score,
                SUM(CASE WHEN qa.attempt_status = 'pass' THEN 1 ELSE 0 END)                                          AS pass_count,
                SUM(CASE WHEN qa.attempt_status = 'fail' THEN 1 ELSE 0 END)                                          AS fail_count,
                ROUND(MAX(CASE WHEN qa.total_marks > 0 THEN (qa.earned_marks / qa.total_marks * 100) ELSE 0 END), 2) AS highest_score,
                ROUND(MIN(CASE WHEN qa.total_marks > 0 THEN (qa.earned_marks / qa.total_marks * 100) ELSE 0 END), 2) AS lowest_score
            FROM {$attempts_table} qa
            WHERE qa.attempt_status IN ('pass', 'fail', 'attempt_completed')
            GROUP BY qa.quiz_id"
        );

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $quiz_id = absint($row->quiz_id);
            if (!$quiz_id) {
                continue;
            }

            $quiz_title = (string) get_the_title($quiz_id);
            $pass_count = (int) $row->pass_count;
            $total      = (int) $row->total_attempts;
            $pass_rate  = $total > 0 ? round($pass_count / $total * 100, 2) : 0.00;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->replace(
                $table,
                [
                    'quiz_id'        => $quiz_id,
                    'quiz_title'     => $quiz_title,
                    'total_attempts' => $total,
                    'unique_students'=> (int) $row->unique_students,
                    'avg_score'      => (float) $row->avg_score,
                    'pass_count'     => $pass_count,
                    'fail_count'     => (int) $row->fail_count,
                    'pass_rate'      => (float) $pass_rate,
                    'highest_score'  => (float) $row->highest_score,
                    'lowest_score'   => (float) $row->lowest_score,
                    'last_updated'   => current_time('mysql'),
                ],
                ['%d', '%s', '%d', '%d', '%f', '%d', '%d', '%f', '%f', '%f', '%s']
            );
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // CSV output
    // -------------------------------------------------------------------------

    private static function output_csv(): void {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT quiz_id, quiz_title, total_attempts, unique_students, avg_score,
                    pass_count, fail_count, pass_rate, highest_score, lowest_score, last_updated
             FROM {$table}
             ORDER BY total_attempts DESC"
        );

        $filename = 'mcems-quiz-stats-' . gmdate('Y-m-d') . '.csv';

        // Discard any buffered output before sending file headers.
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // UTF-8 BOM for Excel compatibility.
        echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen('php://output', 'wb');

        fputcsv($out, [
            __('Quiz ID',            'mc-ems-exam-center-for-tutor-lms'),
            __('Quiz Title',         'mc-ems-exam-center-for-tutor-lms'),
            __('Total Attempts',     'mc-ems-exam-center-for-tutor-lms'),
            __('Unique Students',    'mc-ems-exam-center-for-tutor-lms'),
            __('Average Score (%)',  'mc-ems-exam-center-for-tutor-lms'),
            __('Pass Count',         'mc-ems-exam-center-for-tutor-lms'),
            __('Fail Count',         'mc-ems-exam-center-for-tutor-lms'),
            __('Pass Rate (%)',       'mc-ems-exam-center-for-tutor-lms'),
            __('Highest Score (%)',  'mc-ems-exam-center-for-tutor-lms'),
            __('Lowest Score (%)',   'mc-ems-exam-center-for-tutor-lms'),
            __('Last Updated',       'mc-ems-exam-center-for-tutor-lms'),
        ]);

        if ($rows) {
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->quiz_id,
                    $row->quiz_title,
                    $row->total_attempts,
                    $row->unique_students,
                    $row->avg_score,
                    $row->pass_count,
                    $row->fail_count,
                    $row->pass_rate,
                    $row->highest_score,
                    $row->lowest_score,
                    $row->last_updated,
                ]);
            }
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    }

    // -------------------------------------------------------------------------
    // Admin page render
    // -------------------------------------------------------------------------

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mc-ems-exam-center-for-tutor-lms'), 403);
        }

        global $wpdb;
        $table = self::table_name();

        // Admin notice after recalculation.
        $notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['recalculated']) && $_GET['recalculated'] === '1') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $quiz_count = isset($_GET['quiz_count']) ? absint($_GET['quiz_count']) : 0;
            $notice     = sprintf(
                /* translators: %d: number of quizzes */
                _n(
                    'Statistics recalculated for %d quiz.',
                    'Statistics recalculated for %d quizzes.',
                    $quiz_count,
                    'mc-ems-exam-center-for-tutor-lms'
                ),
                $quiz_count
            );
        }

        // Fetch all stats rows ordered by most attempts first.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY total_attempts DESC"
        );

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=mcems_export_quiz_stats_csv'),
            'mcems_export_quiz_stats_csv'
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Quiz Statistics', 'mc-ems-exam-center-for-tutor-lms'); ?></h1>
            <p class="description">
                <?php esc_html_e('Aggregated quiz attempt statistics sourced from Tutor LMS. Click "Recalculate" to refresh data from the latest attempts.', 'mc-ems-exam-center-for-tutor-lms'); ?>
            </p>

            <?php if ($notice) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <div class="mcems-panel" style="margin-top:16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <div>
                        <h2 class="mcems-title"><?php esc_html_e('Quiz Attempt Statistics', 'mc-ems-exam-center-for-tutor-lms'); ?></h2>
                        <p class="mcems-desc"><?php esc_html_e('Only completed quiz attempts (pass / fail / completed) are counted.', 'mc-ems-exam-center-for-tutor-lms'); ?></p>
                    </div>
                    <div class="mcems-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('mcems_recalculate_quiz_stats'); ?>
                            <input type="hidden" name="action" value="mcems_recalculate_quiz_stats">
                            <button type="submit" class="mcems-btn">
                                <?php esc_html_e('Recalculate', 'mc-ems-exam-center-for-tutor-lms'); ?>
                            </button>
                        </form>
                        <a href="<?php echo esc_url($export_url); ?>" class="mcems-link">
                            <?php esc_html_e('Export CSV', 'mc-ems-exam-center-for-tutor-lms'); ?>
                        </a>
                    </div>
                </div>

                <?php if (empty($rows)) : ?>
                    <div class="mcems-empty">
                        <?php esc_html_e('No statistics available yet. Click "Recalculate" to generate statistics from Tutor LMS quiz attempts.', 'mc-ems-exam-center-for-tutor-lms'); ?>
                    </div>
                <?php else : ?>
                    <div class="mcems-tablewrap">
                        <table class="mcems-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Quiz', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Attempts', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Students', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Avg Score', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Pass', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Fail', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Pass Rate', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('High Score', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Low Score', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                    <th><?php esc_html_e('Last Updated', 'mc-ems-exam-center-for-tutor-lms'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($row->quiz_title !== '' ? $row->quiz_title : '—'); ?></strong>
                                            <br><span style="color:#6b7280;font-size:12px;">#<?php echo absint($row->quiz_id); ?></span>
                                        </td>
                                        <td><?php echo absint($row->total_attempts); ?></td>
                                        <td><?php echo absint($row->unique_students); ?></td>
                                        <td><?php echo esc_html(number_format((float) $row->avg_score, 1)); ?>%</td>
                                        <td>
                                            <span class="mcems-pill" style="background:#d1fae5;color:#065f46;">
                                                <?php echo absint($row->pass_count); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mcems-pill" style="background:#fee2e2;color:#991b1b;">
                                                <?php echo absint($row->fail_count); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html(number_format((float) $row->pass_rate, 1)); ?>%</td>
                                        <td><?php echo esc_html(number_format((float) $row->highest_score, 1)); ?>%</td>
                                        <td><?php echo esc_html(number_format((float) $row->lowest_score, 1)); ?>%</td>
                                        <td style="font-size:12px;color:#6b7280;"><?php echo esc_html($row->last_updated); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="mcems-hint">
                        <?php
                        printf(
                            /* translators: %d: number of quizzes */
                            esc_html(
                                _n(
                                    '%d quiz total.',
                                    '%d quizzes total.',
                                    count($rows),
                                    'mc-ems-exam-center-for-tutor-lms'
                                )
                            ),
                            count($rows)
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
