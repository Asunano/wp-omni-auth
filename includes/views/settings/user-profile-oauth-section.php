<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<h2><?php esc_html_e('OAuth Binding', 'wp-omni-auth'); ?></h2>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('Provider', 'wp-omni-auth'); ?></th>
        <td><?php echo esc_html($provider_name); ?></td>
    </tr>
    <?php if (!empty($oauth_id)) : ?>
    <tr>
        <th scope="row"><?php esc_html_e('OAuth ID', 'wp-omni-auth'); ?></th>
        <td><code><?php echo esc_html($oauth_id); ?></code></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($oauth_email)) : ?>
    <tr>
        <th scope="row"><?php esc_html_e('OAuth Email', 'wp-omni-auth'); ?></th>
        <td><?php echo esc_html($oauth_email); ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($binding_time)) : ?>
    <tr>
        <th scope="row"><?php esc_html_e('Bound Since', 'wp-omni-auth'); ?></th>
        <td><?php echo esc_html($binding_time); ?></td>
    </tr>
    <?php endif; ?>
    <tr>
        <th scope="row"><?php esc_html_e('Unbind', 'wp-omni-auth'); ?></th>
        <td>
            <form method="post" action="<?php echo esc_url(set_url_scheme(admin_url('admin-post.php'), 'https')); ?>">
                <input type="hidden" name="action" value="wpomni_unbind_user">
                <input type="hidden" name="user_id" value="<?php echo esc_attr($user_id); ?>">
                <?php wp_nonce_field('wpomni_unbind_' . $user_id); ?>
                <button type="submit" class="button wpomni-danger-outline" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to unbind this OAuth account? The user will need to re-authenticate via OAuth.', 'wp-omni-auth')); ?>');">
                    <?php esc_html_e('Unbind OAuth', 'wp-omni-auth'); ?>
                </button>
                <p class="description"><?php esc_html_e('After unbinding, the user must log in via OAuth again to re-establish the binding.', 'wp-omni-auth'); ?></p>
            </form>
        </td>
    </tr>
</table>
