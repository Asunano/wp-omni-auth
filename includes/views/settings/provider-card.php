<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpomni-provider-card" data-provider-slug="<?php echo esc_attr($slug); ?>">
    <div class="wpomni-card-icon"><?php echo $icon_html; ?></div>
    <div class="wpomni-card-info">
        <h3><?php echo esc_html($name); ?></h3>
        <p>
            <?php if ($is_configured) : ?>
                <span class="wpomni-card-status <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>">
                    <?php echo $is_enabled ? esc_html__('Enabled', 'wp-omni-auth') : esc_html__('Disabled', 'wp-omni-auth'); ?>
                </span>
            <?php else : ?>
                <span class="wpomni-card-status disabled"<?php echo $config_title ? ' title="' . esc_attr($config_title) . '"' : ''; ?>><?php esc_html_e('Not configured', 'wp-omni-auth'); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="wpomni-card-actions">
        <?php if ($is_custom) : ?>
            <button type="button" class="button button-link-delete wpomni-remove-provider" data-slug="<?php echo esc_attr($slug); ?>"><?php esc_html_e('Remove', 'wp-omni-auth'); ?></button>
        <?php endif; ?>
        <button type="button" class="button wpomni-configure-provider" data-slug="<?php echo esc_attr($slug); ?>">
            <?php esc_html_e('Configure', 'wp-omni-auth'); ?>
        </button>
    </div>
</div>
