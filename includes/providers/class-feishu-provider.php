<?php
if (!defined('ABSPATH')) { exit; }
class WPOmniAuth_Feishu_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct('feishu','Feishu','<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="16" height="16"><path d="M0 0 C42.24 0 84.48 0 128 0 C128 42.24 128 84.48 128 128 C85.76 128 43.52 128 0 128 C0 85.76 0 43.52 0 0 Z " fill="#FDFEFE" transform="translate(0,0)"/><g transform="translate(12,0)"><path d="M0 0 C6.73 -0.18 13.45 -0.3 20.18 -0.38 C22.47 -0.42 24.75 -0.47 27.04 -0.53 C30.33 -0.61 33.62 -0.65 36.91 -0.68 C37.93 -0.72 38.95 -0.76 40 -0.79 C46.96 -0.8 46.96 -0.8 49.95 1.5 C51.5 3.26 52.78 5 54 7 C54.49 7.79 54.49 7.79 54.99 8.59 C55.83 10.05 56.58 11.55 57.31 13.06 C58.05 14.53 58.05 14.53 58.8 16.04 C60.02 19.05 60.59 21.78 61 25 C61.52 24.84 62.04 24.68 62.58 24.52 C70.85 22.76 79.4 23.35 87 27 C75.28 50.47 64.12 72.34 37.69 81.44 C22.87 85.62 5.69 84.72 -7.86 77.47 C-11.21 75.54 -14.26 73.74 -17 71 C-17.24 69.01 -17.24 69.01 -17.23 66.55 C-17.23 65.63 -17.23 64.71 -17.23 63.76 C-17.22 62.76 -17.21 61.77 -17.2 60.74 C-17.19 59.72 -17.19 58.71 -17.19 57.66 C-17.18 54.4 -17.15 51.14 -17.12 47.88 C-17.11 45.67 -17.11 43.46 -17.1 41.25 C-17.08 35.84 -17.04 30.42 -17 25 C-13.89 26.48 -12 27.88 -9.69 30.44 C-0.04 40.29 11.5 47.36 24 53 C24.69 52.51 25.37 52.01 26.08 51.5 C26.98 50.86 27.88 50.22 28.81 49.56 C30.15 48.61 30.15 48.61 31.52 47.63 C34 46 34 46 37 45 C29.14 29.35 16.09 12.32 1.18 2.84 C0.6 2.43 0.6 2.43 0 2 C0 1.34 0 0.68 0 0 Z " fill="#3570FE" transform="translate(31,24)"/><path d="M0 0 C6.73 -0.18 13.45 -0.3 20.18 -0.38 C22.47 -0.42 24.75 -0.47 27.04 -0.53 C30.33 -0.61 33.62 -0.65 36.91 -0.68 C37.93 -0.72 38.95 -0.76 40 -0.79 C46.96 -0.8 46.96 -0.8 49.95 1.5 C51.5 3.26 52.78 5 54 7 C54.33 7.52 54.66 8.04 54.99 8.58 C58.23 14.11 60.71 19.57 61 26 C60.54 26.18 60.08 26.37 59.6 26.56 C54.01 29.05 49.93 32.5 45.49 36.68 C42.74 39.24 39.9 41.62 37 44 C36.38 43.04 35.77 42.09 35.13 41.11 C25.76 26.75 15.69 13.56 1.65 3.41 C1.11 2.94 0.56 2.48 0 2 C0 1.34 0 0.68 0 0 Z " fill="#02D5B9" transform="translate(31,24)"/><path d="M0 0 C-2.22 4.46 -4.51 8.89 -6.81 13.31 C-7.24 14.14 -7.66 14.97 -8.1 15.83 C-15.37 29.75 -15.37 29.75 -21.56 32.88 C-22.31 33.26 -23.05 33.65 -23.82 34.05 C-33.16 38.1 -41.28 35.31 -50.44 32.12 C-51.55 31.76 -52.66 31.39 -53.77 31.02 C-61.86 28.27 -61.86 28.27 -63 26 C-62.39 25.6 -61.79 25.2 -61.16 24.79 C-53.71 19.73 -47.21 13.96 -40.65 7.79 C-29.19 -2.79 -14.62 -7.02 0 0 Z " fill="#133D9A" transform="translate(118,51)"/></g></svg>');
    }
    public function get_settings_fields() {
        return [
            ['key'=>'client_id','label'=>__('App ID','wp-omni-auth'),'type'=>'text','default'=>'','class'=>'regular-text','description'=>__('Feishu Open Platform → App Details → Credentials & Basic Info → App ID','wp-omni-auth')],
            ['key'=>'client_secret','label'=>__('App Secret','wp-omni-auth'),'type'=>'password','default'=>'','class'=>'regular-text'],
            ['key'=>'region','label'=>__('Region','wp-omni-auth'),'type'=>'select','default'=>'cn','options'=>['cn'=>__('China (feishu.cn)','wp-omni-auth'),'intl'=>__('International (larksuite.com)','wp-omni-auth')]],
        ];
    }
    public function get_authorization_url($state) {
        $region=$this->get_option('region','cn');
        $accounts=($region==='intl')?'https://accounts.larksuite.com':'https://accounts.feishu.cn';
        return $accounts.'/open-apis/authen/v1/authorize?'.http_build_query([
            'client_id'=>$this->get_client_id(),
            'redirect_uri'=>$this->get_redirect_uri(),
            'response_type'=>'code',
            'scope'=>'contact:contact.base:readonly',
            'state'=>$state,
        ]);
    }
    public function get_access_token($code) {
        $region=$this->get_option('region','cn');
        $base=($region==='intl')?'https://open.larksuite.com':'https://open.feishu.cn';
        $r=$this->remote_post($base.'/open-apis/authen/v2/oauth/token',[
            'headers'=>['Content-Type'=>'application/json; charset=utf-8'],
            'body'=>wp_json_encode([
                'grant_type'=>'authorization_code',
                'client_id'=>$this->get_client_id(),
                'client_secret'=>$this->get_client_secret(),
                'code'=>$code,
                'redirect_uri'=>$this->get_redirect_uri(),
            ]),
        ]);
        if(empty($r)||($r['code']??-1)!==0){$this->log('ERROR: Failed to get user_access_token');return null;}
        return $r['data']['access_token']??null;
    }
    public function get_user_data($access_token) {
        $region=$this->get_option('region','cn');
        $base=($region==='intl')?'https://open.larksuite.com':'https://open.feishu.cn';
        $r=$this->remote_get($base.'/open-apis/authen/v1/user_info',['headers'=>['Authorization'=>'Bearer '.$access_token,'Content-Type'=>'application/json; charset=utf-8']]);
        if(!empty($r)&&($r['code']??-1)===0){$d=$r['data']??[];$d['id']=$d['open_id']??$d['user_id']??'';return $d;}
        return null;
    }
    public function get_email_from_user_data($user_data) { return $user_data['email']??''; }
    private function get_redirect_uri(){return add_query_arg('wpomni_callback',$this->slug,wp_login_url());}
    private function log($m,$d=null){WPOmniAuth_Manager::debug_log($m,$d,$this->get_name());}
}
