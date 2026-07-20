<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Manager::build_oauth_buttons().
 *
 * Verifies that the login-button brand color is driven by each provider's
 * get_button_color() (no longer hardcoded for GitHub/Google, and no longer
 * limited to custom providers).
 */
class Test_Login_Buttons extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        // Most option lookups return their default; the debug-log path stays
        // off so build_oauth_buttons() never touches the filesystem.
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ]);
        WP_Mock::userFunction('set_transient', [ 'return' => true ]);
        WP_Mock::userFunction('esc_url', [ 'return' => function ($u) { return $u; } ]);
        WP_Mock::userFunction('esc_attr', [ 'return' => function ($v) { return $v; } ]);
        WP_Mock::userFunction('esc_html', [ 'return' => function ($v) { return $v; } ]);
        WP_Mock::userFunction('__', [ 'return' => function ($t) { return $t; } ]);
        WP_Mock::userFunction('wp_login_url', [ 'return' => 'https://example.com/wp-login.php' ]);
        WP_Mock::userFunction('add_query_arg', [
            'return' => function ($key, $value, $url) {
                $query = is_array($key) ? http_build_query($key) : $key . '=' . $value;
                return $url . '?' . $query;
            },
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
    }

    private function make_manager(array $providers) {
        $manager = new Testable_Manager();
        $manager->fake_providers = $providers;
        return $manager;
    }

    private function mock_provider($slug, $color, $text = '', $border = '') {
        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('is_enabled')->willReturn(true);
        $provider->method('get_slug')->willReturn($slug);
        $provider->method('get_name')->willReturn(ucfirst($slug));
        $provider->method('get_authorization_url')->willReturn('https://example.com/auth/' . $slug);
        $provider->method('get_button_color')->willReturn($color);
        $provider->method('get_button_text_color')->willReturn($text);
        $provider->method('get_button_border_color')->willReturn($border);
        return $provider;
    }

    public function test_builtin_provider_gets_brand_color_class_and_var() {
        $manager = $this->make_manager([ $this->mock_provider('github', '#24292e') ]);
        $html = $manager->build_oauth_buttons();

        // Brand color is applied to every built-in provider (was previously
        // hardcoded only for GitHub/Google). When get_button_text_color() is
        // not set, the button uses a white background with branded text/border.
        $this->assertStringContainsString('wpomni-btn-brand', $html);
        $this->assertStringContainsString('--wpomni-btn-bg:#ffffff', $html);
        $this->assertStringContainsString('--wpomni-btn-color:#24292e', $html);
        $this->assertStringContainsString('--wpomni-btn-border:#24292e', $html);
        // The slug class is still present (used elsewhere), but the brand class
        // now drives the color via CSS variables.
        $this->assertStringContainsString('wpomni-btn-github', $html);
    }

    public function test_google_uses_explicit_text_and_border_colors() {
        $manager = $this->make_manager([ $this->mock_provider('google', '#ffffff', '#3c4043', '#dadce0') ]);
        $html = $manager->build_oauth_buttons();

        $this->assertStringContainsString('wpomni-btn-brand', $html);
        $this->assertStringContainsString('--wpomni-btn-bg:#ffffff', $html);
        $this->assertStringContainsString('--wpomni-btn-color:#3c4043', $html);
        $this->assertStringContainsString('--wpomni-btn-border:#dadce0', $html);
    }

    public function test_provider_without_color_falls_back_to_theme() {
        $manager = $this->make_manager([ $this->mock_provider('custom', '') ]);
        $html = $manager->build_oauth_buttons();

        // No brand class / inline color vars when get_button_color() is empty.
        $this->assertStringNotContainsString('wpomni-btn-brand', $html);
        $this->assertStringNotContainsString('--wpomni-btn-bg', $html);
    }
}
