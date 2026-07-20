<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/traits/class-settings-registration.php';
require_once __DIR__ . '/traits/class-settings-views.php';
require_once __DIR__ . '/traits/class-settings-ajax.php';

class WPOmniAuth_Settings_Page {

    use WPOmniAuth_Settings_Registration, WPOmniAuth_Settings_Views, WPOmniAuth_Settings_Ajax;

    /**
     * Transient key holding toasts queued during a request (e.g. after a
     * settings save + redirect) so they can be rendered on the next page load.
     */
    const TOAST_TRANSIENT = 'wpomni_admin_toasts';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_default_sections'], 5);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'register_provider_save']);
        add_action('admin_init', [$this, 'maybe_save_blacklist']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_ajax_wpomni_add_custom_provider', [$this, 'ajax_add_custom_provider']);
        add_action('wp_ajax_wpomni_remove_custom_provider', [$this, 'ajax_remove_custom_provider']);
        add_action('wp_ajax_wpomni_view_log', [$this, 'ajax_view_log']);
        add_action('wp_ajax_wpomni_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_wpomni_download_log', [$this, 'ajax_download_log']);
        add_action('wp_ajax_wpomni_regenerate_emergency_key', [$this, 'ajax_regenerate_emergency_key']);
        add_action('wp_ajax_wpomni_view_emergency_key', [$this, 'ajax_view_emergency_key']);
        add_action('wp_ajax_wpomni_check_update', [$this, 'ajax_check_update']);
        add_action('wp_ajax_wpomni_clean_data', [$this, 'ajax_clean_data']);
        add_action('wp_ajax_wpomni_reset_all_data', [$this, 'ajax_reset_all_data']);

        // User profile: OAuth binding section
        add_action('show_user_profile', [$this, 'render_user_profile_oauth_section']);
        add_action('edit_user_profile', [$this, 'render_user_profile_oauth_section']);
        add_action('admin_post_wpomni_unbind_user', [$this, 'handle_unbind_user']);
        add_action('admin_notices', [$this, 'maybe_show_unbind_notice']);
    }

    public function add_admin_menu() {
        add_options_page(
            __('OAuth Login', 'wp-omni-auth'),
            __('OAuth Login', 'wp-omni-auth'),
            'manage_options',
            'wp-omni-auth',
            [$this, 'render_settings_page']
        );
    }

