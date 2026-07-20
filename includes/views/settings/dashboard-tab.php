<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpomni-stats-grid">
    <div class="wpomni-stat-card stat-success">
        <p class="wpomni-stat-label"><?php esc_html_e('Today\'s Logins', 'wp-omni-auth'); ?></p>
        <p class="wpomni-stat-value"><?php echo esc_html($stats['today']); ?></p>
        <p class="wpomni-stat-sub"><?php esc_html_e('Successful logins today', 'wp-omni-auth'); ?></p>
    </div>
    <div class="wpomni-stat-card stat-success">
        <p class="wpomni-stat-label"><?php esc_html_e('This Week', 'wp-omni-auth'); ?></p>
        <p class="wpomni-stat-value"><?php echo esc_html($stats['week']); ?></p>
        <p class="wpomni-stat-sub"><?php esc_html_e('Successful logins (7 days)', 'wp-omni-auth'); ?></p>
    </div>
    <div class="wpomni-stat-card stat-failure">
        <p class="wpomni-stat-label"><?php esc_html_e('Failures (Week)', 'wp-omni-auth'); ?></p>
        <p class="wpomni-stat-value"><?php echo esc_html($stats['failures']); ?></p>
        <p class="wpomni-stat-sub"><?php esc_html_e('Failed attempts (7 days)', 'wp-omni-auth'); ?></p>
    </div>
    <div class="wpomni-stat-card">
        <p class="wpomni-stat-label"><?php esc_html_e('Active Providers', 'wp-omni-auth'); ?></p>
        <p class="wpomni-stat-value"><?php echo esc_html($stats['providers']); ?></p>
        <p class="wpomni-stat-sub"><?php esc_html_e('Enabled OAuth providers', 'wp-omni-auth'); ?></p>
    </div>
</div>

<div class="wpomni-dashboard-row">
    <!-- Recent Logins -->
    <div class="wpomni-section wpomni-recent-logins">
        <div class="wpomni-section-header">
            <h2><?php esc_html_e('Recent Logins', 'wp-omni-auth'); ?></h2>
        </div>
        <div class="wpomni-section-body wpomni-p-0">
            <?php if (!$table_exists) : ?>
                <p class="wpomni-no-data"><?php esc_html_e('Login history table not yet created.', 'wp-omni-auth'); ?></p>
            <?php elseif (empty($recent_logins)) : ?>
                <p class="wpomni-no-data"><?php esc_html_e('No login records yet.', 'wp-omni-auth'); ?></p>
            <?php else : ?>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'wp-omni-auth'); ?></th>
                            <th><?php esc_html_e('User', 'wp-omni-auth'); ?></th>
                            <th><?php esc_html_e('Provider', 'wp-omni-auth'); ?></th>
                            <th><?php esc_html_e('IP', 'wp-omni-auth'); ?></th>
                            <th><?php esc_html_e('Status', 'wp-omni-auth'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logins as $row) : ?>
                        <tr>
                            <td title="<?php echo esc_attr($row['created_at']); ?>"><?php printf(esc_html__('%s ago', 'wp-omni-auth'), esc_html($row['time_ago'])); ?></td>
                            <td><?php echo esc_html($row['display']); ?></td>
                            <td><?php echo esc_html($row['provider']); ?></td>
                            <td>
                                <span class="wpomni-ip-cell">
                                    <code class="wpomni-ip-value"><?php echo esc_html($row['ip']); ?></code>
                                    <code class="wpomni-ip-masked"><?php echo esc_html($row['ip_masked']); ?></code>
                                    <button type="button" class="wpomni-ip-toggle" aria-label="<?php esc_attr_e('Toggle IP visibility', 'wp-omni-auth'); ?>"
                                        data-show-title="<?php esc_attr_e('Show IP', 'wp-omni-auth'); ?>"
                                        data-hide-title="<?php esc_attr_e('Hide IP', 'wp-omni-auth'); ?>">
                                        <svg class="wpomni-eye-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </span>
                            </td>
                            <td><span class="wpomni-status-badge <?php echo esc_attr($row['status']); ?>"><?php echo esc_html($row['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Provider Status -->
    <div class="wpomni-section">
        <div class="wpomni-section-header">
            <h2>
                <?php esc_html_e('Provider Status', 'wp-omni-auth'); ?>
                <span class="wpomni-section-count"><?php echo (int) count($provider_statuses); ?></span>
            </h2>
        </div>
        <div class="wpomni-section-body">
            <?php if (empty($provider_statuses)) : ?>
                <p class="wpomni-no-data"><?php esc_html_e('No enabled providers yet.', 'wp-omni-auth'); ?></p>
            <?php else : ?>
                <div class="wpomni-provider-status-scroll">
                    <ul class="wpomni-provider-status-list">
                        <?php foreach ($provider_statuses as $item) : ?>
                        <li class="wpomni-provider-status-item<?php echo !empty($item['is_active']) ? '' : ' inactive'; ?>">
                            <div class="wpomni-ps-icon">
                                <?php if (!empty($item['icon_html'])) : ?>
                                    <?php echo $item['icon_html']; ?>
                                <?php else : ?>
                                    <span class="wpomni-text-faint">—</span>
                                <?php endif; ?>
                            </div>
                            <div class="wpomni-ps-info">
                                <p class="wpomni-ps-name"><?php echo esc_html($item['name']); ?></p>
                                <p class="wpomni-ps-meta"><?php echo esc_html($item['slug']); ?></p>
                            </div>
                            <span class="wpomni-card-status <?php echo esc_attr($item['status_class']); ?>"><?php echo esc_html($item['status_label']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
