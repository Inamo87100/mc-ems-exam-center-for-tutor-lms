<?php
if (!defined('ABSPATH')) exit;

class EMS_Session_ID_Column {

    public static function init(): void {
        add_filter('manage_edit-slot_esame_columns', [__CLASS__, 'add_col'], 20);
        add_action('manage_slot_esame_posts_custom_column', [__CLASS__, 'render'], 10, 2);
    }

    public static function add_col($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['ems_session_id'] = __('Session ID', 'mc-ems');
            }
        }
        if (!isset($new['ems_session_id'])) {
            $new['ems_session_id'] = __('Session ID', 'mc-ems');
        }
        return $new;
    }

    public static function render($column, $post_id): void {
        if ($column === 'ems_session_id') {
            echo (int) $post_id;
        }
    }
}
