<?php
/**
 * Plugin Name: MC-EMS – Exam Center for Tutor LMS
 * Description: Advanced exam session management system for Tutor LMS with booking calendar, student reservations, and exam access control.
 * Version: 1.2.2
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
define('MCEMEXCE_VERSION',    '1.2.2');
define('MCEMEXCE_DB_VERSION', '1.1.0');
define('MCEMEXCE_PLUGIN_URL',  plugin_dir_url(__FILE__));
define('MCEMEXCE_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Load all classes with file existence checks
$mcemexce_class_files = [
    'includes/class-mcemexce-tutor.php',
    'includes/class-mcemexce-upsell.php',
    'includes/class-mcemexce-settings.php',
    'includes/class-mcemexce-upgrader.php',
    'includes/class-mcemexce-cpt-sessioni-esame.php',
    'includes/class-mcemexce-booking.php',
    'includes/class-mcemexce-bookings-list.php',
    'includes/class-mcemexce-calendar-sessioni.php',
    'includes/class-mcemexce-admin-sessioni.php',
    'includes/class-mcemexce-tutor-gate.php',
    'includes/class-mcemexce-session-id-column.php',
    'includes/class-mcemexce-quiz-stats.php',
];

foreach ($mcemexce_class_files as $mcemexce_file) {
    $mcemexce_full_path = MCEMEXCE_PLUGIN_PATH . $mcemexce_file;
    if (file_exists($mcemexce_full_path)) {
        require_once $mcemexce_full_path;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MC-EMS: Missing required file – ' . $mcemexce_full_path); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
unset($mcemexce_class_files, $mcemexce_file, $mcemexce_full_path);

/**
 * Check whether Tutor LMS is installed and active.
 * Works both before and after plugins_loaded.
 */
function mcemexce_is_tutor_active(): bool {
    if (defined('TUTOR_VERSION') || function_exists('tutor') || function_exists('tutor_utils')) {
        return true;
    }

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active('tutor/tutor.php') || is_plugin_active('tutor-lms/tutor.php');
}

// Activation hook
register_activation_hook(__FILE__, 'mcemexce_activate');

/**
 * Plugin activation callback.
 * Checks dependencies and runs the database upgrade routine.
 */
function mcemexce_activate(): void {
    if (!mcemexce_is_tutor_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('MC-EMS – Exam Session Management requires Tutor LMS to be installed and active. Please install and activate Tutor LMS first.', 'mc-ems-exam-center-for-tutor-lms'),
            esc_html__('Plugin Activation Error', 'mc-ems-exam-center-for-tutor-lms'),
            ['back_link' => true]
        );
    }

    if (!class_exists('MCEMEXCE_Upgrader')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MC-EMS Activation: MCEMEXCE_Upgrader class not found.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return;
    }

    try {
        MCEMEXCE_Upgrader::maybe_upgrade();
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
    if (!mcemexce_is_tutor_active()) {
        add_action('admin_notices', 'mcemexce_missing_tutor_notice');
        return;
    }

    $mcemexce_init_classes = [
        'MCEMEXCE_CPT_Sessioni_Esame',
        'MCEMEXCE_Booking',
        'MCEMEXCE_Bookings_List_Base',
        'MCEMEXCE_Calendar_Sessioni',
        'MCEMEXCE_Tutor_Gate',
        'MCEMEXCE_Tutor',
    ];

    foreach ($mcemexce_init_classes as $mcemexce_class) {
        if (!class_exists($mcemexce_class)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MC-EMS: Class not found – ' . $mcemexce_class); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            continue;
        }
        try {
            call_user_func([$mcemexce_class, 'init']);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MC-EMS: Error in ' . $mcemexce_class . '::init() – ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    if (is_admin()) {
        if (class_exists('MCEMEXCE_Upsell')) {
            MCEMEXCE_Upsell::init();
        }

        $mcemexce_admin_classes = [
            'MCEMEXCE_Settings'        => 'init_admin',
            'MCEMEXCE_Admin_Sessioni'  => 'init',
            'MCEMEXCE_Session_ID_Column' => 'init',
            'MCEMEXCE_Quiz_Stats'      => 'init',
        ];

        foreach ($mcemexce_admin_classes as $mcemexce_class => $mcemexce_method) {
            if (!class_exists($mcemexce_class)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MC-EMS: Admin class not found – ' . $mcemexce_class); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
                continue;
            }
            try {
                call_user_func([$mcemexce_class, $mcemexce_method]);
            } catch (Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MC-EMS: Error in ' . $mcemexce_class . '::' . $mcemexce_method . '() – ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }
    }
});

/**
 * Admin notice shown when Tutor LMS is not active.
 */
function mcemexce_missing_tutor_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('MC-EMS – Exam Session Management requires Tutor LMS to be installed and active.', 'mc-ems-exam-center-for-tutor-lms');
    echo '</p></div>';
}

