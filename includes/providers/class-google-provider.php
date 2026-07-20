<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Google_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('google', 'Google', '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>');
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
                'key'     => 'scope',
                'label'   => __('Scope', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'openid email profile',
                'class'   => 'regular-text',
            ],
        ];
    }


    public function get_authorization_url($state) {
        $params = [
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $this->get_redirect_uri(),
            'scope'         => $this->get_option('scope', 'openid email profile'),
            'response_type' => 'code',
            'state'         => $state,
        ];
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        $this->log('Google auth URL generated', ['redirect_uri' => $this->get_redirect_uri()]);
        return $url;
    }

    public function get_access_token($code) {
        $this->log('Google: Requesting access token', [
            'client_id'    => substr($this->get_client_id(), 0, 10) . '...',
            'redirect_uri' => $this->get_redirect_uri(),
        ]);

        $response = $this->remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        $this->log('Google: Token response', [
            'token_type' => $response['token_type'] ?? 'N/A',
            'expires_in' => $response['expires_in'] ?? 'N/A',
        ]);

        if (empty($response)) {
            $this->log('ERROR: Google token response is empty');
            return null;
        }

        if (isset($response['error'])) {
            $this->log('ERROR: Google token error', [
                'error'       => $response['error'],
                'description' => $response['error_description'] ?? '',
            ]);
            return null;
        }

        return $response['access_token'] ?? null;
    }

    public function get_user_data($access_token) {
        $this->log('Google: Requesting user data');

        $response = $this->remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);

        $this->log('Google: User data response', [
            'id'    => $response['id'] ?? 'N/A',
            'email' => $response['email'] ?? 'N/A',
        ]);

        return $response;
    }

    public function get_email_from_user_data($user_data) {
        $email = $user_data['email'] ?? '';
        if (!empty($email)) {
            $this->log('Google: Email extracted', ['email' => $email]);
        } else {
            $this->log('ERROR: No email in Google user data');
        }
        return $email;
    }

    private function get_redirect_uri() {
        return add_query_arg('wpomni_callback', $this->slug, wp_login_url());
    }

    public function get_button_color() {
        return '#ffffff';
    }

    public function get_button_text_color() {
        return '#3c4043';
    }

    public function get_button_border_color() {
        return '#909090';
    }

    private function log($message, $data = null) {
        WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
    }
}
