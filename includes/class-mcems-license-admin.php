<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MC_EMS_License_Admin {

    const LICENSE_OPTION_NAME = 'mc_ems_license_key';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_license_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_license_form' ) );
    }

    public function add_license_menu() {
        add_options_page(
            __( 'MC EMS License', 'mc-ems-base' ),
            __( 'MC EMS License', 'mc-ems-base' ),
            'manage_options',
            'mc-ems-license',
            array( $this, 'display_license_page' )
        );
    }

    public function handle_license_form() {
        if ( isset( $_POST['mc_ems_license_submit'] ) ) {
            check_admin_referer( 'mc_ems_license_action', 'mc_ems_license_nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            $key = isset( $_POST['mc_ems_license_key'] ) ? sanitize_text_field( $_POST['mc_ems_license_key'] ) : '';
            update_option( self::LICENSE_OPTION_NAME, $key );
            add_settings_error(
                'mc_ems_license',
                'mc_ems_license_updated',
                __( 'License key updated.', 'mc-ems-base' ),
                'updated'
            );
        }
    }

    public function display_license_page() {
        $license_key = get_option( self::LICENSE_OPTION_NAME, '' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MC EMS License Settings', 'mc-ems-base' ); ?></h1>
            <?php settings_errors( 'mc_ems_license' ); ?>
            <form method="post" action="">
                <?php wp_nonce_field( 'mc_ems_license_action', 'mc_ems_license_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="mc_ems_license_key"><?php esc_html_e( 'License Key', 'mc-ems-base' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="mc_ems_license_key"
                                name="mc_ems_license_key"
                                value="<?php echo esc_attr( $license_key ); ?>"
                                class="regular-text"
                                style="min-width:350px;"
                            />
                            <p class="description"><?php esc_html_e( 'Paste your MC EMS license key here to unlock premium features.', 'mc-ems-base' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save License', 'mc-ems-base' ), 'primary', 'mc_ems_license_submit' ); ?>
            </form>
        </div>
        <?php
    }
}
