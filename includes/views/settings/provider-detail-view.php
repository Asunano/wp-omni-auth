<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpomni-provider-detail" id="wpomni-detail-<?php echo esc_attr($slug); ?>" data-slug="<?php echo esc_attr($slug); ?>">
    <a class="wpomni-back-link" data-back-to-list>
        <?php esc_html_e('Back to provider list', 'wp-omni-auth'); ?>
    </a>

    <div class="wpomni-section">
        <div class="wpomni-section-header">
            <div class="wpomni-detail-icon"><?php echo $icon_html; ?></div>
            <h2><?php echo esc_html($name); ?></h2>
        </div>
        <div class="wpomni-section-body">
            <?php
            // Configuration guidance link as admin notice style
            ?>
            <div class="notice notice-info inline" style="margin:0 0 16px;">
                <p>
                    <?php echo wp_kses_post(sprintf(
                        /* translators: %s: URL to setup guide */
                        __('配置指导，请访问 <a href="%s" target="_blank">我们的设置指南</a>。', 'wp-omni-auth'),
                        'https://blog.drxian.cn/archives/1465'
                    )); ?>
                </p>
            </div>

            <table class="form-table">
                <?php if ($is_custom) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Provider Name', 'wp-omni-auth'); ?></th>
                    <td>
                        <input type="text" name="wpomni_<?php echo esc_attr($slug); ?>_name"
                            value="<?php echo esc_attr(get_option("wpomni_{$slug}_name", $name)); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Icon', 'wp-omni-auth'); ?></th>
                    <td>
                        <input type="text" name="wpomni_<?php echo esc_attr($slug); ?>_icon"
                            value="<?php echo esc_attr(get_option("wpomni_{$slug}_icon", '')); ?>" class="large-text"
                            placeholder="<?php echo esc_attr__('Image URL or SVG code', 'wp-omni-auth'); ?>"
                            data-icon-input>
                        <p class="description">
                            <?php esc_html_e('Enter an image URL (e.g. https://example.com/icon.svg) or paste SVG code.', 'wp-omni-auth'); ?>
                        </p>
                        <div data-icon-preview class="wpomni-icon-preview">
                            <span class="wpomni-muted"><?php esc_html_e('Preview:', 'wp-omni-auth'); ?></span>
                            <span data-icon-preview-area class="wpomni-icon-preview-area"></span>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <tr>
                    <th scope="row"><?php esc_html_e('Enable', 'wp-omni-auth'); ?></th>
                    <td>
                        <input type="hidden" name="wpomni_<?php echo esc_attr($slug); ?>_enabled" value="no">
                        <label>
                            <input type="checkbox" name="wpomni_<?php echo esc_attr($slug); ?>_enabled" value="yes"
                                <?php checked($is_enabled); ?>>
                            <?php echo esc_html(sprintf(__('Enable %s login', 'wp-omni-auth'), $name)); ?>
                        </label>
                    </td>
                </tr>

                <?php echo $fields_html; ?>
            </table>

            <div class="wpomni-detail-submit">
                <button type="submit" class="wpomni-save-btn"><?php echo esc_html__('Save Changes', 'wp-omni-auth'); ?></button>
                <?php if ($is_enabled) : ?>
                <button type="button" class="wpomni-test-bind-btn" data-slug="<?php echo esc_attr($slug); ?>">
                    <?php echo esc_html($is_bound ? __('Re-test & re-bind', 'wp-omni-auth') : __('Test connection & bind', 'wp-omni-auth')); ?>
                </button>
                <?php endif; ?>
            </div>

            <?php if ($is_bound) : ?>
            <div class="wpomni-bound-notice">
                <span class="wpomni-bound-badge"><?php esc_html_e('Bound', 'wp-omni-auth'); ?></span>
                <span class="wpomni-bound-id"><?php echo esc_html(substr($bound_id, 0, 6) . str_repeat('•', max(0, strlen($bound_id) - 6))); ?></span>
                <span class="wpomni-bound-hint"><?php esc_html_e('Your account is linked to this provider via its stable ID (not email).', 'wp-omni-auth'); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
