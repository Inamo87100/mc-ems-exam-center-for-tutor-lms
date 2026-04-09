<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MC-EMS Upsell: upgrade prompts for the MC-EMS Premium add-on.
 *
 * All promotion is shown only within the MC-EMS plugin admin area and
 * follows WordPress.org plugin guidelines (no global banners, no pop-ups,
 * fully dismissible notices). No features are gated or limited in the free
 * version — this class only provides optional informational call-to-action UI.
 */
class MCEMEXCE_Upsell {

/** Upgrade destination URL */
const UPGRADE_URL = 'https://mambacoding.com/product/exam-center-for-tutor-lms/';

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

public static function init(): void {
add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
}

// -------------------------------------------------------------------------
// Admin notice (shown on every MC-EMS screen, dismissible)
// -------------------------------------------------------------------------

public static function admin_notice(): void {
$screen = get_current_screen();
if ( ! $screen ) {
return;
}
if ( ! self::is_mcemexce_screen( $screen ) ) {
return;
}
?>
<div class="notice notice-info is-dismissible">
<p>
<strong><?php esc_html_e( 'MC-EMS Exam Center', 'mc-ems-exam-center-for-tutor-lms' ); ?></strong>
&mdash;
<?php esc_html_e( 'Enjoying the plugin? Discover additional features in', 'mc-ems-exam-center-for-tutor-lms' ); ?>
<a href="<?php echo esc_url( self::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer">
<?php esc_html_e( 'MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
</a>
</p>
</div>
<?php
}

/**
 * Returns true when the current screen belongs to the MC-EMS plugin.
 */
private static function is_mcemexce_screen( WP_Screen $screen ): bool {
return (
strpos( $screen->id, 'mcems' ) !== false ||
strpos( $screen->id, MCEMEXCE_CPT_Sessioni_Esame::CPT ) !== false
);
}

// -------------------------------------------------------------------------
// Upgrade tab content (rendered inside Settings page)
// -------------------------------------------------------------------------

public static function render_upgrade_tab(): void {
$upgrade_url = self::UPGRADE_URL;
?>
<div style="max-width:720px;margin:16px 0;">

<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;margin-bottom:20px;">
<h2 style="margin:0 0 8px 0;font-size:1.3em;">
<?php esc_html_e( 'MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</h2>
<p style="margin:0 0 16px 0;color:#50575e;">
<?php esc_html_e( 'The free version is fully functional. MC-EMS Premium adds extra features such as priority support and advanced integrations.', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</p>
<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
<?php esc_html_e( 'See all Premium features', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
</a>
</div>

<p>
<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-large">
<?php esc_html_e( 'Learn more about MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</a>
</p>

</div>
<?php
}
}
