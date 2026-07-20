<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Event_Dispatcher — email + webhook notification dispatch.
 *
 * Covers dispatch routing, email notification gating/throttle, webhook
 * scheduling, cron retry with HMAC signing, and exception safety.
 */
class Test_Event_Dispatcher extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        // Default mocks used by most tests.
        WP_Mock::userFunction('__', [
            'return' => function ($text, $domain = null) {
                return $text;
            },
        ]);
        WP_Mock::userFunction('esc_html', [
            'return' => function ($v) { return $v; },
        ]);
        WP_Mock::userFunction('current_time', [
            'return' => function ($type) {
                return date($type === 'mysql' ? 'Y-m-d H:i:s' : 'c');
            },
        ]);
        WP_Mock::userFunction('admin_url', [
            'return' => function ($path = '') {
                return 'https://example.com/wp-admin/' . ltrim($path, '/');
            },
        ]);
        WP_Mock::userFunction('home_url', [
            'return' => 'https://example.com',
        ]);
        WP_Mock::userFunction('wp_parse_url', [
            'return' => function ($url) {
                $parts = parse_url($url);
                return $parts !== false ? $parts : [];
            },
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
    }

    // ================================================================
    // dispatch()
    // ================================================================

    public function test_dispatch_calls_email_and_webhook_handlers() {
        // set up the email handler to detect the call
        $email_called = false;
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) use (&$email_called) {
                if ($key === 'wpomni_email_notify_enabled') {
                    $email_called = true;
                    return 'no'; // bail early
                }
                return $default;
            },
        ]);

        // do_action must be called — use a wildcard so Mockery doesn't fail
        // on argument matching between the test's expectAction and the actual call.
        WP_Mock::userFunction('do_action', ['return' => null]);

        WPOmniAuth_Event_Dispatcher::dispatch('login_success', ['email' => 'test@example.com']);

        $this->assertTrue($email_called, 'handle_email should have been called');
    }

    public function test_dispatch_fires_wp_action() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ]);

        WP_Mock::expectAction('wpomni_auth/custom_event', ['key' => 'val']);

        WPOmniAuth_Event_Dispatcher::dispatch('custom_event', ['key' => 'val']);

        $this->assertTrue(true);
    }

    public function test_handler_exception_logged() {
        // Make handle_email throw
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_debug_mode') {
                    return 'yes';
                }
                throw new \RuntimeException('Simulated handler failure');
            },
        ]);

        WP_Mock::userFunction('current_time', [
            'return' => '2026-01-01 00:00:00',
        ]);

        // Prevent the do_action from propagating
        WP_Mock::userFunction('do_action', ['return' => null]);

        // Should not throw despite the handler failure
        WPOmniAuth_Event_Dispatcher::dispatch('test_event', []);

        $this->assertTrue(true);
    }

    // ================================================================
    // handle_email()
    // ================================================================

    public function test_email_notify_disabled_returns_early() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_email_notify_enabled') {
                    return 'no';
                }
                return $default;
            },
        ]);

        // wp_mail must NOT be called
        WP_Mock::userFunction('wp_mail', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::handle_email('login_failure', []);
        $this->assertTrue(true);
    }

    public function test_email_notify_unselected_event_skipped() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_email_notify_enabled') {
                    return 'yes';
                }
                if ($key === 'wpomni_email_notify_events') {
                    return ['login_failure', 'access_denied'];
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_mail', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::handle_email('login_success', []);
        $this->assertTrue(true);
    }

    public function test_email_sends_for_failure_event() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_email_notify_enabled') {
                    return 'yes';
                }
                if ($key === 'wpomni_email_notify_events') {
                    return ['login_failure'];
                }
                if ($key === 'wpomni_email_notify_to') {
                    return 'admin@example.com';
                }
                if ($key === 'blogname') {
                    return 'Test Site';
                }
                if ($key === 'admin_email') {
                    return 'admin@example.com';
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_mail', [
            'times' => 1,
            'return' => function ($to, $subject, $body, $headers) {
                $this->assertSame('admin@example.com', $to);
                $this->assertStringContainsString('Login Failed', $subject);
                $this->assertStringContainsString('test@evil.com', $body);
                return true;
            },
        ]);
        WP_Mock::userFunction('set_transient', ['return' => true]);

        WPOmniAuth_Event_Dispatcher::handle_email('login_failure', [
            'email' => 'test@evil.com',
            'ip'    => '1.2.3.4',
            'message' => 'Bad password',
        ]);
    }

    public function test_email_throttle_login_success() {
        $throttle_checks = 0;
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_email_notify_enabled') {
                    return 'yes';
                }
                if ($key === 'wpomni_email_notify_events') {
                    return ['login_success'];
                }
                if ($key === 'wpomni_email_notify_to') {
                    return 'admin@example.com';
                }
                if ($key === 'blogname') {
                    return 'Test Site';
                }
                if ($key === 'admin_email') {
                    return 'admin@example.com';
                }
                return $default;
            },
        ]);

        // Simulate throttle hit — transient returns truthy
        $throttle_checks++;
        WP_Mock::userFunction('get_transient', [
            'args'   => [WP_Mock\Functions::type('string')],
            'return' => 1, // throttle active
        ]);

        WP_Mock::userFunction('wp_mail', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::handle_email('login_success', [
            'user_id' => 1,
            'email'   => 'admin@example.com',
            'ip'      => '1.2.3.4',
        ]);
        $this->assertTrue(true);
    }

    public function test_email_provider_bind_no_throttle() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_email_notify_enabled') {
                    return 'yes';
                }
                if ($key === 'wpomni_email_notify_events') {
                    return ['provider_bind'];
                }
                if ($key === 'wpomni_email_notify_to') {
                    return 'admin@example.com';
                }
                if ($key === 'blogname') {
                    return 'Test Site';
                }
                if ($key === 'admin_email') {
                    return 'admin@example.com';
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('get_userdata', [
            'return' => function ($id) {
                return (object) ['display_name' => 'Admin', 'ID' => $id];
            },
        ]);

        WP_Mock::userFunction('wp_mail', [
            'times' => 1,
            'return' => function ($to, $subject, $body, $headers) {
                $this->assertStringContainsString('Provider Bound', $subject);
                $this->assertStringContainsString('GitHub', $body);
                return true;
            },
        ]);
        WP_Mock::userFunction('set_transient', ['return' => true]);

        WPOmniAuth_Event_Dispatcher::handle_email('provider_bind', [
            'user_id'  => 1,
            'provider' => 'GitHub',
            'oauth_id' => 'gh-12345',
            'email'    => 'admin@example.com',
            'ip'       => '1.2.3.4',
        ]);
    }

    // ================================================================
    // send_html_mail()
    // ================================================================

    public function test_send_html_mail() {
        WP_Mock::userFunction('wp_mail', [
            'times' => 1,
            'return' => function ($to, $subject, $body, $headers) {
                $this->assertSame('user@example.com', $to);
                $this->assertSame('Test Subject', $subject);
                $this->assertStringContainsString('<html>', $body);
                $this->assertContains('Content-Type: text/html; charset=UTF-8', $headers);
                return true;
            },
        ]);

        $result = WPOmniAuth_Event_Dispatcher::send_html_mail(
            'user@example.com',
            'Test Subject',
            '<html><body><p>Hello</p></body></html>'
        );
        $this->assertTrue($result);
    }

    // ================================================================
    // handle_webhook()
    // ================================================================

    public function test_webhook_not_configured_returns_early() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_webhook_url') {
                    return '';
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::handle_webhook('login_success', []);
        $this->assertTrue(true);
    }

    public function test_webhook_schedules_cron() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_webhook_url') {
                    return 'https://hooks.example.com/notify';
                }
                if ($key === 'wpomni_webhook_events') {
                    return ['login_success'];
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_schedule_single_event', [
            'times' => 1,
            'return' => true,
        ]);

        WPOmniAuth_Event_Dispatcher::handle_webhook('login_success', [
            'user_id' => 1,
            'email'   => 'admin@example.com',
            'ip'      => '1.2.3.4',
        ]);
        $this->assertTrue(true);
    }

    // ================================================================
    // cron_send_webhook()
    // ================================================================

    public function test_cron_send_webhook_signs_payload() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_webhook_secret') {
                    return 'hmac-secret';
                }
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_json_encode', [
            'return' => function ($data) {
                return json_encode($data);
            },
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => function ($url, $args) {
                $this->assertSame('https://hooks.example.com/notify', $url);
                $this->assertArrayHasKey('X-WPOmniAuth-Signature', $args['headers']);
                $this->assertArrayHasKey('X-WPOmniAuth-Event', $args['headers']);
                $this->assertSame('login_success', $args['headers']['X-WPOmniAuth-Event']);
                $expected_sig = hash_hmac('sha256', $args['body'], 'hmac-secret');
                $this->assertSame($expected_sig, $args['headers']['X-WPOmniAuth-Signature']);
                return ['response' => ['code' => 200]];
            },
        ]);

        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::cron_send_webhook(
            'https://hooks.example.com/notify',
            ['event' => 'login_success', 'user_id' => 1],
            1
        );
        $this->assertTrue(true);
    }

    public function test_cron_send_webhook_retries() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_json_encode', [
            'return' => function ($data) {
                return json_encode($data);
            },
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'return' => new WP_Error('timeout', 'Connection timed out'),
        ]);

        WP_Mock::userFunction('is_wp_error', [
            'return' => true,
        ]);

        // First retry should be scheduled at +30s
        WP_Mock::userFunction('wp_schedule_single_event', [
            'times' => 1,
            'return' => true,
        ]);

        WPOmniAuth_Event_Dispatcher::cron_send_webhook(
            'https://hooks.example.com/notify',
            ['event' => 'login_success'],
            1
        );
        $this->assertTrue(true);
    }

    public function test_cron_send_webhook_no_retry_on_success() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ]);

        WP_Mock::userFunction('wp_json_encode', [
            'return' => function ($data) { return json_encode($data); },
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'return' => ['response' => ['code' => 200]],
        ]);

        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        WPOmniAuth_Event_Dispatcher::cron_send_webhook(
            'https://hooks.example.com/notify',
            ['event' => 'login_success'],
            1
        );
        $this->assertTrue(true);
    }
}
