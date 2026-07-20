<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Login_Guard::maybe_block_password_login().
 *
 * Covers the OAuth-only password blocker: disabled mode, empty credentials,
 * emergency-mode bypass, the no-enabled-provider safety net, and the actual
 * block when a provider is enabled.
 */
class Test_Login_Guard extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();
        // Default: OAuth-only off, no emergency mode.
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_hide_password', 'no'],
            'return' => 'no',
        ])->byDefault();
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_emergency_active'],
            'return' => false,
        ])->byDefault();
        // maybe_block_password_login() calls is_wp_error($user) on the incoming user.
        WP_Mock::userFunction('is_wp_error', [
            'return' => false,
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        // Reset the Manager singleton so a controlled instance set by one test
        // does not leak into another (the constructor is private, so we use reflection).
        $ref = new ReflectionProperty('WPOmniAuth_Manager', 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    /**
     * Inject a Testable_Manager whose get_all_providers() returns $providers,
     * by-passing the private singleton constructor. `class_exists()` is a PHP
     * internal function that WP_Mock cannot override; WPOmniAuth_Manager is
     * always loaded in the test bootstrap, so the safety-net branch always
     * calls WPOmniAuth_Manager::instance().
     */
    private function set_manager_singleton(array $providers) {
        $manager = new Testable_Manager();
        $manager->fake_providers = $providers;

        $ref_instance = new ReflectionProperty('WPOmniAuth_Manager', 'instance');
        $ref_instance->setAccessible(true);
        $ref_instance->setValue(null, $manager);
    }

    private function user_mock() {
        return $this->getMockBuilder('WP_User')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function test_oauth_only_disabled_returns_user() {
        $user = $this->user_mock();
        $result = WPOmniAuth_Login_Guard::maybe_block_password_login($user, 'bob', 'secret');
        $this->assertSame($user, $result);
    }

    public function test_empty_credentials_returns_user() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_hide_password', 'no'],
            'return' => 'yes',
        ]);
        $user = $this->user_mock();
        $result = WPOmniAuth_Login_Guard::maybe_block_password_login($user, '', '');
        $this->assertSame($user, $result);
    }

    public function test_emergency_mode_returns_user() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_hide_password', 'no'],
            'return' => 'yes',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_emergency_active'],
            'return' => true,
        ]);
        $user = $this->user_mock();
        $result = WPOmniAuth_Login_Guard::maybe_block_password_login($user, 'bob', 'secret');
        $this->assertSame($user, $result);
    }

    public function test_blocks_when_provider_enabled() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_hide_password') {
                    return 'yes';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('__', [
            'return' => 'Password login is disabled. Please use OAuth to log in.',
        ]);

        // With an enabled provider the safety net must NOT bail out, so the
        // blocker returns a WP_Error.
        $enabled_provider = new class {
            public function is_enabled() {
                return true;
            }
        };
        $this->set_manager_singleton(['github' => $enabled_provider]);

        $user = $this->user_mock();
        $result = WPOmniAuth_Login_Guard::maybe_block_password_login($user, 'bob', 'secret');
        $this->assertInstanceOf('WP_Error', $result);
    }

    public function test_allows_when_no_enabled_provider() {
        WP_Mock::userFunction('get_option', [
            'args'   => ['wpomni_hide_password', 'no'],
            'return' => 'yes',
        ]);

        // No enabled providers -> safety net bails out, password login allowed.
        $disabled_provider = new class {
            public function is_enabled() {
                return false;
            }
        };
        $this->set_manager_singleton(['github' => $disabled_provider]);

        $user = $this->user_mock();
        $result = WPOmniAuth_Login_Guard::maybe_block_password_login($user, 'bob', 'secret');
        $this->assertSame($user, $result);
    }
}
