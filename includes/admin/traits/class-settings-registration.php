<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings registration trait.
 *
 * Holds section registration, field registration, sanitize callbacks and
 * the save handlers for WP-OmniAuth settings. Composed into
 * WPOmniAuth_Settings_Page; no public API changes.
 */
trait WPOmniAuth_Settings_Registration {

    public function register_default_sections() {
        $manager = WPOmniAuth_Manager::instance();

        $manager->register_settings_section([
            'slug' => 'general',
            'title' => __('General Settings', 'wp-omni-auth'),
            'render_callback' => [$this, 'render_general_section'],
            'register_callback' => [$this, 'register_general_settings'],
            'sub_tab' => 'general',
            'priority' => 10,
        ]);

        $manager->register_settings_section([
            'slug' => 'debug_log',
            'title' => __('Debug Log', 'wp-omni-auth'),
            'render_callback' => [$this, 'render_debug_log_section'],
            'register_callback' => [$this, 'register_debug_log_settings'],
            'sub_tab' => 'debug',
            'priority' => 20,
        ]);

        $manager->register_settings_section([
            'slug' => 'security',
            'title' => __('Security Settings', 'wp-omni-auth'),
            'render_callback' => [$this, 'render_security_section'],
            'register_callback' => [$this, 'register_security_settings'],
            'sub_tab' => 'security',
            'priority' => 50,
        ]);

        $manager->register_settings_section([
            'slug' => 'notifications',
            'title' => __('Notifications', 'wp-omni-auth'),
            'render_callback' => [$this, 'render_notifications_section'],
            'register_callback' => [$this, 'register_notifications_settings'],
            'sub_tab' => 'notifications',
            'priority' => 60,
        ]);

        $manager->register_settings_section([
            'slug' => 'data',
            'title' => __('Data Management', 'wp-omni-auth'),
            'render_callback' => [$this, 'render_data_management_section'],
            'sub_tab' => 'data',
            'priority' => 70,
        ]);
    }

    public function register_settings() {
        $manager = WPOmniAuth_Manager::instance();
        foreach ($manager->get_settings_sections() as $section) {
            if (is_callable($section['register_callback'] ?? null)) {
                call_user_func($section['register_callback']);
            }
        }
    }

    public function register_general_settings() {
        $checkbox_sanitize = function ($value) {
            return ($value === 'yes') ? 'yes' : 'no';
        };

        register_setting('wpomni_home', 'wpomni_hide_password', [
            'sanitize_callback' => $checkbox_sanitize,
        ]);
        register_setting('wpomni_home', 'wpomni_user_mode', [
            'sanitize_callback' => function ($value) {
                return in_array($value, ['allowlist', 'all_users'], true) ? $value : 'allowlist';
            },
        ]);
        register_setting('wpomni_home', 'wpomni_allowed_user_ids', [
            'sanitize_callback' => function ($value) {
                if (is_array($value)) {
                    return array_map('absint', $value);
                }
                return [];
            },
        ]);
        register_setting('wpomni_home', 'wpomni_use_mirror', [
            'sanitize_callback' => $checkbox_sanitize,
        ]);
    }

    public function register_debug_log_settings() {
        $checkbox_sanitize = function ($value) {
            return ($value === 'yes') ? 'yes' : 'no';
        };

        register_setting('wpomni_home', 'wpomni_debug_mode', [
            'sanitize_callback' => $checkbox_sanitize,
        ]);
    }

