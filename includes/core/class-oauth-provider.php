<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class WPOmniAuth_Provider {
    protected $slug;
    protected $name;
    protected $icon;

    abstract public function get_authorization_url($state);
    abstract public function get_access_token($code);
    abstract public function get_user_data($access_token);
    abstract public function get_email_from_user_data($user_data);

    /**
     * Extract the provider's stable, unique user identifier from the user data.
     *
     * This is the value that gets stored in the `wpomni_{slug}_id` user meta
     * and used to match a returning user WITHOUT relying on email (so a user
     * whose OAuth provider returns a different email than their WP account can
     * still be recognized). The default returns `$user_data['id']`, which works
     * for most providers. Providers whose stable identifier lives elsewhere
     * (QQ/WeChat openid, Apple `sub`, etc.) should override this.
     *
     * @param array $user_data User data returned by get_user_data().
     * @return string Stable provider-side user ID, or '' if unavailable.
     */
    public function get_user_id_from_user_data($user_data) {
        return $user_data['id'] ?? $user_data['sub'] ?? $user_data['openid'] ?? $user_data['unionid'] ?? '';
    }

    /**
     * Declare settings fields for this provider.
     * Used by the Settings Page to dynamically register and render the form.
     *
     * Field types: toggle, text, password, url, select
     *
     * @return array[]
     */
    public function get_settings_fields() {
        return [];
    }

    /**
     * Declare the option keys that must be filled for this provider to work.
     * The configuration checker (WPOmniAuth_Provider_Checker) reads this to
     * decide whether a provider is fully configured. Subclasses may override
     * (e.g. custom providers add their endpoint URLs).
     *
     * @return string[] Option key suffixes, e.g. ['client_id', 'client_secret']
     */
    public function get_required_config_keys() {
        return ['client_id', 'client_secret'];
    }

    /**
     * Return setup guide for this provider.
     * Override in subclasses to provide provider-specific instructions.
     *
     * @param string $callback_url The OAuth callback URL for this provider
     * @return array{steps: string[], notes: string[]}
     */
    public function get_setup_guide($callback_url) {
        return [];
    }

    /**
     * Brand background color for this provider's login button.
     *
     * Return a hex color (e.g. '#24292e') to paint the button with the
     * provider's brand color. Returning an empty string falls back to the
     * site's admin theme color. Built-in providers override this with their
     * brand color; custom providers read it from the "Button Color" setting.
     *
     * @return string Hex color or empty string.
     */
    public function get_button_color() {
        return '';
    }

    /**
     * Optional explicit text color for the login button.
     *
     * Most providers should return '' so the manager auto-picks a readable
     * color (black/white) from the background. Only override when the brand
     * demands a specific text color (e.g. Google's dark text on a white button).
     *
     * @return string Hex color or empty string (auto).
     */
    public function get_button_text_color() {
        return '';
    }

    /**
     * Optional explicit border color for the login button.
     *
     * Returning '' reuses the background color as the border. Override only
     * when the brand button needs a distinct border (e.g. Google's light grey
     * border on a white button).
     *
     * @return string Hex color or empty string (reuse background).
     */
    public function get_button_border_color() {
        return '';
    }

    /**
     * Sanitize callback for URL fields — requires HTTPS.
     */
    public function sanitize_url($value) {
        if (empty($value)) {
            return '';
        }
        $value = esc_url_raw(trim($value));
        if (empty($value)) {
            return '';
        }
        if (strpos($value, 'https://') !== 0) {
            add_settings_error('wpomni_settings', 'url_not_https',
                __('OAuth endpoints must use HTTPS for security.', 'wp-omni-auth'));
            return '';
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            add_settings_error('wpomni_settings', 'url_invalid',
                __('Invalid URL format.', 'wp-omni-auth'));
            return '';
        }
        return $value;
    }

    /**
     * Sanitize callback for secret fields — preserve existing value when empty.
     */
    public function sanitize_secret($value) {
        if (empty($value)) {
            return get_option("wpomni_{$this->slug}_client_secret", '');
        }
        return $value;
    }

    public function __construct($slug, $name, $icon) {
        $this->slug = $slug;
        $this->name = $name;
        $this->icon = $icon;
    }

    public function get_slug() {
        return $this->slug;
    }

    public function get_name() {
        return __($this->name, 'wp-omni-auth');
    }

    public function get_icon() {
        return $this->icon;
    }

    public function is_enabled() {
        return get_option("wpomni_{$this->slug}_enabled", 'no') === 'yes';
    }

    protected function get_client_id() {
        return get_option("wpomni_{$this->slug}_client_id", '');
    }

    protected function get_client_secret() {
        return get_option("wpomni_{$this->slug}_client_secret", '');
    }

    protected function get_option($key, $default = '') {
        return get_option("wpomni_{$this->slug}_{$key}", $default);
    }

    protected function remote_post($url, $args) {
        if (!$this->is_url_allowed($url)) {
            return null;
        }
        $args = wp_parse_args($args, ['timeout' => 15, 'sslverify' => true]);
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    protected function remote_get($url, $args) {
        if (!$this->is_url_allowed($url)) {
            return null;
        }
        $args = wp_parse_args($args, ['timeout' => 15, 'sslverify' => true]);
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Reject requests to non-public hosts (loopback / private / reserved
     * ranges) to mitigate SSRF via a maliciously configured custom provider
     * endpoint. Public https endpoints pass; anything resolving to an internal
     * address is blocked.
     *
     * @param string $url
     * @return bool
     */
    protected function is_url_allowed($url) {
        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
            return false;
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }
        $addr = @gethostbyname($host);
        if ($addr === $host) {
            // DNS resolution failed — don't guess.
            return false;
        }
        if (!function_exists('filter_var')) {
            return true;
        }
        return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
