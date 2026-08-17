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
            <?php
            // Render as a nonce-protected GET link to admin-post.php instead of a
            // nested <form>. The profile edit screen is itself wrapped in a single
            // <form id="your-profile">, so a nested <form> here is invalid HTML and
            // its wp_nonce_field() emitted a second name="_wpnonce" hidden input that
            // collided with WordPress's own profile-update nonce — causing
            // "The link you followed has expired" on profile save. A GET link to
            // admin-post.php carries the nonce as a URL parameter (no form collision)
            // and actually reaches the unbind handler, which also fixes the previously
            // broken unbind button.
            $unbind_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'  => 'wpomni_unbind_user',
                        'user_id' => $user_id,
                    ],
                    admin_url('admin-post.php')
                ),
                'wpomni_unbind_' . $user_id
            );
            ?>
            <a class="button wpomni-danger-outline"
               href="<?php echo esc_url($unbind_url); ?>"
               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to unbind this OAuth account? The user will need to re-authenticate via OAuth.', 'wp-omni-auth')); ?>');">
                <?php esc_html_e('Unbind OAuth', 'wpomni-auth'); ?>
            </a>
            <p class="description"><?php esc_html_e('After unbinding, the user must log in via OAuth again to re-establish the binding.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
</table>
