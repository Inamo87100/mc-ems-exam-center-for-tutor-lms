<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MC-EMS Upsell: upgrade prompts for the MC-EMS Premium add-on.
 *
 * All promotion is shown only within the MC-EMS plugin admin area and
 * follows WordPress.org plugin guidelines (no global banners, no pop-ups,
 * fully dismissible notices).
 *
 * Free-plan limits (enforced by MCEMEXCE_Limits) are communicated clearly
 * so that administrators understand what the free version can and cannot do.
 */
class MCEMEXCE_Upsell {

/**
	 * Upgrade destination URL.
	 *
	 * @var string
	 */
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

// Do not show the upsell notice when premium is already active.
if ( class_exists( 'MCEMEXCE_Limits' ) && MCEMEXCE_Limits::is_premium() ) {
return;
}
?>
<div class="notice notice-info is-dismissible">
<p>
<strong><?php esc_html_e( 'MC-EMS Exam Center – Free Version', 'mc-ems-exam-center-for-tutor-lms' ); ?></strong>
&mdash;
<?php
if ( class_exists( 'MCEMEXCE_Limits' ) ) {
    printf(
        /* translators: 1: max active sessions, 2: max seats per session, 3: max sessions per day per exam */
        esc_html__( 'Current limits: %1$d active sessions — %2$d seats/session — %3$d session/day per exam.', 'mc-ems-exam-center-for-tutor-lms' ),
        (int) MCEMEXCE_Limits::FREE_MAX_ACTIVE_SESSIONS,
        (int) MCEMEXCE_Limits::FREE_MAX_SEATS_PER_SESSION,
        (int) MCEMEXCE_Limits::FREE_MAX_SESSIONS_PER_DAY
    );
    echo ' ';
}
?>
<a href="<?php echo esc_url( self::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer">
<?php esc_html_e( 'Upgrade to MC-EMS Premium to remove all limits', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
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

<?php if ( class_exists( 'MCEMEXCE_Limits' ) ): ?>
<h3 style="margin:16px 0 6px 0;font-size:1em;">
<?php esc_html_e( 'Free version limits', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</h3>
<ul style="list-style:disc;margin-left:20px;color:#50575e;">
<li><?php printf(
    /* translators: %d: max active sessions */
    esc_html__( 'Max %d active sessions at a time', 'mc-ems-exam-center-for-tutor-lms' ),
    (int) MCEMEXCE_Limits::FREE_MAX_ACTIVE_SESSIONS
); ?></li>
<li><?php printf(
    /* translators: %d: max seats per session */
    esc_html__( 'Max %d seats per session', 'mc-ems-exam-center-for-tutor-lms' ),
    (int) MCEMEXCE_Limits::FREE_MAX_SEATS_PER_SESSION
); ?></li>
<li><?php printf(
    /* translators: %d: max sessions per day per exam */
    esc_html__( 'Max %d session per day per exam', 'mc-ems-exam-center-for-tutor-lms' ),
    (int) MCEMEXCE_Limits::FREE_MAX_SESSIONS_PER_DAY
); ?></li>
</ul>
<p style="margin:12px 0 0 0;color:#50575e;">
<?php esc_html_e( 'MC-EMS Premium removes all of the above limits and unlocks additional features such as priority support and advanced integrations.', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</p>
<?php else: ?>
<p style="margin:0 0 16px 0;color:#50575e;">
<?php esc_html_e( 'MC-EMS Premium removes free-version session limits and adds extra features such as priority support and advanced integrations.', 'mc-ems-exam-center-for-tutor-lms' ); ?>
</p>
<?php endif; ?>

<p style="margin:16px 0 0 0;">
<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
<?php esc_html_e( 'See all Premium features', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
</a>
</p>
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
