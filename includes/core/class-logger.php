<?php
if (!defined('ABSPATH')) { exit; }

class WPOmniAuth_Logger {

public static function sanitize_log_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        $sensitive_keys = ['_access_token', 'access_token', 'client_secret', 'token'];
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = self::sanitize_log_data($value);
            } elseif (in_array($key, $sensitive_keys, true)) {
                $value = '***REDACTED***';
            }
        }
        return $data;
    }

public static function debug_log($message, $data = null, $tag = 'Manager') {
        if (get_option('wpomni_debug_mode', 'no') !== 'yes') {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$tag}] {$message}";

        if ($data !== null) {
            $log_entry .= "\n" . print_r(self::sanitize_log_data($data), true);
        }

        $log_entry .= "\n" . str_repeat('-', 80) . "\n";

        $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

private static function log($message, $data = null) {
        self::debug_log($message, $data, 'Manager');
    }

public static function is_debug_enabled() {
        return get_option('wpomni_debug_mode', 'no') === 'yes';
    }

public static function get_log_file_path() {
        return WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
    }

public static function clear_log() {
        if (file_exists(WP_CONTENT_DIR . '/.wp-omni-auth-debug.log')) {
            file_put_contents(WP_CONTENT_DIR . '/.wp-omni-auth-debug.log', '');
        }
    }

public static function get_log_content($lines = 100) {
        if (!file_exists(WP_CONTENT_DIR . '/.wp-omni-auth-debug.log')) {
            return '';
        }
        $content = file_get_contents(WP_CONTENT_DIR . '/.wp-omni-auth-debug.log');
        $lines_array = explode("\n", $content);
        $lines_array = array_slice($lines_array, -$lines);
        return implode("\n", $lines_array);
    }

}
