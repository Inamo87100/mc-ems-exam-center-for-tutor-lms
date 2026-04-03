<?php
/**
 * MC-EMS uninstall cleanup
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Plugin options
delete_option( 'mcemexce_settings' );
delete_option( 'mcemexce_db_version' );
delete_option( 'mcemexce_quiz_stats_cache_created' );

// Custom tables – table name is built internally; no user input involved.
$mcemexce_quiz_stats_table = $wpdb->prefix . 'mcemexce_quiz_stats_cache';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$mcemexce_quiz_stats_table}" );

// NOTE: We intentionally do NOT delete CPT posts (mcemexce_session) automatically,
// because they may be business records. If you need a full wipe, do it manually.
