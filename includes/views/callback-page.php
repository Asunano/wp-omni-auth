<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?> - <?php bloginfo('name'); ?></title>
<?php if ($is_success) : ?>
<meta http-equiv="refresh" content="0.5;url=<?php echo $safe_redirect; ?>">
<script>
document.addEventListener("DOMContentLoaded",function(){
    setTimeout(function(){window.location.replace(<?php echo wp_json_encode($safe_redirect); ?>);},500);
});
</script>
<?php endif; ?>
<script>
document.addEventListener("DOMContentLoaded",function(){
    setTimeout(function(){
        var btn=document.querySelector(".link");
        if(btn){btn.classList.add("visible");
            var area=btn.closest(".redirect-area");
            if(area)area.classList.add("has-visible");
        }
    },5000);
});
</script>
<?php
// Let WordPress control the page background — same approach as the OAuth login
// screen: WP's login.min.css paints `body` with the WP login background, so we
// do NOT hardcode a colour. We load ONLY WP's (small) login stylesheet here and
// intentionally skip the plugin's own login-styles.css — the callback card uses
// its own inline <style>, and avoiding that extra render-blocking request
// removes the ~1s paint delay the full login <head> introduced on the callback.
// The 'login' stylesheet is normally registered later (on the login page); the
// callback runs early on `init`, so register it here first if not yet available.
if ( function_exists( 'wp_styles' ) ) {
    $wp_styles = wp_styles();
    if ( $wp_styles && ! $wp_styles->query( 'login', 'registered' ) ) {
        wp_register_style( 'login', admin_url( 'css/login.min.css' ), array(), null );
    }
}
wp_enqueue_style( 'login' );
do_action( 'login_head' );
wp_print_styles();
?>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --card-bg:#fff;--text:#1d2327;--text-secondary:#646970;
  --border:#dcdcde;--ok:#00a32a;--ok-bg:#edfaef;--err:#d63638;--err-bg:#fcf0f1;
  --link:var(--wp-admin-theme-color, #2271b1);--link-hover:var(--wp-admin-theme-color-darker-10, #135e96);--shadow:rgba(0,0,0,0.08);
}
@media(prefers-color-scheme:dark){:root{
  --card-bg:#2c3338;--text:#f0f0f1;--text-secondary:#a7aaad;
  --border:#50575e;--ok:#68de7a;--ok-bg:rgba(104,222,122,0.1);--err:#f07171;--err-bg:rgba(240,113,113,0.1);
  --link:#72aee6;--link-hover:#4ec0e8;--shadow:rgba(0,0,0,0.3);
}}
body.login{
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
  color:var(--text);padding:18px;
}
.card{
  background:var(--card-bg);border:1px solid var(--border);border-radius:12px;
  box-shadow:0 3px 18px var(--shadow);max-width:594px;
  width:100%;padding:40px 46px;text-align:center;
  display:flex;flex-direction:column;
  animation:card-in .5s cubic-bezier(.23,1,.32,1) both;
}
@keyframes card-in{
  from{opacity:0;transform:translateY(12px);filter:blur(6px)}
  to{opacity:1;transform:translateY(0);filter:blur(0)}
}
.status-group{display:flex;align-items:center;justify-content:center;gap:11px;margin-bottom:18px}
.status-icon{width:20px;height:20px;flex-shrink:0}
.status-icon.ok{color:var(--ok)}
.status-icon.err{color:var(--err)}
.status-title{font-size:20px;font-weight:600;color:var(--text)}
.provider-icon{display:flex;justify-content:center;margin-top:-10px;margin-bottom:10px;animation:wpomni-redirect-pulse 1.6s ease-in-out infinite}
.provider-icon svg,.provider-icon img{width:80px;height:80px;opacity:0.85}
/* Pulse matches the login screen's .wpomni-redirect-icon (login-styles.css). */
@keyframes wpomni-redirect-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
.user-name{font-size:25px;font-weight:600;margin-bottom:18px;color:var(--text)}
.welcome-text{font-size:25px;font-weight:600;color:var(--text)}
.redirect-area{display:flex;flex-direction:column;align-items:center;gap:0;margin-bottom:0}
.redirect-area.has-visible{gap:13px;margin-bottom:4px;transition:gap .4s ease,margin-bottom .4s ease}
.status-text{font-size:17px;color:var(--text-secondary);margin:0;display:flex;align-items:center;justify-content:center}
.error-msg{
  font-size:13px;color:var(--err);background:var(--err-bg);
  border-radius:12px;padding:13px 17px;margin-bottom:18px;
  line-height:1.6;word-break:break-word;text-align:left;
}
/* Identity block: which provider (and, when known, the masked account email)
   was involved in a failed login, so a stranger or admin understands why. */
