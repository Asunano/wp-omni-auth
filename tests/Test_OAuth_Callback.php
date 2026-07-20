<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the OAuth callback entry point (WPOmniAuth_Manager::handle_oauth_callback).
 *
 * Covers the security early-exit branches: IP blacklist, rate limiting, invalid
 * provider, missing parameters, state verification failure, and replay
 * protection. Each branch terminates via render_callback_page(), which the
 * Testable_Manager turns into a CallbackPageException we assert on.
 *
 * The success path reaches insert_login_log()/maybe_auto_ban() which require a
 * live $wpdb, so it is intentionally not exercised here (covered manually / by
 * the WordPress integration test suite).
 */
class Test_OAuth_Callback extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        // $wpdb stub: the table-existence check (SHOW TABLES) must return early
        // so insert_login_log() never reaches a real DB query / maybe_auto_ban.
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function get_var($query) {
                return null;
            }
            public function insert() {
                return true;
            }
            public function prepare() {
                return '';
            }
        };

        // Default: most option/transient lookups return their defaults; per-call
        // tests register more specific expectations on top of these.
        // NOTE: this is intentionally a wildcard (no `args`) default, not
        // byDefault(), because some tests (e.g. test_replay_protection_rejected)
        // register a specific get_option whose value must NOT override the
        // wildcard for unrelated option lookups — and the replay test purposely
        // reuses the request IP as a blacklist entry, so a specific win there
        // would divert the flow into the blacklist branch instead of replay.
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                return $default;
            },
        ]);
        WP_Mock::userFunction('get_transient', [
            'return' => false,
        ])->byDefault();
        WP_Mock::userFunction('set_transient', [
            'return' => true,
        ]);
        // delete_transient is called on the success path (lock cleanup).
        WP_Mock::userFunction('delete_transient', [
            'return' => true,
        ]);
        // current_time is called inside insert_login_log() / meta persistence.
        WP_Mock::userFunction('current_time', [
            'return' => function ($f) {
                return date($f);
            },
        ]);
        // sanitize_text_field is used to read $_GET / $_SERVER inputs.
        WP_Mock::userFunction('sanitize_text_field', [
            'return' => function ($v) {
                return is_string($v) ? trim($v) : $v;
            },
        ]);
        // is_user_logged_in() / is_ssl() are evaluated inside the debug-log data
        // array on the success path.
        WP_Mock::userFunction('is_user_logged_in', [
            'return' => false,
        ]);
        WP_Mock::userFunction('is_ssl', [
            'return' => false,
        ]);
        WP_Mock::userFunction('site_url', [
            'return' => 'https://example.com',
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => true,
        ]);
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
        WP_Mock::userFunction('admin_url', [
            'return' => 'https://example.com/wp-admin/',
        ]);
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        unset($_GET, $_SERVER);
    }

    /**
     * Build a Testable_Manager whose provider simply returns the supplied
     * token/user so we can drive the flow up to the rejection branches.
     */
    private function make_manager($provider = null) {
        $manager = new Testable_Manager();
        $manager->fake_provider = $provider;
        return $manager;
    }

    private function fake_provider($enabled = true) {
        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('is_enabled')->willReturn($enabled);
        $provider->method('get_slug')->willReturn('github');
        return $provider;
    }

    private function expect_rejection($manager) {
        $this->expectException(CallbackPageException::class);
        $this->expectExceptionMessageMatches('/Access denied|Too many requests|Invalid OAuth|Security verification|already in progress/i');
        $manager->handle_oauth_callback();
    }

    public function test_missing_callback_param_returns_early() {
        $_GET = [];
        $manager = $this->make_manager();
        // No exception, no side effects when the callback param is absent.
        $manager->handle_oauth_callback();
        $this->assertTrue(true);
    }

    public function test_ip_blacklisted_rejected() {
        $_GET = ['wpomni_callback' => 'github'];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_blacklisted_ips', []],
            'return' => [['ip' => '1.2.3.4']],
        ]);

        $manager = $this->make_manager($this->fake_provider());
        $this->expect_rejection($manager);
    }

    public function test_rate_limited_rejected() {
        $_GET = ['wpomni_callback' => 'github'];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_rate_limit_per_ip', 10],
            'return' => 10,
        ]);
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_rate_limit_global', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_rate_' . hash('sha256', '1.2.3.4')],
            'return' => 10,
        ]);

        $manager = $this->make_manager($this->fake_provider());
        $this->expect_rejection($manager);
    }

    public function test_disabled_provider_rejected() {
        $_GET = ['wpomni_callback' => 'github'];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $manager = $this->make_manager($this->fake_provider(false));
        $this->expect_rejection($manager);
    }

    public function test_missing_code_or_state_rejected() {
        $_GET = ['wpomni_callback' => 'github'];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $manager = $this->make_manager($this->fake_provider());
        $this->expect_rejection($manager);
    }

    public function test_state_mismatch_rejected() {
        $_GET = [
            'wpomni_callback' => 'github',
            'code'            => 'authcode',
            'state'           => '1000_wrongtoken',
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        WP_Mock::userFunction('wp_salt', ['return' => 'test-secret']);
        WP_Mock::userFunction('wp_hash', ['return' => function ($v) { return $v; }]);

        $manager = $this->make_manager($this->fake_provider());
        $this->expect_rejection($manager);
    }

    public function test_replay_protection_rejected() {
        $time  = time();
        $state = $time . '_' . $time . 'githubtest-secret'; // valid format & token

        $_GET = [
            'wpomni_callback' => 'github',
            'code'            => 'authcode',
            'state'           => $state,
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        WP_Mock::userFunction('wp_salt', ['return' => 'test-secret']);
        WP_Mock::userFunction('wp_hash', ['return' => function ($v) { return $v; }]);
        // Replay lock already held for this code.
        WP_Mock::userFunction('get_transient', [
            'args'   => ['wpomni_code_lock_' . substr(hash('sha256', 'authcode'), 0, 12)],
            'return' => 1,
        ]);

        $manager = $this->make_manager($this->fake_provider());
        $this->expect_rejection($manager);
    }

    /**
     * Full success path: a valid state, an enabled provider that returns a
     * token + user, and an allow-listed user. Asserts that the auth cookie is
     * set. insert_login_log() early-returns on the $wpdb stub, so no real DB
     * is touched.
     */
    public function test_success_sets_auth_cookie() {
        $time  = time();
        $state = $time . '_' . $time . 'githubtest-secret';

        $_GET = [
            'wpomni_callback' => 'github',
            'code'            => 'authcode',
            'state'           => $state,
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        // Override the default get_transient so state verification passes
        // and rate/replay checks return false (not locked/limited).
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_oauth_state_') === 0) {
                    return ['slug' => 'github', 'exp' => time() + 600];
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('set_transient', ['return' => true]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        // Provider returns a token + user profile.
        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('is_enabled')->willReturn(true);
        $provider->method('get_slug')->willReturn('github');
        $provider->method('get_access_token')->willReturn('access-token');
        $provider->method('get_user_data')->willReturn(['id' => '123', 'email' => 'user@example.com']);
        $provider->method('get_email_from_user_data')->willReturn('user@example.com');

        // User matching + meta persistence.
        WP_Mock::userFunction('get_user_meta', ['return' => '']);
        WP_Mock::userFunction('update_user_meta', ['return' => true]);
        WP_Mock::userFunction('nocache_headers');
        WP_Mock::userFunction('wp_set_current_user');
        WP_Mock::userFunction('wp_set_auth_cookie', [
            'args'  => [1, true],
            'times' => 1,
        ]);

        $manager = $this->make_manager($provider);
        $manager->fake_user = (object) ['ID' => 1, 'user_login' => 'tester', 'display_name' => 'tester'];

        // headers_sent() is a PHP internal function; WP_Mock cannot mock it, but
        // under CLI it naturally returns false, so no mock is needed.
        //
        // The success path ends by rendering the callback page. Testable_Manager
        // turns render_callback_page() into a CallbackPageException, so we assert
        // it (the auth cookie is already set before that point; wp_set_auth_cookie
        // is verified once via the times => 1 expectation above).
        $this->expectException(CallbackPageException::class);
        $manager->handle_oauth_callback();
    }

    /**
     * Bind mode: a logged-in admin completed the OAuth flow with a bind marker.
     * The callback must write the per-provider `wpomni_{slug}_id` meta and must
     * NOT log the admin in (no auth cookie).
     *
     * @requires PHP 8.0
     */
    public function test_bind_mode_binds_admin_and_does_not_login() {
        $time  = time();
        $state = $time . '_' . $time . 'githubtest-secret';

        $_GET = [
            'wpomni_callback' => 'github',
            'code'            => 'authcode',
            'state'           => $state,
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['HTTP_USER_AGENT'] = 'test-agent';

        // Override the default get_transient so the state verifies and the bind
        // marker resolves to the current admin (user ID 1).
        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_oauth_state_') === 0) {
                    return ['slug' => 'github', 'exp' => time() + 600];
                }
                if (strpos($key, 'wpomni_bind_') === 0) {
                    return 1;
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('is_user_logged_in', ['return' => true]);
        WP_Mock::userFunction('get_current_user_id', ['return' => 1]);
        WP_Mock::userFunction('get_user_by', [
            'return' => function ($field, $id) {
                return (object) ['ID' => $id, 'display_name' => 'Tester'];
            },
        ]);
        WP_Mock::userFunction('esc_url', [
            'return' => function ($u) { return $u; },
        ]);
        WP_Mock::userFunction('wp_set_auth_cookie', ['times' => 0]);

        // Expect do_action calls from insert_login_log and
        // handle_bind_callback to satisfy Mockery verification.
        WP_Mock::userFunction('do_action', ['return' => null]);

        $bound_meta_written = false;
        WP_Mock::userFunction('update_user_meta', [
            'return' => function ($user_id, $key, $value) use (&$bound_meta_written) {
                if ($key === 'wpomni_github_id' && $user_id === 1) {
                    $bound_meta_written = true;
                }
                return true;
            },
        ]);

        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('is_enabled')->willReturn(true);
        $provider->method('get_slug')->willReturn('github');
        $provider->method('get_access_token')->willReturn('access-token');
        $provider->method('get_user_data')->willReturn(['id' => '999', 'email' => 'a@b.com']);
        $provider->method('get_email_from_user_data')->willReturn('a@b.com');
        $provider->method('get_user_id_from_user_data')->willReturn('stable-id-123');

        $manager = $this->make_manager($provider);

        $thrown = null;
        try {
            $manager->handle_oauth_callback();
        } catch (CallbackPageException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected CallbackPageException on bind success');
        $this->assertSame('success', $thrown->page_type, 'Bind should render the success page');
        $this->assertTrue($bound_meta_written, 'Expected wpomni_github_id meta to be written for the bound admin');
    }

    /**
     * Bind mode with no stable identity returned by the provider: the callback
     * must error out and must NOT write the per-provider binding meta.
     */
    public function test_bind_mode_without_stable_id_shows_error() {
        $time  = time();
        $state = $time . '_' . $time . 'githubtest-secret';

        $_GET = [
            'wpomni_callback' => 'github',
            'code'            => 'authcode',
            'state'           => $state,
        ];
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['HTTP_USER_AGENT'] = 'test-agent';

        WP_Mock::userFunction('get_transient', [
            'return' => function ($key) {
                if (strpos($key, 'wpomni_oauth_state_') === 0) {
                    return ['slug' => 'github', 'exp' => time() + 600];
                }
                if (strpos($key, 'wpomni_bind_') === 0) {
                    return 1;
                }
                return false;
            },
        ]);
        WP_Mock::userFunction('is_user_logged_in', ['return' => true]);
        WP_Mock::userFunction('get_current_user_id', ['return' => 1]);
        WP_Mock::userFunction('wp_set_auth_cookie', ['times' => 0]);

        $bound_meta_written = false;
        WP_Mock::userFunction('update_user_meta', [
            'return' => function ($user_id, $key, $value) use (&$bound_meta_written) {
                if ($key === 'wpomni_github_id') {
                    $bound_meta_written = true;
                }
                return true;
            },
        ]);

        $provider = $this->createMock(WPOmniAuth_Provider::class);
        $provider->method('is_enabled')->willReturn(true);
        $provider->method('get_slug')->willReturn('github');
        $provider->method('get_access_token')->willReturn('access-token');
        $provider->method('get_user_data')->willReturn(['id' => '', 'email' => '']);
        $provider->method('get_email_from_user_data')->willReturn('');
        $provider->method('get_user_id_from_user_data')->willReturn('');

        $manager = $this->make_manager($provider);

        $thrown = null;
        try {
            $manager->handle_oauth_callback();
        } catch (CallbackPageException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected CallbackPageException on bind failure');
        $this->assertSame('error', $thrown->page_type, 'Missing stable ID should render the error page');
        $this->assertFalse($bound_meta_written, 'Binding meta must NOT be written when no stable ID is returned');
    }
}
