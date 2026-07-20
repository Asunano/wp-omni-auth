<?php
if (!defined('ABSPATH')) { exit; }
// Rename to class-qq-provider.php to re-enable this provider.
class WPOmniAuth_QQ_Provider extends WPOmniAuth_Provider {
    private $current_openid = '';

    public function __construct() {
        parent::__construct('qq', 'QQ', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" width="16" height="16" fill="#12B7F5"><path d="M433.754 420.445c-11.526 1.393-44.86-52.741-44.86-52.741 0 31.345-16.136 72.247-51.051 101.786 16.842 5.192 54.843 19.167 45.803 34.421-7.316 12.343-125.51 7.881-159.632 4.037-34.122 3.844-152.316 8.306-159.632-4.037-9.045-15.25 28.918-29.214 45.783-34.415-34.92-29.539-51.059-70.445-51.059-101.792 0 0-33.334 54.134-44.859 52.741-5.37-.65-12.424-29.644 9.347-99.704 10.261-33.024 21.995-60.478 40.144-105.779C60.683 98.063 108.982.006 224 0c113.737.006 163.156 96.133 160.264 214.963 18.118 45.223 29.912 72.85 40.144 105.778 21.768 70.06 14.716 99.053 9.346 99.704z"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key' => 'client_id', 'label' => __('App ID', 'wp-omni-auth'), 'type' => 'text', 'default' => '', 'class' => 'regular-text'],
            ['key' => 'client_secret', 'label' => __('App Key', 'wp-omni-auth'), 'type' => 'password', 'default' => '', 'class' => 'regular-text'],
        ];
    }
    public function get_authorization_url($state) {
        return 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $this->get_redirect_uri(),
            'state'         => $state,
            'scope'         => 'get_user_info',
        ]);
    }
    public function get_access_token($code) {
        $response = wp_remote_post('https://graph.qq.com/oauth2.0/token', [
            'body' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'code'          => $code,
                'redirect_uri'  => $this->get_redirect_uri(),
            ],
        ]);
        if (is_wp_error($response)) { return null; }
        // 响应格式: access_token=...&expires_in=...&refresh_token=...
        parse_str(wp_remote_retrieve_body($response), $data);
        if (empty($data['access_token'])) { return null; }

        $me = wp_remote_get('https://graph.qq.com/oauth2.0/me?access_token=' . rawurlencode($data['access_token']));
        if (!is_wp_error($me)) {
            $me_body = wp_remote_retrieve_body($me);
            // 返回: callback( {"client_id":"...","openid":"..."} );
            if (preg_match('/callback\(\s*(\{.*\})\s*\)/s', $me_body, $m)) {
                $json = json_decode($m[1], true);
                if (!empty($json['openid'])) {
                    $this->current_openid = $json['openid'];
                }
            }
        }
        return $data['access_token'];
    }
    public function get_user_data($access_token) {
        if (empty($this->current_openid)) { return null; }
        $url = 'https://graph.qq.com/user/get_user_info?' . http_build_query([
            'access_token'        => $access_token,
            'oauth_consumer_key'  => $this->get_client_id(),
            'openid'              => $this->current_openid,
        ]);
        $r = $this->remote_get($url);
        if (is_array($r)) {
            // 资料接口不含 openid，补上以便按 provider+id 匹配
            $r['id'] = $this->current_openid;
        }
        return $r;
    }
    public function get_email_from_user_data($user_data) {
        $id = $this->current_openid ?: ($user_data['openid'] ?? '');
        if (empty($id)) { return ''; }
        return 'qq_' . $id . '@oauth.wpomni.local';
    }
    public function get_user_id_from_user_data($user_data) {
        // QQ's stable identifier is the openid captured during token exchange.
        return $this->current_openid ?: ($user_data['id'] ?? ($user_data['openid'] ?? ''));
    }
    private function get_redirect_uri() { return add_query_arg('wpomni_callback', $this->slug, wp_login_url()); }
    public function get_button_color() {
        return '#12B7F5';
    }

    private function log($m, $d = null) { WPOmniAuth_Manager::debug_log($m, $d, $this->get_name()); }
}
