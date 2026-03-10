<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Upgrader {

    public static function maybe_upgrade(): void {
        $installed = get_option('nfems_db_version', '0.0.0');
        if (version_compare($installed, NFEMS_DB_VERSION, '>=')) return;

        // Ensure defaults
        if (!get_option(NFEMS_Settings::OPTION_KEY)) {
            add_option(NFEMS_Settings::OPTION_KEY, NFEMS_Settings::defaults());
        }

        // Migrate legacy meta (from earlier NF-EMS builds) -> canonical slot_* meta keys
        self::migrate_legacy_meta();

        update_option('nfems_db_version', NFEMS_DB_VERSION);
    }

    private static function migrate_legacy_meta(): void {
        $ids = get_posts([
            'post_type'      => NFEMS_CPT_Sessioni_Esame::CPT,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($ids as $sid) {
            $sid = (int) $sid;

            $date = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, true);
            $time = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, true);
            $cap  = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, true);

            // date/time/capacity
            if ($date === '') {
                $ld = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_DATE, true);
                if ($ld) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_DATE, $ld);
            }
            if ($time === '') {
                $lt = (string) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_TIME, true);
                if ($lt) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_TIME, $lt);
            }
            if ($cap <= 0) {
                $lc = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_CAPACITY, true);
                if ($lc > 0) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_CAPACITY, $lc);
            }

            // proctor
            $p = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, true);
            if (!$p) {
                $lp = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_PROCTOR_USER_ID, true);
                if ($lp) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_PROCTOR_USER_ID, $lp);
            }

            // special flags
            $is = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, true);
            if (!$is) {
                $lis = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_IS_SPECIAL, true);
                if ($lis) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_IS_SPECIAL, $lis);
            }

            $su = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, true);
            if (!$su) {
                $lsu = (int) get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_SPECIAL_USER_ID, true);
                if ($lsu) update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_SPECIAL_USER_ID, $lsu);
            }

            // bookings -> occupati
            $occ = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, true);
            if (!is_array($occ) || !$occ) {
                $legacy = get_post_meta($sid, NFEMS_CPT_Sessioni_Esame::L_MK_BOOKINGS, true);
                if (is_array($legacy) && $legacy) {
                    $uids = array_values(array_unique(array_map('intval', array_keys($legacy))));
                    update_post_meta($sid, NFEMS_CPT_Sessioni_Esame::MK_OCCUPATI, $uids);
                }
            }
        }
    }
}
