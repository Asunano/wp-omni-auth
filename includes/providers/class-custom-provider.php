<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Custom_Provider extends WPOmniAuth_Provider {
    public function __construct($slug, $name = '', $icon = '') {
        $stored_name = get_option("wpomni_{$slug}_name", $name);
        $stored_icon = get_option("wpomni_{$slug}_icon", $icon);
        parent::__construct($slug, $stored_name ?: $slug, $stored_icon ?: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>');
    }

    public function get_settings_fields() {
        return [
            [
                'key'     => 'client_id',
                'label'   => __('Client ID', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => '',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'client_secret',
                'label'   => __('Client Secret', 'wp-omni-auth'),
                'type'    => 'password',
                'default' => '',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'color',
                'label'   => __('Button Color', 'wp-omni-auth'),
                'type'    => 'color',
                'default' => '',
                'description' => __('Color of this provider’s button on the login screen. Leave blank to use the site’s admin theme color. Text color is chosen automatically for contrast.', 'wp-omni-auth'),
            ],
            [
                'key'     => 'authorization_url',
                'label'   => __('Authorization URL', 'wp-omni-auth'),
                'type'    => 'url',
                'default' => '',
                'class'   => 'large-text',
                'placeholder' => 'https://',
            ],
            [
                'key'     => 'token_url',
                'label'   => __('Token URL', 'wp-omni-auth'),
                'type'    => 'url',
                'default' => '',
                'class'   => 'large-text',
                'placeholder' => 'https://',
            ],
            [
                'key'     => 'userinfo_url',
                'label'   => __('User Info URL', 'wp-omni-auth'),
                'type'    => 'url',
                'default' => '',
                'class'   => 'large-text',
                'placeholder' => 'https://',
            ],
            [
                'key'     => 'email_field',
                'label'   => __('Email Field', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'email',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'scope',
                'label'   => __('Scope', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'openid email profile',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'token_in_header',
                'label'   => __('Token in Header', 'wp-omni-auth'),
                'type'    => 'toggle',
                'default' => 'yes',
                'description' => __('Send access token in Authorization header (Bearer)', 'wp-omni-auth'),
            ],
            [
                'key'     => 'token_key',
                'label'   => __('Token Response Key', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'access_token',
                'class'   => 'regular-text',
                'description' => __('The key in the token response that contains the access token.', 'wp-omni-auth'),
            ],
        ];
    }

    /**
     * Custom providers also need their OAuth endpoint URLs filled in.
     */
    public function get_required_config_keys() {
        return ['client_id', 'client_secret', 'authorization_url', 'token_url', 'userinfo_url'];
    }


    public function get_authorization_url($state) {
        $auth_url = $this->get_option('authorization_url');
        if (empty($auth_url)) {
            $this->log('ERROR: Authorization URL is empty');
            return '';
        }
        $params = [
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $this->get_redirect_uri(),
            'scope'         => $this->get_option('scope', 'openid email profile'),
            'response_type' => 'code',
            'state'         => $state,
        ];
        $url = $auth_url . '?' . http_build_query($params);
        $this->log('Auth URL generated', ['redirect_uri' => $this->get_redirect_uri()]);
        return $url;
    }

    public function get_access_token($code) {
        $token_url = $this->get_option('token_url');
        if (empty($token_url)) {
            $this->log('ERROR: Token URL is empty');
            return null;
        }

        $this->log('Requesting access token', [
            'client_id'    => substr($this->get_client_id(), 0, 10) . '...',
            'redirect_uri' => $this->get_redirect_uri(),
        ]);

        $response = $this->remote_post($token_url, [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (empty($response)) {
            // remote_post returns null when JSON decode fails.
            // Some providers (e.g. QQ) return URL-encoded responses.
            // Fall back to raw HTTP response and try parse_str.
            $raw = $this->raw_post($token_url, $code);
            if (!empty($raw)) {
                $this->log('Token response parsed from URL-encoded format');
                return $raw;
            }
            $this->log('ERROR: Token response is empty');
            return null;
        }

        if (isset($response['error'])) {
            $this->log('ERROR: Token error', [
                'error'       => $response['error'],
                'description' => $response['error_description'] ?? '',
            ]);
            return null;
        }

        $token_key = $this->get_option('token_key', 'access_token');
        $token = $response[$token_key] ?? null;
        $this->log('Token response received', ['token_key' => $token_key, 'has_token' => !empty($token)]);
        return $token;
    }

    /**
     * Fallback: send a raw POST and parse URL-encoded response.
     * Some OAuth providers (e.g. QQ) return access_token=xxx&expires_in=3600
     * instead of JSON.
     */
    private function raw_post($url, $code) {
        $args = [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ];
        if (!$this->is_url_allowed($url)) {
            return null;
        }
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }
        $data = [];
        parse_str($body, $data);
        $token_key = $this->get_option('token_key', 'access_token');
        return $data[$token_key] ?? null;
    }

    public function get_user_data($access_token) {
        $userinfo_url = $this->get_option('userinfo_url');
        if (empty($userinfo_url)) {
            $this->log('ERROR: User Info URL is empty');
            return [];
        }

        $this->log('Requesting user data', ['token_in_header' => $this->get_option('token_in_header', 'yes')]);

        $token_in_header = $this->get_option('token_in_header', 'yes');
        if ($token_in_header === 'yes') {
            $response = $this->remote_get($userinfo_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                ],
            ]);
        } else {
            $separator = strpos($userinfo_url, '?') === false ? '?' : '&';
            $response = $this->remote_get($userinfo_url . $separator . 'access_token=' . urlencode($access_token), []);
        }

        $this->log('User data response received', ['has_data' => !empty($response)]);
        return $response;
    }

    public function get_email_from_user_data($user_data) {
        if (!is_array($user_data)) {
            $this->log('ERROR: User data is not an array');
            return '';
        }
        $email_field = $this->get_option('email_field', 'email');
        // Support dot-notation for nested fields (e.g. "user.email").
        $keys = explode('.', $email_field);
        $email = $user_data;
        foreach ($keys as $k) {
            if (!is_array($email) || !isset($email[$k])) {
                $email = null;
                break;
            }
            $email = $email[$k];
        }
        if (empty($email) && is_string($email_field) && strpos($email_field, '.') === false) {
            // Fallback: flat field lookup, then nested under 'user'.
            $email = $user_data[$email_field] ?? '';
            if (empty($email) && !empty($user_data['user'])) {
                $email = $user_data['user'][$email_field] ?? '';
            }
        }
        if (!empty($email) && is_string($email)) {
            $this->log('Email extracted', ['email' => $email, 'field' => $email_field]);
        } else {
            $this->log('ERROR: No email found', ['field' => $email_field]);
        }
        return is_string($email) ? $email : '';
    }

    private function get_redirect_uri() {
        return add_query_arg('wpomni_callback', $this->slug, wp_login_url());
    }

    /**
     * Custom providers read their login-button color from the "Button Color"
     * setting (wpomni_{slug}_color). An empty value means "use the site's
     * admin theme color".
     */
    public function get_button_color() {
        return get_option("wpomni_{$this->slug}_color", '');
    }

    private function log($message, $data = null) {
        WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
    }
}
