<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Microsoft_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('microsoft', 'Microsoft', '<svg viewBox="0 0 21 21" width="16" height="16"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('Application (client) ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text'],
            ['key'=>'client_secret','label'=>__('Client Secret','wp-omni-auth'),'type'=>'password','default'=>'','class'=>'regular-text'],
            ['key'=>'tenant_id','label'=>__('Tenant ID','wp-omni-auth'),'type'=>'text','default'=>'common','class'=>'regular-text',
             'description'=>__('Use "common" for personal + organizational, "consumers" for personal only, "organizations" for work/school only, or a specific Tenant ID.','wp-omni-auth')],
            ['key'=>'scope','label'=>__('Scope','wp-omni-auth'),'type'=>'text','default'=>'openid email profile','class'=>'regular-text'],
        ];
    }
    public function get_authorization_url($state) {
        $tenant = $this->get_option('tenant_id','common');
        $params = ['client_id'=>$this->get_client_id(),'redirect_uri'=>$this->get_redirect_uri(),'scope'=>$this->get_option('scope','openid email profile'),'response_type'=>'code','state'=>$state,'response_mode'=>'query'];
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize?',$tenant).http_build_query($params);
    }
    public function get_access_token($code) {
        $tenant = $this->get_option('tenant_id','common');
        $response = $this->remote_post(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token',$tenant),['body'=>['client_id'=>$this->get_client_id(),'client_secret'=>$this->get_client_secret(),'code'=>$code,'redirect_uri'=>$this->get_redirect_uri(),'grant_type'=>'authorization_code']]);
        if(empty($response)||isset($response['error'])){return null;}
        return $response['access_token']??null;
    }
    public function get_user_data($access_token) {
        return $this->remote_get('https://graph.microsoft.com/v1.0/me',['headers'=>['Authorization'=>'Bearer '.$access_token]]);
    }
    public function get_email_from_user_data($user_data) {
        $email = $user_data['mail']??'';
        if(empty($email)){$email=$user_data['userPrincipalName']??'';}
        return (strpos($email,'@')!==false)?$email:'';
    }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    public function get_button_color() {
        return '#ffffff';
    }
    public function get_button_text_color() {
        return '#5e5e5e';
    }
    public function get_button_border_color() {
        return '#8a8a8a';
    }

    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
