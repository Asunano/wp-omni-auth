<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<h2><?php esc_html_e('OAuth Providers', 'wp-omni-auth'); ?></h2>
<p class="wpomni-disclaimer" style="font-size:12px;color:#888;margin:0 0 12px;line-height:1.6;padding:8px 10px;background:#fcf9e8;border-left:3px solid #dba617;border-radius:3px;">
    <?php echo wp_kses_post(sprintf(
        /* translators: %s: URL to setup guide */
        __('<strong>免责声明：</strong>本插件展示的 OAuth 提供商图标仅用于身份识别目的，不代表与相关品牌有任何关联、认可或合作关系。部分图标系作者根据公开资料制作，可能与官方图标存在差异。所有商标和品牌名称归各自权利人所有。
如您认为某图标侵犯了您的合法权益，或存在与事实不符、误导用户、影响品牌声誉的情形，请与我们联系，我们将在核实后尽快处理。
欢迎前往 <a href="%s" target="_blank">我们的设置指南</a> 提交准确的官方图标资源。', 'wp-omni-auth'),
        'https://blog.drxian.cn/archives/1465'
    )); ?>
</p>
<?php if (empty($all_cards_html)) : ?>
    <p class="wpomni-muted wpomni-italic"><?php esc_html_e('No providers configured yet.', 'wp-omni-auth'); ?></p>
<?php endif; ?>

<div class="wpomni-provider-grid">
    <?php echo $all_cards_html; ?>
    <button type="button" class="wpomni-provider-card wpomni-add-provider-tile" id="wpomni-add-provider" aria-label="<?php esc_attr_e('Add Custom Provider', 'wp-omni-auth'); ?>">
        <span class="wpomni-add-plus" aria-hidden="true">+</span>
        <span class="wpomni-add-label"><?php esc_html_e('Add Custom Provider', 'wp-omni-auth'); ?></span>
    </button>
</div>
