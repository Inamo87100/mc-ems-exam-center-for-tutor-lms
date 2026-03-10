<?php
if (!defined('ABSPATH')) exit;

class NFEMS_Admin_Banner {

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'render']);
    }

    protected static function is_plugin_screen() {
        if (!function_exists('get_current_screen')) return false;
        $screen = get_current_screen();
        if (!$screen) return false;

        $screen_id = isset($screen->id) ? (string) $screen->id : '';
        $post_type = isset($screen->post_type) ? (string) $screen->post_type : '';

        if ($post_type === NFEMS_CPT_Sessioni_Esame::CPT) {
            return true;
        }

        $allowed = [
            'settings_page_nfems-settings',
            NFEMS_CPT_Sessioni_Esame::CPT . '_page_nfems-settings-cpt',
            NFEMS_CPT_Sessioni_Esame::CPT . '_page_nfems-gestione-sessioni',
        ];

        return in_array($screen_id, $allowed, true);
    }

    protected static function premium_active() {
        return defined('EMS_PREMIUM_VERSION') || class_exists('EMS_Premium_Bootstrap');
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        if (!self::is_plugin_screen()) return;

        $is_premium = self::premium_active();
        $logo_url = NFEMS_PLUGIN_URL . 'assets/images/mamba-logo.png';
        $target = 'https://mambacoding.com/';

        $headline = $is_premium
            ? __('Powered by Mamba Coding', 'mc-ems')
            : __('Upgrade to MC-EMS Premium and unlock the full exam workflow', 'mc-ems');

        $text = $is_premium
            ? __('Discover more tools, updates and WordPress solutions on Mamba Coding.', 'mc-ems')
            : __('Sell smarter, automate more, and manage exam sessions like a pro. Get premium booking tools, advanced workflows, and extra features designed to help you save time and grow faster.', 'mc-ems');

        $button = $is_premium
            ? __('Visit Mamba Coding', 'mc-ems')
            : __('Buy MC-EMS Premium now', 'mc-ems');

        echo '<div class="notice" style="padding:0;border:none;background:transparent;box-shadow:none;margin:16px 0 18px 0;">';
        echo '<div style="background:linear-gradient(135deg,#31006F 0%,#4a1590 45%,#FDB927 100%);border-radius:18px;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:24px;box-shadow:0 10px 24px rgba(49,0,111,.18);">';
        echo '<div style="display:flex;align-items:center;gap:20px;min-width:0;">';
        echo '<a href="' . esc_url($target) . '" target="_blank" rel="noopener noreferrer" style="display:block;flex:0 0 auto;background:#fff;border-radius:14px;padding:10px 14px;line-height:0;">';
        echo '<img src="' . esc_url($logo_url) . '" alt="Mamba Coding" style="display:block;height:64px;max-width:100%;width:auto;">';
        echo '</a>';
        echo '<div style="min-width:0;">';
        echo '<div style="font-size:24px;font-weight:700;line-height:1.2;color:#fff;margin:0 0 6px 0;">' . esc_html($headline) . '</div>';
        echo '<div style="font-size:14px;line-height:1.5;color:rgba(255,255,255,.92);max-width:760px;">' . esc_html($text) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div style="flex:0 0 auto;">';
        echo '<a href="' . esc_url($target) . '" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:#FDB927;color:#31006F;text-decoration:none;font-size:14px;font-weight:700;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.18);">' . esc_html($button) . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
