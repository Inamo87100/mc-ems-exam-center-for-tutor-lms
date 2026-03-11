<?php
/*
Plugin Name: MC-EMS Premium – Add-on
Description: Premium add-on for MC-EMS: advanced exam exam bookings list features.
Version: 2.2.6.4-premium
Author: MC Tools
Text Domain: mc-ems
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;


/** Load translations */
function mc_ems_load_textdomain() {
    load_plugin_textdomain('mc-ems', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'mc_ems_load_textdomain');
define('EMS_PREMIUM_VERSION', '1.1.4');

final class EMS_Premium_Bootstrap {

    public static function init(): void {
        add_action('plugins_loaded', [__CLASS__, 'boot'], 20);
        add_action('admin_notices', [__CLASS__, 'notice_missing_base']);
    }

    public static function base_active(): bool {
        // Base defines NFEMS_VERSION and provides these classes.
        return defined('NFEMS_VERSION') && class_exists('NFEMS_Settings') && class_exists('NFEMS_CPT_Sessioni_Esame');
    }

    public static function notice_missing_base(): void {
        if (!current_user_can('activate_plugins')) return;
        if (self::base_active()) return;

        echo '<div class="notice notice-error"><p><strong>EMS Premium</strong> richiede il plugin <strong>EMS – Exam Management System (Base)</strong> attivo.</p></div>';
    }

    public static function boot(): void {
        if (!self::base_active()) return;

        require_once plugin_dir_path(__FILE__) . 'includes/class-nfems-bookings-list.php';

        // Replace placeholder shortcode (Base) with real one
        if (shortcode_exists('mcems_bookings_list')) {
            remove_shortcode('mcems_bookings_list');
        }

        if (class_exists('NFEMS_Bookings_List')) {
            NFEMS_Bookings_List::init();
        }
    }
}

EMS_Premium_Bootstrap::init();
