<?php
if (!defined('ABSPATH')) { exit; }

class WPOmniAuth_Login_Log {

    /**
     * Request-level flag: the login-log table existence is verified (and, if
     * needed, created) at most once per request, so high-volume logging does
     * not run a SHOW TABLES query on every single insert.
     *
     * @var bool
     */
    private static $table_verified = false;

public static function insert_login_log($args) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wpomni_login_log';

        // Ensure the table exists before inserting. dbDelta is idempotent and also
        // reconciles schema drift (e.g. a manually-created table missing columns),
        // so a logging call never fails silently just because the table check missed it.
        if (!self::$table_verified) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
                self::ensure_login_log_table();
            }
            self::$table_verified = true;
        }

        $defaults = [
            'user_id'    => 0,
            'provider'   => '',
            'email'      => '',
            'ip'         => '',
            'status'     => 'failure',
            'message'    => '',
            'user_agent' => '',
        ];
        $data = wp_parse_args($args, $defaults);
        $data['created_at'] = current_time('mysql');

        // Truncate user_agent to fit column
        if (strlen($data['user_agent']) > 512) {
            $data['user_agent'] = substr($data['user_agent'], 0, 512);
        }
        if (strlen($data['message']) > 255) {
            $data['message'] = substr($data['message'], 0, 255);
        }

        $wpdb->insert($table_name, $data, [
            '%d', // user_id
            '%s', // provider
            '%s', // email
            '%s', // ip
            '%s', // status
            '%s', // message
            '%s', // user_agent
            '%s', // created_at
        ]);

        // Clear dashboard stats cache when new log is inserted
        delete_transient('wpomni_dashboard_stats_v2');

        // Auto-ban check on failure
        if ($data['status'] === 'failure' && !empty($data['ip'])) {
            WPOmniAuth_Manager::maybe_auto_ban($data['ip']);
        }

        // Dispatch event for email/webhook notifications
        $event = ($data['status'] === 'success') ? 'login_success' : 'login_failure';
        if (in_array($data['message'] ?? '', ['Access denied.', 'IP blacklisted.'], true)) {
            $event = 'access_denied';
        }
        WPOmniAuth_Event_Dispatcher::dispatch($event, $data);
    }

public static function cleanup_login_log() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wpomni_login_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        $retention_days = (int) get_option('wpomni_log_retention_days', 90);

        // 0 means keep forever
        if ($retention_days <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }

public static function ensure_login_log_table() {
        global $wpdb;

        // dbDelta() and $wpdb->get_charset_collate() only exist in a real
        // WordPress environment. Guard so this is a safe no-op in non-DB
        // contexts (e.g. unit tests with a stub $wpdb) instead of fatal-erroring
        // when insert_login_log() calls it to self-heal a missing table.
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (file_exists($upgrade_file)) {
                require_once $upgrade_file;
            }
        }
        if (!function_exists('dbDelta') || !method_exists($wpdb, 'get_charset_collate')) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wpomni_login_log';

        $sql = "CREATE TABLE {$table_name} (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED DEFAULT 0,
            provider    VARCHAR(64) NOT NULL,
            email       VARCHAR(255) DEFAULT '',
            ip          VARCHAR(45) DEFAULT '',
            status      VARCHAR(16) NOT NULL,
            message     VARCHAR(255) DEFAULT '',
            user_agent  VARCHAR(512) DEFAULT '',
            created_at  DATETIME NOT NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at),
            INDEX idx_user (user_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

}
