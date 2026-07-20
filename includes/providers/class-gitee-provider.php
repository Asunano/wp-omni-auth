<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Gitee_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('gitee','Gitee','<svg viewBox="0 0 24 24" width="16" height="16" fill="#C71D23"><path d="M11.984 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.016 0zm6.09 5.333c.328 0 .593.266.592.593v1.482a.594.594 0 0 1-.593.592H9.777c-.982 0-1.778.796-1.778 1.778v5.63c0 .327.266.592.593.592h5.63c.982 0 1.778-.796 1.778-1.778v-.296a.593.593 0 0 0-.592-.593h-4.15a.592.592 0 0 1-.592-.592v-1.482a.593.593 0 0 1 .593-.592h6.815c.327 0 .593.265.593.592v3.408a4 4 0 0 1-4 4H5.926a.593.593 0 0 1-.593-.593V9.778a4.444 4.444 0 0 1 4.445-4.444h8.296z"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('Client ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text'],
            ['key'=>'client_secret','label'=>__('Client Secret','wp-omni-auth'),'type'=>'password','default'=>'','class'=>'regular-text'],
            ['key'=>'scope','label'=>__('Scope','wp-omni-auth'),'type'=>'text','default'=>'user_info','class'=>'regular-text','description'=>__('Available: user_info projects pull_requests issues notes keys hook groups gists enterprises','wp-omni-auth')],
        ];
    }
    public function get_authorization_url($state) {
        return 'https://gitee.com/oauth/authorize?'.http_build_query(['client_id'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'scope'=>$this->get_option('scope','user_info'),'response_type'=>'code','state'=>$state]);
    }
    public function get_access_token($code) {
        $r=$this->remote_post('https://gitee.com/oauth/token',['body'=>['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'client_secret'=>$this->get_client_secret()]]);
        if(empty($r)||isset($r['error'])){return null;}
        return $r['access_token']??null;
    }
    public function get_user_data($access_token) {
        return $this->remote_get('https://gitee.com/api/v5/user',['headers'=>['Authorization'=>'Bearer '.$access_token]]);
    }
    public function get_email_from_user_data($user_data) {
        return $user_data['email']??'';
    }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
