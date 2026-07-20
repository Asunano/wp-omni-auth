<?php
/**
 * PHPUnit bootstrap for WP-OmniAuth.
 *
 * Sets up the minimal WordPress stubs required to load the plugin classes,
 * then boots WP_Mock so WordPress functions can be mocked in tests.
 */

// WordPress constants the plugin reads at load time.
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wp-omni-auth-wp/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-omni-auth-wp/wp-content');
}
if (!defined('WPOMNIAUTH_PLUGIN_DIR')) {
    define('WPOMNIAUTH_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('WPOMNIAUTH_PLUGIN_URL')) {
    define('WPOMNIAUTH_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-omni-auth/');
}
if (!defined('WPOMNIAUTH_VERSION')) {
    define('WPOMNIAUTH_VERSION', '0.1.0');
}

// WordPress cookie constants referenced by the OAuth callback's debug-log data
// array (manager.php logs is_ssl()/site_url()/COOKIE_DOMAIN/COOKIEPATH on the
// success path). They are not provided by WP_Mock, so define minimal stand-ins.
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

// WordPress time constants referenced by the provider checker.
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Boot WP_Mock before any plugin class is autoloaded/used.
require_once dirname(__DIR__) . '/vendor/autoload.php';

WP_Mock::bootstrap();

// WP_Mock does not define every WordPress pluggable function. The GitHub
// updater instantiates itself at load time and calls plugin_basename(), so we
// provide a minimal stub when it is missing. Guarded so a real WordPress
// environment (which defines it) is never overridden.
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return basename($file);
    }
}

// Minimal WP_Error stub. The production login-guard builds a WP_Error when it
// blocks password login; PHPUnit only auto-generates WP_User (via getMockBuilder),
// not WP_Error (which is instantiated directly in production code), so we
// provide a tiny stand-in for the unit tests.
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            if ($code !== '') {
                $this->errors[ $code ][] = $message;
            }
            if ($data !== '') {
                $this->error_data[ $code ] = $data;
            }
        }
    }
}

// wp_parse_args is called by insert_login_log and the Manager. It's a basic
// WordPress utility; provide a minimal stub so tests don't fatal on it.
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = '') {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r = $args;
        } else {
            parse_str($args, $r);
        }
        if (is_array($defaults)) {
            return array_merge($defaults, $r);
        }
        return $r;
    }
}

// Load the plugin's classes. The entry file registers hooks we don't need in
// unit tests, so we load only the includes/ classes directly. Recurse into
// subdirectories (core/, providers/, admin/, admin/traits/) so the new layout
// is covered as well.
$include_dir = dirname(__DIR__) . '/includes';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($include_dir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && preg_match('/class-.*\.php$/', $file->getFilename())) {
        require_once $file->getPathname();
    }
}

// Test helpers (Testable_Manager, CallbackPageException).
require_once __DIR__ . '/class-testable-manager.php';
