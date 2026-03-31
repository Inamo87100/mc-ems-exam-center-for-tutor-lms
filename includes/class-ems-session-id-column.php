<?php
if (!defined('ABSPATH')) exit;

/**
 * EMS_Session_ID_Column
 *
 * Adds a "Session ID" column to the admin list for exam sessions.
 * The column is sortable and a backend search/filter is supported.
 */
class EMS_Session_ID_Column {

    public static function init(): void {
        add_filter('manage_edit-' . MCEMS_CPT_Sessioni_Esame::CPT . '_columns', [__CLASS__, 'add_col'], 20);
        add_action('manage_' . MCEMS_CPT_Sessioni_Esame::CPT . '_posts_custom_column', [__CLASS__, 'render'], 10, 2);
        add_filter('manage_edit-' . MCEMS_CPT_Sessioni_Esame::CPT . '_sortable_columns', [__CLASS__, 'sortable_cols']);
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
                $new['ems_session_id'] = __('Session ID', 'mc-ems-base');
            }
        }
        if (!isset($new['ems_session_id'])) {
            $new['ems_session_id'] = __('Session ID', 'mc-ems-base');
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
        $sortable['ems_session_id'] = 'ID';
        return $sortable;
    }

    /**
     * Output the column value.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render($column, $post_id): void {
        if ($column === 'ems_session_id') {
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
        if ($post_type !== MCEMS_CPT_Sessioni_Esame::CPT) {
            return;
        }

        $value = isset($_GET['ems_session_id_filter'])
            ? absint(wp_unslash($_GET['ems_session_id_filter'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 0;

        echo '<input type="number" name="ems_session_id_filter" id="ems_session_id_filter"'
            . ' value="' . ($value > 0 ? (int) $value : '') . '"'
            . ' placeholder="' . esc_attr__('Session ID…', 'mc-ems-base') . '"'
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

        if ($query->get('post_type') !== MCEMS_CPT_Sessioni_Esame::CPT) {
            return;
        }

        $raw = isset($_GET['ems_session_id_filter']) ? absint(wp_unslash($_GET['ems_session_id_filter'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($raw <= 0) {
            return;
        }

        // Pin the query to that exact post ID.
        $query->set('p', $raw);
    }
}
