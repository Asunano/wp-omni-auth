<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Login-guard: global authentication / emergency-access hooks.
 *
 * The logic here was previously written as inline closures in the plugin entry
 * file (wp-omni-auth.php). Extracting it into a class makes the security-critical
 * behavior independently testable while the entry file only registers the hooks.
 *
 * Public method signatures intentionally match the original closure signatures
 * so the hook registrations are a mechanical swap (no behavior change).
 */
class WPOmniAuth_Login_Guard {

    /**
     * Whether emergency mode is currently active (password login temporarily allowed).
     *
     * @return bool
     */
    public static function is_emergency_active() {
        if (!get_transient('wpomni_emergency_active')) {
            return false;
        }
        $emergency_ip = get_transient('wpomni_emergency_ip');
        // If no IP transient exists (legacy or migration), allow all.
        if (empty($emergency_ip)) {
            return true;
        }
        $current_ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        return $emergency_ip === $current_ip;
    }

    /**
     * Base URL of our login stylesheet (no version query string).
     *
     * @return string|null URL or null when WPOMNIAUTH_PLUGIN_URL is undefined.
     */
    public static function login_styles_base_url() {
        if (!defined('WPOMNIAUTH_PLUGIN_URL')) {
            return null;
        }
        return WPOMNIAUTH_PLUGIN_URL . 'assets/css/login-styles.css';
    }

    /**
     * Cache-busting version string for our login stylesheet.
     *
     * The version is the plugin version PLUS the file mtime so that any CSS
     * change between builds produces a NEW query string. Without this, a CDN
     * (e.g. Cloudflare) serving wp-login.php would cache the old
     * `login-styles.css?ver=0.1.0` forever and override corrected styles on the
     * OAuth / emergency screens.
     *
     * Both the inline <link> (render_login_head) and the WP-enqueued handle
     * (WPOmniAuth_Manager::enqueue_login_styles) MUST use this exact version so
     * the browser/CDN only ever sees one canonical, cache-busted URL.
     *
     * @return string
     */
    public static function login_styles_version() {
        $ver = defined('WPOMNIAUTH_VERSION') ? WPOMNIAUTH_VERSION : '1';
        if (defined('WPOMNIAUTH_PLUGIN_DIR')) {
            $css_path = WPOMNIAUTH_PLUGIN_DIR . 'assets/css/login-styles.css';
            if (file_exists($css_path)) {
                $ver = WPOMNIAUTH_VERSION . '.' . filemtime($css_path);
            }
        }
        return $ver;
    }

    public static function login_styles_url() {
        $base = self::login_styles_base_url();
        if ($base === null) {
            return null;
        }
        return add_query_arg('ver', self::login_styles_version(), $base);
    }

    /**
     * Print the <head> styles for a custom login screen.
     *
     * wp_login_head() is NOT a global WordPress function (it is only defined inside
     * wp-login.php), so calling it from our replaced screen fatals. Instead we emit
     * our own stylesheet <link> directly (relying on wp_print_styles() during
     * login_init is unreliable because it can run before/after WP's own print
     * pass) and optionally load WordPress core login.css (needed for the emergency
     * form).
     *
     * @param bool $with_core_login Load WordPress core login.css (needed for the
     *                             emergency form, but skipped for the self-contained
     *                             OAuth card to avoid style conflicts).
     */
    public static function render_login_head($with_core_login = true) {
        // Emit our own stylesheet directly so it is guaranteed to load.
        $css_url = self::login_styles_url();
        if ($css_url) {
            echo "\n" . '<link rel="stylesheet" id="wpomni-login-css" href="' . esc_url($css_url) . '" type="text/css" media="all" />' . "\n";
        }

        // Optionally load WordPress core login styles (used by the emergency form).
        if ($with_core_login && function_exists('wp_style_is') && wp_style_is('login', 'registered')) {
            wp_enqueue_style('login');
        }

        // Let other plugins hook in (some enqueue login styles on this action).
        do_action('login_head');

        // Print any enqueued core/login styles.
        if ($with_core_login) {
            wp_print_styles();
        }
    }

    /**
     * [S5] Global password-blocking filter (authenticate, priority 999).
     *
     * The Manager's own authenticate hook only runs when the Manager is
     * instantiated (login pages, callbacks, admin). This global filter ensures
     * REST API and XML-RPC password authentication are also blocked in
     * OAuth-only mode.
     *
     * @param WP_User|WP_Error|null $user
     * @param string                $username
     * @param string                $password
     * @return WP_User|WP_Error|null
     */
    public static function maybe_block_password_login($user, $username, $password) {
        if (is_wp_error($user)) {
            return $user;
        }
        if (get_option('wpomni_hide_password', 'no') !== 'yes') {
            return $user;
        }
        if (empty($username) && empty($password)) {
            return $user;
        }
        // Emergency mode: temporarily allow password login
        if (get_transient('wpomni_emergency_active')) {
            return $user;
        }
        // Safety net: if no providers are enabled, don't block password login (prevents lockout)
        if (class_exists('WPOmniAuth_Manager')) {
            $has_enabled = false;
            foreach (WPOmniAuth_Manager::instance()->get_all_providers() as $provider) {
                if ($provider->is_enabled()) {
                    $has_enabled = true;
                    break;
                }
            }
            if (!$has_enabled) {
                return $user;
            }
        }
        return new WP_Error(
            'oauth_only_mode',
            __('Password login is disabled. Please use OAuth to log in.', 'wp-omni-auth')
        );
    }

}