.callback-identity{
  display:flex;align-items:center;justify-content:center;gap:9px;
  margin-bottom:14px;
}
.callback-identity__icon{display:inline-flex;width:26px;height:26px}
.callback-identity__icon svg,.callback-identity__icon img{width:26px;height:26px}
.callback-identity__name{font-size:16px;font-weight:600;color:var(--text)}
/* Match the login screen's .wpomni-btn look & feel (border-radius, entrance
   animation, hover lift). Hidden until .visible is added (5s fallback). */
.link{
  display:none;padding:11px 31px;background:#1d2327;color:#fff;
  text-decoration:none;border-radius:10px;font-size:15px;font-weight:600;
  letter-spacing:0.01em;cursor:pointer;box-sizing:border-box;
  opacity:0;transform:translateY(16px);
  transition:background .15s ease,transform .4s cubic-bezier(0.22,1,0.36,1),box-shadow .4s cubic-bezier(0.22,1,0.36,1);
}
.link.visible{display:inline-block;animation:wpomni-btn-in .4s cubic-bezier(0.22,1,0.36,1) forwards}
.link.visible:hover{background:#000;color:#fff;transform:translateY(-5px);box-shadow:0 14px 32px rgba(0,0,0,0.2)}
@media(prefers-color-scheme:dark){
  .link{background:#f0f0f1;color:#1d2327}
  .link.visible:hover{background:#fff;color:#1d2327;transform:translateY(-5px);box-shadow:0 14px 32px rgba(0,0,0,0.5)}
}
.spinner{
  display:inline-block;width:22px;height:22px;border:3px solid var(--border);
  border-top-color:var(--link);border-radius:50%;animation:spin .6s linear infinite;
  vertical-align:middle;margin-right:9px;flex-shrink:0;
}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes wpomni-btn-in{to{opacity:1;transform:translateY(0)}}
.site-name{font-size:13px;color:var(--text-secondary);margin-top:auto;padding-top:22px;opacity:0.6}
@media(max-width:480px){
  .card{padding:31px 22px}
  .status-group{gap:9px;margin-bottom:14px}
  .status-icon{width:17px;height:17px}
  .status-title{font-size:18px}
  .provider-icon svg,.provider-icon img{width:64px;height:64px}
  .user-name{font-size:22px}
  .welcome-text{font-size:22px}
  .status-text{font-size:15px}
  .spinner{width:19px;height:19px}
  .error-msg{font-size:12px;padding:11px 13px}
  .link.visible{padding:10px 26px;font-size:14px}
}
</style>
</head>
<body class="login">
<div class="card">
    <?php
    // Bind mode: this success page is shown after "Test connection & bind",
    // not after a login, so use bind-specific copy.
    $is_bind = !empty($context['mode']) && $context['mode'] === 'bind';
    $bind_provider = $context['provider_name'] ?? '';
    ?>
    <?php if ($is_success && $safe_icon) : ?>
    <div class="provider-icon"><?php echo $safe_icon; ?></div>
    <?php endif; ?>
    <div class="status-group">
        <?php if ($is_success) : ?>
        <svg class="status-icon ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
        <span class="status-title"><?php echo $is_bind ? esc_html__('绑定成功', 'wp-omni-auth') : esc_html__('Login Successful', 'wp-omni-auth'); ?></span>
        <?php else : ?>
        <svg class="status-icon err" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M10 10l4 4m0 -4l-4 4" /></svg>
        <span class="status-title"><?php echo esc_html($title); ?></span>
        <?php endif; ?>
    </div>
    <?php if ($is_bind) : ?>
    <div class="user-name"><span class="welcome-text"><?php echo esc_html(sprintf(__('你的账号已成功绑定到 %s', 'wp-omni-auth'), $bind_provider)); ?></span></div>
    <?php elseif ($user_name) : ?>
    <div class="user-name"><span class="welcome-text"><?php esc_html_e('Welcome back,', 'wp-omni-auth'); ?></span> <?php echo esc_html($user_name); ?></div>
    <?php endif; ?>
    <?php if ($is_success) : ?>
    <div class="redirect-area">
        <p class="status-text"><span class="spinner"></span> <?php esc_html_e('Redirecting...', 'wp-omni-auth'); ?></p>
        <a class="link" href="<?php echo $safe_redirect; ?>"><?php esc_html_e('Click here if not redirected', 'wp-omni-auth'); ?></a>
    </div>
    <?php else : ?>
    <?php if (!empty($context['provider_name'])) : ?>
    <div class="callback-identity">
        <?php if (!empty($context['provider_icon'])) : ?>
        <span class="callback-identity__icon" aria-hidden="true"><?php echo $context['provider_icon']; ?></span>
        <?php endif; ?>
        <span class="callback-identity__name"><?php echo esc_html($context['provider_name']); ?></span>
    </div>
    <?php endif; ?>
    <div class="redirect-area">
        <div class="error-msg"><?php echo $safe_message; ?></div>
        <a class="link" href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Return to login', 'wp-omni-auth'); ?></a>
    </div>
    <?php endif; ?>
    <div class="site-name"><?php bloginfo('name'); ?></div>
</div>
</body>
</html>
