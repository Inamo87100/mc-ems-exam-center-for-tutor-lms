<?php
if (!defined('ABSPATH')) exit;

class MCEMEXCE_Upgrader {

    public static function maybe_upgrade(): void {
        // Migrate data from old mcems_ prefix to mcemexce_ prefix (prefix refactor v1.1.0+).
        // This runs unconditionally and is idempotent (checks before acting).
        self::migrate_from_mcems_prefix();

        $installed = get_option('mcemexce_db_version', '0.0.0');
        if (version_compare($installed, MCEMEXCE_DB_VERSION, '>=')) return;

        // Ensure defaults
        if (!get_option(MCEMEXCE_Settings::OPTION_KEY)) {
            add_option(MCEMEXCE_Settings::OPTION_KEY, MCEMEXCE_Settings::defaults());
        }

        // Create / update custom tables.
        self::create_quiz_stats_table();
        self::create_quiz_stats_cache_table();

        // Rename CPT slug from Italian 'slot_esame' to English 'mcemexce_exam_session'
        // Must run before migrate_legacy_meta() so that meta migration finds the renamed posts.
        if (version_compare($installed, '1.5.0', '<')) {
            self::migrate_cpt_slug();
        }

        // Migrate legacy meta (from earlier NF-EMS builds) -> canonical slot_* meta keys
        self::migrate_legacy_meta();

        update_option('mcemexce_db_version', MCEMEXCE_DB_VERSION);
    }

    /**
     * One-time migration: rename all identifiers from the old 'mcems_' prefix to
     * the new canonical 'mcemexce_' prefix. Safe to call on every request – each
     * step is guarded by an existence check so it is a no-op after the first run.
     */
    private static function migrate_from_mcems_prefix(): void {
        global $wpdb;

        // 1. DB-version option key.
        $old_db_ver = get_option('mcems_db_version');
        if (false !== $old_db_ver && false === get_option('mcemexce_db_version')) {
            update_option('mcemexce_db_version', $old_db_ver);
            delete_option('mcems_db_version');
        }

        // 2. Plugin settings option key.
        $old_settings = get_option('mcems_settings');
        if (false !== $old_settings && false === get_option('mcemexce_settings')) {
            update_option('mcemexce_settings', $old_settings);
            delete_option('mcems_settings');
        }

        // 3. Quiz-stats cache flag option.
        $old_cache_flag = get_option('mcems_quiz_stats_cache_created');
        if (false !== $old_cache_flag && false === get_option('mcemexce_quiz_stats_cache_created')) {
            update_option('mcemexce_quiz_stats_cache_created', $old_cache_flag);
            delete_option('mcems_quiz_stats_cache_created');
        }

        // 4. CPT slug: mcems_exam_session → mcemexce_session (canonical slug).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->posts,
            ['post_type' => MCEMEXCE_CPT_Sessioni_Esame::CPT],
            ['post_type' => 'mcems_exam_session'],
            ['%s'],
            ['%s']
        );

