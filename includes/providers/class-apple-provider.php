<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Apple_Provider extends WPOmniAuth_Provider {
    private $id_token = '';

    public function __construct() {
        parent::__construct('apple','Apple','<svg viewBox="3.51 1.98 16.98 20.04" width="16" height="16"><path fill="currentColor" d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('Service ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text','description'=>__('Apple Developer → Identifiers → Service IDs','wp-omni-auth')],
            ['key'=>'team_id','label'=>__('Team ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text','description'=>__('10-character Team ID from Membership details.','wp-omni-auth')],
            ['key'=>'key_id','label'=>__('Key ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text','description'=>__('Key ID of the "Sign in with Apple" key.','wp-omni-auth')],
            ['key'=>'private_key','label'=>__('Private Key (.p8)','wp-omni-auth'),'type'=>'textarea','default'=>'','class'=>'large-text code','description'=>__('Paste the .p8 file contents (including BEGIN/END PRIVATE KEY).','wp-omni-auth')],
        ];
    }
    public function get_authorization_url($state) {
        return 'https://appleid.apple.com/auth/authorize?'.http_build_query(['client_id'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'scope'=>'name email','response_type'=>'code','response_mode'=>'form_post','state'=>$state]);
    }
    public function get_access_token($code) {
        $secret=$this->generate_client_secret();
        if(empty($secret)){return null;}
        $r=$this->remote_post('https://appleid.apple.com/auth/token',['body'=>['client_id'=>$this->get_client_id(),'client_secret'=>$secret,'code'=>$code,'redirect_uri'=>$this->get_redirect_uri(),'grant_type'=>'authorization_code']]);
        if(empty($r)||isset($r['error'])){return null;}
        // 保留 id_token 以提取稳定的 sub(用户唯一标识),用于绑定/匹配
        $this->id_token = $r['id_token'] ?? '';
        return $r['access_token']??null;
    }
    public function get_user_data($access_token) {
        // Apple doesn't provide a userinfo endpoint. Decode the id_token
        // (stored during get_access_token) to extract user data.
        if(empty($this->id_token)){return ['id'=>'','email'=>'','name'=>''];}
        $parts=explode('.', $this->id_token);
        if(count($parts)<2){return ['id'=>'','email'=>'','name'=>''];}
        $payload=rtrim(strtr($parts[1],'-_','+/'),'=');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $data=json_decode(base64_decode($payload), true);
        if(!is_array($data)){return ['id'=>'','email'=>'','name'=>''];}
        return [
            'id'    => $data['sub']??'',
            'email' => $data['email']??'',
            'name'  => $data['name']??'',
        ];
    }
    public function get_email_from_user_data($user_data) { return $user_data['email']??''; }
    public function get_user_id_from_user_data($user_data) {
        if(empty($this->id_token)){return '';}
        $parts=explode('.', $this->id_token);
        if(count($parts)<2){return '';}
        $payload=rtrim(strtr($parts[1],'-_','+/'),'=');
        // 补齐 base64 padding
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $data=json_decode(base64_decode($payload), true);
        return isset($data['sub']) ? $data['sub'] : '';
    }
    private function generate_client_secret() {
        $team=$this->get_option('team_id');$kid=$this->get_option('key_id');$key=$this->get_option('private_key');$sid=$this->get_client_id();
        if(empty($team)||empty($kid)||empty($key)||empty($sid)){return '';}
        $now=time();$h=rtrim(strtr(base64_encode(wp_json_encode(['alg'=>'ES256','kid'=>$kid])),'+/','-_'),'=');
        $p=rtrim(strtr(base64_encode(wp_json_encode(['iss'=>$team,'iat'=>$now,'exp'=>$now+15777000,'aud'=>'https://appleid.apple.com','sub'=>$sid])),'+/','-_'),'=');
        $u=$h.'.'.$p;$pk=openssl_pkey_get_private($key);if($pk===false){return '';}$s='';openssl_sign($u,$s,$pk,'SHA256');openssl_free_key($pk);
        return $u.'.'.rtrim(strtr(base64_encode($s),'+/','-_'),'=');
    }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    public function get_button_color() {
        return '#000000';
    }

    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