    public function admin_scripts($hook) {
        if ($hook !== 'settings_page_wp-omni-auth') {
            return;
        }

        // Ensure the WP admin color scheme is loaded. It defines the
        // --wp-admin-theme-color family on :root. NOTE: the Block Editor's
        // wp-includes/css/dist/block-library/common.css ALSO declares
        // `--wp-admin-theme-color: #007cba` on :root and loads AFTER the
        // scheme, so it clobbers the real value. We therefore re-apply the
        // CURRENT USER's actual admin scheme color on our own .wpomni-admin
        // scope (element-level vars beat the :root override) so the UI truly
        // follows the WP color setting instead of the legacy #007cba.
        if (function_exists('wp_admin_css')) {
            wp_admin_css('colors');
        }

        wp_enqueue_style(
            'wpomni-admin',
            WPOMNIAUTH_PLUGIN_URL . 'assets/css/admin-settings.css',
            [],
            filemtime(WPOMNIAUTH_PLUGIN_DIR . 'assets/css/admin-settings.css')
        );

        // Apply the CURRENT USER's real WordPress color scheme to our UI.
        //
        // IMPORTANT (root cause of the old #007cba bug): our accent tokens such
        // as `--wpomni-accent` are DECLARED on :root in admin-settings.css as
        // `var(--wp-admin-theme-color, #2271b1)`. Per the CSS custom-property
        // spec, a custom property is substituted at the element where it is
        // DECLARED — i.e. :root. There, Block Editor's
        // wp-includes/css/dist/block-library/common.css has already forced
        // `--wp-admin-theme-color: #007cba` onto :root, so `--wpomni-accent`
        // gets baked to #007cba at :root and inherited everywhere. Redefining
        // `--wp-admin-theme-color` on a descendant (.wpomni-admin) does NOT fix
        // it, because the token was already resolved on :root.
        //
        // Fix: compute the real scheme color in PHP and inject the *resolved*
        // literal values for the whole accent token set, on BOTH :root and
        // .wpomni-admin. This leaves no var() to be mis-resolved, so the active
        // tab / sidebar / modals all follow the user's WP color setting.
        $scheme = $this->get_admin_theme_color();
        $theme_css = '';
        if ($scheme && !empty($scheme['color'])) {
            $color        = $scheme['color'];
            $rgb          = $scheme['rgb'];
            $darker       = $scheme['darker'];
            $tint         = 'rgba(' . $rgb . ', 0.08)';
            $tint_border  = 'rgba(' . $rgb . ', 0.15)';
            // !important on every declaration so these WIN over the Block
            // Editor's `:root { --wp-admin-theme-color: #007cba }` regardless
            // of stylesheet load order, and over the :root fallback in
            // admin-settings.css. This is what makes the active tab / sidebar /
            // modals / native WP hover+focus states all follow the user's real
            // color scheme even on the `fresh` scheme (which WP core does NOT
            // define a --wp-admin-theme-color for, so it would otherwise fall
            // back to the legacy #007cba).
            $css = sprintf(
                ':root,.wpomni-admin{'
                . '--wp-admin-theme-color:%1$s!important;'
                . '--wp-admin-theme-color--rgb:%2$s!important;'
                . '--wp-admin-theme-color-darker-10:%3$s!important;'
                . '--wpomni-accent:%1$s!important;'
                . '--wpomni-accent-dark:%3$s!important;'
                . '--wpomni-accent-rgb:%2$s!important;'
                . '--wpomni-accent-tint:%4$s!important;'
                . '--wpomni-accent-tint-border:%5$s!important;}',
                $color,
                $rgb,
                $darker,
                $tint,
                $tint_border
            );
            $theme_css = $css;
        }
        wp_enqueue_script(
            'wpomni-admin',
            WPOMNIAUTH_PLUGIN_URL . 'assets/js/admin-settings.js',
            ['jquery'],
            filemtime(WPOMNIAUTH_PLUGIN_DIR . 'assets/js/admin-settings.js'),
            true
        );
        wp_localize_script('wpomni-admin', 'wpomni_admin', [
            'nonce' => wp_create_nonce('wpomni_nonce'),
            'i18n'  => [
                'enter_provider_name'  => __('Enter provider name:', 'wp-omni-auth'),
                'custom_provider'      => __('Custom Provider', 'wp-omni-auth'),
                'failed_add'           => __('Failed to add provider: ', 'wp-omni-auth'),
                'unknown_error'        => __('Unknown error', 'wp-omni-auth'),
                'network_error_retry'  => __('Network error. Please try again.', 'wp-omni-auth'),
                'confirm_remove'       => __('Are you sure you want to remove this provider?', 'wp-omni-auth'),
                'failed_remove'        => __('Failed to remove provider: ', 'wp-omni-auth'),
                'loading'              => __('Loading...', 'wp-omni-auth'),
                'log_empty_paren'      => __('(log is empty)', 'wp-omni-auth'),
                'log_empty'            => __('Log is empty', 'wp-omni-auth'),
                'log_loaded'           => __('Log loaded (%s lines)', 'wp-omni-auth'),
                'failed'               => __('Failed: ', 'wp-omni-auth'),
                'network_error'        => __('Network error', 'wp-omni-auth'),
                'confirm_clear_log'    => __('Are you sure you want to clear the debug log?', 'wp-omni-auth'),
                'clearing'             => __('Clearing...', 'wp-omni-auth'),
                'log_cleared'          => __('Log cleared', 'wp-omni-auth'),
                // Modal strings
                'add_custom_provider'  => __('Add Custom Provider', 'wp-omni-auth'),
                'provider_name_label'  => __('Provider Name', 'wp-omni-auth'),
                'provider_name_placeholder' => __('e.g. My GitLab', 'wp-omni-auth'),
                'btn_cancel'           => __('Cancel', 'wp-omni-auth'),
                'btn_add'              => __('Add', 'wp-omni-auth'),
                'btn_remove'           => __('Remove', 'wp-omni-auth'),
                'remove_provider_title' => __('Remove Provider', 'wp-omni-auth'),
                'remove_provider_confirm' => __('Are you sure you want to remove this custom provider? All settings will be lost.', 'wp-omni-auth'),
                // Emergency key
                'generating'             => __('Generating...', 'wp-omni-auth'),
                'key_regenerated'        => __('Key regenerated', 'wp-omni-auth'),
                'click_to_copy'          => __('Click to copy', 'wp-omni-auth'),
                'copied'                 => __('Copied!', 'wp-omni-auth'),
                'key_management'         => __('Key Management', 'wp-omni-auth'),
                'emergency_key_mgmt'     => __('Emergency Key Management', 'wp-omni-auth'),
                'view_full_key'          => __('View Full Key', 'wp-omni-auth'),
                'btn_close'              => __('Close', 'wp-omni-auth'),
                // Update check
                'checking'               => __('Checking...', 'wp-omni-auth'),
                'up_to_date'             => __('You are running the latest version.', 'wp-omni-auth'),
                'new_version_available'  => __('New version %s available.', 'wp-omni-auth'),
                'go_to_plugins'          => __('Go to Plugins page', 'wp-omni-auth'),
                'check_failed'           => __('Update check failed.', 'wp-omni-auth'),
                'latest'                 => __('latest', 'wp-omni-auth'),
                // Update source (mirror) dropdown
                'saving'                 => __('Saving...', 'wp-omni-auth'),
                'saved'                  => __('Saved', 'wp-omni-auth'),
                'save_failed'            => __('Save failed', 'wp-omni-auth'),
                // Data management
                'select_at_least_one'    => __('Please select at least one category.', 'wp-omni-auth'),
                'resetting'              => __('Resetting...', 'wp-omni-auth'),
                'select_file'            => __('Select a file first', 'wp-omni-auth'),
                'importing'              => __('Importing...', 'wp-omni-auth'),
            ],
        ]);

        // Surface any toasts queued during a previous request (e.g. after a
        // settings save + redirect) so they are rendered client-side.
        $toasts = get_transient(self::TOAST_TRANSIENT);
        if (!is_array($toasts)) {
            $toasts = [];
        }
        delete_transient(self::TOAST_TRANSIENT);

        // Proactively surface persistent health-check warnings as toast popups
        // on the settings page (instead of an inline admin notice banner).
        foreach ($this->get_health_check_toasts() as $toast) {
            $toasts[] = $toast;
        }

        wp_localize_script('wpomni-admin', 'wpomni_toasts', $toasts);

        // Scheme-dependent CSS variables must stay inline: they depend on the
        // user's admin color scheme and cannot be baked into the static file.
        if ($theme_css !== '') {
            echo "\n<style id=\"wpomni-theme-vars\">\n" . $theme_css . "\n</style>\n";
        }

        // The full stylesheet is enqueued externally above. Only inline the
        // entire file when WPOMNIAUTH_INLINE_ASSETS is defined true — useful for
        // environments that block access to /wp-content/plugins/* static files.
        // This avoids shipping the ~770-line CSS twice on every normal
        // settings-page load (the external copy already covers it).
        if (defined('WPOMNIAUTH_INLINE_ASSETS') && WPOMNIAUTH_INLINE_ASSETS) {
            $css_file = WPOMNIAUTH_PLUGIN_DIR . 'assets/css/admin-settings.css';
            if (is_readable($css_file)) {
                echo "\n<style id=\"wpomni-admin-inline\">\n" . file_get_contents($css_file) . "\n</style>\n";
            }
        }
        // Queue the inline JS fallback (guarded: only runs if the external
        // file failed to define WP_Omni_Toasts). Registered here so it only
        // fires on this settings page.
        add_action('admin_footer', [$this, 'admin_footer_scripts'], 20);
    }

