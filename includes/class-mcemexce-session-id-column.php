<?php
if (!defined('ABSPATH')) exit;

/**
 * MCEMEXCE_Session_ID_Column
 *
 * Adds a "Session ID" column to the admin list for exam sessions.
 * The column is sortable and a backend search/filter is supported.
 */
class MCEMEXCE_Session_ID_Column {

    public static function init(): void {
        add_filter('manage_edit-' . MCEMEXCE_CPT_Sessioni_Esame::CPT . '_columns', [__CLASS__, 'add_col'], 20);
        add_action('manage_' . MCEMEXCE_CPT_Sessioni_Esame::CPT . '_posts_custom_column', [__CLASS__, 'render'], 10, 2);
        add_filter('manage_edit-' . MCEMEXCE_CPT_Sessioni_Esame::CPT . '_sortable_columns', [__CLASS__, 'sortable_cols']);
        add_action('pre_get_posts', [__CLASS__, 'filter_by_session_id']);
        add_action('restrict_manage_posts', [__CLASS__, 'render_filter_input']);
    }

    /**
     * Add the Session ID column after the Title column.
     *
     * @param array $columns
     * @return array
     */
    public static function add_col($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['mcemexce_session_id'] = __('Session ID', 'mc-ems-exam-center-for-tutor-lms');
            }
        }
        if (!isset($new['mcemexce_session_id'])) {
            $new['mcemexce_session_id'] = __('Session ID', 'mc-ems-exam-center-for-tutor-lms');
        }
        return $new;
    }

    /**
     * Register the column as sortable.
     *
     * @param array $sortable
     * @return array
     */
    public static function sortable_cols($sortable) {
        $sortable['mcemexce_session_id'] = 'ID';
        return $sortable;
    }

    /**
     * Output the column value.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render($column, $post_id): void {
        if ($column === 'mcemexce_session_id') {
            echo absint($post_id);
        }
    }

    /**
     * Render a text input in the admin list table filters bar so users can
     * filter sessions by Session ID.
     *
     * @param string $post_type
     */
    public static function render_filter_input($post_type): void {
        if ($post_type !== MCEMEXCE_CPT_Sessioni_Esame::CPT) {
            return;
        }

        $nonce_raw   = isset($_GET['mcemexce_session_id_nonce']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_session_id_nonce'])) : '';
        $nonce_valid = $nonce_raw && wp_verify_nonce($nonce_raw, 'mcemexce_session_id_filter');

        $value = ($nonce_valid && isset($_GET['mcemexce_session_id_filter']))
            ? absint(wp_unslash($_GET['mcemexce_session_id_filter']))
            : 0;

        echo '<input type="hidden" name="mcemexce_session_id_nonce" value="' . esc_attr(wp_create_nonce('mcemexce_session_id_filter')) . '">';
        echo '<input type="number" name="mcemexce_session_id_filter" id="mcemexce_session_id_filter"'
            . ' value="' . ($value > 0 ? (int) $value : '') . '"'
            . ' placeholder="' . esc_attr__('Session ID…', 'mc-ems-exam-center-for-tutor-lms') . '"'
            . ' style="width:110px;" min="1">';
    }

    /**
     * Apply the Session ID filter to the query when the input is submitted.
     *
     * @param WP_Query $query
     */
    public static function filter_by_session_id($query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== MCEMEXCE_CPT_Sessioni_Esame::CPT) {
            return;
        }

        $nonce_raw = isset($_GET['mcemexce_session_id_nonce']) ? sanitize_text_field(wp_unslash($_GET['mcemexce_session_id_nonce'])) : '';
        if (!$nonce_raw || !wp_verify_nonce($nonce_raw, 'mcemexce_session_id_filter')) {
            return;
        }

        $raw = isset($_GET['mcemexce_session_id_filter']) ? absint(wp_unslash($_GET['mcemexce_session_id_filter'])) : 0;
        if ($raw <= 0) {
            return;
        }

        // Pin the query to that exact post ID.
        $query->set('p', $raw);
    }
}
