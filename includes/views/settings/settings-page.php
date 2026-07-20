<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpomni-admin">

    <!-- ============================================ -->
    <!-- Top tab bar (Dashboard / Providers / Settings / About) -->
    <!-- ============================================ -->
    <nav class="wpomni-tabs" aria-label="<?php esc_attr_e('WP-OmniAuth navigation', 'wp-omni-auth'); ?>">
        <a href="<?php echo esc_url(add_query_arg('tab', 'dashboard')); ?>" class="wpomni-tab-item<?php echo $current_tab === 'dashboard' ? ' active' : ''; ?>"<?php echo $current_tab === 'dashboard' ? ' aria-current="page"' : ''; ?>>
            <?php esc_html_e('Dashboard', 'wp-omni-auth'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'providers')); ?>" class="wpomni-tab-item<?php echo $current_tab === 'providers' ? ' active' : ''; ?>"<?php echo $current_tab === 'providers' ? ' aria-current="page"' : ''; ?>>
            <?php esc_html_e('OAuth Providers', 'wp-omni-auth'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'settings')); ?>" class="wpomni-tab-item<?php echo $current_tab === 'settings' ? ' active' : ''; ?>"<?php echo $current_tab === 'settings' ? ' aria-current="page"' : ''; ?>>
            <?php esc_html_e('Settings', 'wp-omni-auth'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'about')); ?>" class="wpomni-tab-item<?php echo $current_tab === 'about' ? ' active' : ''; ?>"<?php echo $current_tab === 'about' ? ' aria-current="page"' : ''; ?>>
            <?php esc_html_e('About', 'wp-omni-auth'); ?>
        </a>
    </nav>

    <div class="wpomni-main">

        <?php settings_errors('wpomni_settings'); ?>

        <?php if (isset($_GET['saved']) && $_GET['saved'] === 'true') : ?>
            <div class="wpomni-saved-notice">
                <p><?php esc_html_e('Settings saved.', 'wp-omni-auth'); ?></p>
            </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- Tab 1: Dashboard (no form needed)          -->
        <!-- ============================================ -->
        <div id="wpomni-tab-dashboard" class="wpomni-tab-content<?php echo $current_tab === 'dashboard' ? ' active' : ''; ?>">
            <?php echo $dashboard_html; ?>
        </div>

        <form method="post" action="<?php echo esc_url(set_url_scheme(admin_url('admin-post.php'), 'https')); ?>" id="wpomni-form-providers" novalidate>
            <input type="hidden" name="action" value="wpomni_save_providers">
            <?php wp_nonce_field('wpomni_providers_nonce', 'wpomni_providers_nonce'); ?>

            <!-- ============================================ -->
            <!-- Tab 2: OAuth Providers                       -->
            <!-- ============================================ -->
            <div id="wpomni-tab-providers" class="wpomni-tab-content<?php echo $current_tab === 'providers' ? ' active' : ''; ?>" data-active-provider="<?php echo esc_attr($active_provider); ?>">

                <!-- Provider List View -->
                <div id="wpomni-provider-list" class="wpomni-provider-list">
                    <?php echo $provider_list_html; ?>
                </div>

                <!-- Provider Detail Views (one per provider, JS-toggled) -->
                <?php echo $provider_details_html; ?>

                <?php
                // Hidden field: preserve custom provider slugs
                $custom_slugs = get_option('wpomni_custom_providers', []);
                $slugs_string = is_array($custom_slugs) ? implode(',', $custom_slugs) : '';
                ?>
                <input type="hidden" name="wpomni_custom_providers" value="<?php echo esc_attr($slugs_string); ?>">
            </div>
        </form>

        <form method="post" action="options.php" id="wpomni-form-settings">
            <?php settings_fields('wpomni_home'); ?>

            <!-- ============================================ -->
            <!-- Tab 3: Settings                              -->
            <!-- ============================================ -->
            <div id="wpomni-tab-settings" class="wpomni-tab-content<?php echo $current_tab === 'settings' ? ' active' : ''; ?>">

                <div class="wpomni-settings-layout">
                    <nav class="wpomni-settings-sidebar" aria-label="<?php esc_attr_e('Settings sections', 'wp-omni-auth'); ?>">
                        <ul class="wpomni-settings-nav">
                            <?php foreach ($settings_sub_tabs as $sub_key => $sub_label) : ?>
                                <li>
                                    <a href="<?php echo esc_url(add_query_arg(['tab' => 'settings', 'sub' => $sub_key])); ?>"
                                       class="wpomni-settings-nav-item<?php echo $current_sub === $sub_key ? ' active' : ''; ?>"<?php echo $current_sub === $sub_key ? ' aria-current="page"' : ''; ?>">
                                        <?php echo esc_html($sub_label); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                    <div class="wpomni-settings-content">

                    <?php if (!$has_providers) : ?>
                        <div class="wpomni-notice wpomni-notice-warning">
                            <strong><?php esc_html_e('No OAuth providers configured.', 'wp-omni-auth'); ?></strong><br>
                            <?php
                            printf(
                                /* translators: %s: URL to OAuth Providers tab */
                                wp_kses(__('Please go to <a href="%s">OAuth Providers</a> to set up at least one login method.', 'wp-omni-auth'), ['a' => ['href' => []]]),
                                esc_url(admin_url('options-general.php?page=wp-omni-auth&tab=providers'))
                            );
                            ?>
                        </div>
                        <div class="wpomni-disabled-overlay">
                    <?php endif; ?>

                    <?php
                    // Render registered sections grouped by their declared sub_tab.
                    // Each sub-tab gets a .wpomni-subtab-content wrapper so ALL fields stay
                    // in the single options.php form (saving one sub-tab does not
                    // clear the others). 'data' is skipped here and rendered below,
                    // outside the no-providers overlay, so it stays available without providers.
                    foreach ($settings_sub_tabs as $sub_key => $sub_label) {
                        if ($sub_key === 'data') {
                            continue; // Rendered via its registered section below (outside the overlay).
                        }
                        ?>
                        <div class="wpomni-subtab-content<?php echo $current_sub === $sub_key ? ' active' : ''; ?>" data-sub="<?php echo esc_attr($sub_key); ?>">
                            <?php foreach ($sections_by_sub[$sub_key] ?? [] as $section) : ?>
                                <div class="wpomni-section">
                                    <div class="wpomni-section-header">
                                        <h2><?php echo esc_html($section['title']); ?></h2>
                                    </div>
                                    <div class="wpomni-section-body">
                                        <?php call_user_func($section['render_callback']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                    }
                    ?>

                    <?php if (!$has_providers) : ?>
                        </div><!-- .wpomni-disabled-overlay -->
                    <?php endif; ?>

                    <?php
                    // Data Management is a registered section (slug 'data') but is rendered
                    // here, outside the no-providers overlay, so it stays available even when
                    // no provider is configured. The loop above `continue`s for 'data'.
                    // $data_html already contains the inner section content; wrap it here.
                    if ($data_html !== '') :
                    ?>
                    <div class="wpomni-subtab-content<?php echo $current_sub === 'data' ? ' active' : ''; ?>" data-sub="data">
                        <div class="wpomni-section wpomni-mt-6">
                            <div class="wpomni-section-header">
                                <h2><?php esc_html_e('Data Management', 'wp-omni-auth'); ?></h2>
                            </div>
                            <div class="wpomni-section-body">
                                <?php echo $data_html; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php submit_button(); ?>

            <!-- Modal: Confirm Full Reset (always present, outside sub-tabs) -->
            <div class="wpomni-modal-overlay" id="wpomni-modal-confirm-reset">
                <div class="wpomni-modal wpomni-modal--reset">
                    <div class="wpomni-modal-header">
                        <h3><?php esc_html_e('Confirm Full Reset', 'wp-omni-auth'); ?></h3>
                    </div>
                    <div class="wpomni-modal-body">
                        <p><?php esc_html_e('This will delete ALL plugin data including:', 'wp-omni-auth'); ?></p>
                        <ul class="wpomni-reset-list">
                            <li><?php esc_html_e('All settings and provider configurations', 'wp-omni-auth'); ?></li>
                            <li><?php esc_html_e('Login history logs', 'wp-omni-auth'); ?></li>
                            <li><?php esc_html_e('IP blacklist and rate limits', 'wp-omni-auth'); ?></li>
                            <li><?php esc_html_e('Emergency access key', 'wp-omni-auth'); ?></li>
                            <li><?php esc_html_e('User OAuth bindings', 'wp-omni-auth'); ?></li>
                        </ul>
                        <p class="wpomni-danger-text"><?php esc_html_e('This action cannot be undone!', 'wp-omni-auth'); ?></p>
                    </div>
                    <div class="wpomni-modal-footer">
                        <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'wp-omni-auth'); ?></button>
                        <button type="button" class="button button-primary wpomni-danger-fill" id="wpomni-confirm-reset-yes"><?php esc_html_e('Reset Everything', 'wp-omni-auth'); ?></button>
                    </div>
                </div>
            </div>
            </div><!-- .wpomni-settings-content -->
            </div><!-- .wpomni-settings-layout -->
            </div><!-- #wpomni-tab-settings -->
        </form>

        <!-- ============================================ -->
        <!-- Tab 4: About (no form needed)                -->
        <!-- ============================================ -->
        <div id="wpomni-tab-about" class="wpomni-tab-content<?php echo $current_tab === 'about' ? ' active' : ''; ?>">
            <?php echo $about_html; ?>
        </div>

        <!-- ============================================ -->
        <!-- Modal: Add Custom Provider                   -->
        <!-- ============================================ -->
        <div class="wpomni-modal-overlay" id="wpomni-modal-add">
            <div class="wpomni-modal">
                <div class="wpomni-modal-header">
                    <h3><?php esc_html_e('Add Custom Provider', 'wp-omni-auth'); ?></h3>
                </div>
                <div class="wpomni-modal-body">
                    <p><label for="wpomni-new-provider-name"><?php esc_html_e('Provider Name', 'wp-omni-auth'); ?></label></p>
                    <input type="text" id="wpomni-new-provider-name" placeholder="<?php echo esc_attr__('e.g. My GitLab', 'wp-omni-auth'); ?>" autocomplete="off">
                </div>
                <div class="wpomni-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'wp-omni-auth'); ?></button>
                    <button type="button" class="button button-primary" id="wpomni-modal-add-confirm"><?php esc_html_e('Add', 'wp-omni-auth'); ?></button>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- Modal: Confirm Remove Provider               -->
        <!-- ============================================ -->
        <div class="wpomni-modal-overlay" id="wpomni-modal-remove">
            <div class="wpomni-modal">
                <div class="wpomni-modal-header">
                    <h3><?php esc_html_e('Remove Provider', 'wp-omni-auth'); ?></h3>
                </div>
                <div class="wpomni-modal-body">
                    <p><?php esc_html_e('Are you sure you want to remove this custom provider? All settings will be lost.', 'wp-omni-auth'); ?></p>
                    <input type="hidden" id="wpomni-remove-provider-slug" value="">
                </div>
                <div class="wpomni-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'wp-omni-auth'); ?></button>
                    <button type="button" class="button button-danger" id="wpomni-modal-remove-confirm"><?php esc_html_e('Remove', 'wp-omni-auth'); ?></button>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- Modal: Emergency Key Management              -->
        <!-- ============================================ -->
        <div class="wpomni-modal-overlay" id="wpomni-modal-emergency-key">
            <div class="wpomni-modal wpomni-modal-key">
                <div class="wpomni-modal-header">
                    <h3><?php esc_html_e('Emergency Key Management', 'wp-omni-auth'); ?></h3>
                </div>
                <div class="wpomni-modal-body">
                    <p class="description wpomni-mb-2"><?php esc_html_e('Current key', 'wp-omni-auth'); ?></p>
                    <code id="wpomni-modal-key-display" class="wpomni-key-display">—</code>
                    <div id="wpomni-modal-key-hint" class="hidden wpomni-mt-2 wpomni-success-text"><?php esc_html_e('Click to copy', 'wp-omni-auth'); ?></div>
                    <div id="wpomni-modal-key-status" class="wpomni-mt-2 wpomni-muted"></div>
                </div>
                <div class="wpomni-modal-footer wpomni-modal-footer-wrap">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Close', 'wp-omni-auth'); ?></button>
                    <button type="button" class="button" id="wpomni-modal-view-key"><?php esc_html_e('View Full Key', 'wp-omni-auth'); ?></button>
                    <button type="button" class="button button-primary" id="wpomni-modal-regen-key"><?php esc_html_e('Regenerate Key', 'wp-omni-auth'); ?></button>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- Modal: Confirm Regenerate Key                -->
        <!-- ============================================ -->
        <div class="wpomni-modal-overlay" id="wpomni-modal-confirm-regen">
            <div class="wpomni-modal wpomni-modal-key">
                <div class="wpomni-modal-header">
                    <h3><?php esc_html_e('Regenerate Emergency Key', 'wp-omni-auth'); ?></h3>
                </div>
                <div class="wpomni-modal-body">
                    <p><?php esc_html_e('Are you sure you want to regenerate the emergency key? The old key will stop working immediately.', 'wp-omni-auth'); ?></p>
                </div>
                <div class="wpomni-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'wp-omni-auth'); ?></button>
                    <button type="button" class="button button-primary" id="wpomni-confirm-regen-yes"><?php esc_html_e('Regenerate', 'wp-omni-auth'); ?></button>
                </div>
            </div>
        </div>
            </div><!-- .wpomni-main -->
        </div><!-- .wrap -->
