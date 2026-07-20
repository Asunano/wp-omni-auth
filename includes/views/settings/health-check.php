<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if ($show_no_provider) : ?>
<div class="notice notice-error">
    <p>
        <strong><?php esc_html_e('WP-OmniAuth:', 'wp-omni-auth'); ?></strong>
        <?php
        printf(
            wp_kses(
                /* translators: %s: settings page URL */
                __('OAuth-only mode is enabled, but no OAuth providers are configured. Users <strong>cannot log in</strong>. <a href="%s">Configure providers</a>.', 'wp-omni-auth'),
                ['a' => ['href' => []], 'strong' => []]
            ),
            esc_url($settings_url)
        );
        ?>
    </p>
</div>
<?php endif; ?>

<?php if ($show_no_key) : ?>
<div class="notice notice-warning">
    <p>
        <strong><?php esc_html_e('WP-OmniAuth:', 'wp-omni-auth'); ?></strong>
        <?php esc_html_e('No emergency access key is set. If all OAuth providers go down, you will be locked out. Generate a key in the settings page.', 'wp-omni-auth'); ?>
    </p>
</div>
<?php endif; ?>
