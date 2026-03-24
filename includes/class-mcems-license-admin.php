<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCEMS_License_Admin {

    const LICENSE_OPTION_NAME = 'mc_ems_license_key';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_license_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_license_form' ) );
    }

    public function add_license_menu() {
        add_submenu_page(
            'edit.php?post_type=mcems_exam_session',
            __( 'License', 'mc-ems-base' ),
            __( 'License', 'mc-ems-base' ),
            'manage_options',
            'mc-ems-license',
            array( $this, 'display_license_page' ),
            99
        );
    }

    public function handle_license_form() {
        if ( ! isset( $_POST['mc_ems_license_submit'] ) ) {
            return;
        }

        check_admin_referer( 'mc_ems_license_action', 'mc_ems_license_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $key     = isset( $_POST['mc_ems_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mc_ems_license_key'] ) ) : '';
        $old_key = (string) get_option( self::LICENSE_OPTION_NAME, '' );

        update_option( self::LICENSE_OPTION_NAME, $key, false );

        if ( $old_key !== $key ) {
            mcems_clear_license_cache();
        }

        $result = mcems_check_license( true );

        $notice_code = 'saved';
        switch ( isset( $result['status'] ) ? $result['status'] : 'error' ) {
            case 'valid':
                $notice_code = 'valid';
                break;
            case 'expired':
                $notice_code = 'expired';
                break;
            case 'invalid':
                $notice_code = 'invalid';
                break;
            default:
                $notice_code = 'error';
                break;
        }

        $redirect_url = add_query_arg(
            array(
                'post_type'            => 'mcems_exam_session',
                'page'                 => 'mc-ems-license',
                'mcems_license_notice' => $notice_code,
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    private function get_status_config( $status ) {
        $status = strtolower( (string) $status );

        $map = array(
            'valid' => array(
                'label'      => __( 'Active', 'mc-ems-base' ),
                'badge_bg'   => '#dcfce7',
                'badge_text' => '#166534',
                'card_bg'    => '#f0fdf4',
                'border'     => '#86efac',
                'icon'       => '✅',
            ),
            'expired' => array(
                'label'      => __( 'Expired', 'mc-ems-base' ),
                'badge_bg'   => '#fef3c7',
                'badge_text' => '#92400e',
                'card_bg'    => '#fffbeb',
                'border'     => '#fcd34d',
                'icon'       => '⏳',
            ),
            'invalid' => array(
                'label'      => __( 'Invalid', 'mc-ems-base' ),
                'badge_bg'   => '#fee2e2',
                'badge_text' => '#991b1b',
                'card_bg'    => '#fef2f2',
                'border'     => '#fca5a5',
                'icon'       => '⚠️',
            ),
            'error' => array(
                'label'      => __( 'Error', 'mc-ems-base' ),
                'badge_bg'   => '#e0f2fe',
                'badge_text' => '#075985',
                'card_bg'    => '#f0f9ff',
                'border'     => '#7dd3fc',
                'icon'       => 'ℹ️',
            ),
        );

        return isset( $map[ $status ] ) ? $map[ $status ] : $map['error'];
    }

    private function print_notice_from_query_arg() {
        $notice = isset( $_GET['mcems_license_notice'] ) ? sanitize_key( wp_unslash( $_GET['mcems_license_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' === $notice ) {
            return;
        }

        $last_check = mcems_get_last_license_check();
        $message    = isset( $last_check['message'] ) ? (string) $last_check['message'] : '';
        $type       = 'notice-info';

        switch ( $notice ) {
            case 'valid':
                $type = 'notice-success';
                if ( '' === $message ) {
                    $message = __( 'Valid and active license saved successfully.', 'mc-ems-base' );
                }
                break;
            case 'expired':
                $type = 'notice-warning';
                if ( '' === $message ) {
                    $message = __( 'The license was saved, but it has expired.', 'mc-ems-base' );
                }
                break;
            case 'invalid':
                $type = 'notice-error';
                if ( '' === $message ) {
                    $message = __( 'The entered license key is not valid.', 'mc-ems-base' );
                }
                break;
            case 'error':
                $type = 'notice-warning';
                if ( '' === $message ) {
                    $message = __( 'License saved, but the verification server could not be reached.', 'mc-ems-base' );
                }
                break;
            default:
                if ( '' === $message ) {
                    $message = __( 'License settings updated.', 'mc-ems-base' );
                }
                break;
        }

        echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    public function display_license_page() {
        $license_key = (string) get_option( self::LICENSE_OPTION_NAME, '' );
        $license     = $license_key !== '' ? mcems_check_license( false ) : mcems_get_last_license_check();

        if ( ! is_array( $license ) || empty( $license ) ) {
            $license = array(
                'status'       => $license_key !== '' ? 'error' : 'invalid',
                'message'      => $license_key !== ''
                    ? __( 'License information is currently unavailable.', 'mc-ems-base' )
                    : __( 'Enter a license key to activate premium features.', 'mc-ems-base' ),
                'activated_at' => '',
                'created_at'   => '',
                'expires_at'   => '',
                'checked_at'   => '',
            );
        }

        $status        = isset( $license['status'] ) ? strtolower( (string) $license['status'] ) : 'error';
        $status_config = $this->get_status_config( $status );
        $activation_raw = '';
        if ( ! empty( $license['activated_at'] ) ) {
            $activation_raw = $license['activated_at'];
        } elseif ( ! empty( $license['created_at'] ) ) {
            $activation_raw = $license['created_at'];
        }

        $activated_at  = $activation_raw ? mcems_format_license_date( $activation_raw ) : '';
        $expires_at    = isset( $license['expires_at'] ) ? mcems_format_license_date( $license['expires_at'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MC-EMS License Manager', 'mc-ems-base' ); ?></h1>

            <?php $this->print_notice_from_query_arg(); ?>

            <div style="max-width:1100px; margin-top:20px; display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">
                <div style="background:#ffffff; border:1px solid #dcdcde; border-radius:14px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px;">
                        <div>
                            <h2 style="margin:0 0 8px; font-size:22px;"><?php esc_html_e( 'License activation', 'mc-ems-base' ); ?></h2>
                            <p style="margin:0; color:#50575e; font-size:14px;">
                                <?php esc_html_e( 'Enter your license key to unlock premium features and keep your installation verified.', 'mc-ems-base' ); ?>
                            </p>
                        </div>
                        <span style="display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:<?php echo esc_attr( $status_config['badge_bg'] ); ?>; color:<?php echo esc_attr( $status_config['badge_text'] ); ?>; font-weight:600; font-size:13px; white-space:nowrap;">
                            <span aria-hidden="true"><?php echo esc_html( $status_config['icon'] ); ?></span>
                            <?php echo esc_html( $status_config['label'] ); ?>
                        </span>
                    </div>

                    <div style="background:<?php echo esc_attr( $status_config['card_bg'] ); ?>; border:1px solid <?php echo esc_attr( $status_config['border'] ); ?>; border-radius:12px; padding:16px 18px; margin-bottom:20px;">
                        <strong style="display:block; margin-bottom:6px;"><?php esc_html_e( 'Verification status', 'mc-ems-base' ); ?></strong>
                        <div style="color:#374151; line-height:1.5;"><?php echo esc_html( isset( $license['message'] ) ? (string) $license['message'] : '' ); ?></div>
                    </div>

                    <form method="post" action="">
                        <?php wp_nonce_field( 'mc_ems_license_action', 'mc_ems_license_nonce' ); ?>

                        <label for="mc_ems_license_key" style="display:block; font-weight:600; margin-bottom:8px;">
                            <?php esc_html_e( 'License key', 'mc-ems-base' ); ?>
                        </label>

                        <input
                            type="text"
                            id="mc_ems_license_key"
                            name="mc_ems_license_key"
                            value="<?php echo esc_attr( $license_key ); ?>"
                            class="regular-text"
                            autocomplete="off"
                            spellcheck="false"
                            style="width:100%; max-width:640px; min-height:42px; border-radius:8px; font-family:monospace; font-size:14px;"
                        />

                        <p style="margin:10px 0 0; color:#50575e;">
                            <?php esc_html_e( 'Use a valid and active license key. After saving, the plugin will verify the license automatically.', 'mc-ems-base' ); ?>
                        </p>

                        <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                            <?php submit_button( __( 'Save and verify license', 'mc-ems-base' ), 'primary', 'mc_ems_license_submit', false ); ?>
                            <span style="color:#50575e; font-size:13px;">
                                <?php esc_html_e( 'Tip: if you changed key, the previous cached verification will be cleared automatically.', 'mc-ems-base' ); ?>
                            </span>
                        </div>
                    </form>
                </div>

                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div style="background:#ffffff; border:1px solid #dcdcde; border-radius:14px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.04);">
                        <h2 style="margin:0 0 16px; font-size:18px;"><?php esc_html_e( 'License details', 'mc-ems-base' ); ?></h2>
                        <table style="width:100%; border-collapse:collapse;">
                            <tr>
                                <td style="padding:10px 0; border-bottom:1px solid #f0f0f1; color:#50575e;"><?php esc_html_e( 'Status', 'mc-ems-base' ); ?></td>
                                <td style="padding:10px 0; border-bottom:1px solid #f0f0f1; text-align:right; font-weight:600;"><?php echo esc_html( $status_config['label'] ); ?></td>
                            </tr>
                            <tr>
                                <td style="padding:10px 0; border-bottom:1px solid #f0f0f1; color:#50575e;"><?php esc_html_e( 'Activation date', 'mc-ems-base' ); ?></td>
                                <td style="padding:10px 0; border-bottom:1px solid #f0f0f1; text-align:right; font-weight:600;"><?php echo esc_html( $activated_at ? $activated_at : '—' ); ?></td>
                            </tr>
                            <tr>
                                <td style="padding:10px 0; color:#50575e;"><?php esc_html_e( 'Expiration date', 'mc-ems-base' ); ?></td>
                                <td style="padding:10px 0; text-align:right; font-weight:600;"><?php echo esc_html( $expires_at ? $expires_at : '—' ); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