    public function register_security_settings() {
        $checkbox_sanitize = function ($value) {
            return ($value === 'yes') ? 'yes' : 'no';
        };

        register_setting('wpomni_home', 'wpomni_trusted_proxy', [
            'sanitize_callback' => $checkbox_sanitize,
        ]);
        register_setting('wpomni_home', 'wpomni_client_ip_source', [
            'sanitize_callback' => function ($value) {
                $allowed = ['auto', 'cloudflare', 'edgeone', 'esa', 'xfwd_first', 'xfwd_last', 'xrealip', 'custom'];
                return in_array($value, $allowed, true) ? $value : 'auto';
            },
        ]);
        register_setting('wpomni_home', 'wpomni_client_ip_custom_header', [
            'sanitize_callback' => function ($value) {
                // Only accept a valid header name (letters, digits, hyphen).
                $value = sanitize_text_field($value);
                return preg_match('/^[A-Za-z0-9-]+$/', $value) ? $value : '';
            },
        ]);
        register_setting('wpomni_home', 'wpomni_client_ip_custom_position', [
            'sanitize_callback' => function ($value) {
                return ($value === 'first') ? 'first' : 'last';
            },
        ]);
        register_setting('wpomni_home', 'wpomni_auto_ban_threshold', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_auto_ban_window', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_auto_ban_duration', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_rate_limit_per_ip', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_rate_limit_global', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_rate_limit_per_provider', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_rate_limit_per_identity', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('wpomni_home', 'wpomni_blacklisted_ips', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                $clean = [];
                foreach ($value as $entry) {
                    $ip = sanitize_text_field($entry['ip'] ?? '');
                    if (!empty($ip)) {
                        $clean[] = [
                            'ip' => $ip,
                            'reason' => sanitize_text_field($entry['reason'] ?? ''),
                            'created_at' => sanitize_text_field($entry['created_at'] ?? current_time('mysql')),
                        ];
                    }
                }
                return $clean;
            },
        ]);
        register_setting('wpomni_home', 'wpomni_trusted_proxy_ips', [
            'sanitize_callback' => [$this, 'sanitize_trusted_proxy_ips'],
        ]);
    }

    /**
     * Sanitize the trusted-proxy IP allowlist (comma/space/newline separated
     * IPv4/IPv6 addresses or CIDR ranges). Invalid entries are dropped.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    public function sanitize_trusted_proxy_ips($value) {
        if (empty($value)) {
            return [];
        }
        if (is_array($value)) {
            $value = implode("\n", $value);
        }
        $lines = preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ($lines as $entry) {
            $entry = trim($entry);
            if (self::is_valid_ip_or_cidr($entry)) {
                $clean[] = $entry;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function is_valid_ip_or_cidr($value) {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (!preg_match('#^([^/]+)/(\d{1,3})$#', $value, $m)) {
            return false;
        }
        $prefix = (int) $m[2];
        if (!filter_var($m[1], FILTER_VALIDATE_IP)) {
            return false;
        }
        $max = (strpos($m[1], ':') !== false) ? 128 : 32;
        return $prefix >= 0 && $prefix <= $max;
    }

    public function maybe_save_blacklist() {
        if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'wpomni_home') {
            return;
        }
        if (!isset($_POST['wpomni_blacklist_text'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $text = sanitize_textarea_field($_POST['wpomni_blacklist_text']);
        $lines = array_filter(array_map('trim', explode("\n", $text)));

        // Preserve existing metadata (reason, created_at) for IPs that already exist
        $existing = get_option('wpomni_blacklisted_ips', []);
        $existing_map = [];
        if (is_array($existing)) {
            foreach ($existing as $entry) {
                $existing_map[$entry['ip']] = $entry;
            }
        }

        $new_blacklist = [];
        foreach ($lines as $ip_line) {
            $ip_line = sanitize_text_field($ip_line);
            if (empty($ip_line)) {
                continue;
            }
            if (isset($existing_map[$ip_line])) {
                $new_blacklist[] = $existing_map[$ip_line]; // Preserve metadata
            } else {
                $new_blacklist[] = [
                    'ip' => $ip_line,
                    'reason' => __('Manual entry', 'wp-omni-auth'),
                    'created_at' => current_time('mysql'),
                ];
            }
        }

        update_option('wpomni_blacklisted_ips', $new_blacklist);
    }

    public function register_notifications_settings() {
        $checkbox_sanitize = function ($value) {
            return ($value === 'yes') ? 'yes' : 'no';
        };

        register_setting('wpomni_home', 'wpomni_email_notify_enabled', [
            'sanitize_callback' => $checkbox_sanitize,
        ]);
        register_setting('wpomni_home', 'wpomni_email_notify_to', [
            'sanitize_callback' => 'sanitize_email',
        ]);
        register_setting('wpomni_home', 'wpomni_email_notify_on', [
            'sanitize_callback' => function ($value) {
                return in_array($value, ['failures', 'all', 'none'], true) ? $value : 'failures';
            },
        ]);
        register_setting('wpomni_home', 'wpomni_email_notify_events', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return ['login_failure', 'access_denied', 'ip_blocked', 'provider_bind'];
                }
                $allowed = ['login_success', 'login_failure', 'access_denied', 'ip_blocked', 'provider_bind'];
                return array_values(array_intersect($value, $allowed));
            },
        ]);
        register_setting('wpomni_home', 'wpomni_webhook_url', [
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('wpomni_home', 'wpomni_webhook_events', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                $allowed = ['login_success', 'login_failure', 'access_denied', 'ip_blocked', 'provider_bind'];
                return array_values(array_intersect($value, $allowed));
            },
        ]);
        register_setting('wpomni_home', 'wpomni_webhook_secret', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function sanitize_custom_providers($value) {
        if (is_array($value)) {
            return array_map('sanitize_key', $value);
        }
        if (is_string($value) && !empty($value)) {
            return array_map('sanitize_key', array_filter(explode(',', $value)));
        }
        return [];
    }

    public function sanitize_icon_value($value) {
        $value = trim($value);
        if (empty($value)) {
            return '';
        }
        // If it looks like a URL, sanitize as URL
        if (preg_match('#^https?://#i', $value)) {
            return esc_url_raw($value);
        }
        // Otherwise treat as SVG HTML
        return wp_kses($value, [
            'svg'  => ['viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true],
            'path' => ['fill' => true, 'd' => true, 'stroke' => true, 'stroke-width' => true],
            'g'    => [],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true],
            'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true],
        ]);
    }

    public function register_provider_save() {
        add_action('admin_post_wpomni_save_providers', [$this, 'save_providers']);
        add_action('wp_ajax_wpomni_save_providers', [$this, 'ajax_save_providers']);
        add_action('wp_ajax_wpomni_save_mirror', [$this, 'ajax_save_mirror']);
        // "Test connection & bind": redirect the current admin into the real
        // OAuth flow with a bind marker. Implemented on the Manager.
        add_action('wp_ajax_wpomni_begin_bind', [WPOmniAuth_Manager::instance(), 'begin_oauth_bind']);
        // Data management: export (admin-post direct download) and import (AJAX).
        add_action('admin_post_wpomni_export_data', [$this, 'admin_export_data']);
        add_action('wp_ajax_wpomni_import_data', [$this, 'ajax_import_data']);
    }

    public function ajax_save_mirror() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $previous = get_option('wpomni_use_mirror', 'no');
        $value = (isset($_POST['wpomni_use_mirror']) && $_POST['wpomni_use_mirror'] === 'yes') ? 'yes' : 'no';
        update_option('wpomni_use_mirror', $value);

        wp_send_json_success([
            'value'    => $value,
            'previous' => $previous,
        ]);
    }

    public function save_providers() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wp-omni-auth'));
        }

        check_admin_referer('wpomni_providers_nonce', 'wpomni_providers_nonce');

        $toasts = $this->process_provider_save();
        foreach ($toasts as $t) {
            $this->add_toast($t['message'], $t['type'], $t['title']);
        }

        $redirect = add_query_arg([
            'page' => 'wp-omni-auth',
            'tab'  => 'providers',
            'saved' => 'true',
        ], set_url_scheme(admin_url('options-general.php'), 'https'));

        // Preserve the deep-link back to the provider being edited.
        if (!empty($_REQUEST['provider'])) {
            $redirect = add_query_arg('provider', sanitize_key($_REQUEST['provider']), $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * AJAX entry point for the provider save form.
     *
     * Returns a JSON response with the toasts that should be shown, so the
     * client can render feedback even when the request is served from a
     * proxied / mixed-content environment where a full-page redirect would
     * otherwise fail silently.
     */
    public function ajax_save_providers() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wp-omni-auth')]);
        }

        if (empty($_POST['wpomni_providers_nonce']) || !wp_verify_nonce($_POST['wpomni_providers_nonce'], 'wpomni_providers_nonce')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'wp-omni-auth')]);
        }

        $toasts = $this->process_provider_save();
        wp_send_json_success(['toasts' => $toasts]);
    }

    /**
     * Core provider-save routine shared by the native (admin-post) and AJAX
     * save handlers. Writes all provider options, refreshes the config-check
     * cache, and returns the list of toast descriptors to surface to the user.
     *
     * @return array List of ['message'=>string,'type'=>string,'title'=>?string]
     */
    protected function process_provider_save() {
        $manager = WPOmniAuth_Manager::instance();
        $all_providers = $manager->get_all_providers();

        foreach ($all_providers as $slug => $provider) {
            $safe_slug = sanitize_key($slug);

            // Enabled (checkbox with hidden fallback)
            $enabled = isset($_POST["wpomni_{$safe_slug}_enabled"]) && $_POST["wpomni_{$safe_slug}_enabled"] === 'yes' ? 'yes' : 'no';
            update_option("wpomni_{$safe_slug}_enabled", $enabled);

            // Provider fields
            foreach ($provider->get_settings_fields() as $field) {
                $option_name = "wpomni_{$safe_slug}_{$field['key']}";

                if (!isset($_POST[$option_name])) {
                    continue;
                }

                $raw_value = $_POST[$option_name];

                if ($field['key'] === 'client_secret') {
                    // Only update secret if a new value was provided
                    $sanitized = $provider->sanitize_secret($raw_value);
                    if ($sanitized !== '') {
                        update_option($option_name, $sanitized);
                    }
                } elseif (isset($field['type']) && $field['type'] === 'url') {
                    $sanitized = $provider->sanitize_url($raw_value);
                    update_option($option_name, $sanitized);
                } elseif (isset($field['type']) && $field['type'] === 'toggle') {
                    update_option($option_name, ($raw_value === 'yes') ? 'yes' : 'no');
                } else {
                    update_option($option_name, sanitize_text_field($raw_value));
                }
            }

            // Custom provider name and icon
            if ($provider instanceof WPOmniAuth_Custom_Provider) {
                if (isset($_POST["wpomni_{$safe_slug}_name"])) {
                    update_option("wpomni_{$safe_slug}_name", sanitize_text_field($_POST["wpomni_{$safe_slug}_name"]));
                }
                if (isset($_POST["wpomni_{$safe_slug}_icon"])) {
                    update_option("wpomni_{$safe_slug}_icon", $this->sanitize_icon_value($_POST["wpomni_{$safe_slug}_icon"]));
                }
            }
        }

        // Custom providers list
        if (isset($_POST['wpomni_custom_providers'])) {
            $custom_slugs = $this->sanitize_custom_providers($_POST['wpomni_custom_providers']);
            update_option('wpomni_custom_providers', $custom_slugs);
        }

        // Run the provider configuration check and notify the admin.
        $checker = WPOmniAuth_Provider_Checker::instance();
        $checker->refresh_cache();

        $incomplete_enabled = [];
        foreach ($manager->get_all_providers() as $slug => $provider) {
            if (get_option("wpomni_{$slug}_enabled", 'no') !== 'yes') {
                continue;
            }
            $status = $checker->check($provider);
            if (!$status['configured']) {
                $incomplete_enabled[] = [
                    'name'    => $provider->get_name(),
                    'missing' => $status['missing'],
                ];
            }
        }

        $toasts = [];
        if (!empty($incomplete_enabled)) {
            // Emit one toast per enabled-but-incomplete provider. The toast
            // system stacks and auto-dismisses them, so many at once is fine.
            foreach ($incomplete_enabled as $entry) {
                $toasts[] = [
                    'message' => __('Incomplete configuration:', 'wp-omni-auth') . ' ' . implode(', ', $entry['missing']),
                    'type'    => 'warning',
                    'title'   => $entry['name'],
                ];
            }
        } else {
            $toasts[] = [
                'message' => __('Provider settings saved. All enabled providers are configured.', 'wp-omni-auth'),
                'type'    => 'success',
            ];
        }

        return $toasts;
    }

}
