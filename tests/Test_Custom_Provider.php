<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Custom_Provider — the user-configured OAuth provider.
 *
 * Covers constructor option-reading, settings fields, authorization URL
 * building, token exchange, user data fetching, and email extraction.
 */
class Test_Custom_Provider extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        WP_Mock::userFunction('sanitize_text_field', [
            'return' => function ($v) {
                return is_string($v) ? trim($v) : $v;
            },
        ]);
        WP_Mock::userFunction('__', [
            'return' => function ($text, $domain = null) {
                return $text;
            },
        ]);
        WP_Mock::userFunction('esc_html', [
            'return' => function ($v) { return $v; },
        ]);
        WP_Mock::userFunction('esc_url', [
            'return' => function ($v) { return $v; },
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
    }

    private function make_provider($slug = 'myapp', $option_overrides = []) {
        $options = array_merge([
            "wpomni_{$slug}_name"    => 'My App',
            "wpomni_{$slug}_icon"    => '<svg></svg>',
            'wpomni_custom_providers' => [
                $slug => ['status' => 'active'],
            ],
        ], $option_overrides);

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        return new WPOmniAuth_Custom_Provider($slug, 'My App', '<svg></svg>');
    }

    public function test_constructor_reads_options() {
        $provider = $this->make_provider();
        $this->assertSame('myapp', $provider->get_slug());
        $this->assertSame('My App', $provider->get_name());
    }

    public function test_get_settings_fields_returns_10_fields() {
        $provider = $this->make_provider();
        $fields = $provider->get_settings_fields();
        $this->assertIsArray($fields);
        $this->assertCount(10, $fields);
        $this->assertContains('client_id', array_column($fields, 'key'));
        $this->assertContains('client_secret', array_column($fields, 'key'));
        $this->assertContains('color', array_column($fields, 'key'));
        $this->assertContains('authorization_url', array_column($fields, 'key'));
        $this->assertContains('token_url', array_column($fields, 'key'));
        $this->assertContains('userinfo_url', array_column($fields, 'key'));
    }

    public function test_get_required_config_keys() {
        $provider = $this->make_provider();
        $keys = $provider->get_required_config_keys();
        $this->assertContains('client_id', $keys);
        $this->assertContains('client_secret', $keys);
        $this->assertContains('authorization_url', $keys);
        $this->assertContains('token_url', $keys);
        $this->assertContains('userinfo_url', $keys);
        $this->assertCount(5, $keys);
    }

    public function test_get_authorization_url_includes_params() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"              => 'My App',
            "wpomni_{$slug}_icon"              => '<svg></svg>',
            "wpomni_{$slug}_client_id"         => 'app-123',
            "wpomni_{$slug}_authorization_url" => 'https://provider.com/oauth/authorize',
            "wpomni_{$slug}_scope"             => 'openid profile',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);
        WP_Mock::userFunction('wp_login_url', [
            'return' => 'https://example.com/wp-login.php',
        ]);
        WP_Mock::userFunction('add_query_arg', [
            'return' => function ($key, $value, $url) {
                return $url . '?' . $key . '=' . $value;
            },
        ]);

        $provider = new WPOmniAuth_Custom_Provider($slug, 'My App', '<svg></svg>');
        $url = $provider->get_authorization_url('test-state-123');

        $this->assertStringContainsString('client_id=app-123', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
        $this->assertStringContainsString('scope=openid+profile', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString(rawurlencode('https://example.com/wp-login.php?wpomni_callback=myapp'), $url);
    }

    public function test_get_access_token_sends_post() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"              => 'My App',
            "wpomni_{$slug}_icon"              => '<svg></svg>',
            "wpomni_{$slug}_client_id"         => 'app-123',
            "wpomni_{$slug}_client_secret"     => 'secret-456',
            "wpomni_{$slug}_token_url"         => 'https://provider.com/oauth/token',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);
        WP_Mock::userFunction('wp_login_url', [
            'return' => 'https://example.com/wp-login.php',
        ]);
        WP_Mock::userFunction('add_query_arg', [
            'return' => function ($key, $value, $url) {
                return $url . '?' . $key . '=' . $value;
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => function ($response) {
                return $response['body'] ?? '';
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => 200,
        ]);

        $provider = $this->getMockBuilder(WPOmniAuth_Custom_Provider::class)
            ->setConstructorArgs([$slug, 'My App', '<svg></svg>'])
            ->setMethods(['remote_post'])
            ->getMock();

        $provider->method('remote_post')->willReturn([
            'access_token' => 'token-xyz',
        ]);

        $token = $provider->get_access_token('authcode-123');
        $this->assertSame('token-xyz', $token);
    }

    public function test_get_access_token_custom_key() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"              => 'My App',
            "wpomni_{$slug}_icon"              => '<svg></svg>',
            "wpomni_{$slug}_client_id"         => 'app-123',
            "wpomni_{$slug}_client_secret"     => 'secret-456',
            "wpomni_{$slug}_token_url"         => 'https://provider.com/oauth/token',
            "wpomni_{$slug}_token_key"         => 'id_token',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);
        WP_Mock::userFunction('wp_login_url', [
            'return' => 'https://example.com/wp-login.php',
        ]);
        WP_Mock::userFunction('add_query_arg', [
            'return' => function ($key, $value, $url) {
                return $url . '?' . $key . '=' . $value;
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => function ($response) {
                return $response['body'] ?? '';
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => 200,
        ]);

        $provider = $this->getMockBuilder(WPOmniAuth_Custom_Provider::class)
            ->setConstructorArgs([$slug, 'My App', '<svg></svg>'])
            ->setMethods(['remote_post'])
            ->getMock();

        $provider->method('remote_post')->willReturn([
            'id_token' => 'custom-token-value',
        ]);

        $token = $provider->get_access_token('authcode-123');
        $this->assertSame('custom-token-value', $token);
    }

    public function test_get_user_data_sends_get() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"              => 'My App',
            "wpomni_{$slug}_icon"              => '<svg></svg>',
            "wpomni_{$slug}_userinfo_url"      => 'https://provider.com/oauth/userinfo',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => function ($response) {
                return $response['body'] ?? '';
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => 200,
        ]);

        $provider = $this->getMockBuilder(WPOmniAuth_Custom_Provider::class)
            ->setConstructorArgs([$slug, 'My App', '<svg></svg>'])
            ->setMethods(['remote_get'])
            ->getMock();

        $provider->method('remote_get')->willReturn([
            'id' => 'user-1', 'email' => 'user@example.com',
        ]);

        $data = $provider->get_user_data('token-xyz');
        $this->assertIsArray($data);
        $this->assertSame('user-1', $data['id']);
        $this->assertSame('user@example.com', $data['email']);
    }

    public function test_get_user_data_query_param_mode() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"              => 'My App',
            "wpomni_{$slug}_icon"              => '<svg></svg>',
            "wpomni_{$slug}_userinfo_url"      => 'https://provider.com/oauth/userinfo',
            "wpomni_{$slug}_token_in_header"   => 'no',
            "wpomni_{$slug}_token_key"         => 'access_token',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        // In query param mode, the provider should append the token as a query param
        $provider = $this->getMockBuilder(WPOmniAuth_Custom_Provider::class)
            ->setConstructorArgs([$slug, 'My App', '<svg></svg>'])
            ->setMethods(['remote_get'])
            ->getMock();

        $provider->expects($this->once())
            ->method('remote_get')
            ->with($this->callback(function ($url) {
                return strpos($url, 'access_token=') !== false;
            }))
            ->willReturn([
                'id' => 'user-1',
            ]);

        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => function ($response) {
                return $response['body'] ?? '';
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => 200,
        ]);

        $data = $provider->get_user_data('query-token-abc');
        $this->assertIsArray($data);
        $this->assertSame('user-1', $data['id']);
    }

    public function test_get_email_from_user_data() {
        $provider = $this->make_provider();
        $email = $provider->get_email_from_user_data(['id' => '1', 'email' => 'user@example.com']);
        $this->assertSame('user@example.com', $email);
    }

    public function test_get_email_from_user_data_nested() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"       => 'My App',
            "wpomni_{$slug}_icon"       => '<svg></svg>',
            "wpomni_{$slug}_email_field" => 'user.email',
            'wpomni_custom_providers'   => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        $provider = new WPOmniAuth_Custom_Provider($slug, 'My App', '<svg></svg>');
        $email = $provider->get_email_from_user_data([
            'user' => ['email' => 'nested@example.com'],
        ]);
        $this->assertSame('nested@example.com', $email);
    }

    public function test_get_email_from_user_data_flat_fallback() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"       => 'My App',
            "wpomni_{$slug}_icon"       => '<svg></svg>',
            "wpomni_{$slug}_email_field" => 'mail',
            'wpomni_custom_providers'   => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        $provider = new WPOmniAuth_Custom_Provider($slug, 'My App', '<svg></svg>');
        $email = $provider->get_email_from_user_data(['id' => '1', 'mail' => 'flat@example.com']);
        $this->assertSame('flat@example.com', $email);
    }

    public function test_get_button_color() {
        $slug = 'myapp';
        $options = [
            "wpomni_{$slug}_name"  => 'My App',
            "wpomni_{$slug}_icon"  => '<svg></svg>',
            "wpomni_{$slug}_color" => '#ff5500',
            'wpomni_custom_providers' => [$slug => ['status' => 'active']],
        ];

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        $provider = new WPOmniAuth_Custom_Provider($slug, 'My App', '<svg></svg>');
        $this->assertSame('#ff5500', $provider->get_button_color());
    }

    public function test_get_button_color_default_empty() {
        $provider = $this->make_provider();
        $this->assertSame('', $provider->get_button_color());
    }

    public function test_get_email_from_user_data_empty_when_missing() {
        $provider = $this->make_provider();
        $email = $provider->get_email_from_user_data(['id' => '1']);
        $this->assertSame('', $email);
    }

    public function test_get_user_id_from_user_data() {
        $provider = $this->make_provider();
        $this->assertSame('abc-123', $provider->get_user_id_from_user_data(['id' => 'abc-123']));
        $this->assertSame('', $provider->get_user_id_from_user_data([]));
    }
}
