<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings AJAX trait.
 *
 * Holds all ajax_* handler methods for WP-OmniAuth settings. Composed into
 * WPOmniAuth_Settings_Page; no public API changes.
 */
trait WPOmniAuth_Settings_Ajax {

    public function ajax_add_custom_provider() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $slug = 'custom_' . wp_generate_password(8, false);
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : __('Custom Provider', 'wp-omni-auth');

        $custom_providers = get_option('wpomni_custom_providers', []);
        $custom_providers[] = $slug;
        update_option('wpomni_custom_providers', $custom_providers);

        update_option("wpomni_{$slug}_name", $name);
        update_option("wpomni_{$slug}_enabled", 'no');

        wp_send_json_success([
            'slug' => $slug,
            'name' => $name,
        ]);
    }

    public function ajax_remove_custom_provider() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
        if (empty($slug)) {
            wp_send_json_error('Invalid slug');
        }

        $custom_providers = get_option('wpomni_custom_providers', []);
        $custom_providers = array_diff($custom_providers, [$slug]);
        update_option('wpomni_custom_providers', $custom_providers);

        delete_option("wpomni_{$slug}_name");
        delete_option("wpomni_{$slug}_enabled");
        delete_option("wpomni_{$slug}_client_id");
        delete_option("wpomni_{$slug}_client_secret");
        delete_option("wpomni_{$slug}_authorization_url");
        delete_option("wpomni_{$slug}_token_url");
        delete_option("wpomni_{$slug}_userinfo_url");
        delete_option("wpomni_{$slug}_email_field");
        delete_option("wpomni_{$slug}_scope");
        delete_option("wpomni_{$slug}_token_in_header");
        delete_option("wpomni_{$slug}_token_key");

        wp_send_json_success();
    }

    public function ajax_view_log() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $manager  = WPOmniAuth_Manager::instance();
        $log_file = $manager->get_log_file_path();

        if (!file_exists($log_file)) {
            $hint = (get_option('wpomni_debug_mode', 'no') === 'yes')
                ? __('Debug log file has not been created yet. Perform an OAuth login or any plugin action to generate it.', 'wp-omni-auth')
                : __('Debug Mode is off. Enable it (Debug Log settings) and perform an OAuth login to generate the log file.', 'wp-omni-auth');
            wp_send_json_success(['log' => $hint]);
        }

        $content = file_get_contents($log_file);
        if ($content === false) {
            wp_send_json_error('Failed to read log file.');
        }

        wp_send_json_success(['log' => $content ?: '']);
    }

    public function ajax_clear_log() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $manager  = WPOmniAuth_Manager::instance();
        $log_file = $manager->get_log_file_path();

        if (file_exists($log_file)) {
            $result = file_put_contents($log_file, '');
            if ($result === false) {
                wp_send_json_error('Failed to clear log file. Check file permissions.');
            }
        }

        wp_send_json_success();
    }

    public function ajax_download_log() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wp-omni-auth'));
        }

        $manager  = WPOmniAuth_Manager::instance();
        $log_file = $manager->get_log_file_path();

        if (!file_exists($log_file)) {
            wp_die(esc_html__('Debug log file has not been created yet.', 'wp-omni-auth'));
        }

        $content = file_get_contents($log_file);
        if ($content === false) {
            wp_die(esc_html__('Failed to read log file.', 'wp-omni-auth'));
        }

        // Stream the file as a download (filename is dot-prefixed/hidden on disk).
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($log_file) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $content;
        exit;
    }

    public function ajax_view_emergency_key() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $key = get_option('wpomni_emergency_key', '');
        if (empty($key)) {
            wp_send_json_error(__('No emergency key set. Click "Regenerate Key" to create one.', 'wp-omni-auth'));
        }

        wp_send_json_success(['key' => $key]);
    }

    public function ajax_regenerate_emergency_key() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $new_key = wp_generate_password(48, false);
        update_option('wpomni_emergency_key', $new_key);

        $masked = str_repeat('•', 8) . substr($new_key, -6);

        wp_send_json_success([
            'masked' => $masked,
            'full'   => $new_key,
        ]);
    }

    public function ajax_check_update() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Clear caches and force fresh check
        if (class_exists('WPOmniAuth_GitHub_Updater')) {
            WPOmniAuth_GitHub_Updater::clear_cache();
        }
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $update = get_site_transient('update_plugins');
        $basename = plugin_basename(WPOMNIAUTH_PLUGIN_DIR . 'wp-omni-auth.php');
        $has_update = isset($update->response[$basename]);

        $remote_version = '';
        if ($has_update) {
            $remote_version = $update->response[$basename]->new_version;
        } else {
            // Fetch latest version info for display even when up to date
            $cached = get_transient('wpomni_version_json');
            if (is_object($cached)) {
                $remote_version = $cached->version;
            }
        }

        wp_send_json_success([
            'has_update'     => $has_update,
            'current_version' => WPOMNIAUTH_VERSION,
            'latest_version' => $remote_version,
            'update_url'     => admin_url('plugins.php'),
        ]);
    }

    public function ajax_clean_data() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $categories = isset($_POST['categories']) ? array_map('sanitize_key', (array) $_POST['categories']) : [];
        if (empty($categories)) {
            wp_send_json_error(__('No categories selected.', 'wp-omni-auth'));
        }

        global $wpdb;
        $cleared = [];

        foreach ($categories as $cat) {
            switch ($cat) {
                case 'login_log':
                    $table = $wpdb->prefix . 'wpomni_login_log';
                    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                        $count = $wpdb->query("TRUNCATE TABLE {$table}");
                        $cleared[] = __('Login logs', 'wp-omni-auth');
                    }
                    break;

                case 'blacklist':
                    update_option('wpomni_blacklisted_ips', []);
                    $cleared[] = __('IP blacklist', 'wp-omni-auth');
                    break;

                case 'rate_limits':
                    $wpdb->query(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpomni_rate_%' OR option_name LIKE '_transient_timeout_wpomni_rate_%'"
                    );
                    $cleared[] = __('Rate limits', 'wp-omni-auth');
                    break;

                case 'caches':
                    delete_transient('wpomni_dashboard_stats_v2');
                    if (class_exists('WPOmniAuth_GitHub_Updater')) {
                        WPOmniAuth_GitHub_Updater::clear_cache();
                    }
                    $cleared[] = __('Caches', 'wp-omni-auth');
                    break;

                case 'debug_log':
                    $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
                    if (file_exists($log_file)) {
                        file_put_contents($log_file, '');
                    }
                    $cleared[] = __('Debug log', 'wp-omni-auth');
                    break;

                case 'used_codes':
                    delete_option('wpomni_used_codes');
                    $cleared[] = __('Used codes', 'wp-omni-auth');
                    break;
            }
        }

        if (empty($cleared)) {
            wp_send_json_error(__('Nothing to clear.', 'wp-omni-auth'));
        }

        wp_send_json_success([
            'cleared' => $cleared,
            'message' => sprintf(
                /* translators: %s: comma-separated list of cleared categories */
                __('Cleared: %s', 'wp-omni-auth'),
                implode(', ', $cleared)
            ),
        ]);
    }

    public function ajax_reset_all_data() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        // 1. Delete all wpomni_ options
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpomni_%'"
        );

        // 2. Delete all wpomni_ transients (including timeouts)
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpomni_%' OR option_name LIKE '_transient_timeout_wpomni_%'"
        );

        // 3. Drop login log table
        $table = $wpdb->prefix . 'wpomni_login_log';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        // 4. Delete user meta
        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('wpomni_provider', 'wpomni_id', 'wpomni_email', 'wpomni_binding_time')"
        );

        // 5. Clear debug log file
        $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
        }

        // 6. Clear cron
        wp_clear_scheduled_hook('wpomni_cleanup_login_log');

        // 7. Re-create table and restore default options (like a fresh activate)
        WPOmniAuth_Manager::ensure_login_log_table();
        WPOmniAuth_Manager::activate();

        // 8. Flush object cache
        wp_cache_flush();

        wp_send_json_success([
            'message' => __('All data has been reset to defaults.', 'wp-omni-auth'),
        ]);
    }

    /**
     * Export all plugin data as JSON (triggers a file download via admin-post).
     * Hooked to admin_post_wpomni_export_data.
     */
    public function admin_export_data() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('wpomni_export');

        global $wpdb;

        // Collect ALL wpomni_ options (excluding transients and timeouts).
        $options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'wpomni\_%' AND option_name NOT LIKE 'wpomni\_transient\_%'"
        );

        $data = ['options' => []];
        foreach ($options as $row) {
            $data['options'][$row->option_name] = maybe_unserialize($row->option_value);
        }

        // Provider bindings: per-provider + legacy meta.
        $bindings = $wpdb->get_results(
            "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key LIKE 'wpomni\_%'"
        );
        $data['user_bindings'] = [];
        foreach ($bindings as $b) {
            $data['user_bindings'][] = [
                'user_id'    => (int) $b->user_id,
                'meta_key'   => $b->meta_key,
                'meta_value' => $b->meta_value,
            ];
        }

        // Login log (last 1000 rows to keep the export manageable).
        $table = $wpdb->prefix . 'wpomni_login_log';
        $logs = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1000");
        }
        $data['login_log'] = $logs;

        $data['_meta'] = [
            'plugin'       => 'WP-OmniAuth',
            'version'      => WPOMNIAUTH_VERSION,
            'exported_at'  => current_time('mysql'),
            'description'  => __('Full plugin data export', 'wp-omni-auth'),
        ];

        $site_slug = sanitize_title(get_bloginfo('name'));
        $filename  = 'wp-omni-auth-backup-' . $site_slug . '-' . date('Y-m-d') . '.json';
        $plain    = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $payload  = self::encrypt_export($plain);
        $payload  = 'WPOMNIAUTH:' . $payload;

        header('Content-Type: application/octet-stream; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        exit;
    }

    /**
     * Encrypt a plain-text string for export. Uses AES-256-CBC with a site-bound key.
     *
     * @param string $data
     * @return string base64(IV).base64(ciphertext)
     */
    private static function encrypt_export($data) {
        $key = hash('sha256', defined('AUTH_KEY') ? AUTH_KEY : 'wp-omni-auth-default');
        if (defined('NONCE_KEY')) {
            $key = hash_hmac('sha256', $key, NONCE_KEY);
        }
        $iv_len   = openssl_cipher_iv_length('aes-256-cbc');
        $iv       = openssl_random_pseudo_bytes($iv_len);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv) . '.' . base64_encode($encrypted);
    }

    /**
     * Decrypt an export payload. Returns the original JSON string.
     *
     * @param string $payload base64(IV).base64(ciphertext)
     * @return string|false
     */
    private static function decrypt_export($payload) {
        $parts = explode('.', $payload, 2);
        if (count($parts) < 2) {
            return false;
        }
        $iv        = base64_decode($parts[0]);
        $ciphertext = base64_decode($parts[1]);
        if ($iv === false || $ciphertext === false) {
            return false;
        }
        $key = hash('sha256', defined('AUTH_KEY') ? AUTH_KEY : 'wp-omni-auth-default');
        if (defined('NONCE_KEY')) {
            $key = hash_hmac('sha256', $key, NONCE_KEY);
        }
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Import plugin data from a JSON backup file.
     * Hooked to wp_ajax_wpomni_import_data.
     */
    public function ajax_import_data() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized.', 'wp-omni-auth'));
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(__('File upload failed.', 'wp-omni-auth'));
        }

        $raw = file_get_contents($_FILES['file']['tmp_name']);

        // Detect encrypted export (prefixed with WPOMNIAUTH:).
        if (strpos($raw, 'WPOMNIAUTH:') === 0) {
            $payload = substr($raw, 11);
            $decoded = self::decrypt_export($payload);
            if ($decoded === false) {
                wp_send_json_error(__('Decryption failed. This backup was exported from a different site.', 'wp-omni-auth'));
            }
            $raw = $decoded;
        }

        $data = json_decode($raw, true);

        if (empty($data) || !isset($data['_meta']['plugin']) || $data['_meta']['plugin'] !== 'WP-OmniAuth') {
            wp_send_json_error(__('Invalid backup file.', 'wp-omni-auth'));
        }

        global $wpdb;
        $imported = [];

        // Restore options.
        if (!empty($data['options'])) {
            foreach ($data['options'] as $key => $value) {
                update_option($key, $value);
                $imported[] = $key;
            }
        }

        // Restore user bindings (delete existing wpomni_ meta first, then insert).
        if (!empty($data['user_bindings'])) {
            $wpdb->query(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpomni\_%'"
            );
            foreach ($data['user_bindings'] as $b) {
                add_user_meta((int) $b['user_id'], $b['meta_key'], $b['meta_value'], true);
            }
            $imported[] = sprintf(__('%d user bindings', 'wp-omni-auth'), count($data['user_bindings']));
        }

        // Restore login log.
        if (!empty($data['login_log'])) {
            $table = $wpdb->prefix . 'wpomni_login_log';
            $wpdb->query("TRUNCATE TABLE {$table}");
            foreach ($data['login_log'] as $row) {
                $wpdb->insert($table, (array) $row);
            }
            $imported[] = sprintf(__('%d login log entries', 'wp-omni-auth'), count($data['login_log']));
        }

        wp_cache_flush();

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: imported items summary */
                __('Restored: %s', 'wp-omni-auth'),
                implode(', ', $imported)
            ),
        ]);
    }

}