    /**
     * Build persistent health-check warning toasts (OAuth-only mode
     * misconfigurations). These are surfaced as popup notifications on the
     * settings page rather than inline admin notices.
     *
     * @return array<int,array{message:string,type:string,title:string}>
     */
    private function get_health_check_toasts() {
        if (!current_user_can('manage_options')) {
            return [];
        }

        // Only relevant in OAuth-only mode.
        if (get_option('wpomni_hide_password', 'no') !== 'yes') {
            return [];
        }

        $toasts = [];

        $manager = WPOmniAuth_Manager::instance();
        $has_enabled = false;
        foreach ($manager->get_all_providers() as $provider) {
            if ($provider->is_enabled()) {
                $has_enabled = true;
                break;
            }
        }

        if (!$has_enabled) {
            $toasts[] = [
                'message' => __('OAuth-only mode is enabled, but no OAuth providers are configured. Users cannot log in.', 'wp-omni-auth'),
                'type'    => 'error',
                'title'   => __('WP-OmniAuth', 'wp-omni-auth'),
            ];
        }

        if (empty(get_option('wpomni_emergency_key', ''))) {
            $toasts[] = [
                'message' => __('No emergency access key is set. If all OAuth providers go down, you will be locked out. Generate a key in the settings page.', 'wp-omni-auth'),
                'type'    => 'warning',
                'title'   => __('WP-OmniAuth', 'wp-omni-auth'),
            ];
        }

        return $toasts;
    }

