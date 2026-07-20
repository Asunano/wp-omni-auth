<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Emergency_Access — the emergency backdoor flow.
 *
 * Covers entry-point guards, email link request/click, manual key verification,
 * CAPTCHA, honeypot, and rate limiting. Tests that terminate via wp_die or
 * wp_safe_redirect use output-buffering / exception handling to catch them.
 */
class Test_Emergency_Access extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        // Prevent actual output / exit during tests that may trigger wp_die.
        // We restore the error handler in tearDown().
        WP_Mock::userFunction('__', [
            'return' => function ($text, $domain = null) {
                return $text;
            },
        ]);
        WP_Mock::userFunction('esc_html__', [
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
        WP_Mock::userFunction('sanitize_text_field', [
            'return' => function ($v) {
                return is_string($v) ? trim($v) : $v;
            },
        ]);
        WP_Mock::userFunction('sanitize_email', [
            'return' => function ($v) { return $v; },
        ]);
        WP_Mock::userFunction('sanitize_key', [
            'return' => function ($v) { return $v; },
        ]);
        WP_Mock::userFunction('current_time', [
            'return' => '2026-01-01 00:00:00',
        ]);
        WP_Mock::userFunction('wp_verify_nonce', [
            'return' => true,
        ])->byDefault();
        WP_Mock::userFunction('is_email', [
            'return' => true,
        ]);
        WP_Mock::userFunction('wp_rand', [
            'return' => function ($min, $max) {
                // Return a deterministic value so CAPTCHA/SVG output is stable.
                return (int) (($min + $max) / 2);
            },
        ]);
        WP_Mock::userFunction('wp_generate_password', [
            'return' => function ($length, $special_chars = true) {
                return str_repeat('a', $length);
            },
        ]);
        WP_Mock::userFunction('wp_hash_password', [
            'return' => function ($password) {
                return hash('sha256', $password);
            },
        ]);
        WP_Mock::userFunction('wp_check_password', [
            'return' => function ($password, $hash) {
                return hash('sha256', $password) === $hash;
            },
        ]);
        WP_Mock::userFunction('wp_login_url', [
            'return' => 'https://example.com/wp-login.php',
        ]);
        WP_Mock::userFunction('wp_safe_redirect', [
            'return' => function ($url) {
                // Simulate the redirect by throwing a controlled exception
                // so tests can assert the redirect URL.
                throw new \RuntimeException('REDIRECT:' . $url);
            },
        ]);
        WP_Mock::userFunction('wp_die', [
            'return' => function ($message, $title = '', $args = []) {
                throw new \RuntimeException('WPDIE:' . $message);
            },
        ]);
        WP_Mock::userFunction('get_bloginfo', [
            'return' => 'Test Site',
        ]);
        WP_Mock::userFunction('home_url', [
            'return' => 'https://example.com',
        ]);
        WP_Mock::userFunction('admin_url', [
            'return' => 'https://example.com/wp-admin/',
        ]);

        $GLOBALS['pagenow'] = 'wp-login.php';
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        unset($GLOBALS['pagenow'], $_GET, $_POST, $_SERVER);
    }

    // ================================================================
    // Entry-point guards
    // ================================================================

    public function test_handle_not_on_login_page_returns() {
        $GLOBALS['pagenow'] = 'index.php';

        // Should return without error
        WPOmniAuth_Emergency_Access::handle_emergency_access();
        $this->assertTrue(true);
    }

    public function test_handle_oauth_only_disabled_returns() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_hide_password', 'no'],
            'return' => 'no',
        ]);

        // Should return without error when OAuth-only is disabled
        WPOmniAuth_Emergency_Access::handle_emergency_access();
        $this->assertTrue(true);
    }

    public function test_handle_emergency_already_active_returns() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_hide_password', 'no'],
            'return' => 'yes',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'  => ['wpomni_emergency_active'],
            'return' => 1, // already active
        ]);

        // Should return without error when emergency mode is already active
        WPOmniAuth_Emergency_Access::handle_emergency_access();
        $this->assertTrue(true);
    }

    public function test_handle_no_emergency_param_returns() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_hide_password', 'no'],
            'return' => 'yes',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'  => ['wpomni_emergency_active'],
            'return' => false,
        ]);

        // No ?wpomni_emergency in URL — should return without error
        $_GET = [];
        WPOmniAuth_Emergency_Access::handle_emergency_access();
        $this->assertTrue(true);
    }

    // ================================================================
    // Email link request (process_email_link_request)
    // ================================================================

    public function test_email_link_request_ip_rate_limited() {
        $_GET = ['wpomni_emergency' => '1', 'action' => 'email'];
        $_POST = [
            '_wpnonce'       => 'valid-nonce',
            'wpomni_email'   => 'admin@example.com',
            'wpomni_captcha' => 'ABCDE',
            'wpomni_captcha_token' => 'token-1',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                // IP rate limit: 3 requests already made
                if (strpos($key, 'wpomni_emg_reqip_') === 0) {
                    return 3;
                }
                if (strpos($key, 'wpomni_emg_captcha_') === 0) {
                    return hash('sha256', 'ABCDE');
                }
                return false;
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WPDIE:Service temporarily unavailable.');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    public function test_email_link_request_nonce_fail() {
        $_GET = ['wpomni_emergency' => '1', 'action' => 'email'];
        $_POST = [
            '_wpnonce'       => 'invalid',
            'wpomni_email'   => 'admin@example.com',
            'wpomni_captcha' => 'ABCDE',
            'wpomni_captcha_token' => 'token-1',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_emg_captcha_') === 0) {
                    return hash('sha256', 'ABCDE');
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', ['return' => true]);
        WP_Mock::userFunction('wp_verify_nonce', [
            'return' => false, // nonce fails
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WPDIE:Security check failed.');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    public function test_email_link_request_honeypot() {
        $_GET = ['wpomni_emergency' => '1', 'action' => 'email'];
        $_POST = [
            '_wpnonce'        => 'valid-nonce',
            'wpomni_email'    => 'admin@example.com',
            'wpomni_captcha'  => 'ABCDE',
            'wpomni_captcha_token' => 'token-1',
            'wpomni_website'  => 'http://spam.com', // honeypot filled!
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WPDIE:Security check failed.');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    public function test_email_link_request_captcha_fail() {
        $_GET = ['wpomni_emergency' => '1', 'action' => 'email'];
        $_POST = [
            '_wpnonce'       => 'valid-nonce',
            'wpomni_email'   => 'admin@example.com',
            'wpomni_captcha' => 'WRONG',
            'wpomni_captcha_token' => 'token-1',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_emg_captcha_') === 0) {
                    return hash('sha256', 'ABCDE'); // stored correct answer
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', ['return' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://example.com/wp-login.php?wpomni_emergency=1&capterror=1');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    // ================================================================
    // Email link click (process_email_link_click)
    // ================================================================

    public function test_email_link_click_valid_token() {
        $_GET = [
            'wpomni_emergency' => '1',
            'wpomni_token'     => 'valid-token-123',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                if ($key === 'wpomni_debug_mode') {
                    return 'no';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_emg_link_') === 0) {
                    return json_encode([
                        'email'   => 'admin@example.com',
                        'expires' => time() + 900,
                    ]);
                }
                if ($key === 'wpomni_emergency_active') {
                    return false;
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 2, // emergency_active + emergency_ip
            'return' => true,
        ]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://example.com/wp-login.php?wpomni_emergency_active=1');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    public function test_email_link_click_expired_token_redirects() {
        $_GET = [
            'wpomni_emergency' => '1',
            'wpomni_token'     => 'expired-token',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => false, // no stored token -> expired/invalid
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://example.com/wp-login.php?wpomni_emergency=1&linkfail=1');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    // ================================================================
    // Manual key verification (process_emergency_key_verify)
    // ================================================================

    public function test_process_emergency_key_correct() {
        $_GET = ['wpomni_emergency' => '1'];
        $_POST = [
            '_wpnonce'       => 'valid-nonce',
            'wpomni_key'     => 'the-correct-key',
            'wpomni_captcha' => 'ABCDE',
            'wpomni_captcha_token' => 'token-key-1',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                if ($key === 'wpomni_emergency_key') {
                    return 'the-correct-key';
                }
                if ($key === 'wpomni_debug_mode') {
                    return 'no';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_emergency_attempts_') === 0) {
                    return 0;
                }
                if (strpos($key, 'wpomni_emg_captcha_') === 0) {
                    return hash('sha256', 'ABCDE');
                }
                if ($key === 'wpomni_emergency_active') {
                    return false;
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 2, // emergency_active + emergency_ip
            'return' => true,
        ]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://example.com/wp-login.php?wpomni_emergency_active=1');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    public function test_process_emergency_key_wrong() {
        $_GET = ['wpomni_emergency' => '1'];
        $_POST = [
            '_wpnonce'       => 'valid-nonce',
            'wpomni_key'     => 'wrong-key',
            'wpomni_captcha' => 'ABCDE',
            'wpomni_captcha_token' => 'token-key-1',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                if ($key === 'wpomni_emergency_key') {
                    return 'the-correct-key';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_emergency_attempts_') === 0) {
                    return 0;
                }
                if (strpos($key, 'wpomni_emg_captcha_') === 0) {
                    return hash('sha256', 'ABCDE');
                }
                if ($key === 'wpomni_emergency_active') {
                    return false;
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1, // attempts counter incremented
            'return' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIRECT:https://example.com/wp-login.php?wpomni_emergency=1&error=1');

        WPOmniAuth_Emergency_Access::handle_emergency_access();
    }

    // ================================================================
    // Utility methods
    // ================================================================

    public function test_generate_emergency_code_valid_charset() {
        $ref = new ReflectionMethod('WPOmniAuth_Emergency_Access', 'generate_emergency_code');
        $ref->setAccessible(true);

        $code = $ref->invoke(null, 6);
        $this->assertSame(6, strlen($code));
        // Charset: ABCDEFGHJKLMNPQRSTUVWXYZ23456789 (no I, O, 0, 1)
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $code);
    }

    public function test_generate_emergency_code_default_length() {
        $ref = new ReflectionMethod('WPOmniAuth_Emergency_Access', 'generate_emergency_code');
        $ref->setAccessible(true);

        $code = $ref->invoke(null);
        $this->assertSame(6, strlen($code));
    }

    public function test_render_emergency_notices_active() {
        WP_Mock::userFunction('get_transient', [
            'args'  => ['wpomni_emergency_active'],
            'return' => 1,
        ]);

        // Should output HTML. Verify it contains the expected text.
        WP_Mock::userFunction('esc_html_e', [
            'return' => function ($text, $domain = null) {
                echo $text;
            },
        ]);

        ob_start();
        WPOmniAuth_Emergency_Access::render_emergency_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('wpomni-emergency-notice', $output);
        $this->assertStringContainsString('Emergency mode active', $output);
    }

    public function test_render_emergency_notices_inactive() {
        WP_Mock::userFunction('get_transient', [
            'args'  => ['wpomni_emergency_active'],
            'return' => false,
        ]);

        ob_start();
        WPOmniAuth_Emergency_Access::render_emergency_notices();
        $output = ob_get_clean();

        // When no emergency is active and no ?wpomni_emergency_active flag,
        // the output should be empty.
        $this->assertSame('', $output);
    }

    public function test_is_honeypot_tripped_true() {
        $_POST['wpomni_website'] = 'http://spam.com';

        $ref = new ReflectionMethod('WPOmniAuth_Emergency_Access', 'is_honeypot_tripped');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke(null));
    }

    public function test_is_honeypot_tripped_false() {
        $_POST['wpomni_website'] = '';

        $ref = new ReflectionMethod('WPOmniAuth_Emergency_Access', 'is_honeypot_tripped');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke(null));
    }
}
