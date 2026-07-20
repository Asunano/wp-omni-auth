<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Wechat_Provider extends WPOmniAuth_Provider {
    private $current_openid = '';
    private $current_unionid = '';

    public function __construct() {
        parent::__construct('wechat','WeChat','<svg viewBox="0.98 2.11 20.04 16.43" width="16" height="16"><path fill="currentColor" d="M8.69 3C4.96 3 2 5.53 2 8.65c0 1.8.96 3.4 2.46 4.47l-.63 1.9 2.2-1.1c.79.23 1.62.35 2.47.35.17 0 .33-.01.5-.02A5.96 5.96 0 0 1 9 12.5C9 9.46 11.69 7 15 7c.17 0 .34.01.5.02C14.66 4.72 11.93 3 8.69 3zm-2.5 3.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm5 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM15 8c-2.76 0-5 2.01-5 4.5s2.24 4.5 5 4.5c.59 0 1.15-.09 1.68-.24l1.82.9-.51-1.54C19.23 14.97 20 13.57 20 12.5 20 10.01 17.76 8 15 8zm-2 3.5a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5zm4 0a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5z"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('AppID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text'],
            ['key'=>'client_secret','label'=>__('AppSecret','wp-omni-auth'),'type'=>'password','default'=>'','class'=>'regular-text'],
        ];
    }
    public function get_authorization_url($state) {
        return 'https://open.weixin.qq.com/connect/qrconnect?'.http_build_query(['appid'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'scope'=>'snsapi_login','state'=>$state,'response_type'=>'code']).'#wechat_redirect';
    }
    public function get_access_token($code) {
        $r=$this->remote_get('https://api.weixin.qq.com/sns/oauth2/access_token',['body'=>['appid'=>$this->get_client_id(),'secret'=>$this->get_client_secret(),'code'=>$code,'grant_type'=>'authorization_code']]);
        if(empty($r)||isset($r['errcode'])){return null;}
        // 微信在 token 响应中直接返回 openid / unionid，缓存以便后续使用
        $this->current_openid = $r['openid'] ?? '';
        $this->current_unionid = $r['unionid'] ?? '';
        return $r['access_token']??null;
    }
    public function get_user_data($access_token) {
        $openid=$this->current_openid;
        if(empty($openid)){return null;}
        return $this->remote_get('https://api.weixin.qq.com/sns/userinfo',['body'=>['access_token'=>$access_token,'openid'=>$openid,'lang'=>'zh_CN']]);
    }
    public function get_email_from_user_data($user_data) {
        // 微信不提供邮箱，用 unionid 或 openid 作为标识
        return '';
    }
    public function get_user_id_from_user_data($user_data) {
        // 微信的稳定标识:优先 unionid(同主体下互通),否则 openid
        return $this->current_unionid ?: $this->current_openid;
    }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    public function get_button_color() {
        return '#07C160';
    }
    public function get_button_text_color() {
        return '#ffffff';
    }
    public function get_button_border_color() {
        return '#07C160';
    }

    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
