<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Authentik_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('authentik','Authentik','<svg viewBox="0 0 24 24" width="16" height="16" fill="#FD4B2D"><path d="M13.96 9.01h-.84V7.492h-1.234v3.663H5.722c.34.517.538.982.538 1.152 0 .46-1.445 3.059-3.197 3.059C.8 15.427-.745 12.8.372 10.855a3.062 3.062 0 0 1 2.691-1.606c1.04 0 1.971.915 2.557 1.755V6.577a3.773 3.773 0 0 1 3.77-3.769h10.84C22.31 2.808 24 4.5 24 6.577v10.845a3.773 3.773 0 0 1-3.77 3.769h-1.6V17.5h-7.64v3.692h-1.6a3.773 3.773 0 0 1-3.77-3.769v-3.41h12.114v-6.52h-1.59v.893h-.84v-.893H13.96v1.516Zm-9.956 1.845c-.662-.703-1.578-.544-2.209 0-2.105 2.054 1.338 5.553 3.302 1.447a5.395 5.395 0 0 0-1.093-1.447Z"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('Client ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text'],
            ['key'=>'client_secret','label'=>__('Client Secret','wp-omni-auth'),'type'=>'password','default'=>'','class'=>'regular-text'],
            ['key'=>'base_url','label'=>__('Authentik URL','wp-omni-auth'),'type'=>'url','default'=>'','class'=>'regular-text','placeholder'=>'https://auth.example.com','description'=>__('Your Authentik instance URL (no trailing slash).','wp-omni-auth')],
            ['key'=>'scope','label'=>__('Scope','wp-omni-auth'),'type'=>'text','default'=>'openid email profile','class'=>'regular-text'],
        ];
    }
    public function get_authorization_url($state) {
        $base=rtrim($this->get_option('base_url'),'/');
        return $base.'/application/o/authorize/?'.http_build_query(['client_id'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'scope'=>$this->get_option('scope','openid email profile'),'response_type'=>'code','state'=>$state]);
    }
    public function get_access_token($code) {
        $base=rtrim($this->get_option('base_url'),'/');
        $r=$this->remote_post($base.'/application/o/token/',['body'=>['client_id'=>$this->get_client_id(),'client_secret'=>$this->get_client_secret(),'code'=>$code,'redirect_uri'=>$this->get_redirect_uri(),'grant_type'=>'authorization_code']]);
        if(empty($r)||isset($r['error'])){return null;}
        return $r['access_token']??null;
    }
    public function get_user_data($access_token) {
        $base=rtrim($this->get_option('base_url'),'/');
        return $this->remote_get($base.'/application/o/userinfo/',['headers'=>['Authorization'=>'Bearer '.$access_token]]);
    }
    public function get_email_from_user_data($user_data) { return $user_data['email']??''; }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
