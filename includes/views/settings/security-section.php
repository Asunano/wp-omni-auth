<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<table class="form-table">
    <tr>
        <th scope="row"><?php esc_html_e('Trusted Proxy Mode', 'wp-omni-auth'); ?></th>
        <td>
            <input type="hidden" name="wpomni_trusted_proxy" value="no">
            <label>
                <input type="checkbox" name="wpomni_trusted_proxy" value="yes" <?php checked(get_option('wpomni_trusted_proxy', 'no'), 'yes'); ?>>
                <?php esc_html_e('Read client IP from proxy headers (Cloudflare, X-Forwarded-For)', 'wp-omni-auth'); ?>
            </label>
            <p class="description"><?php esc_html_e('Enable only if your site is behind Cloudflare, CDN, or a reverse proxy. Leave disabled for direct connections.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Client IP Source', 'wp-omni-auth'); ?></th>
        <td>
            <?php
            $ip_source = get_option('wpomni_client_ip_source', 'auto');
            $ip_sources = [
                'auto'       => __('Auto (CF-Connecting-IP → X-Forwarded-For first → X-Real-IP)', 'wp-omni-auth'),
                'cloudflare' => __('Cloudflare (CF-Connecting-IP)', 'wp-omni-auth'),
                'edgeone'    => __('EdgeOne (EO-Connecting-IP)', 'wp-omni-auth'),
                'esa'        => __('ESA / generic CDN (X-Forwarded-For last → X-Real-IP)', 'wp-omni-auth'),
                'xfwd_first' => __('X-Forwarded-For (first segment)', 'wp-omni-auth'),
                'xfwd_last'  => __('X-Forwarded-For (last segment)', 'wp-omni-auth'),
                'xrealip'    => __('X-Real-IP', 'wp-omni-auth'),
                'custom'     => __('Custom header (configure below)', 'wp-omni-auth'),
            ];
            ?>
            <select name="wpomni_client_ip_source" id="wpomni_client_ip_source">
                <?php foreach ($ip_sources as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($ip_source, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Different CDNs populate X-Forwarded-For inconsistently. Pick the header / segment that carries the real client IP for your setup.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <tr class="wpomni-client-ip-custom"<?php echo $ip_source !== 'custom' ? ' style="display:none;"' : ''; ?>>
        <th scope="row"><?php esc_html_e('Custom Header Name', 'wp-omni-auth'); ?></th>
        <td>
            <input type="text" name="wpomni_client_ip_custom_header" value="<?php echo esc_attr(get_option('wpomni_client_ip_custom_header', '')); ?>" class="regular-text" placeholder="X-Forwarded-For">
            <p class="description"><?php esc_html_e('HTTP header holding the client IP (no HTTP_ prefix). E.g. X-Forwarded-For, X-Real-IP, X-Client-IP.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <tr class="wpomni-client-ip-custom"<?php echo $ip_source !== 'custom' ? ' style="display:none;"' : ''; ?>>
        <th scope="row"><?php esc_html_e('Segment Position', 'wp-omni-auth'); ?></th>
        <td>
            <?php $ip_position = get_option('wpomni_client_ip_custom_position', 'last'); ?>
            <select name="wpomni_client_ip_custom_position">
                <option value="last" <?php selected($ip_position, 'last'); ?>><?php esc_html_e('Last (rightmost) — trusted edge appended it', 'wp-omni-auth'); ?></option>
                <option value="first" <?php selected($ip_position, 'first'); ?>><?php esc_html_e('First (leftmost) — client IP from X-Forwarded-For', 'wp-omni-auth'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('For chained X-Forwarded-For, choose which comma-separated segment to trust. Use "First" when your CDN places the client IP at the start.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <?php if (get_option('wpomni_trusted_proxy', 'no') === 'yes') : ?>
    <script type="text/javascript">
        (function () {
            var source = document.getElementById('wpomni_client_ip_source');
            if (!source) return;
            var toggle = function () {
                var show = source.value === 'custom';
                document.querySelectorAll('.wpomni-client-ip-custom').forEach(function (row) {
                    row.style.display = show ? '' : 'none';
                });
            };
            source.addEventListener('change', toggle);
        })();
    </script>
    <tr>
        <th scope="row"><?php esc_html_e('Trusted Proxy IPs', 'wp-omni-auth'); ?></th>
        <td>
            <textarea name="wpomni_trusted_proxy_ips" rows="3" class="large-text code" placeholder="203.0.113.10&#10;10.0.0.0/8"><?php echo esc_textarea(implode("\n", (array) get_option('wpomni_trusted_proxy_ips', []))); ?></textarea>
            <p class="description"><?php esc_html_e('Only trust proxy headers (X-Forwarded-For, etc.) when the direct connection comes from one of these IPs/CIDRs. Leaving this empty trusts headers from any source (only safe behind a proxy that strips client-supplied headers). Required to prevent IP spoofing of rate limits / bans.', 'wp-omni-auth'); ?></p>
        </td>
    </tr>
    <?php endif; ?>
    <tr>
        <th scope="row"><?php esc_html_e('Auto-Ban Threshold', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_auto_ban_threshold" value="<?php echo esc_attr(get_option('wpomni_auto_ban_threshold', 10)); ?>" class="small-text" min="0">
            <span class="description"><?php esc_html_e('failed attempts before auto-banning an IP (0 = disabled)', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Auto-Ban Window', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_auto_ban_window" value="<?php echo esc_attr(get_option('wpomni_auto_ban_window', 24)); ?>" class="small-text" min="1">
            <span class="description"><?php esc_html_e('hours to look back for failures', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Auto-Ban Duration', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_auto_ban_duration" value="<?php echo esc_attr(get_option('wpomni_auto_ban_duration', 24)); ?>" class="small-text" min="1">
            <span class="description"><?php esc_html_e('hours an IP stays banned after auto-ban (time-limited, so a shared/NAT IP is not locked out forever). Manual blacklist entries remain permanent.', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Rate Limit (per IP)', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_rate_limit_per_ip" value="<?php echo esc_attr(get_option('wpomni_rate_limit_per_ip', 10)); ?>" class="small-text" min="0">
            <span class="description"><?php esc_html_e('requests per minute per IP (0 = disabled)', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Rate Limit (global)', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_rate_limit_global" value="<?php echo esc_attr(get_option('wpomni_rate_limit_global', 60)); ?>" class="small-text" min="0">
            <span class="description"><?php esc_html_e('total requests per minute across all IPs (0 = disabled)', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Rate Limit (per provider)', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_rate_limit_per_provider" value="<?php echo esc_attr(get_option('wpomni_rate_limit_per_provider', 30)); ?>" class="small-text" min="0">
            <span class="description"><?php esc_html_e('authorization requests per minute per provider (0 = disabled). Protects a single OAuth app from being overwhelmed.', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Rate Limit (per identity)', 'wp-omni-auth'); ?></th>
        <td>
            <input type="number" name="wpomni_rate_limit_per_identity" value="<?php echo esc_attr(get_option('wpomni_rate_limit_per_identity', 10)); ?>" class="small-text" min="0">
            <span class="description"><?php esc_html_e('login attempts per minute per OAuth account/email (0 = disabled). Throttles a single looping or compromised account without permanently locking out legitimate users.', 'wp-omni-auth'); ?></span>
        </td>
    </tr>
</table>

<h3 class="wpomni-mt-6"><?php esc_html_e('IP Blacklist', 'wp-omni-auth'); ?></h3>
<p class="description"><?php esc_html_e('One IP or CIDR per line (e.g. 1.2.3.4 or 5.6.7.0/24)', 'wp-omni-auth'); ?></p>
<textarea name="wpomni_blacklist_text" rows="5" class="large-text code" placeholder="1.2.3.4&#10;5.6.7.0/24"><?php echo esc_textarea($blacklist_lines); ?></textarea>

<?php if (!empty($blacklist_rows)) : ?>
<table class="widefat wpomni-mt-3 wpomni-maxw-600">
        <thead>
        <tr>
            <th><?php esc_html_e('IP', 'wp-omni-auth'); ?></th>
            <th><?php esc_html_e('Reason', 'wp-omni-auth'); ?></th>
            <th><?php esc_html_e('Added', 'wp-omni-auth'); ?></th>
            <th><?php esc_html_e('Expires', 'wp-omni-auth'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($blacklist_rows as $entry) : ?>
        <tr>
            <td><code><?php echo esc_html($entry['ip']); ?></code></td>
            <td><?php echo esc_html($entry['reason'] ?? '—'); ?></td>
            <td><?php echo esc_html($entry['created_at'] ?? '—'); ?></td>
            <td><?php echo esc_html(!empty($entry['expired_at']) ? $entry['expired_at'] : __('Permanent', 'wp-omni-auth')); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
