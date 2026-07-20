<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpomni-about">
    <div class="wpomni-about-hero">
        <div class="wpomni-about-hero-body">
            <div class="wpomni-about-hero-title">
                <h2><?php echo esc_html($plugin_data['Name'] ?: 'WP-OmniAuth'); ?></h2>
                <span class="wpomni-version-pill">v<?php echo esc_html(WPOMNIAUTH_VERSION); ?></span>
            </div>
            <p class="wpomni-about-desc">
                ⭐ <?php esc_html_e('如果这个项目对你有帮助，请给它一个 Star！', 'wp-omni-auth'); ?> ⭐
                <br><br>
                <?php esc_html_e('WP-OmniAuth 是一个轻量级 WordPress 插件，为你的站点添加 OAuth 2.0 登录能力。支持 GitHub、Google、微信等 11 个主流平台一键登录，也支持通过后台配置接入任意 OAuth 2.0 服务商。无需 Composer、无构建步骤，标准「拖入即用」的 WordPress 插件。', 'wp-omni-auth'); ?>
            </p>
            <p class="wpomni-about-author">
                <span class="wpomni-muted"><?php esc_html_e('By', 'wp-omni-auth'); ?></span>
                Asunano
            </p>
            <div class="wpomni-hero-links">
                <a href="https://github.com/Asunano/wp-omni-auth" target="_blank" class="wpomni-hero-link">
                    <svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                    <?php esc_html_e('GitHub 仓库', 'wp-omni-auth'); ?>
                </a>
                <a href="https://blog.drxian.cn/archives/1465" target="_blank" class="wpomni-hero-link">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                    <?php esc_html_e('配置教程', 'wp-omni-auth'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=wp-omni-auth&tab=providers')); ?>" class="wpomni-hero-link">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-6 14H6v-2h8v2zm4-4H6v-2h12v2zm0-4H6V8h12v2z"/></svg>
                    <?php esc_html_e('配置提供商', 'wp-omni-auth'); ?>
                </a>
            </div>
        </div>
    </div>

    <div class="wpomni-about-grid">
        <div class="wpomni-about-card wpomni-about-card-wide">
            <h3><?php esc_html_e('功能特性', 'wp-omni-auth'); ?></h3>
            <ul class="wpomni-feature-list">
                <li><?php esc_html_e('11 个内置提供商（Apple、GitHub、Google、微信、QQ、钉钉、飞书等）', 'wp-omni-auth'); ?></li>
                <li><?php esc_html_e('自定义提供商：通过后台界面配置即可接入任意 OAuth 2.0 服务商', 'wp-omni-auth'); ?></li>
                <li><?php esc_html_e('OAuth-Only 模式：隐藏密码登录，适合纯 SSO 场景', 'wp-omni-auth'); ?></li>
                <li><?php esc_html_e('安全防护：CSRF state 防护 + 限流 + IP 黑名单 + 日志脱敏', 'wp-omni-auth'); ?></li>
                <li><?php esc_html_e('应急访问：两步式入口，被锁门外也能恢复密码登录', 'wp-omni-auth'); ?></li>
                <li><?php esc_html_e('自动更新：接入 WordPress 原生更新系统，后台一键升级', 'wp-omni-auth'); ?></li>
            </ul>
        </div>

        <div class="wpomni-about-card wpomni-about-card-wide">
            <h3><?php esc_html_e('更新设置', 'wp-omni-auth'); ?></h3>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="display:flex;align-items:center;gap:8px;font-size:13px;">
                    <span class="wpomni-field-label" style="margin:0;"><?php esc_html_e('更新源:', 'wp-omni-auth'); ?></span>
                    <?php
                    $current_mirror      = get_option('wpomni_use_mirror', 'no');
                    $current_mirror_val  = ($current_mirror === 'yes') ? 'yes' : 'no';
                    $current_mirror_text = ($current_mirror_val === 'yes')
                        ? __('镜像 (gh-proxy.org)', 'wp-omni-auth')
                        : __('直连 (GitHub)', 'wp-omni-auth');
                    ?>
                    <div class="wpomni-select" id="wpomni-use-mirror" data-value="<?php echo esc_attr($current_mirror_val); ?>" style="display:inline-flex;">
                        <button type="button" class="wpomni-select-trigger" id="wpomni-use-mirror-button"
                                aria-haspopup="listbox" aria-expanded="false">
                            <span class="wpomni-select-value"><?php echo esc_html($current_mirror_text); ?></span>
                            <span class="wpomni-select-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="wpomni-select-menu" role="listbox" aria-labelledby="wpomni-use-mirror-button" hidden>
                            <li class="wpomni-select-option" role="option" data-value="no" aria-selected="<?php echo $current_mirror_val === 'no' ? 'true' : 'false'; ?>">
                                <span class="wpomni-select-option-label"><?php esc_html_e('直连 (GitHub)', 'wp-omni-auth'); ?></span>
                                <span class="wpomni-select-check" aria-hidden="true">&#10003;</span>
                            </li>
                            <li class="wpomni-select-option" role="option" data-value="yes" aria-selected="<?php echo $current_mirror_val === 'yes' ? 'true' : 'false'; ?>">
                                <span class="wpomni-select-option-label"><?php esc_html_e('镜像 (gh-proxy.org)', 'wp-omni-auth'); ?></span>
                                <span class="wpomni-select-check" aria-hidden="true">&#10003;</span>
                            </li>
                        </ul>
                    </div>
                    <span id="wpomni-mirror-status" class="wpomni-ml-2 wpomni-muted"></span>
                </span>

                <span style="font-size:13px;"><strong><?php esc_html_e('当前版本:', 'wp-omni-auth'); ?></strong> <?php echo esc_html(WPOMNIAUTH_VERSION); ?></span>

                <button type="button" class="button" id="wpomni-check-update">
                    <?php esc_html_e('检查更新', 'wp-omni-auth'); ?>
                </button>
                <span id="wpomni-update-status" class="wpomni-ml-2 wpomni-muted"></span>
            </div>
        </div>
    </div>
</div>
