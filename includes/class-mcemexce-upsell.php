<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MC-EMS Upsell: free-plan limit display and premium upgrade prompts.
 *
 * All promotion is shown only within the MC-EMS plugin admin area and
 * follows WordPress.org plugin guidelines (no global banners, no pop-ups,
 * fully dismissible notices, graceful degradation when Premium is active).
 */
class MCEMEXCE_Upsell {

	/** Free-plan limits */
	const FREE_MAX_SESSIONS_PER_DAY  = 1;
	const FREE_MAX_ACTIVE_SESSIONS   = 5;
	const FREE_MAX_SEATS_PER_SESSION = 5;

	/** Upgrade destination URL */
	const UPGRADE_URL = 'https://mambacoding.com/product/exam-center-for-tutor-lms/';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init(): void {
		if ( self::is_premium() ) {
			return;
		}
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	// -------------------------------------------------------------------------
	// Premium detection
	// -------------------------------------------------------------------------

	/**
	 * Returns true when MC-EMS Premium is installed and active.
	 * Premium plugins should define the MCEMEXCE_PREMIUM constant or the
	 * MCEMEXCE_Premium class before this is called.
	 */
	public static function is_premium(): bool {
		return defined( 'MCEMEXCE_PREMIUM' ) || class_exists( 'MCEMEXCE_Premium' );
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
				<strong><?php esc_html_e( 'MC-EMS Free plan limits:', 'mc-ems-exam-center-for-tutor-lms' ); ?></strong>
				<?php esc_html_e( '1 session per day', 'mc-ems-exam-center-for-tutor-lms' ); ?> &bull;
				<?php
				echo esc_html( sprintf(
					/* translators: %d: maximum number of active sessions allowed on the free plan */
					__( 'max %d active sessions', 'mc-ems-exam-center-for-tutor-lms' ),
					self::FREE_MAX_ACTIVE_SESSIONS
				) );
				?> &bull;
				<?php
				echo esc_html( sprintf(
					/* translators: %d: maximum number of seats per session allowed on the free plan */
					__( 'max %d seats per session', 'mc-ems-exam-center-for-tutor-lms' ),
					self::FREE_MAX_SEATS_PER_SESSION
				) );
				?>
				&mdash;
				<a href="<?php echo esc_url( self::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Upgrade to MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
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
	// Reusable UI helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a safe HTML string for a limit-exceeded error message that includes
	 * an upgrade link.  Use wp_kses_post() when echoing the return value.
	 */
	public static function limit_error( string $description ): string {
		$upgrade_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::UPGRADE_URL ),
			esc_html__( 'Upgrade to MC-EMS Premium to remove all limits', 'mc-ems-exam-center-for-tutor-lms' )
		);

		return '<strong>' . esc_html__( 'Free plan limit reached:', 'mc-ems-exam-center-for-tutor-lms' ) . '</strong> '
			. esc_html( $description ) . ' &mdash; ' . $upgrade_link . '.';
	}

	/**
	 * Echo an inline upgrade-prompt box (for use inside admin page cards).
	 */
	public static function upgrade_prompt( string $message = '' ): void {
		if ( self::is_premium() ) {
			return;
		}
		echo '<div class="notice notice-warning inline" style="margin:6px 0 12px 0;padding:8px 12px;">';
		echo '<p style="margin:0;">';
		if ( $message ) {
			echo esc_html( $message ) . ' ';
		}
		printf(
			/* translators: %s: "MC-EMS Premium" with upgrade hyperlink */
			esc_html__( 'This feature is available in %s.', 'mc-ems-exam-center-for-tutor-lms' ),
			'<a href="' . esc_url( self::UPGRADE_URL ) . '" target="_blank" rel="noopener noreferrer"><strong>'
				. esc_html__( 'MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' )
			. '</strong></a>'
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Echo a small "Premium" badge intended to sit next to feature labels.
	 */
	public static function premium_badge(): void {
		if ( self::is_premium() ) {
			return;
		}
		echo '<span style="display:inline-block;background:#f0ad4e;color:#fff;font-size:10px;font-weight:700;'
			. 'padding:1px 6px;border-radius:3px;vertical-align:middle;margin-left:6px;text-transform:uppercase;">'
			. esc_html__( 'Premium', 'mc-ems-exam-center-for-tutor-lms' )
			. '</span>';
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
					<?php esc_html_e( 'Upgrade to MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?>
				</h2>
				<p style="margin:0 0 16px 0;color:#50575e;">
					<?php esc_html_e( 'The free version includes all the essentials for exam session management. Upgrade to Premium to remove all limits and unlock advanced features.', 'mc-ems-exam-center-for-tutor-lms' ); ?>
				</p>
				<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
					<?php esc_html_e( 'See all Premium features', 'mc-ems-exam-center-for-tutor-lms' ); ?> &rarr;
				</a>
			</div>

			<table class="widefat fixed striped" style="margin-bottom:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feature', 'mc-ems-exam-center-for-tutor-lms' ); ?></th>
						<th style="text-align:center;width:110px;"><?php esc_html_e( 'Free', 'mc-ems-exam-center-for-tutor-lms' ); ?></th>
						<th style="text-align:center;width:110px;background:#f0f6ff;"><?php esc_html_e( 'Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$features = [
						[
							__( 'Sessions per day', 'mc-ems-exam-center-for-tutor-lms' ),
							/* translators: free plan limit: 1 session per day */
							'1',
							__( 'Unlimited', 'mc-ems-exam-center-for-tutor-lms' ),
						],
						[
							__( 'Active sessions at a time', 'mc-ems-exam-center-for-tutor-lms' ),
							/* translators: free plan limit: max 5 active sessions */
							'5',
							__( 'Unlimited', 'mc-ems-exam-center-for-tutor-lms' ),
						],
						[
							__( 'Seats per session', 'mc-ems-exam-center-for-tutor-lms' ),
							/* translators: free plan limit: max 5 seats per session */
							'5',
							__( 'Up to 500', 'mc-ems-exam-center-for-tutor-lms' ),
						],
						[
							__( 'Student exam booking & calendar', 'mc-ems-exam-center-for-tutor-lms' ),
							'✅',
							'✅',
						],
						[
							__( 'Proctor assignment', 'mc-ems-exam-center-for-tutor-lms' ),
							'✅',
							'✅',
						],
						[
							__( 'Booking cancellation', 'mc-ems-exam-center-for-tutor-lms' ),
							'✅',
							'✅',
						],
						[
							__( 'Email notifications', 'mc-ems-exam-center-for-tutor-lms' ),
							'✅',
							'✅',
						],
						[
							__( 'Exam access control (Tutor Gate)', 'mc-ems-exam-center-for-tutor-lms' ),
							'✅',
							'✅',
						],
						[
							__( 'Priority support', 'mc-ems-exam-center-for-tutor-lms' ),
							'❌',
							'✅',
						],
					];

					foreach ( $features as [ $label, $free_val, $pro_val ] ) :
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td style="text-align:center;"><?php echo esc_html( $free_val ); ?></td>
						<td style="text-align:center;background:#f0f6ff;"><?php echo esc_html( $pro_val ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-large">
					<?php esc_html_e( 'Upgrade to MC-EMS Premium', 'mc-ems-exam-center-for-tutor-lms' ); ?>
				</a>
			</p>

		</div>
		<?php
	}
}

if ( ! function_exists( 'mcemexce_is_premium' ) ) {
	/**
	 * Returns true when MC-EMS Premium is installed and active.
	 */
	function mcemexce_is_premium(): bool {
		return MCEMEXCE_Upsell::is_premium();
	}
}
