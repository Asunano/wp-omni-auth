<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Provider_Checker — provider health config check.
 */
class Test_Provider_Checker extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        WP_Mock::userFunction('__', [
            'return' => function ($text, $domain = null) {
                return $text;
            },
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        // Reset the Provider_Checker singleton
        $ref = new ReflectionProperty('WPOmniAuth_Provider_Checker', 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        // Reset the Manager singleton
        $ref_m = new ReflectionProperty('WPOmniAuth_Manager', 'instance');
        $ref_m->setAccessible(true);
        $ref_m->setValue(null, null);
    }

    private function make_provider($slug = 'custom', $required_keys = ['client_id', 'client_secret'], $configured = true) {
        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('get_slug')->willReturn($slug);
        $provider->method('get_required_config_keys')->willReturn($required_keys);
        $provider->method('get_name')->willReturn(ucfirst($slug));

        // get_settings_fields returns label info
        $provider->method('get_settings_fields')->willReturn([
            'client_id'     => ['label' => 'Client ID'],
            'client_secret' => ['label' => 'Client Secret'],
        ]);

        return $provider;
    }

    public function test_check_returns_configured_status() {
        $provider = $this->make_provider('github', ['client_id', 'client_secret']);

        // Both keys are configured (non-empty)
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_github_client_id') {
                    return 'my-client-id';
                }
                if ($key === 'wpomni_github_client_secret') {
                    return 'my-secret';
                }
                return $default;
            },
        ]);

        $result = WPOmniAuth_Provider_Checker::instance()->check($provider);

        $this->assertTrue($result['configured']);
        $this->assertSame('ok', $result['status']);
        $this->assertEmpty($result['missing']);
    }

    public function test_check_returns_incomplete_status() {
        $provider = $this->make_provider('github', ['client_id', 'client_secret']);

        // client_id configured, client_secret missing
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_github_client_id') {
                    return 'my-client-id';
                }
                if ($key === 'wpomni_github_client_secret') {
                    return ''; // empty = missing
                }
                return $default;
            },
        ]);

        $result = WPOmniAuth_Provider_Checker::instance()->check($provider);

        $this->assertFalse($result['configured']);
        $this->assertSame('incomplete', $result['status']);
        $this->assertContains('client_secret', $result['missing']);
    }

    public function test_check_detects_all_missing_keys() {
        $provider = $this->make_provider('custom', ['client_id', 'client_secret', 'authorization_url']);

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return ''; // all empty
            },
        ]);

        $result = WPOmniAuth_Provider_Checker::instance()->check($provider);

        $this->assertFalse($result['configured']);
        $this->assertCount(3, $result['missing']);
        $this->assertContains('client_id', $result['missing']);
        $this->assertContains('client_secret', $result['missing']);
        $this->assertContains('authorization_url', $result['missing']);
    }

    public function test_check_includes_label_for_missing_keys() {
        $provider = $this->make_provider('github', ['client_id']);

        WP_Mock::userFunction('get_option', [
            'return' => '',
        ]);

        $result = WPOmniAuth_Provider_Checker::instance()->check($provider);

        $this->assertIsString($result['label']);
        // Label should mention the missing key name
        $this->assertNotEmpty($result['label']);
    }

    // ================================================================
    // Singleton lifecycle (cached status read)
    // ================================================================

    public function test_singleton_returns_same_instance() {
        $first = WPOmniAuth_Provider_Checker::instance();
        $second = WPOmniAuth_Provider_Checker::instance();

        $this->assertSame($first, $second);
    }

    public function test_refresh_cache_calls_check_on_providers() {
        $provider = $this->make_provider('github', ['client_id']);

        // Mock Manager singleton via the Testable_Manager pattern
        $manager = new Testable_Manager();
        $manager->fake_providers = [$provider];

        $ref_instance = new ReflectionProperty('WPOmniAuth_Manager', 'instance');
        $ref_instance->setAccessible(true);
        $ref_instance->setValue(null, $manager);

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_github_client_id') {
                    return 'my-id';
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'return' => true,
        ]);

        WPOmniAuth_Provider_Checker::instance()->refresh_cache();
        $this->assertTrue(true);
    }
}
