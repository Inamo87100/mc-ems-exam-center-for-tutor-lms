<?php
/**
 * Plugin Name:       MC-EMS – Exam Session Management for Tutor LMS
 * Plugin URI:        https://github.com/Inamo87100/mc-ems-base
 * Description:       Exam Management System – base module.
 * Version:           2.5.0
 * Author:            Mamba Coding
 * Author URI:        https://mambacoding.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0-or-later.html
 * Text Domain:       mc-ems
 * Domain Path:       /languages
 * Requires at least:  5.0
 * Requires PHP:      7.2
 */
if (!defined('ABSPATH')) exit;

// Constants
define('MCEMS_VERSION',    '2.5.0');
define('MCEMS_DB_VERSION', '2.5.0');
define('MCEMS_PLUGIN_URL',  plugin_dir_url(__FILE__));
define('MCEMS_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Load all classes
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-tutor.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-settings.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-upgrader.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-cpt-sessioni-esame.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-booking.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-bookings-list.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-calendar-sessioni.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-admin-sessioni.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-admin-banner.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-mcems-tutor-gate.php';
require_once MCEMS_PLUGIN_PATH . 'includes/class-ems-session-id-column.php';

// Activation hook
register_activation_hook(__FILE__, ['MCEMS_Upgrader', 'maybe_upgrade']);

// Bootstrap the plugin
add_action('plugins_loaded', function () {
    MCEMS_CPT_Sessioni_Esame::init();
    MCEMS_Booking::init();
    MCEMS_Bookings_List_Base::init();
    MCEMS_Calendar_Sessioni::init();
    MCEMS_Tutor_Gate::init();
    MCEMS_Tutor::init();

    if (is_admin()) {
        MCEMS_Settings::init_admin();
        MCEMS_Admin_Sessioni::init();
        MCEMS_Admin_Banner::init();
        EMS_Session_ID_Column::init();
    }
});

