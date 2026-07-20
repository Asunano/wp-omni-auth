<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('OAuth-Only Mode', 'wp-omni-auth'); ?></th>
        <td>
            <input type="hidden" name="wpomni_hide_password" value="no">
            <label>
                <input type="checkbox" name="wpomni_hide_password" value="yes" <?php checked(get_option('wpomni_hide_password', 'no'), 'yes'); ?>>
                <?php esc_html_e('Hide username/password login form (show only OAuth buttons)', 'wp-omni-auth'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Emergency Access Key', 'wp-omni-auth'); ?></th>
        <td>
            <p class="description wpomni-mb-2">
                <?php esc_html_e('Used for emergency password login when all OAuth providers are down. Access via:', 'wp-omni-auth'); ?>
                <code><?php echo esc_html(wp_login_url() . '?wpomni_emergency=1'); ?></code>
            </p>
            <button type="button" class="button" id="wpomni-emergency-key-manager"
                data-masked="<?php echo esc_attr($emergency_masked); ?>">
                <?php esc_html_e('Key Management', 'wp-omni-auth'); ?>
            </button>
            <span id="wpomni-emergency-key-status" class="wpomni-ml-2 wpomni-muted"></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Allowed Users', 'wp-omni-auth'); ?></th>
        <td>
            <fieldset>
                <label class="wpomni-block wpomni-mb-2">
                    <input type="radio" name="wpomni_user_mode" value="allowlist" <?php checked($user_mode, 'allowlist'); ?>>
                    <strong><?php esc_html_e('Specific users only', 'wp-omni-auth'); ?></strong>
                </label>
                <div id="wpomni-user-allowlist" class="wpomni-ml-6 wpomni-mb-3<?php echo $user_mode !== 'allowlist' ? ' hidden' : ''; ?>">
                    <?php foreach ($users as $u) : ?>
                        <label class="wpomni-block wpomni-mb-1">
                            <input type="checkbox" name="wpomni_allowed_user_ids[]" value="<?php echo esc_attr($u['ID']); ?>" <?php checked(in_array($u['ID'], $allowed_ids)); ?>>
                            <?php echo esc_html($u['display_name'] . ' (' . $u['user_login'] . ')'); ?>
                            <span class="wpomni-text-muted">— <?php echo esc_html($u['user_email']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <label class="wpomni-block wpomni-mb-2">
                    <input type="radio" name="wpomni_user_mode" value="all_users" <?php checked($user_mode, 'all_users'); ?>>
                    <strong><?php esc_html_e('All registered users', 'wp-omni-auth'); ?></strong>
                    <p class="description wpomni-mt-1"><?php esc_html_e('Any WordPress user matched by OAuth email can log in.', 'wp-omni-auth'); ?></p>
                </label>
            </fieldset>
        </td>
    </tr>
</table>
