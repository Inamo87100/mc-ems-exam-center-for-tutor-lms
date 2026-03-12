<?php
/*
Plugin Name: MC-EMS – Exam Management System
Description: Exam session management (CPT), candidate exam bookings, exam bookings list and proctor assignment calendar.
Version: 2.4.2-base
Author: MC Tools
Text Domain: mc-ems
Domain Path: /languages
Requires at least: 6.0
Requires PHP: 7.0
*/

if (!defined('ABSPATH')) exit;

define('MCEMS_VERSION', '2.4.2-base');
define('MCEMS_DB_VERSION', '1.5.0');
define('MCEMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MCEMS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-settings.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-tutor.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-tutor-gate.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-upgrader.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-cpt-sessioni-esame.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-ems-session-id-column.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-booking.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-bookings-list.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-calendar-sessioni.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-admin-sessioni.php';
require_once MCEMS_PLUGIN_DIR . 'includes/class-mcems-admin-banner.php';

register_activation_hook(__FILE__, function () {
    // Ensure options exist + merge defaults
    MCEMS_Upgrader::maybe_upgrade();
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('mc-ems', false, dirname(plugin_basename(__FILE__)) . '/languages');

    MCEMS_Upgrader::maybe_upgrade();

    if (is_admin()) {
        MCEMS_Settings::init_admin();
    }

    MCEMS_CPT_Sessioni_Esame::init();
    EMS_Session_ID_Column::init();
    MCEMS_Booking::init();
    MCEMS_Tutor_Gate::init();
    MCEMS_Bookings_List_Base::init();

    MCEMS_Calendar_Sessioni::init();

    if (is_admin()) {
        MCEMS_Admin_Sessioni::init();
        MCEMS_Admin_Banner::init();

        // AJAX: user search by email (special sessions)
        add_action('wp_ajax_mcems_user_search', function () {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'mcems_user_search')) {
                wp_send_json_error(['message' => 'bad_nonce'], 400);
            }

            $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
            $q = trim($q);
            if (strlen($q) < 3) {
                wp_send_json_success([]);
            }

            $user_query = new WP_User_Query([
                'number' => 20,
                'orderby' => 'ID',
                'order' => 'DESC',
                'search' => '*' . $q . '*',
                'search_columns' => ['user_email'],
                'fields' => ['ID', 'user_email', 'display_name'],
            ]);

            $out = [];
            foreach ((array) $user_query->get_results() as $u) {
                $out[] = [
                    'id' => (int) $u->ID,
                    'email' => (string) $u->user_email,
                    'name' => (string) $u->display_name,
                ];
            }

            wp_send_json_success($out);
        });
    }
});
