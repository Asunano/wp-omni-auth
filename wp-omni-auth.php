<?php
/**
 * Plugin Name: WP-OmniAuth
 * Plugin URI: https://github.com/Asunano/wp-omni-auth
 * Description: OAuth 2.0 login for WordPress with 11 built-in providers and unlimited custom OAuth. Supports OAuth-only mode, security/rate-limit layer, login history, and self-updates. | 为 WordPress 添加 OAuth 2.0 登录能力，支持 11 个内置提供商与不限数量的自定义提供商。教程：https://blog.drxian.cn/archives/1465
 * Version: 0.1.0
 * Author: Asunano
 * Author URI: https://github.com/Asunano
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-omni-auth
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard against WordPress listing/loading this file twice.
// The build process stages a full copy at build/wp-omni-auth/wp-omni-auth.php.
// Because WordPress scans the plugins directory recursively, that staging copy
// would otherwise appear as a second "WP-OmniAuth" entry and could trigger
// "cannot redeclare" fatal errors. If we are that copy, do nothing.
if (basename(dirname(dirname(__FILE__))) === 'build') {
    return;
}

// Detect HTTPS behind reverse proxies (Nginx, Caddy, CloudFlare, etc.)
// Must run before any code that calls is_ssl()
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    $_SERVER['HTTPS'] = 'on';
}

define('WPOMNIAUTH_VERSION', '0.1.0');
define('WPOMNIAUTH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPOMNIAUTH_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-oauth-provider.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-security.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-logger.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-login-log.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-emergency-access.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-login-guard.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-provider-checker.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/providers/class-custom-provider.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-oauth-state.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-user-matcher.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-login-buttons.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-oauth-manager.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/core/class-event-dispatcher.php';

register_activation_hook(__FILE__, ['WPOmniAuth_Manager', 'activate']);
register_deactivation_hook(__FILE__, ['WPOmniAuth_Manager', 'deactivate']);

// Customize plugin action links in the Installed Plugins list.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=wp-omni-auth') . '">'
        . __('Settings', 'wp-omni-auth') . '</a>';
    $blog_link = '<a href="https://blog.drxian.cn/archives/1465" target="_blank">'
        . __('Guide', 'wp-omni-auth') . '</a>';
    array_unshift($links, $settings_link, $blog_link);
    return $links;
});

// Remove the auto-generated "Settings" from the plugin title area on the
// Installed Plugins screen by clearing the set_options capability.
add_filter('all_plugins', function ($plugins) {
    $file = plugin_basename(__FILE__);
    if (isset($plugins[$file])) {
        $plugins[$file]['set_options'] = false;
    }
    return $plugins;
});

// Add blog link to the plugin row meta (Version | Author | ... area).
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="https://blog.drxian.cn/archives/1465" target="_blank">'
            . __('Configuration Guide', 'wp-omni-auth') . '</a>';
    }
    return $links;
}, 10, 2);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('wp-omni-auth', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // [P1] Only instantiate Manager when needed:
    // - Login/register pages (for OAuth buttons + password blocking)
    // - OAuth callback requests
    // - Admin pages (for settings page)
    // This avoids creating provider objects on regular frontend requests.
    $is_login = (defined('WP_LOGIN') && WP_LOGIN)
        || (isset($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'], true))
        || isset($_GET['wpomni_callback'])
        || (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wp-omni-auth');

    if ($is_login) {
        WPOmniAuth_Manager::instance();
    }

    // Load GitHub updater on all admin pages (update checks fire on plugins.php, update-core.php, etc.)
    if (is_admin()) {
        require_once WPOMNIAUTH_PLUGIN_DIR . 'includes/admin/class-github-updater.php';
    }
});

register_shutdown_function(function () {
    // Only log fatal errors if debug mode is enabled
    if (get_option('wpomni_debug_mode', 'no') !== 'yes') {
        return;
    }
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
        $log_entry = "[" . date('Y-m-d H:i:s') . "] FATAL ERROR\n";
        $log_entry .= "Type: " . $error['type'] . "\n";
        $log_entry .= "Message: " . $error['message'] . "\n";
        $log_entry .= "File: " . $error['file'] . "\n";
        $log_entry .= "Line: " . $error['line'] . "\n";
        $log_entry .= str_repeat('-', 80) . "\n";
        $log_dir = dirname($log_file);
        if (is_writable($log_dir) || (is_dir($log_dir) && wp_mkdir_p($log_dir))) {
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
});

// Run upgrade routines when plugin version changes (admin only)
add_action('admin_init', ['WPOmniAuth_Manager', 'maybe_upgrade']);

// Login log cleanup cron handler (must be global — WP-Cron doesn't load Manager)
add_action('wpomni_cleanup_login_log', ['WPOmniAuth_Manager', 'cleanup_login_log']);

// Webhook async sender cron handler
add_action('wpomni_send_webhook', ['WPOmniAuth_Event_Dispatcher', 'cron_send_webhook'], 10, 3);

/**
 * [P1] Lazy-load the Manager: only instantiate when the request actually
 * contains an OAuth callback parameter. This avoids creating provider objects
 * and registering hooks on 99.9% of page loads that don't need them.
 */
add_action('init', function () {
    if (!isset($_GET['wpomni_callback'])) {
        return;
    }
    $manager = WPOmniAuth_Manager::instance();
    $manager->handle_oauth_callback();
}, 1); // Priority 1: run early to process callback before other init hooks

/**
 * [A] Server-side "begin login" endpoint. The OAuth login buttons point here
 * (?wpomni_login={slug}) so we can throttle initiation before redirecting to
 * the provider. Mirrors the callback handler's early init priority.
 */
add_action('init', function () {
    if (!isset($_GET['wpomni_login'])) {
        return;
    }
    $manager = WPOmniAuth_Manager::instance();
    $manager->begin_oauth_login();
}, 1); // Priority 1: run early, before other init hooks

/**
 * [S5] Global password-blocking filter.
 * The Manager's authenticate hook only runs when the Manager is instantiated
 * (login pages, callbacks, admin). This global filter ensures REST API and
 * XML-RPC password authentication are also blocked in OAuth-only mode.
 */
add_filter('authenticate', ['WPOmniAuth_Login_Guard', 'maybe_block_password_login'], 999, 3);

/**
 * Emergency backdoor — two ways to temporarily re-enable password login when
 * OAuth is unavailable (OAuth-only mode only):
 *   A. Email login link  — GET ?wpomni_emergency=1&wpomni_token=XXX (emailed by
 *      the request form); valid 15 min and bound to the requesting IP.
 *   B. Manual key (backup) — POST the emergency key on the rendered page.
 * The actual bypass of the password blocker happens in WPOmniAuth_Login_Guard
 * (authenticate, priority 999) which checks the wpomni_emergency_active transient.
 */
add_action('init', ['WPOmniAuth_Emergency_Access', 'handle_emergency_access'], 0); // Priority 0: run before callback handler

/**
 * Show admin notice when emergency mode is active.
 */
add_action('admin_notices', ['WPOmniAuth_Emergency_Access', 'render_emergency_notices']);
