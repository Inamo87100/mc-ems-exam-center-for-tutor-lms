<?php
/**
 * MC-EMS uninstall cleanup
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Plugin options
delete_option('nfems_settings');
delete_option('nfems_db_version');

// NOTE: We intentionally do NOT delete CPT posts (slot_esame) automatically,
// because they may be business records. If you need a full wipe, do it manually.
