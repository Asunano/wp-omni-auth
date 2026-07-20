<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<h3><?php esc_html_e('Email Notifications', 'wp-omni-auth'); ?></h3>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('Enable Email Notifications', 'wp-omni-auth'); ?></th>
        <td>
            <input type="hidden" name="wpomni_email_notify_enabled" value="no">
            <label>
                <input type="checkbox" name="wpomni_email_notify_enabled" value="yes" <?php checked(get_option('wpomni_email_notify_enabled', 'no'), 'yes'); ?>>
                <?php esc_html_e('Send email notifications for login events', 'wp-omni-auth'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Notification Email', 'wp-omni-auth'); ?></th>
        <td>
            <input type="email" name="wpomni_email_notify_to" value="<?php echo esc_attr(get_option('wpomni_email_notify_to', get_option('admin_email'))); ?>" class="regular-text">
            <p class="description"><?php esc_html_e('Defaults to admin email if empty.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Notify On', 'wp-omni-auth'); ?></th>
        <td>
            <fieldset>
                <?php
                $notify_events = get_option('wpomni_email_notify_events', ['login_failure', 'access_denied', 'ip_blocked', 'provider_bind']);
                $event_options = [
                    'login_success' => __('Login success', 'wp-omni-auth'),
                    'login_failure' => __('Login failure', 'wp-omni-auth'),
                    'access_denied' => __('Access denied', 'wp-omni-auth'),
                    'ip_blocked'    => __('IP blocked', 'wp-omni-auth'),
                    'provider_bind' => __('Provider bound', 'wp-omni-auth'),
                ];
                foreach ($event_options as $event_slug => $label) :
                ?>
                    <label class="wpomni-block wpomni-mb-1">
                        <input type="checkbox" name="wpomni_email_notify_events[]" value="<?php echo esc_attr($event_slug); ?>" <?php checked(in_array($event_slug, $notify_events)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="description"><?php esc_html_e('Select which events trigger an email notification.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
</table>

<h3 class="wpomni-mt-6"><?php esc_html_e('Webhook', 'wp-omni-auth'); ?></h3>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('Webhook URL', 'wp-omni-auth'); ?></th>
        <td>
            <input type="url" name="wpomni_webhook_url" value="<?php echo esc_attr(get_option('wpomni_webhook_url', '')); ?>" class="regular-text" placeholder="https://example.com/webhook">
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Trigger Events', 'wp-omni-auth'); ?></th>
        <td>
            <fieldset>
                <?php
                $event_labels = [
                    'login_success' => __('Login success', 'wp-omni-auth'),
                    'login_failure' => __('Login failure', 'wp-omni-auth'),
                    'access_denied' => __('Access denied', 'wp-omni-auth'),
                    'ip_blocked'    => __('IP blocked', 'wp-omni-auth'),
                    'provider_bind' => __('Provider bound', 'wp-omni-auth'),
                ];
                foreach ($event_labels as $event_slug => $label) :
                ?>
                    <label class="wpomni-block wpomni-mb-1">
                        <input type="checkbox" name="wpomni_webhook_events[]" value="<?php echo esc_attr($event_slug); ?>" <?php checked(in_array($event_slug, $webhook_events)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Webhook Secret', 'wp-omni-auth'); ?></th>
        <td>
            <input type="text" name="wpomni_webhook_secret" value="<?php echo esc_attr(get_option('wpomni_webhook_secret', '')); ?>" class="regular-text code">
            <p class="description"><?php esc_html_e('Used for HMAC-SHA256 signature verification. Leave empty to disable signing.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
</table>
