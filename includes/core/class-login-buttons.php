<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth login button rendering — builds the provider-button markup with
 * brand colors, icons, and CSS variables.
 *
 * Extracted from WPOmniAuth_Manager so the logic is independently testable.
 */
class WPOmniAuth_Login_Buttons {

    /**
     * Build the OAuth provider-button markup (used both when appending buttons
     * to the default wp-login form and when rendering the full OAuth-only
     * screen).
     *
     * Accepts providers as a parameter (the caller, typically Manager, owns
     * the provider list).
     *
     * @param WPOmniAuth_Provider[] $providers All available providers.
     * @return string
     */
    public static function render($providers) {
        self::log('Building OAuth login buttons');

        $enabled_providers = array_filter($providers, function ($provider) {
            return $provider->is_enabled();
        });

        if (empty($enabled_providers)) {
            self::log('No enabled providers found');
            return '';
        }

        self::log('Enabled providers', array_keys($enabled_providers));

        $html = '<div class="wpomni-login-buttons">';
        foreach ($enabled_providers as $provider) {
            $state = WPOmniAuth_OAuth_State::create($provider->get_slug());
            $url = $provider->get_authorization_url($state);
            if (empty($url)) {
                self::log('WARNING: Empty auth URL for provider', ['slug' => $provider->get_slug()]);
                continue;
            }
            self::log('Generated auth URL', ['slug' => $provider->get_slug(), 'url' => $url]);
            $icon = self::get_icon($provider);
            $slug = $provider->get_slug();

            $extra_class = '';
            $style = '';
            $color = $provider->get_button_color();
            if (!empty($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                $extra_class = ' wpomni-btn-brand';
                $text_color = $provider->get_button_text_color();
                if (empty($text_color) || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $text_color)) {
                    $text_color = $color;
                }
                $border_color = $provider->get_button_border_color();
                if (empty($border_color) || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $border_color)) {
                    $border_color = $color;
                }
                // Use brand color as background when the provider explicitly sets a
                // contrasting text color (e.g. GitHub: dark bg + white text).
                // Otherwise default to white background (Google-style: white bg +
                // branded icon/text) so the brand-colored SVG icon stays visible.
                $bg_color = ($text_color !== $color) ? $color : '#ffffff';
                $style = ' style="--wpomni-btn-bg:' . esc_attr($bg_color)
                    . ';--wpomni-btn-color:' . esc_attr($text_color)
                    . ';--wpomni-btn-border:' . esc_attr($border_color) . '"';
            }

            $html .= sprintf(
                '<p><a href="%s" class="wpomni-btn wpomni-btn-%s%s" data-provider-name="%s"%s>%s<span class="wpomni-btn-label">%s</span></a></p>',
                esc_url(add_query_arg('wpomni_login', $slug, wp_login_url())),
                esc_attr($slug),
                $extra_class,
                esc_attr($provider->get_name()),
                $style,
                $icon,
                esc_html(sprintf(__('Login with %s', 'wp-omni-auth'), $provider->get_name()))
            );
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * If icon is a URL, convert to img tag for HTML display.
     */
    public static function normalize_icon_for_display($icon) {
        if (empty($icon)) {
            return '';
        }
        if (preg_match('#^https?://#i', trim($icon))) {
            return '<img src="' . esc_url($icon) . '" alt="" style="width:100%;height:100%;object-fit:contain;">';
        }
        return $icon;
    }

    /**
     * Get a safe icon SVG/HTML for a provider.
     */
    public static function get_icon($provider) {
        $slug = $provider->get_slug();

        // Check for custom icon stored option (for custom providers or overrides)
        $custom_icon = get_option("wpomni_{$slug}_icon", '');
        if (!empty($custom_icon)) {
            if (preg_match('#^https?://#i', trim($custom_icon))) {
                return '<img src="' . esc_url($custom_icon) . '" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">';
            }
            return wp_kses($custom_icon, [
                'svg'     => ['viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true],
                'path'    => ['fill' => true, 'd' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true],
                'g'       => ['transform' => true, 'fill' => true, 'stroke' => true],
                'circle'  => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'transform' => true],
                'rect'    => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true, 'transform' => true],
            ]);
        }

        $icons = [
            'github' => '<svg viewBox="0 0 16 16" width="16" height="16"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>',
            'google' => '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>',
        ];

        if (isset($icons[$slug])) {
            return $icons[$slug];
        }

        return $provider->get_icon();
    }

    /**
     * Log a debug message via the plugin's logger.
     */
    private static function log($message, $data = null) {
        WPOmniAuth_Logger::debug_log($message, $data, 'LoginButtons');
    }
}
