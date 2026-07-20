<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Manager's security utilities: IP blacklist (incl. CIDR),
 * rate limiting, client-IP resolution, and log-data redaction.
 */
class Test_Security extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();
        // get_client_ip() / get_client_ip() proxy handling call sanitize_text_field.
        WP_Mock::userFunction('sanitize_text_field', [
            'return' => function ($v) {
                return is_string($v) ? trim($v) : $v;
            },
        ]);
        // Default: any unspecified get_option calls return the default value.
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ])->byDefault();
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
    }

    private function invoke_private_static($method, ...$args) {
        $ref = new ReflectionMethod('WPOmniAuth_Manager', $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public function test_is_ip_blacklisted_empty() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_blacklisted_ips', []],
            'return' => [],
        ]);

        $this->assertFalse(WPOmniAuth_Manager::is_ip_blacklisted('1.2.3.4'));
    }

    public function test_is_ip_blacklisted_exact() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_blacklisted_ips', []],
            'return' => [['ip' => '1.2.3.4']],
        ]);

        $this->assertTrue(WPOmniAuth_Manager::is_ip_blacklisted('1.2.3.4'));
        $this->assertFalse(WPOmniAuth_Manager::is_ip_blacklisted('5.6.7.8'));
    }

    public function test_is_ip_blacklisted_cidr() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_blacklisted_ips', []],
            'return' => [['ip' => '192.168.1.0/24']],
        ]);

        $this->assertTrue(WPOmniAuth_Manager::is_ip_blacklisted('192.168.1.50'));
        $this->assertFalse(WPOmniAuth_Manager::is_ip_blacklisted('192.168.2.50'));
    }

    public function test_ip_in_cidr() {
        $this->assertTrue($this->invoke_private_static('ip_in_cidr', '10.0.0.5', '10.0.0.0/8'));
        $this->assertFalse($this->invoke_private_static('ip_in_cidr', '11.0.0.5', '10.0.0.0/8'));
    }

    public function test_check_rate_limit_not_exceeded() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_per_ip', 10],
            'return' => 10,
        ]);
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_global', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_' . hash('sha256', '1.2.3.4')],
            'return' => 5,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_global'],
            'return' => 5,
        ]);

        $this->assertFalse(WPOmniAuth_Manager::check_rate_limit('1.2.3.4'));
    }

    public function test_check_rate_limit_per_ip_exceeded() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_per_ip', 10],
            'return' => 10,
        ]);
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_global', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_' . hash('sha256', '1.2.3.4')],
            'return' => 10,
        ]);

        $this->assertTrue(WPOmniAuth_Manager::check_rate_limit('1.2.3.4'));
    }

    public function test_check_rate_limit_global_exceeded() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_per_ip', 10],
            'return' => 10,
        ]);
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_rate_limit_global', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_' . hash('sha256', '1.2.3.4')],
            'return' => 0,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_global'],
            'return' => 60,
        ]);

        $this->assertTrue(WPOmniAuth_Manager::check_rate_limit('1.2.3.4'));
    }

    public function test_increment_rate_limit() {
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_' . hash('sha256', '1.2.3.4')],
            'return' => 2,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_global'],
            'return' => 7,
        ]);
        // increment_rate_limit() writes the per-IP and global counters exactly
        // once each; collapse the two mocks into a single count assertion.
        WP_Mock::userFunction('set_transient', [
            'times' => 2,
            'return' => true,
        ]);

        WPOmniAuth_Manager::increment_rate_limit('1.2.3.4');

        // Real assertion so the test is not flagged Risky (set_transient count is
        // still verified by the times => 2 expectation above at tear-down).
        $this->assertTrue(true);
    }

    public function test_get_client_ip_direct() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_trusted_proxy', 'no'],
            'return' => 'no',
        ]);
        $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

        $this->assertSame('9.9.9.9', WPOmniAuth_Manager::get_client_ip());
    }

    public function test_get_client_ip_cloudflare() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_trusted_proxy', 'no'],
            'return' => 'yes',
        ]);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '2.2.2.2, 3.3.3.3';

        $this->assertSame('1.1.1.1', WPOmniAuth_Manager::get_client_ip());
    }

    public function test_get_client_ip_x_forwarded_for() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_trusted_proxy', 'no'],
            'return' => 'yes',
        ]);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2.2.2.2, 3.3.3.3';

        $this->assertSame('2.2.2.2', WPOmniAuth_Manager::get_client_ip());
    }

    public function test_get_client_ip_custom_first_segment() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_trusted_proxy', 'no'],
            'return' => 'yes',
        ]);
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_client_ip_source', 'auto'],
            'return' => 'custom',
        ]);
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_client_ip_custom_header', ''],
            'return' => 'X-Forwarded-For',
        ]);
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_client_ip_custom_position', 'last'],
            'return' => 'first',
        ]);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        // A CDN (EdgeOne/CF/ESA) may append its own hop at the end, so the
        // real client IP sits at the FIRST segment of X-Forwarded-For.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.1';

        $this->assertSame('203.0.113.7', WPOmniAuth_Manager::get_client_ip());
    }

    public function test_sanitize_log_data_redacts_secrets() {
        $data = [
            'access_token'  => 'secret-token',
            'client_secret' => 'super-secret',
            'token'         => 'tok',
            'nested'        => ['_access_token' => 'inner-secret', 'keep' => 'visible'],
            'keep_me'       => 'plain',
        ];

        $result = $this->invoke_private_static('sanitize_log_data', $data);

        $this->assertSame('***REDACTED***', $result['access_token']);
        $this->assertSame('***REDACTED***', $result['client_secret']);
        $this->assertSame('***REDACTED***', $result['token']);
        $this->assertSame('***REDACTED***', $result['nested']['_access_token']);
        $this->assertSame('visible', $result['nested']['keep']);
        $this->assertSame('plain', $result['keep_me']);
    }
}
