<?php
if (!defined('ABSPATH')) exit;

class MCEMS_License_Admin {

    const LICENSE_OPTION_NAME  = 'mc_ems_license_key';
    const LICENSE_ACTIVE_NAME  = 'mc_ems_license_active';
    const LICENSE_ACTIVATION_DATE = 'mc_ems_license_activation_date';
    const LICENSE_EXPIRATION_DATE = 'mc_ems_license_expiration_date';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_license_menu'));
        add_action('admin_init', array($this, 'handle_license_form'));
    }

    // Add the License page as a submenu under Exam Management System
    public function add_license_menu() {
        add_submenu_page(
            'edit.php?post_type=mcems_exam_session', // parent slug
            __('License', 'mc-ems-base'),            // page title
            __('License', 'mc-ems-base'),            // menu title
            'manage_options',                        // capability
            'mc-ems-license',                        // menu slug
            array($this, 'display_license_page'),    // callback
            99
        );
    }

    public function handle_license_form() {
        if (isset($_POST['mc_ems_license_submit'])) {
            check_admin_referer('mc_ems_license_action', 'mc_ems_license_nonce');
            if (!current_user_can('manage_options')) return;

            $key = isset($_POST['mc_ems_license_key']) ? sanitize_text_field($_POST['mc_ems_license_key']) : '';
            if (empty($key)) {
                update_option(self::LICENSE_OPTION_NAME, '');
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                update_option(self::LICENSE_ACTIVATION_DATE, '');
                update_option(self::LICENSE_EXPIRATION_DATE, '');
                add_settings_error(
                    'mc_ems_license',
                    'mc_ems_license_empty',
                    __('Please enter a license key.', 'mc-ems-base'),
                    'error'
                );
                return;
            }

            // ----
            // QUI si chiama funzione di validazione licenza reale, qui simuliamo attivazione sempre valida
            // Puoi sostituire questa logica con una chiamata API o validatore custom
            $valid = $this->validate_license($key);
            // ----

            if ($valid) {
                $activation_date = date('Y-m-d');
                $expiration_date = date('Y-m-d', strtotime('+1 year'));
                update_option(self::LICENSE_OPTION_NAME, $key);
                update_option(self::LICENSE_ACTIVE_NAME, 1);
                update_option(self::LICENSE_ACTIVATION_DATE, $activation_date);
                update_option(self::LICENSE_EXPIRATION_DATE, $expiration_date);
                add_settings_error(
                    'mc_ems_license',
                    'mc_ems_license_activated',
                    __('License activated successfully!', 'mc-ems-base'),
                    'updated'
                );
            } else {
                update_option(self::LICENSE_OPTION_NAME, $key);
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                update_option(self::LICENSE_ACTIVATION_DATE, '');
                update_option(self::LICENSE_EXPIRATION_DATE, '');
                add_settings_error(
                    'mc_ems_license',
                    'mc_ems_license_invalid',
                    __('Invalid license key. Please try again.', 'mc-ems-base'),
                    'error'
                );
            }
        }
    }

    // Funzione di validazione (modifica secondo la tua logica)
    public function validate_license($key) {
        // Esempio (dev'essere sostituito con la tua logica/API!)
        // Qui ritorna true se la chiave NON contiene 'fail'
        return (stripos($key, 'fail') === false);
    }

    public function display_license_page() {
        $license_key   = get_option(self::LICENSE_OPTION_NAME, '');
        $license_active = get_option(self::LICENSE_ACTIVE_NAME, 0);
        $activation_date = get_option(self::LICENSE_ACTIVATION_DATE, '');
        $expiration_date = get_option(self::LICENSE_EXPIRATION_DATE, '');

        $is_expired = false;
        if ($license_active && $expiration_date) {
            $is_expired = (strtotime($expiration_date) < current_time('timestamp'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MC EMS License Settings', 'mc-ems-base'); ?></h1>
            <?php settings_errors('mc_ems_license'); ?>

            <?php if ($license_key): ?>
                <div class="mcems-license-status <?php echo $license_active && !$is_expired ? 'active' : 'inactive'; ?>">
                    <?php if ($license_active && !$is_expired): ?>
                        <span style="color:green; font-weight:bold;">✔️ <?php esc_html_e('License active', 'mc-ems-base'); ?></span><br/>
                        <strong><?php esc_html_e('Activation date:', 'mc-ems-base'); ?></strong> <?php echo esc_html($activation_date); ?><br/>
                        <strong><?php esc_html_e('Expiration date:', 'mc-ems-base'); ?></strong> <?php echo esc_html($expiration_date); ?>
                    <?php elseif ($is_expired): ?>
                        <span style="color:orange; font-weight:bold;">⚠️ <?php esc_html_e('License expired', 'mc-ems-base'); ?></span><br/>
                        <strong><?php esc_html_e('Expiration date:', 'mc-ems-base'); ?></strong> <?php echo esc_html($expiration_date); ?>
                    <?php else: ?>
                        <span style="color:red; font-weight:bold;">❌ <?php esc_html_e('License not active or invalid', 'mc-ems-base'); ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mcems-license-status inactive">
                    <span style="color:red; font-weight:bold;">❌ <?php esc_html_e('No license inserted.', 'mc-ems-base'); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('mc_ems_license_action', 'mc_ems_license_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="mc_ems_license_key"><?php esc_html_e('License Key', 'mc-ems-base'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="mc_ems_license_key"
                                name="mc_ems_license_key"
                                value="<?php echo esc_attr($license_key); ?>"
                                class="regular-text"
                                style="min-width:350px;"
                                autocomplete="off"
                            />
                            <p class="description"><?php esc_html_e('Paste your MC EMS license key here to unlock premium features.', 'mc-ems-base'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save License', 'mc-ems-base'), 'primary', 'mc_ems_license_submit'); ?>
            </form>
        </div>
        <style>
            .mcems-license-status {
                margin: 10px 0 30px 0;
                padding: 14px 18px;
                border-radius: 5px;
                font-size: 15px;
                border: 1px solid #ccc;
                max-width: 700px;
            }
            .mcems-license-status.active {
                background: #e9f9ef;
                border-color: #1edd58;
            }
            .mcems-license-status.inactive {
                background: #ffeaea;
                border-color: #ff3838;
            }
        </style>
        <?php
    }
}
