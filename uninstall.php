<?php
/**
 * WP-OmniAuth Uninstall
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Cleans up all plugin data using wildcard patterns to avoid missing options.
 *
 * @package WP-OmniAuth
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Delete ALL wpomni_ options (wildcard covers all current and future options)
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpomni_%'"
);

// 2. Delete ALL wpomni_ transients (including timeout entries)
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpomni_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpomni_%'"
);

// 3. Delete transient locks (different prefix pattern)
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpomni_code_lock_%'"
);

// 4. Drop custom tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpomni_login_log");

// 5. Delete user meta (all wpomni_ keys, including per-provider bindings
//    like wpomni_{slug}_id and the legacy wpomni_provider/id/email/binding_time)
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpomni_%'"
);

// 6. Clear scheduled cron tasks
wp_clear_scheduled_hook('wpomni_cleanup_login_log');

// 7. Delete debug log file
$log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
if (file_exists($log_file)) {
    @unlink($log_file);
}

// 8. Clear cache
wp_cache_flush();
