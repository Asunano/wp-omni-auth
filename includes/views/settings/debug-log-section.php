<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('Debug Mode', 'wp-omni-auth'); ?></th>
        <td>
            <input type="hidden" name="wpomni_debug_mode" value="no">
            <label>
                <input type="checkbox" name="wpomni_debug_mode" value="yes" <?php checked(get_option('wpomni_debug_mode', 'no'), 'yes'); ?>>
                <?php esc_html_e('Enable debug logging for OAuth callbacks', 'wp-omni-auth'); ?>
            </label>
            <p class="description"><?php esc_html_e('Logs are saved to wp-content/.wp-omni-auth-debug.log (dot-prefix, not web-accessible)', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
</table>

<hr>

<p class="description">
    <?php esc_html_e('Debug logs are written to the following file (dot-prefixed, not web-accessible). It only appears after a debug-logged event occurs while Debug Mode is on — use', 'wp-omni-auth'); ?>
    <code>ls -a</code>
    <?php esc_html_e('or enable "Show hidden files" in your file manager to see it.', 'wp-omni-auth'); ?>
</p>
<p>
    <code class="wpomni-code"><?php echo esc_html($log_path); ?></code>
    <?php if ($log_exists) : ?>
        <span class="wpomni-success-text wpomni-ml-2">✓ <?php esc_html_e('File exists', 'wp-omni-auth'); ?></span>
    <?php else : ?>
        <span class="wpomni-danger-text wpomni-ml-2">✗ <?php esc_html_e('Not created yet', 'wp-omni-auth'); ?></span>
    <?php endif; ?>
</p>
<p>
    <button type="button" class="button" id="wpomni-view-log"><?php esc_html_e('View Debug Log', 'wp-omni-auth'); ?></button>
    <button type="button" class="button" id="wpomni-clear-log"><?php esc_html_e('Clear Debug Log', 'wp-omni-auth'); ?></button>
    <?php if ($log_exists) : ?>
        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpomni_download_log&nonce=' . wp_create_nonce('wpomni_nonce'))); ?>" class="button" id="wpomni-download-log"><?php esc_html_e('Download Debug Log', 'wp-omni-auth'); ?></a>
    <?php else : ?>
        <button type="button" class="button" disabled aria-disabled="true" title="<?php esc_attr_e('Log file not created yet', 'wp-omni-auth'); ?>"><?php esc_html_e('Download Debug Log', 'wp-omni-auth'); ?></button>
    <?php endif; ?>
    <span id="wpomni-log-status" class="wpomni-ml-2 wpomni-muted"></span>
</p>
<div id="wpomni-log-content" class="hidden">
    <textarea rows="20" class="wpomni-log-textarea" readonly></textarea>
</div>
