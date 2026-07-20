<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<p class="description"><?php esc_html_e('Select data categories to clear, or perform a full reset.', 'wp-omni-auth'); ?></p>
<table class="form-table wpomni-mt-0" role="presentation">
    <tbody>
        <tr>
            <th scope="row"><?php esc_html_e('Clear Data', 'wp-omni-auth'); ?></th>
            <td>
                <fieldset>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="login_log">
                        <?php esc_html_e('Login logs', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('OAuth login history records', 'wp-omni-auth'); ?></span>
                    </label>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="blacklist">
                        <?php esc_html_e('IP blacklist', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('Blocked IP addresses', 'wp-omni-auth'); ?></span>
                    </label>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="rate_limits">
                        <?php esc_html_e('Rate limits', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('Active rate limit counters', 'wp-omni-auth'); ?></span>
                    </label>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="caches">
                        <?php esc_html_e('Caches', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('Dashboard stats & GitHub release cache', 'wp-omni-auth'); ?></span>
                    </label>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="debug_log">
                        <?php esc_html_e('Debug log', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('Debug log file contents', 'wp-omni-auth'); ?></span>
                    </label>
                    <label class="wpomni-block wpomni-mb-2">
                        <input type="checkbox" name="wpomni_clean[]" value="used_codes">
                        <?php esc_html_e('Used codes', 'wp-omni-auth'); ?>
                        <span class="description">— <?php esc_html_e('OAuth code replay protection data', 'wp-omni-auth'); ?></span>
                    </label>
                </fieldset>
                <p class="wpomni-mt-2">
                    <button type="button" class="button" id="wpomni-clean-selected"><?php esc_html_e('Clear Selected', 'wp-omni-auth'); ?></button>
                </p>
                <div id="wpomni-clean-status" class="wpomni-mt-2 wpomni-muted"></div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Full Reset', 'wp-omni-auth'); ?></th>
            <td>
                <p class="description"><?php esc_html_e('Delete ALL plugin data and restore default settings. This cannot be undone.', 'wp-omni-auth'); ?></p>
                <p>
                    <button type="button" class="button button-link-delete" id="wpomni-reset-all-data"><?php esc_html_e('Reset All Data', 'wp-omni-auth'); ?></button>
                </p>
                <div id="wpomni-reset-status" class="wpomni-mt-2 wpomni-muted"></div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Export / Restore', 'wp-omni-auth'); ?></th>
            <td>
                <p class="description"><?php esc_html_e('Download all plugin data as JSON for backup, or restore from a previous export.', 'wp-omni-auth'); ?></p>
                <p class="wpomni-mt-2">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpomni_export_data'), 'wpomni_export')); ?>" class="button"><?php esc_html_e('Export Data', 'wp-omni-auth'); ?></a>
                </p>
                <p class="wpomni-mt-2">
                    <label for="wpomni-import-file" class="wpomni-block wpomni-mb-1"><?php esc_html_e('Restore from file:', 'wp-omni-auth'); ?></label>
                    <input type="file" id="wpomni-import-file" accept=".json">
                    <button type="button" class="button" id="wpomni-import-data"><?php esc_html_e('Upload & Restore', 'wp-omni-auth'); ?></button>
                    <span id="wpomni-import-status" class="wpomni-ml-2 wpomni-muted"></span>
                </p>
                <div id="wpomni-import-result" class="wpomni-mt-2"></div>
            </td>
        </tr>
    </tbody>
</table>
