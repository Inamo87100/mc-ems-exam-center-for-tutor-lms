<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MC-EMS Free-Plan Limits
 *
 * Centralises all hard limits that apply to the free version of MC-EMS.
 * The MC-EMS Premium add-on can remove every limit by:
 *   1. Defining the constant MCEMS_PREMIUM before this class is loaded, OR
 *   2. Registering the class MCEMS_Premium, OR
 *   3. Filtering 'mcemexce_is_premium' to return true.
 *
 * Individual limits are also individually filterable so that Premium (or a
 * site-owner) can adjust them without fully bypassing the gating logic:
 *   - mcemexce_free_max_active_sessions   (int)
 *   - mcemexce_free_max_seats_per_session (int)
 *   - mcemexce_free_max_sessions_per_day  (int)
 *
 * @package MC-EMS
 * @since   1.3.0
 */
class MCEMEXCE_Limits {

	// -------------------------------------------------------------------------
	// Free-plan hard limits (documented constants)
	// -------------------------------------------------------------------------

	/**
	 * Maximum number of future/active published sessions allowed at one time.
	 *
	 * @var int
	 */
	const FREE_MAX_ACTIVE_SESSIONS = 5;

	/**
	 * Maximum number of seats (capacity) per individual session.
	 *
	 * @var int
	 */
	const FREE_MAX_SEATS_PER_SESSION = 5;

	/**
	 * Maximum number of sessions per day per exam/course.
	 *
	 * @var int
	 */
	const FREE_MAX_SESSIONS_PER_DAY = 1;

	// -------------------------------------------------------------------------
	// Premium detection
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the MC-EMS Premium add-on is active.
	 *
	 * The result is filterable so that the premium plugin (or any authorised
	 * third-party) can unlock all limits by hooking 'mcemexce_is_premium'.
	 *
	 * @return bool
	 */
	public static function is_premium(): bool {
		$active = defined( 'MCEMS_PREMIUM' ) || class_exists( 'MCEMS_Premium', false );

		/**
		 * Filters whether MC-EMS Premium is active.
		 *
		 * @param bool $active Whether the premium add-on is currently active.
		 */
		return (bool) apply_filters( 'mcemexce_is_premium', $active );
	}

	// -------------------------------------------------------------------------
	// Effective limits (filtered, premium-aware)
	// -------------------------------------------------------------------------

	/**
	 * Returns the maximum number of seats allowed per session.
	 * Returns PHP_INT_MAX when premium is active.
	 *
	 * @return int
	 */
	public static function get_max_seats(): int {
		if ( self::is_premium() ) {
			return PHP_INT_MAX;
		}

		/**
		 * Filters the free-plan per-session seat cap.
		 *
		 * @param int $max Default value from the FREE_MAX_SEATS_PER_SESSION constant.
		 */
		return max( 1, (int) apply_filters( 'mcemexce_free_max_seats_per_session', self::FREE_MAX_SEATS_PER_SESSION ) );
	}

	/**
	 * Returns the maximum number of active sessions allowed at one time.
	 * Returns PHP_INT_MAX when premium is active.
	 *
	 * @return int
	 */
	public static function get_max_active_sessions(): int {
		if ( self::is_premium() ) {
			return PHP_INT_MAX;
		}

		/**
		 * Filters the free-plan active-sessions cap.
		 *
		 * @param int $max Default value from the FREE_MAX_ACTIVE_SESSIONS constant.
		 */
		return max( 1, (int) apply_filters( 'mcemexce_free_max_active_sessions', self::FREE_MAX_ACTIVE_SESSIONS ) );
	}

	/**
	 * Returns the maximum number of sessions per day per exam/course.
	 * Returns PHP_INT_MAX when premium is active.
	 *
	 * @return int
	 */
	public static function get_max_sessions_per_day(): int {
		if ( self::is_premium() ) {
			return PHP_INT_MAX;
		}

		/**
		 * Filters the free-plan sessions-per-day-per-exam cap.
		 *
		 * @param int $max Default value from the FREE_MAX_SESSIONS_PER_DAY constant.
		 */
		return max( 1, (int) apply_filters( 'mcemexce_free_max_sessions_per_day', self::FREE_MAX_SESSIONS_PER_DAY ) );
	}

	// -------------------------------------------------------------------------
	// Counters
	// -------------------------------------------------------------------------

	/**
	 * Counts the number of published, future (active) sessions.
	 *
	 * A session is considered "active" when its date is today or later.
	 * Sessions already in the past are excluded so they do not consume quota.
	 *
	 * @return int
	 */
	public static function count_active_sessions(): int {
		$today = current_time( 'Y-m-d' );

		$q = new WP_Query( [
			'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => [
				[
					'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE,
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		] );

		return (int) $q->found_posts;
	}

	/**
	 * Counts published sessions for a specific exam on a specific date.
	 *
	 * @param int    $exam_id The Tutor LMS exam post ID.
	 * @param string $date    Date string in Y-m-d format.
	 * @return int
	 */
	public static function count_sessions_for_exam_on_date( int $exam_id, string $date ): int {
		$q = new WP_Query( [
			'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => MCEMEXCE_CPT_Sessioni_Esame::MK_DATE,
					'value' => $date,
				],
				[
					'key'     => MCEMEXCE_CPT_Sessioni_Esame::MK_EXAM_ID,
					'value'   => $exam_id,
					'type'    => 'NUMERIC',
					'compare' => '=',
				],
			],
		] );

		return (int) $q->found_posts;
	}

	// -------------------------------------------------------------------------
	// Upgrade URL helper
	// -------------------------------------------------------------------------

	/**
	 * Returns the MC-EMS Premium upgrade URL.
	 *
	 * Centralised here so that limit-enforcement messages can include a
	 * consistent upsell link without depending on the Upsell class.
	 *
	 * @return string
	 */
	public static function upgrade_url(): string {
		return 'https://mambacoding.com/product/exam-center-for-tutor-lms/';
	}
}
