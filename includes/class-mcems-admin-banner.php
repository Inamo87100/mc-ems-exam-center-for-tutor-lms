<?php
if (!defined('ABSPATH')) exit;

/**
 * MCEMS_Admin_Banner
 *
 * Displays a dismissible admin banner/notice on MC-EMS plugin screens.
 * Dismiss state is stored in the `mcems_dismissed_banners` option so that
 * the banner is suppressed for all admin users once dismissed.
 */
class MCEMS_Admin_Banner {

    /** WordPress option key that holds dismissed banner IDs. */
    const OPTION_KEY = 'mcems_dismissed_banners';

    /** Identifier for the current promotional/setup banner. */
    const BANNER_ID = 'mcems_promo_v1';

    /** Nonce action for AJAX dismiss. */
    const NONCE_ACTION = 'mcems_dismiss_banner';

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'render']);
        add_action('wp_ajax_mcems_dismiss_banner', [__CLASS__, 'ajax_dismiss']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected static function is_plugin_screen() {
        if (!function_exists('get_current_screen')) return false;
        $screen = get_current_screen();
        if (!$screen) return false;

        $screen_id = isset($screen->id) ? (string) $screen->id : '';
        $post_type = isset($screen->post_type) ? (string) $screen->post_type : '';

        if ($post_type === MCEMS_CPT_Sessioni_Esame::CPT) {
            return true;
        }

        $allowed = [
            MCEMS_CPT_Sessioni_Esame::CPT . '_page_mcems-settings-cpt',
            MCEMS_CPT_Sessioni_Esame::CPT . '_page_mcems-manage-sessions',
        ];

        return in_array($screen_id, $allowed, true);
    }

    protected static function premium_active() {
        return defined('EMS_PREMIUM_VERSION') || class_exists('EMS_Premium_Bootstrap');
    }

    /**
     * Check whether a given banner ID has been dismissed.
     *
     * @param string $banner_id
     * @return bool
     */
    protected static function is_dismissed(string $banner_id): bool {
        $dismissed = get_option(self::OPTION_KEY, []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }
        return in_array($banner_id, $dismissed, true);
    }

    /**
     * Mark a banner as dismissed.
     *
     * @param string $banner_id
     */
    protected static function dismiss(string $banner_id): void {
        $dismissed = get_option(self::OPTION_KEY, []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }
        if (!in_array($banner_id, $dismissed, true)) {
            $dismissed[] = $banner_id;
            update_option(self::OPTION_KEY, $dismissed, false);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------

    /**
     * Handle the AJAX dismiss request.
     */
    public static function ajax_dismiss(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $banner_id = isset($_POST['banner_id']) ? sanitize_key(wp_unslash($_POST['banner_id'])) : '';
        if (!$banner_id) {
            wp_send_json_error(['message' => 'missing_banner_id'], 400);
        }

        self::dismiss($banner_id);
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public static function render() {
        if (!current_user_can('manage_options')) return;
        if (!self::is_plugin_screen()) return;
        if (self::is_dismissed(self::BANNER_ID)) return;

        $banner_id  = self::BANNER_ID;
        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $is_premium = self::premium_active();
        $logo_url   = MCEMS_PLUGIN_URL . 'assets/images/mamba-logo.png';
        $target     = 'https://mambacoding.com/';

        $headline = $is_premium
            ? __('Powered by Mamba Coding', 'mc-ems-base')
            : __('Upgrade to MC-EMS Premium and unlock the full exam workflow', 'mc-ems-base');

        $text = $is_premium
            ? __('Discover more tools, updates and WordPress solutions on Mamba Coding.', 'mc-ems-base')
            : __('Sell smarter, automate more, and manage exam sessions like a pro. Get premium booking tools, advanced workflows, and extra features designed to help you save time and grow faster.', 'mc-ems-base');

        $button = $is_premium
            ? __('Visit Mamba Coding', 'mc-ems-base')
            : __('Buy MC-EMS Premium now', 'mc-ems-base');

        ?>
        <style>
            .mcems-banner-wrap { padding: 0; border: none; background: transparent; box-shadow: none; margin: 16px 0 18px 0; }
            .mcems-banner-inner { background: linear-gradient(135deg, #31006F 0%, #4a1590 45%, #FDB927 100%); border-radius: 18px; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; gap: 24px; box-shadow: 0 10px 24px rgba(49, 0, 111, .18); position: relative; }
            .mcems-banner-logo-wrap { display: flex; align-items: center; gap: 20px; min-width: 0; }
            .mcems-banner-logo-link { display: block; flex: 0 0 auto; background: #fff; border-radius: 14px; padding: 10px 14px; line-height: 0; }
            .mcems-banner-logo-link img { display: block; height: 64px; max-width: 100%; width: auto; }
            .mcems-banner-text-wrap { min-width: 0; }
            .mcems-banner-headline { font-size: 24px; font-weight: 700; line-height: 1.2; color: #fff; margin: 0 0 6px 0; }
            .mcems-banner-desc { font-size: 14px; line-height: 1.5; color: rgba(255,255,255,.92); max-width: 760px; }
            .mcems-banner-btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 18px; border-radius: 12px; background: #FDB927; color: #31006F; text-decoration: none; font-size: 14px; font-weight: 700; white-space: nowrap; box-shadow: 0 4px 12px rgba(0,0,0,.18); }
            .mcems-banner-dismiss { position: absolute; top: 10px; right: 14px; background: transparent; border: none; color: rgba(255,255,255,.7); font-size: 20px; line-height: 1; cursor: pointer; padding: 0; }
            .mcems-banner-dismiss:hover { color: #fff; }
        </style>

        <div class="notice mcems-banner-wrap" id="mcems-banner-<?php echo esc_attr($banner_id); ?>">
            <div class="mcems-banner-inner">
                <div class="mcems-banner-logo-wrap">
                    <a href="<?php echo esc_url($target); ?>" target="_blank" rel="noopener noreferrer" class="mcems-banner-logo-link">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Mamba Coding">
                    </a>
                    <div class="mcems-banner-text-wrap">
                        <div class="mcems-banner-headline"><?php echo esc_html($headline); ?></div>
                        <div class="mcems-banner-desc"><?php echo esc_html($text); ?></div>
                    </div>
                </div>
                <div>
                    <a href="<?php echo esc_url($target); ?>" target="_blank" rel="noopener noreferrer" class="mcems-banner-btn"><?php echo esc_html($button); ?></a>
                </div>
                <button type="button" class="mcems-banner-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'mc-ems-base'); ?>"
                    data-banner="<?php echo esc_attr($banner_id); ?>"
                    data-nonce="<?php echo esc_attr($nonce); ?>">&#x2715;</button>
            </div>
        </div>

        <script>
        (function () {
            var btn = document.querySelector('.mcems-banner-dismiss[data-banner="' + <?php echo wp_json_encode($banner_id); ?> + '"]');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var wrap = document.getElementById('mcems-banner-' + <?php echo wp_json_encode($banner_id); ?>);
                if (wrap) wrap.style.display = 'none';
                var data = new FormData();
                data.append('action', 'mcems_dismiss_banner');
                data.append('banner_id', btn.getAttribute('data-banner'));
                data.append('nonce', btn.getAttribute('data-nonce'));
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: data, credentials: 'same-origin' });
            });
        }());
        </script>
        <?php
    }
}
