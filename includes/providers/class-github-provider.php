<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Github_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('github', 'GitHub', '<svg viewBox="0 0 16 16" width="16" height="16"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>');
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
                'default' => 'user:email',
                'class'   => 'regular-text',
            ],
        ];
    }


    public function get_authorization_url($state) {
        $params = [
            'client_id'    => $this->get_client_id(),
            'redirect_uri' => $this->get_redirect_uri(),
            'scope'        => $this->get_option('scope', 'user:email'),
            'state'        => $state,
        ];
        $url = 'https://github.com/login/oauth/authorize?' . http_build_query($params);
        $this->log('GitHub auth URL generated', ['redirect_uri' => $this->get_redirect_uri()]);
        return $url;
    }

    public function get_access_token($code) {
        $this->log('GitHub: Requesting access token', [
            'client_id' => substr($this->get_client_id(), 0, 10) . '...',
            'redirect_uri' => $this->get_redirect_uri(),
        ]);

        $response = $this->remote_post('https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json'],
            'body'    => [
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'code'          => $code,
                'redirect_uri'  => $this->get_redirect_uri(),
            ],
        ]);

        $this->log('GitHub: Token response', [
            'token_type' => $response['token_type'] ?? 'N/A',
            'scope'      => $response['scope'] ?? 'N/A',
        ]);

        if (empty($response)) {
            $this->log('ERROR: GitHub token response is empty');
            return null;
        }

        if (isset($response['error'])) {
            $this->log('ERROR: GitHub token error', [
                'error' => $response['error'],
                'error_description' => $response['error_description'] ?? '',
            ]);
            return null;
        }

        return $response['access_token'] ?? null;
    }

    public function get_user_data($access_token) {
        $this->log('GitHub: Requesting user data');

        $response = $this->remote_get('https://api.github.com/user', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => 'WP-OmniAuth',
            ],
        ]);

        $this->log('GitHub: User data response', [
            'login' => $response['login'] ?? 'N/A',
            'id' => $response['id'] ?? 'N/A',
            'email' => $response['email'] ?? 'N/A',
        ]);

        return $response;
    }

    public function get_email_from_user_data($user_data) {
        $email = $user_data['email'] ?? '';

        if (!empty($email)) {
            $this->log('GitHub: Email from user data', ['email' => $email]);
            return $email;
        }

        $this->log('GitHub: Email not in user data, fetching emails list');

        $access_token = $user_data['_access_token'] ?? '';
        if (empty($access_token)) {
            $this->log('ERROR: No access token available for email request');
            return '';
        }

        $emails = $this->remote_get('https://api.github.com/user/emails', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => 'WP-OmniAuth',
            ],
        ]);

        $this->log('GitHub: Emails API response', ['emails' => $emails]);

        if (!is_array($emails)) {
            $this->log('ERROR: Emails API returned non-array');
            return '';
        }

        foreach ($emails as $em) {
            if (!empty($em['primary']) && !empty($em['verified'])) {
                $email = $em['email'];
                $this->log('GitHub: Found primary verified email', ['email' => $email]);
                break;
            }
        }

        if (empty($email)) {
            $this->log('ERROR: No primary verified email found');
        }

        return $email;
    }

    private function get_redirect_uri() {
        return add_query_arg('wpomni_callback', $this->slug, wp_login_url());
    }

    public function get_button_color() {
        return '#24292e';
    }

    public function get_button_text_color() {
        return '#ffffff';
    }

    public function get_button_border_color() {
        return '#24292e';
    }

    private function log($message, $data = null) {
        WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
    }
}
