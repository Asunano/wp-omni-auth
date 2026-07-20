<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the provider base-class sanitizers (HTTPS enforcement and secret
 * preservation). These guard the OAuth endpoint and credential handling.
 */
class Test_Sanitizers extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
    }

    /**
     * Build a concrete provider instance (GitHub) for exercising base methods.
     */
    private function make_provider() {
        return new WPOmniAuth_Github_Provider();
    }

    public function test_sanitize_url_rejects_http() {
        WP_Mock::userFunction('add_settings_error', ['times' => 1]);

        $provider = $this->make_provider();
        $result   = $provider->sanitize_url('http://example.com/token');

        $this->assertSame('', $result, 'Non-HTTPS URLs must be rejected.');
    }

    public function test_sanitize_url_rejects_invalid() {
        WP_Mock::userFunction('add_settings_error', ['times' => 1]);
        WP_Mock::userFunction('esc_url_raw', [
            'return' => function ($value) {
                return $value;
            },
        ]);

        $provider = $this->make_provider();
        $result   = $provider->sanitize_url('not a url');

        $this->assertSame('', $result, 'Malformed URLs must be rejected.');
    }

    public function test_sanitize_url_allows_https() {
        WP_Mock::userFunction('esc_url_raw', [
            'return' => function ($value) {
                return $value;
            },
        ]);
        // filter_var is a native PHP function; no mock needed.
        WP_Mock::userFunction('add_settings_error', ['times' => 0]);

        $provider = $this->make_provider();
        $result   = $provider->sanitize_url('https://example.com/token');

        $this->assertSame('https://example.com/token', $result);
    }

    public function test_sanitize_secret_preserves_existing_when_empty() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_github_client_secret', ''],
            'return' => 'existing-secret',
        ]);

        $provider = $this->make_provider();
        $result   = $provider->sanitize_secret('');

        $this->assertSame('existing-secret', $result, 'Empty secret must keep the stored value.');
    }

    public function test_sanitize_secret_uses_new_value() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_github_client_secret', ''],
            'return' => 'existing-secret',
        ]);

        $provider = $this->make_provider();
        $result   = $provider->sanitize_secret('fresh-secret');

        $this->assertSame('fresh-secret', $result, 'A provided secret must replace the stored value.');
    }
}
