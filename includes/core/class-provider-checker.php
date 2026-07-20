<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provider configuration checker for WP-OmniAuth.
 *
 * Extracted as a standalone module so the provider base class and the Manager
 * stay focused on their own concerns. This class owns the check logic, the
 * status cache, the periodic cron, and the scheduling — keeping the feature
 * isolated from other responsibilities.
 *
 * A provider declares what it needs via get_required_config_keys() (option key
 * suffixes). This checker reads those keys plus the provider's get_settings_fields()
 * labels, inspects the stored options, and reports a structured status.
 */
class WPOmniAuth_Provider_Checker {

    private static $instance = null;

    const CACHE_KEY = 'wpomni_provider_status';
    const CACHE_TTL = HOUR_IN_SECONDS;
    const CRON_HOOK = 'wpomni_provider_health_check';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register the cron callback. Called once from the Manager init.
     */
    public function register_hooks() {
        add_action(self::CRON_HOOK, [$this, 'run_cron']);
    }

    /**
     * Check a single provider's configuration.
     *
     * @param WPOmniAuth_Provider $provider
     * @return array{configured: bool, missing: array, status: string, label: string}
     */
    public function check($provider) {
        $slug = $provider->get_slug();
        $labels = $this->get_field_labels($provider);

        $missing = [];
        foreach ($provider->get_required_config_keys() as $key) {
            if (empty(get_option("wpomni_{$slug}_{$key}", ''))) {
                $missing[$key] = $labels[$key] ?? $key;
            }
        }

        $configured = empty($missing);
        return [
            'configured' => $configured,
            'missing'    => $missing,
            'status'     => $configured ? 'ok' : 'incomplete',
            'label'      => $configured
                ? __('Configured', 'wp-omni-auth')
                : __('Not configured', 'wp-omni-auth'),
        ];
    }

    /**
     * Recompute the configuration status for every known provider and cache it.
     */
    public function refresh_cache() {
        $data = [];
        $manager = WPOmniAuth_Manager::instance();
        foreach ($manager->get_all_providers() as $slug => $provider) {
            $data[$slug] = $this->check($provider);
        }
        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * Get the configuration status for a provider.
     *
     * Returns a LIVE check against the stored options. A previously-cached
     * transient (`wpomni_provider_status`) is intentionally NOT used here: that
     * cache is only refreshed on save or by the hourly cron, so it routinely
     * goes stale and would make the dashboard / provider cards keep reporting
     * "Not configured" / "Incomplete" even right after a successful save.
     * Reading the options directly guarantees the status always reflects
     * reality. (refresh_cache() still maintains the transient for other
     * potential consumers, but display must never trust it.)
     *
     * @param string $slug
     * @return array{configured: bool, missing: array, status: string, label: string}|null
     */
    public function get_status($slug) {
        $provider = WPOmniAuth_Manager::instance()->get_provider($slug);
        if ($provider) {
            return $this->check($provider);
        }
        return null;
    }

    /**
     * Cron callback — refresh the cached statuses.
     */
    public function run_cron() {
        $this->refresh_cache();
    }

    /**
     * Schedule the periodic health-check cron (hourly). Call from Manager::activate().
     */
    public function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Clear the scheduled cron. Call from Manager::deactivate().
     */
    public function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Build a key => label map from the provider's declared settings fields.
     *
     * @param WPOmniAuth_Provider $provider
     * @return array<string, string>
     */
    private function get_field_labels($provider) {
        $labels = [];
        foreach ($provider->get_settings_fields() as $field) {
            if (isset($field['key'], $field['label'])) {
                $labels[$field['key']] = $field['label'];
            }
        }
        return $labels;
    }
}