        // 4b. Also migrate any posts that ended up with the invalid 21-char slug
        //     'mcemexce_exam_session' (used in an intermediate refactor before the
        //     slug length was corrected to fit WordPress's 20-character limit).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->posts,
            ['post_type' => MCEMEXCE_CPT_Sessioni_Esame::CPT],
            ['post_type' => 'mcemexce_exam_session'],
            ['%s'],
            ['%s']
        );

        // 5. Rename DB tables if old names still exist.
        foreach ([
            'mcems_quiz_stats'       => 'mcemexce_quiz_stats',
            'mcems_quiz_stats_cache' => 'mcemexce_quiz_stats_cache',
        ] as $old_suffix => $new_suffix) {
            $old_table = $wpdb->prefix . $old_suffix;
            $new_table = $wpdb->prefix . $new_suffix;
            // Table names are derived from $wpdb->prefix + hardcoded suffixes (no user input).
            // Guard defensively: skip if the name contains any non-identifier character.
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $old_table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $new_table ) ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) ) {
                // MySQL does not support parameterised identifiers; table names are validated above.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( 'RENAME TABLE `' . $old_table . '` TO `' . $new_table . '`' );
            }
        }

        // 6. User meta keys: active bookings and booking history.
        foreach ([
            'mcems_active_bookings'            => 'mcemexce_active_bookings',
            'mcems_active_booking'             => 'mcemexce_active_booking',
            'mcems_storico_prenotazioni_slot'   => 'mcemexce_storico_prenotazioni_slot',
        ] as $old_key => $new_key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->usermeta,
                ['meta_key' => $new_key],  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                ['meta_key' => $old_key],  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                ['%s'],
                ['%s']
            );
        }
    }

    private static function migrate_cpt_slug(): void {
        global $wpdb;

        // Rename post_type from legacy Italian slug to English slug in a single query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $wpdb->posts,
            ['post_type' => MCEMEXCE_CPT_Sessioni_Esame::CPT],
            ['post_type' => 'slot_esame'],
            ['%s'],
            ['%s']
        );

        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MC-EMS: CPT slug migration failed – ' . $wpdb->last_error); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Create (or silently update) the mcemexce_quiz_stats table using dbDelta.
     * Safe to call on every upgrade; dbDelta is idempotent.
     */
    private static function create_quiz_stats_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'mcemexce_quiz_stats';
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE {$table} (
            quiz_id          bigint(20) unsigned NOT NULL,
            quiz_title       varchar(255)        NOT NULL DEFAULT '',
            total_attempts   int(11)             NOT NULL DEFAULT 0,
            unique_students  int(11)             NOT NULL DEFAULT 0,
            avg_score        decimal(5,2)        NOT NULL DEFAULT '0.00',
            pass_count       int(11)             NOT NULL DEFAULT 0,
            fail_count       int(11)             NOT NULL DEFAULT 0,
            pass_rate        decimal(5,2)        NOT NULL DEFAULT '0.00',
            highest_score    decimal(5,2)        NOT NULL DEFAULT '0.00',
            lowest_score     decimal(5,2)        NOT NULL DEFAULT '0.00',
            last_updated     datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (quiz_id)
        ) {$collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create (or silently update) the mcemexce_quiz_stats_cache table using dbDelta.
     * Stores per-question error statistics aggregated by course.
     * Safe to call on every upgrade; dbDelta is idempotent.
     */
    private static function create_quiz_stats_cache_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'mcemexce_quiz_stats_cache';
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE {$table} (
            id                mediumint(9)    NOT NULL AUTO_INCREMENT,
            question_id       int(11)         NOT NULL,
            course_id         int(11)         NOT NULL,
            course_title      varchar(255)    NOT NULL DEFAULT '',
            question_title    text            NOT NULL,
            quiz_title        varchar(255)    NOT NULL DEFAULT '',
            topic_title       varchar(255)    NOT NULL DEFAULT '',
            total_answers     int(11)         NOT NULL DEFAULT 0,
            wrong_answers     int(11)         NOT NULL DEFAULT 0,
            error_percentage  decimal(5,2)    NOT NULL DEFAULT 0.00,
            last_updated      datetime        NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY question_course (question_id, course_id),
            KEY course_id (course_id),
            KEY error_percentage (error_percentage)
        ) {$collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function migrate_legacy_meta(): void {
        $ids = get_posts([
            'post_type'      => MCEMEXCE_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($ids as $sid) {
            $sid = (int) $sid;

            $date = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, true);
            $time = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_TIME, true);
            $cap  = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_CAPACITY, true);

            // date/time/capacity
            if ($date === '') {
                $ld = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_DATE, true);
                if ($ld) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_DATE, $ld);
            }
            if ($time === '') {
                $lt = (string) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_TIME, true);
                if ($lt) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_TIME, $lt);
            }
            if ($cap <= 0) {
                $lc = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_CAPACITY, true);
                if ($lc > 0) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_CAPACITY, $lc);
            }

            // proctor
            $p = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            if (!$p) {
                $lp = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_PROCTOR_USER_ID, true);
                if ($lp) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, $lp);
            }

            // special flags
            $is = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_IS_SPECIAL, true);
            if (!$is) {
                $lis = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_IS_SPECIAL, true);
                if ($lis) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_IS_SPECIAL, $lis);
            }

            $su = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
            if (!$su) {
                $lsu = (int) get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_SPECIAL_USER_ID, true);
                if ($lsu) update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $lsu);
            }

            // bookings -> occupati
            $occ = get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            if (!is_array($occ) || !$occ) {
                $legacy = get_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::L_MK_BOOKINGS, true);
                if (is_array($legacy) && $legacy) {
                    $uids = array_values(array_unique(array_map('intval', array_keys($legacy))));
                    update_post_meta($sid, MCEMEXCE_CPT_Sessioni_Esame::MK_OCCUPATI, $uids);
                }
            }
        }
    }
}
