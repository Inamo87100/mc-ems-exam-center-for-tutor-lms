<?php
/**
 * Plugin Name: MC-EMS – Exam Center for Tutor LMS
 * Description: Advanced exam session management system for Tutor LMS with booking calendar, student reservations, and exam access control.
 * Version: 1.1.0
 * Author: Mamba Coding
 * Author URI: https://mambacoding.com
 * Text Domain: mc-ems-exam-center-for-tutor-lms
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// Constants
define('MCEMS_VERSION',    '1.1.0');
define('MCEMS_DB_VERSION', '1.1.0');
define('MCEMS_PLUGIN_URL',  plugin_dir_url(__FILE__));
define('MCEMS_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Load all classes with file existence checks
$mcems_class_files = [
    'includes/class-mcems-tutor.php',
    'includes/class-mcems-upsell.php',
    'includes/class-mcems-settings.php',
    'includes/class-mcems-upgrader.php',
    'includes/class-mcems-cpt-sessioni-esame.php',
    'includes/class-mcems-booking.php',
    'includes/class-mcems-bookings-list.php',
    'includes/class-mcems-calendar-sessioni.php',
    'includes/class-mcems-admin-sessioni.php',
    'includes/class-mcems-tutor-gate.php',
    'includes/class-ems-session-id-column.php',
    'includes/class-mcems-quiz-stats.php',
];

foreach ($mcems_class_files as $mcems_file) {
    $mcems_full_path = MCEMS_PLUGIN_PATH . $mcems_file;
    if (file_exists($mcems_full_path)) {
        require_once $mcems_full_path;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MC-EMS: Missing required file – ' . $mcems_full_path); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
unset($mcems_class_files, $mcems_file, $mcems_full_path);

/**
 * Check whether Tutor LMS is installed and active.
 * Works both before and after plugins_loaded.
 */
function mcems_is_tutor_active(): bool {
    if (defined('TUTOR_VERSION') || function_exists('tutor') || function_exists('tutor_utils')) {
        return true;
    }

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active('tutor/tutor.php') || is_plugin_active('tutor-lms/tutor.php');
}

// Activation hook
register_activation_hook(__FILE__, 'mcems_activate');

/**
 * Plugin activation callback.
 * Checks dependencies and runs the database upgrade routine.
 */
function mcems_activate(): void {
    if (!mcems_is_tutor_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('MC-EMS – Exam Session Management requires Tutor LMS to be installed and active. Please install and activate Tutor LMS first.', 'mc-ems-exam-center-for-tutor-lms'),
            esc_html__('Plugin Activation Error', 'mc-ems-exam-center-for-tutor-lms'),
            ['back_link' => true]
        );
    }

    if (!class_exists('MCEMS_Upgrader')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MC-EMS Activation: MCEMS_Upgrader class not found.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return;
    }

    try {
        MCEMS_Upgrader::maybe_upgrade();
    } catch (Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MC-EMS Activation Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}

// load_plugin_textdomain() is not needed for plugins hosted on WordPress.org
// with WordPress >= 4.6: translations are loaded automatically from the
// language packs served by translate.wordpress.org.
// See: https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#loading-text-domain

// Bootstrap the plugin
add_action('plugins_loaded', function () {
    if (!mcems_is_tutor_active()) {
        add_action('admin_notices', 'mcems_missing_tutor_notice');
        return;
    }

    $mcems_init_classes = [
        'MCEMS_CPT_Sessioni_Esame',
        'MCEMS_Booking',
        'MCEMS_Bookings_List_Base',
        'MCEMS_Calendar_Sessioni',
        'MCEMS_Tutor_Gate',
        'MCEMS_Tutor',
    ];

    foreach ($mcems_init_classes as $mcems_class) {
        if (!class_exists($mcems_class)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MC-EMS: Class not found – ' . $mcems_class); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            continue;
        }
        try {
            call_user_func([$mcems_class, 'init']);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MC-EMS: Error in ' . $mcems_class . '::init() – ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    if (is_admin()) {
        if (class_exists('MCEMS_Upsell')) {
            MCEMS_Upsell::init();
        }

        $mcems_admin_classes = [
            'MCEMS_Settings'        => 'init_admin',
            'MCEMS_Admin_Sessioni'  => 'init',
            'EMS_Session_ID_Column' => 'init',
            'MCEMS_Quiz_Stats'      => 'init',
        ];

        foreach ($mcems_admin_classes as $mcems_class => $mcems_method) {
            if (!class_exists($mcems_class)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MC-EMS: Admin class not found – ' . $mcems_class); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
                continue;
            }
            try {
                call_user_func([$mcems_class, $mcems_method]);
            } catch (Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MC-EMS: Error in ' . $mcems_class . '::' . $mcems_method . '() – ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }
    }
});

/**
 * Admin notice shown when Tutor LMS is not active.
 */
function mcems_missing_tutor_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('MC-EMS – Exam Session Management requires Tutor LMS to be installed and active.', 'mc-ems-exam-center-for-tutor-lms');
    echo '</p></div>';
}

