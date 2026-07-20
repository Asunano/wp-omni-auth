<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings views trait.
 *
 * Holds all rendering methods (settings page, sections, tabs, provider
 * cards/detail views, profile section, notices). Each method is a thin
 * "controller": it prepares data, then renders the corresponding template
 * under includes/views/settings/ via render_template(). HTML markup lives in
 * the templates only — no inline ?>…<?php blocks here. Composed into
 * WPOmniAuth_Settings_Page; no public API changes.
 */
trait WPOmniAuth_Settings_Views {

    /**
     * Render a settings template and return its output as a string.
     *
     * Templates live in includes/views/settings/ and may only use the
     * variables passed via $vars (extracted into the template scope). They
     * must not reference $this.
     *
     * @param string $template Template file name (e.g. 'general-section.php').
     * @param array  $vars     Variables to extract into the template scope.
     * @return string
     */
    private function render_template($template, $vars = []) {
        if (!empty($vars)) {
            extract($vars);
        }
        ob_start();
        require WPOMNIAUTH_PLUGIN_DIR . 'includes/views/settings/' . $template;
        return ob_get_clean();
    }

    public function render_notifications_section() {
        $webhook_events = get_option('wpomni_webhook_events', []);
        if (!is_array($webhook_events)) {
            $webhook_events = [];
        }
        $notify_on = get_option('wpomni_email_notify_on', 'failures');
        echo $this->render_template('notifications-section.php', compact('webhook_events', 'notify_on'));
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Backward compat: old ?tab=home → ?tab=settings
        if (isset($_GET['tab']) && $_GET['tab'] === 'home') {
            wp_safe_redirect(admin_url('options-general.php?page=wp-omni-auth&tab=settings'));
            exit;
        }

        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $manager = WPOmniAuth_Manager::instance();
        $has_providers = $this->has_any_providers_configured();

        // Deep-link: a `provider` param opens the Providers tab on that
        // provider's detail view (so a refresh / bind-redirect keeps the
        // config open instead of collapsing back to the list). Only take over
        // the active tab when the user hasn't explicitly asked for another one
        // (tab absent or already "providers") — otherwise it would lock them
        // out of the Dashboard/Settings/About tabs.
        $active_provider = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : '';
        if ($active_provider !== '' && $manager->get_provider($active_provider)) {
            $requested_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
            if ($requested_tab === '' || $requested_tab === 'providers') {
                $current_tab = 'providers';
            }
        }

        // Settings sub-tabs (also rendered as a nested group in the sidebar)
        $current_sub = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : 'general';
        $settings_sub_tabs = [
            'general'       => __('General', 'wp-omni-auth'),
            'security'      => __('Security', 'wp-omni-auth'),
            'notifications' => __('Notifications', 'wp-omni-auth'),
            'debug'         => __('Debug Log', 'wp-omni-auth'),
            'data'          => __('Data Management', 'wp-omni-auth'),
        ];

        // Build dynamic sub-tab grouping from registered sections.
        // Each section may declare a 'sub_tab' key (and optional 'sub_tab_label'
        // when introducing a brand-new sub-tab). This removes the old hardcoded
        // $section_to_sub whitelist so sections can be added without editing this
        // file. See docs/development.md → "Adding a Custom Settings Section".
        $sections_by_sub = [];
        foreach ($manager->get_settings_sections() as $section) {
            $sub = $section['sub_tab'] ?? '';
            if ($sub === '') {
                continue; // No sub_tab declared → not shown on the Settings tab (e.g. provider sections).
            }
            $sections_by_sub[$sub][] = $section;
            if (!isset($settings_sub_tabs[$sub])) {
                $settings_sub_tabs[$sub] = $section['sub_tab_label'] ?? $section['title'] ?? $sub;
            }
        }

        if (!isset($settings_sub_tabs[$current_sub])) {
            $current_sub = 'general';
        }

        // Pre-render the dynamic, non-section pieces so the page shell template
        // only deals with presentation.
        $dashboard_html = $this->render_dashboard_tab();
        $provider_list_html = $this->render_provider_list_view();

        $all_providers = $manager->get_all_providers();
        $callback_base = wp_login_url();
        $provider_details_html = '';
        foreach ($all_providers as $slug => $provider) {
            $provider_details_html .= $this->render_provider_detail_view($provider, $callback_base);
        }

        $about_html = $this->render_about_tab();

        // Data Management is a registered section (slug 'data') but is rendered
        // outside the no-providers overlay, so it stays available even when no
        // provider is configured. The settings tab loop `continue`s for 'data'.
        $data_section = $manager->get_settings_sections()['data'] ?? null;
        $data_html = '';
        if ($data_section && is_callable($data_section['render_callback'])) {
            // render_data_management_section() echoes rather than returns (it is
            // also used directly as a section render_callback). Capture its output
            // here so it lands inside the settings-page template buffer instead of
            // leaking to the real output stream above the page shell.
            ob_start();
            call_user_func($data_section['render_callback']);
            $data_html = ob_get_clean();
        }

        echo $this->render_template('settings-page.php', compact(
            'current_tab',
            'settings_sub_tabs',
            'current_sub',
            'has_providers',
            'sections_by_sub',
            'active_provider',
            'dashboard_html',
            'provider_list_html',
            'provider_details_html',
            'data_html',
            'about_html'
        ));
    }

