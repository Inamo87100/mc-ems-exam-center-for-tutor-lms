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

define('NFEMS_VERSION', '2.4.2-base');
define('NFEMS_DB_VERSION', '1.4.5');
define('NFEMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NFEMS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-settings.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-tutor.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-tutor-gate.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-upgrader.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-cpt-sessioni-esame.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-ems-session-id-column.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-booking.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-calendar-sessioni.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-admin-sessioni.php';
require_once NFEMS_PLUGIN_DIR . 'includes/class-nfems-admin-banner.php';

register_activation_hook(__FILE__, function () {
    // Ensure options exist + merge defaults
    NFEMS_Upgrader::maybe_upgrade();
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('mc-ems', false, dirname(plugin_basename(__FILE__)) . '/languages');

    NFEMS_Upgrader::maybe_upgrade();

    if (is_admin()) {
        NFEMS_Settings::init_admin();
    }

    NFEMS_CPT_Sessioni_Esame::init();
    EMS_Session_ID_Column::init();
    NFEMS_Booking::init();
    NFEMS_Tutor_Gate::init();

    // Premium placeholder: elenco prenotazioni (sbloccabile con EMS Premium)
    if (!shortcode_exists('mcems_bookings_list')) {
        add_shortcode('mcems_bookings_list', function () {

            // Se il Premium è attivo, non mostriamo nulla: penserà il plugin Premium
            if (defined('EMS_PREMIUM_VERSION') || class_exists('EMS_Premium_Bootstrap')) {
                return '';
            }

            $title = esc_html__('Premium feature', 'mc-ems');
            $msg1  = esc_html__('The', 'mc-ems');
            $msg2  = esc_html__('Exam bookings list', 'mc-ems');
            $msg3  = esc_html__('feature is available only with', 'mc-ems');
            $msg4  = esc_html__('MC-EMS Premium', 'mc-ems');

            return '
            <div style="
                max-width: 800px;
                margin: 30px auto;
                padding: 24px;
                border: 1px solid #fda29b;
                border-radius: 14px;
                background: #fffbfa;
                text-align: center;
            ">
                <div style="font-size: 40px; margin-bottom: 10px;">🔒</div>
                <h2 style="margin: 0 0 10px 0;">' . $title . '</h2>
                <p style="margin: 0; font-size: 16px;">
                    ' . $msg1 . ' <strong>' . $msg2 . '</strong> ' . $msg3 . '
                    <strong>' . $msg4 . '</strong>.
                </p>
            </div>';
        });
    }

    NFEMS_Calendar_Sessioni::init();

    if (is_admin()) {
        NFEMS_Admin_Sessioni::init();
        NFEMS_Admin_Banner::init();

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