    /**
     * Output the plugin JS inline as a fallback when the enqueued
     * /wp-content/plugins/.../admin-settings.js is blocked (404). The inline
     * copy is guarded so it does not double-bind when the external file loads.
     */
    public function admin_footer_scripts() {
        // Inject toast CSS directly in footer — ensures styles load even when
        // the external CSS file is cached or blocked by CDN/proxy.
        echo "\n<style id=\"wpomni-toast-footer\">\n"
            . ".wpomni-toasts{position:fixed!important;top:48px!important;right:20px!important;left:auto!important;bottom:auto!important;z-index:999999!important;display:flex;flex-direction:column;gap:12px;max-width:380px;width:calc(100vw - 40px);pointer-events:none;}"
            . ".wpomni-toast{pointer-events:auto;position:relative;display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #dcdcde;border-left:4px solid var(--wpomni-accent,#2271b1);border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.12);padding:12px 34px 14px 14px;font-size:13px;line-height:1.5;color:#1d2327;overflow:hidden;}"
            . ".wpomni-toast.is-leaving{opacity:0;transition:opacity .2s ease;}"
            . ".wpomni-toast--warning{border-left-color:#dba617;}"
            . ".wpomni-toast--success{border-left-color:#1a9c5b;}"
            . ".wpomni-toast--error{border-left-color:#d63638;}"
            . ".wpomni-toast--info{border-left-color:var(--wpomni-accent,#2271b1);}"
            . ".wpomni-toast__icon{flex:0 0 auto;width:20px;height:20px;margin-top:1px;}"
            . ".wpomni-toast__icon svg{width:20px;height:20px;display:block;}"
            . ".wpomni-toast__body{flex:1 1 auto;min-width:0;}"
            . ".wpomni-toast__title{font-weight:600;margin:0 0 2px;}"
            . ".wpomni-toast__msg{margin:0;word-break:break-word;}"
            . ".wpomni-toast__close{position:absolute;top:6px;right:6px;border:none;background:transparent;cursor:pointer;color:#787c82;font-size:18px;line-height:1;padding:2px 6px;}"
            . ".wpomni-toast__close:hover{color:#1d2327;}"
            . "\n</style>\n";

        $js_file = WPOMNIAUTH_PLUGIN_DIR . 'assets/js/admin-settings.js';
        if (!is_readable($js_file)) {
            return;
        }
        // Defer the guard check until after the enqueued external script has
        // executed. The inline fallback is emitted during `admin_footer`, which
        // runs *before* `admin_print_footer_scripts` (where the external
        // `wpomni-admin` script is printed, since it is enqueued in_footer).
        // Checking `WP_Omni_Toasts` at inline-parse time therefore always sees
        // it as undefined and would run the file a second time, double-binding
        // every handler (e.g. the provider save form -> two AJAX posts -> two
        // "saved" toasts). By waiting for DOMContentLoaded the synchronous
        // external script has already executed, so the guard correctly skips
        // when the external loaded and only falls back when it truly 404'd.
        echo "\n<script id=\"wpomni-admin-inline-js\">\nwindow.addEventListener('DOMContentLoaded', function () {\n  if (typeof window.WP_Omni_Toasts === 'undefined') {\n"
            . file_get_contents($js_file)
            . "\n  }\n});\n</script>\n";
    }

    /**
     * Queue a toast notification.
     *
     * Toasts are stored in a short-lived transient so they survive the
     * save -> redirect flow and are rendered client-side on the next load.
     * Each toast is `{message, type, title}`. `$type` is one of
     * `warning`, `success`, `error`, `info`.
     *
     * @param string $message Translated message body.
     * @param string $type    Toast type (warning|success|error|info).
     * @param string $title   Optional bold title (e.g. provider name).
     */
    public function add_toast($message, $type = 'info', $title = '') {
        $toasts = get_transient(self::TOAST_TRANSIENT);
        if (!is_array($toasts)) {
            $toasts = [];
        }
        $toasts[] = [
            'message' => $message,
            'type'    => $type,
            'title'   => $title,
        ];
        set_transient(self::TOAST_TRANSIENT, $toasts, 60);
    }