    private function render_dashboard_tab() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpomni_login_log';
        $table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

        // --- Stats (with transient cache) ---
        $stats = get_transient('wpomni_dashboard_stats_v2');
        if ($stats === false) {
            $stats = ['today' => 0, 'week' => 0, 'failures' => 0, 'providers' => 0];
            if ($table_exists) {
                // Use WordPress timezone for "today" to avoid MySQL CURDATE() timezone mismatch
                $today_start = wp_date('Y-m-d 00:00:00');
                // Compute the 7-day window in WordPress local time too, so it stays
                // consistent with created_at (also stored in local time via current_time()).
                // Using MySQL NOW() would compare against server/UTC time and skew the count.
                $week_start = wp_date('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
                $stats['today'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = 'success' AND created_at >= %s",
                    $today_start
                ));
                $stats['week'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = 'success' AND created_at >= %s",
                    $week_start
                ));
                $stats['failures'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = 'failure' AND created_at >= %s",
                    $week_start
                ));
            }
            // Count enabled providers
            $manager = WPOmniAuth_Manager::instance();
            foreach ($manager->get_all_providers() as $slug => $provider) {
                if (get_option("wpomni_{$slug}_enabled") === 'yes') {
                    $stats['providers']++;
                }
            }
            set_transient('wpomni_dashboard_stats_v2', $stats, 60);
        }

        // --- Recent Logins ---
        $recent_logins = [];
        if ($table_exists) {
            $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 8");
            // Batch-fetch users once instead of N get_userdata() calls.
            $user_ids = array_unique(array_filter(array_map(function ($r) {
                return (int) $r->user_id;
            }, $rows)));
            $users = [];
            if ($user_ids) {
                foreach (get_users(['include' => $user_ids, 'fields' => 'all']) as $u) {
                    $users[$u->ID] = $u;
                }
            }
            foreach ($rows as $row) {
                $user = isset($users[$row->user_id]) ? $users[$row->user_id] : null;
                $recent_logins[] = [
                    'created_at' => $row->created_at,
                    'time_ago'   => human_time_diff(strtotime($row->created_at), current_time('timestamp')),
                    'display'    => $user ? $user->display_name : ($row->email ?: '—'),
                    'provider'   => $row->provider,
                    'ip'         => $row->ip,
                    'ip_masked'  => self::mask_ip_for_display($row->ip),
                    'status'     => $row->status,
                ];
            }
        }

        // --- Provider Status (enabled providers only) ---
        $provider_statuses = [];
        $manager = WPOmniAuth_Manager::instance();
        $all_providers = $manager->get_all_providers();
        foreach ($all_providers as $slug => $provider) {
            $enabled = get_option("wpomni_{$slug}_enabled") === 'yes';
            // Only surface providers the admin has actually enabled.
            if (!$enabled) {
                continue;
            }
            // Use the configuration checker so the status reflects the periodic
            // health-check result (configured vs. missing required fields).
            $status = $manager->get_provider_status($slug);
            $is_active = !empty($status['configured']);
            if ($is_active) {
                $status_label = __('Enabled', 'wp-omni-auth');
                $status_class = 'enabled';
            } else {
                $status_label = __('Not configured', 'wp-omni-auth');
                $status_class = 'disabled';
            }
            $provider_statuses[] = [
                'name'          => $provider->get_name(),
                'slug'          => $slug,
                'icon_html'     => $this->get_safe_icon_html($provider->get_icon()),
                'is_active'     => $is_active,
                'status_class'  => $status_class,
                'status_label'  => $status_label,
            ];
        }

        return $this->render_template('dashboard-tab.php', compact('stats', 'recent_logins', 'provider_statuses', 'table_exists'));
    }

    private function has_any_providers_configured() {
        $manager = WPOmniAuth_Manager::instance();
        foreach ($manager->get_all_providers() as $provider) {
            $slug = $provider->get_slug();
            if (!empty(get_option("wpomni_{$slug}_client_id", ''))) {
                return true;
            }
        }
        return false;
    }

    public function render_general_section() {
        $user_mode = get_option('wpomni_user_mode', 'allowlist');
        $allowed_ids = get_option('wpomni_allowed_user_ids', []);
        if (!is_array($allowed_ids)) {
            $allowed_ids = [];
        }
        $all_users = get_users(['orderby' => 'display_name', 'number' => 100]);
        $users = [];
        foreach ($all_users as $u) {
            $users[] = [
                'ID'          => $u->ID,
                'display_name' => $u->display_name,
                'user_login'  => $u->user_login,
                'user_email'  => $u->user_email,
            ];
        }
        $ek = get_option('wpomni_emergency_key', '');
        $emergency_masked = !empty($ek) ? str_repeat('•', 8) . substr($ek, -6) : '';
        echo $this->render_template('general-section.php', compact('user_mode', 'allowed_ids', 'users', 'emergency_masked'));
    }

    public function render_debug_log_section() {
        $log_path = WPOmniAuth_Manager::instance()->get_log_file_path();
        $log_exists = file_exists($log_path);
        echo $this->render_template('debug-log-section.php', compact('log_path', 'log_exists'));
    }

    public function render_security_section() {
        $blacklist = get_option('wpomni_blacklisted_ips', []);
        if (!is_array($blacklist)) {
            $blacklist = [];
        }
        $lines = [];
        foreach ($blacklist as $entry) {
            $lines[] = $entry['ip'];
        }
        $blacklist_lines = implode("\n", $lines);
        $blacklist_rows = $blacklist;
        echo $this->render_template('security-section.php', compact('blacklist_lines', 'blacklist_rows'));
    }

    private function render_provider_list_view() {
        $manager = WPOmniAuth_Manager::instance();
        $all_providers = $manager->get_all_providers();

        // Sort providers so ENABLED ones appear first (user request:
        // prioritize enabled providers to the front of the Providers tab).
        // Secondary order keeps built-in providers before custom ones
        // (custom can be added/removed by the user).
        $enabled  = [];
        $disabled = [];
        foreach ($all_providers as $slug => $provider) {
            $is_enabled = get_option("wpomni_{$slug}_enabled", 'no') === 'yes';
            if ($is_enabled) {
                $enabled[$slug] = $provider;
            } else {
                $disabled[$slug] = $provider;
            }
        }

        $builtin_first = function ($a, $b) {
            $a_custom = $a instanceof WPOmniAuth_Custom_Provider;
            $b_custom = $b instanceof WPOmniAuth_Custom_Provider;
            return $a_custom <=> $b_custom;
        };
        uasort($enabled, $builtin_first);
        uasort($disabled, $builtin_first);

        $all_cards_html = '';
        foreach ($enabled as $slug => $provider) {
            $all_cards_html .= $this->get_provider_card_html($provider);
        }
        foreach ($disabled as $slug => $provider) {
            $all_cards_html .= $this->get_provider_card_html($provider);
        }

        return $this->render_template('provider-list-view.php', compact('all_cards_html'));
    }

    private function get_provider_card_html($provider) {
        $slug = $provider->get_slug();
        $name = $provider->get_name();
        $icon = $provider->get_icon();
        $is_enabled = get_option("wpomni_{$slug}_enabled", 'no') === 'yes';
        $status = WPOmniAuth_Provider_Checker::instance()->check($provider);
        $is_configured = !empty($status['configured']);
        $is_custom = ($provider instanceof WPOmniAuth_Custom_Provider);
        $icon_html = $this->get_safe_icon_html($icon);
        $config_title = $is_configured ? '' : __('Missing: ', 'wp-omni-auth') . implode(', ', $status['missing']);
        return $this->render_template('provider-card.php', compact('slug', 'name', 'icon_html', 'is_enabled', 'is_configured', 'is_custom', 'config_title'));
    }

    private function render_provider_detail_view($provider, $callback_base) {
        $slug = $provider->get_slug();
        $name = $provider->get_name();
        $icon = $provider->get_icon();
        $is_custom = ($provider instanceof WPOmniAuth_Custom_Provider);
        $fields = $provider->get_settings_fields();
        $has_secret = !empty(get_option("wpomni_{$slug}_client_secret", ''));
        $callback_url = add_query_arg('wpomni_callback', $slug, $callback_base);
        $is_enabled = get_option("wpomni_{$slug}_enabled", 'no') === 'yes';
        $icon_html = $this->get_safe_icon_html($icon);

        $fields_html = '';
        foreach ($fields as $field) {
            $fields_html .= $this->get_field_html($slug, $field, $has_secret);
        }

        $config_status = WPOmniAuth_Provider_Checker::instance()->check($provider);

        // Is the current admin already bound to this provider?
        $bound_id = '';
        if (is_user_logged_in()) {
            $bound_id = get_user_meta(get_current_user_id(), 'wpomni_' . $slug . '_id', true);
        }
        $is_bound = !empty($bound_id);

        return $this->render_template('provider-detail-view.php', compact('slug', 'name', 'icon_html', 'is_custom', 'callback_url', 'is_enabled', 'fields_html', 'config_status', 'is_bound', 'bound_id'));
    }

    private function get_safe_icon_html($icon) {
        if (empty($icon)) {
            return '';
        }
        // If it's a URL, render as img tag
        if (preg_match('#^https?://#i', trim($icon))) {
            return '<img src="' . esc_url($icon) . '" alt="" style="width:100%;height:100%;object-fit:contain;">';
        }
        // Otherwise sanitize as SVG
        return wp_kses($icon, [
            'svg' => ['viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true],
            'path' => ['fill' => true, 'd' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true],
            'g' => ['transform' => true, 'fill' => true, 'stroke' => true],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'transform' => true],
            'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true, 'transform' => true],
        ]);
    }

    private function render_about_tab() {
        $plugin_data = get_file_data(WPOMNIAUTH_PLUGIN_DIR . 'wp-omni-auth.php', [
            'Name' => 'Plugin Name',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
            'PluginURI' => 'Plugin URI',
        ]);
        return $this->render_template('about-tab.php', compact('plugin_data'));
    }

    public function render_data_management_section() {
        echo $this->render_template('data-management-section.php');
    }

    private function get_field_html($slug, $field, $has_secret) {
        $option_name = "wpomni_{$slug}_{$field['key']}";
        $field_name  = "wpomni_{$slug}_{$field['key']}";
        $value       = get_option($option_name, $field['default'] ?? '');
        $type        = $field['type'] ?? 'text';
        $css_class   = $field['class'] ?? 'regular-text';
        $label       = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $description = $field['description'] ?? '';
        $options     = $field['options'] ?? [];
        return $this->render_template('field.php', compact('field_name', 'value', 'type', 'css_class', 'label', 'placeholder', 'description', 'options', 'has_secret'));
    }

    public function render_user_profile_oauth_section($user) {
        $provider = get_user_meta($user->ID, 'wpomni_provider', true);
        $oauth_id = get_user_meta($user->ID, 'wpomni_id', true);
        $oauth_email = get_user_meta($user->ID, 'wpomni_email', true);
        $binding_time = get_user_meta($user->ID, 'wpomni_binding_time', true);

        if (empty($provider)) {
            return; // No OAuth binding
        }

        // Get provider display name
        $manager = WPOmniAuth_Manager::instance();
        $all_providers = $manager->get_all_providers();
        $provider_name = isset($all_providers[$provider]) ? $all_providers[$provider]->get_name() : $provider;

        $user_id = $user->ID;
        echo $this->render_template('user-profile-oauth-section.php', compact('user_id', 'provider_name', 'oauth_id', 'oauth_email', 'binding_time'));
    }

    public function handle_unbind_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (!$user_id) {
            wp_die(__('Invalid user.', 'wp-omni-auth'));
        }

        check_admin_referer('wpomni_unbind_' . $user_id);

        // Permission check: user can edit themselves, or needs edit_users capability
        if ($user_id === get_current_user_id()) {
            // Users can unbind themselves
        } elseif (!current_user_can('edit_user', $user_id)) {
            wp_die(__('You do not have permission to edit this user.', 'wp-omni-auth'));
        }

        // Remove all OAuth binding meta
        delete_user_meta($user_id, 'wpomni_provider');
        delete_user_meta($user_id, 'wpomni_id');
        delete_user_meta($user_id, 'wpomni_email');
        delete_user_meta($user_id, 'wpomni_binding_time');

        // Redirect back to user profile with success message
        $redirect = admin_url('user-edit.php?user_id=' . $user_id . '&wpomni_unbound=1');
        if ($user_id === get_current_user_id()) {
            $redirect = admin_url('profile.php?wpomni_unbound=1');
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public function maybe_show_unbind_notice() {
        if (!isset($_GET['wpomni_unbound']) || $_GET['wpomni_unbound'] !== '1') {
            return;
        }

        // Only show on the user profile screens, where the unbind action
        // redirects back to. This hook is global (admin_notices), so without
        // this guard the success banner would print on every admin screen.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['profile', 'user-edit'], true)) {
            return;
        }

        echo $this->render_template('unbind-notice.php');
    }




    /**
     * Mask IP for the dashboard login log (show only last segment).
     * IPv4: 192.168.1.100 → •••.100 | IPv6: show last 4 groups after ⋯
     */
    private static function mask_ip_for_display($ip) {
        if (empty($ip)) {
            return "—";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode(".", $ip);
            $p[0] = $p[1] = $p[2] = "•••";
            return implode(".", $p);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p = explode(":", $ip);
            if (count($p) > 4) {
                return "⋯:" . implode(":", array_slice($p, -4));
            }
            return $ip;
        }
        return $ip;
    }
}
