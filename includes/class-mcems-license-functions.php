<?php
if (!defined('ABSPATH')) exit;

class MCEMS_License_Admin {

    const LICENSE_OPTION_NAME      = 'mc_ems_license_key';
    const LICENSE_ACTIVE_NAME      = 'mc_ems_license_active';
    const LICENSE_ACTIVATION_DATE  = 'mc_ems_license_activation_date';
    const LICENSE_EXPIRATION_DATE  = 'mc_ems_license_expiration_date';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_license_menu'));
        add_action('admin_init', array($this, 'handle_license_form'));
    }

    public function add_license_menu() {
        add_submenu_page(
            'edit.php?post_type=mcems_exam_session',
            __('License', 'mc-ems-base'),
            __('License', 'mc-ems-base'),
            'manage_options',
            'mc-ems-license',
            array($this, 'display_license_page'),
            99
        );
    }

    public function handle_license_form() {
        if (isset($_POST['mc_ems_license_submit'])) {
            check_admin_referer('mc_ems_license_action', 'mc_ems_license_nonce');
            if (!current_user_can('manage_options')) return;

            $key = isset($_POST['mc_ems_license_key']) ? sanitize_text_field($_POST['mc_ems_license_key']) : '';
            // Salva subito la chiave per la verifica remota
            update_option(self::LICENSE_OPTION_NAME, $key);

            if (!$key) {
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                update_option(self::LICENSE_ACTIVATION_DATE, '');
                update_option(self::LICENSE_EXPIRATION_DATE, '');
                add_settings_error('mc_ems_license', 'mc_ems_license_empty', __('Please enter a license key.', 'mc-ems-base'), 'error');
                return;
            }

            // Verifica remota tramite funzione fornita
            if (!function_exists('mcems_check_license')) {
                // Protezione: include il file se necessario
                // require_once( YOUR_PATH . '/class-mcems-license-functions.php' );
                add_settings_error('mc_ems_license', 'mc_ems_license_no_function', __('License check function is missing!', 'mc-ems-base'), 'error');
                return;
            }

            $check = mcems_check_license(true);

            if ($check['status'] === 'valid') {
                update_option(self::LICENSE_ACTIVE_NAME, 1);
                update_option(self::LICENSE_ACTIVATION_DATE, isset($check['activation_date']) ? $check['activation_date'] : date('Y-m-d'));
                update_option(self::LICENSE_EXPIRATION_DATE, isset($check['expiration_date']) ? $check['expiration_date'] : '');
                add_settings_error('mc_ems_license', 'mc_ems_license_activated', __('License activated successfully!', 'mc-ems-base'), 'updated');
            }
            elseif ($check['status'] === 'expired') {
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                update_option(self::LICENSE_ACTIVATION_DATE, isset($check['activation_date']) ? $check['activation_date'] : '');
                update_option(self::LICENSE_EXPIRATION_DATE, isset($check['expiration_date']) ? $check['expiration_date'] : '');
                add_settings_error('mc_ems_license', 'mc_ems_license_expired', __('License expired. Please renew your license.', 'mc-ems-base'), 'error');
            }
            elseif ($check['status'] === 'invalid') {
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                update_option(self::LICENSE_ACTIVATION_DATE, '');
                update_option(self::LICENSE_EXPIRATION_DATE, '');
                add_settings_error('mc_ems_license', 'mc_ems_license_invalid', __('Invalid license key. Please try again.', 'mc-ems-base'), 'error');
            }
            else {
                // Errore generico API/offline
                update_option(self::LICENSE_ACTIVE_NAME, 0);
                add_settings_error('mc_ems_license', 'mc_ems_license_error', sprintf(__('License server error: %s', 'mc-ems-base'), isset($check['message']) ? $check['message'] : 'Unknown error.'), 'error');
            }
        }
    }

    public function display_license_page() {
        // Sempre una GET "fresca": migliora la user experience (vedi stato vero)
        $license_key      = get_option(self::LICENSE_OPTION_NAME, '');
        $license_active   = get_option(self::LICENSE_ACTIVE_NAME, 0);
        $activation_date  = get_option(self::LICENSE_ACTIVATION_DATE, '');
        $expiration_date  = get_option(self::LICENSE_EXPIRATION_DATE, '');

        // Stato attuale (prova cache locale/transient per evitare richieste eccessive)
        $status_data = function_exists('mcems_check_license') ? mcems_check_license(false) : ['status' => 'error', 'message' => 'Function not found.'];

        $box_class  = '';
        $status_msg = '';
        $date_info  = '';

        if ($license_key) {
            switch ($status_data['status']) {
                case 'valid':
                    $box_class  = 'active';
                    $status_msg = '<span style="color:green;font-weight:bold;">✔️ ' . esc_html__('License active', 'mc-ems-base') . '</span>';
                    $date_info  = '';
                    if (!empty($status_data['activation_date'])) {
                        $date_info .= '<br><strong>' . esc_html__('Activation date:', 'mc-ems-base') . '</strong> ' . esc_html($status_data['activation_date']);
                    } elseif ($activation_date) {
                        $date_info .= '<br><strong>' . esc_html__('Activation date:', 'mc-ems-base') . '</strong> ' . esc_html($activation_date);
                    }
                    if (!empty($status_data['expiration_date'])) {
                        $date_info .= '<br><strong>' . esc_html__('Expiration date:', 'mc-ems-base') . '</strong> ' . esc_html($status_data['expiration_date']);
                    } elseif ($expiration_date) {
                        $date_info .= '<br><strong>' . esc_html__('Expiration date:', 'mc-ems-base') . '</strong> ' . esc_html($expiration_date);
                    }
                    break;

                case 'expired':
                    $box_class  = 'inactive';
                    $status_msg = '<span style="color:orange;font-weight:bold;">⚠️ ' . esc_html__('License expired', 'mc-ems-base') . '</span>';
                    $date_info  = '';
                    if (!empty($status_data['expiration_date'])) {
                        $date_info .= '<br><strong>' . esc_html__('Expiration date:', 'mc-ems-base') . '</strong> ' . esc_html($status_data['expiration_date']);
                    }
                    break;

                case 'invalid':
                    $box_class  = 'inactive';
                    $status_msg = '<span style="color:red;font-weight:bold;">❌ ' . esc_html__('License invalid', 'mc-ems-base') . '</span>';
                    break;

                case 'error':
                    $box_class  = 'inactive';
                    $status_msg = '<span style="color:red;font-weight:bold;">❌ ' . esc_html__('License check error', 'mc-ems-base') . '</span>';
                    $date_info  = !empty($status_data['message']) ? '<br><em>' . esc_html($status_data['message']) . '</em>' : '';
                    break;
            }
        } else {
            $box_class  = 'inactive';
            $status_msg = '<span style="color:red;font-weight:bold;">❌ ' . esc_html__('No license inserted.', 'mc-ems-base') . '</span>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MC EMS License Settings', 'mc-ems-base'); ?></h1>
            <?php settings_errors('mc_ems_license'); ?>

            <div class="mcems-license-status <?php echo esc_attr($box_class); ?>">
                <?php echo $status_msg . $date_info; ?>
            </div>

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