    /**
     * Return the current user's real WordPress admin scheme colors.
     *
     * WordPress defines the per-scheme colors in
     * wp-includes/css/dist/base-styles/admin-schemes.css as
     * `body.admin-color-{slug} { --wp-admin-theme-color: #xxxxxx; ... }`.
     * We read that authoritative source so our UI matches the user's chosen
     * "Color Scheme" profile setting instead of the legacy #007cba that the
     * Block Editor's common.css forces onto :root.
     *
     * @return array{color:string,rgb:string,darker:string}|null
     */
    private function get_admin_theme_color() {
        $scheme = get_user_option('admin_color');
        if (empty($scheme)) {
            $scheme = 'fresh';
        }

        $map = $this->get_admin_scheme_colors();
        if (isset($map[$scheme])) {
            return $map[$scheme];
        }

        // Unknown scheme: fall back to the classic default blue.
        list($r, $g, $b) = $this->hex_to_rgb('#2271b1');
        return [
            'color'  => '#2271b1',
            'rgb'    => $r . ', ' . $g . ', ' . $b,
            'darker' => $this->darken_hex('#2271b1', 0.10),
        ];
    }

    /**
     * Parse wp-includes/css/dist/base-styles/admin-schemes.css once per
     * request and return a map of scheme slug => color data.
     *
     * @return array<string,array{color:string,rgb:string,darker:string}>
     */
    private function get_admin_scheme_colors() {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }

        // Known scheme base colors. 'fresh' is not listed in admin-schemes.css
        // (the classic default), so it is seeded here; rgb / darker-10 are
        // derived and overwritten below when admin-schemes.css is readable.
        $base = [
            'fresh'      => '#2271b1',
            'modern'     => '#3858e9',
            'light'      => '#0085ba',
            'blue'       => '#096484',
            'coffee'     => '#46403c',
            'ectoplasm'  => '#523f6d',
            'midnight'   => '#e14d43',
            'ocean'      => '#627c83',
            'sunrise'    => '#dd823b',
        ];

        $cache = [];
        foreach ($base as $slug => $color) {
            list($r, $g, $b) = $this->hex_to_rgb($color);
            $cache[$slug] = [
                'color'  => $color,
                'rgb'    => $r . ', ' . $g . ', ' . $b,
                'darker' => $this->darken_hex($color, 0.10),
            ];
        }

        $file = ABSPATH . WPINC . '/css/dist/base-styles/admin-schemes.css';
        if (is_readable($file)) {
            $css = file_get_contents($file);
            if (false !== $css && preg_match_all(
                '/body\.admin-color-([a-z0-9]+)\s*\{([^}]*)\}/',
                $css,
                $blocks,
                PREG_SET_ORDER
            )) {
                foreach ($blocks as $blk) {
                    $slug = strtolower($blk[1]);
                    $body = $blk[2];
                    $color  = $rgb = $darker = '';
                    if (preg_match('/--wp-admin-theme-color:\s*(#[0-9a-fA-F]{3,8})/', $body, $m)) {
                        $color = strtolower($m[1]);
                    }
                    if (preg_match('/--wp-admin-theme-color--rgb:\s*([0-9,\s]+);/', $body, $m)) {
                        $rgb = trim($m[1]);
                    }
                    if (preg_match('/--wp-admin-theme-color-darker-10:\s*([^;]+);/', $body, $m)) {
                        $darker = trim($m[1]);
                    }
                    if ($color && $rgb && $darker) {
                        $cache[$slug] = [
                            'color'  => $color,
                            'rgb'    => $rgb,
                            'darker' => $darker,
                        ];
                    }
                }
            }
        }

        return $cache;
    }

    /**
     * Convert a hex color (#rgb / #rrggbb) to an [r, g, b] integer array.
     *
     * @param string $hex
     * @return array{0:int,1:int,2:int}
     */
    private function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $int = (int) hexdec(substr($hex, 0, 6));
        return [
            ($int >> 16) & 0xff,
            ($int >> 8) & 0xff,
            $int & 0xff,
        ];
    }

    /**
     * Darken a hex color by a fraction (0.10 = 10% darker).
     *
     * @param string $hex
     * @param float  $amount
     * @return string Hex color.
     */
    private function darken_hex($hex, $amount) {
        list($r, $g, $b) = $this->hex_to_rgb($hex);
        $factor = 1 - (float) $amount;
        $r = (int) round($r * $factor);
        $g = (int) round($g * $factor);
        $b = (int) round($b * $factor);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }


}

new WPOmniAuth_Settings_Page();
