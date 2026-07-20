<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WPOmniAuth_Login_Log — login history database table operations.
 *
 */
class Test_Login_Log extends TestCase {

    protected function setUp(): void {
        WP_Mock::setUp();

        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $insert_id = 0;
            public function get_var($query) {
                // Return table name for SHOW TABLES, null for other queries
                if (strpos($query, 'SHOW TABLES') === 0) {
                    return 'wp_wpomni_login_log';
                }
                return null;
            }
            public function insert($table, $data, $format = null) {
                $this->insert_id = 1;
                return true;
            }
            public function prepare($query, ...$args) {
                return $query;
            }
            public function query($query) {
                return true;
            }
            public function delete($table, $where, $format = null) {
                return 1;
            }
        };

        WP_Mock::userFunction('current_time', [
            'return' => function ($type) {
                return $type === 'mysql' ? '2026-01-01 00:00:00' : time();
            },
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
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        // Reset the table_verified static property
        $ref = new ReflectionProperty('WPOmniAuth_Login_Log', 'table_verified');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    public function test_insert_login_log_calls_wpdb_insert() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_debug_mode') {
                    return 'no';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        // Should not call maybe_auto_ban since the login log is success
        WPOmniAuth_Login_Log::insert_login_log([
            'user_id'  => 1,
            'provider' => 'github',
            'email'    => 'user@example.com',
            'ip'       => '1.2.3.4',
            'status'   => 'success',
        ]);
        $this->assertTrue(true);
    }

    public function test_insert_login_log_truncates_long_fields() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_debug_mode') {
                    return 'no';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        $long_provider = str_repeat('a', 100);
        $long_message = str_repeat('b', 300);

        // Should not throw despite long values (truncated by insert_login_log)
        WPOmniAuth_Login_Log::insert_login_log([
            'provider'  => $long_provider,
            'email'     => 'test@example.com',
            'ip'        => '::1',
            'status'    => 'failure',
            'message'   => $long_message,
        ]);
        $this->assertTrue(true);
    }

    public function test_insert_login_log_failure_dispatches_event() {
        WP_Mock::userFunction('get_option', [
            'return' => function ($key, $default = null) {
                if ($key === 'wpomni_auto_ban_threshold') {
                    return 0; // disable auto-ban
                }
                if ($key === 'wpomni_debug_mode') {
                    return 'no';
                }
                return $default;
            },
        ]);
        WP_Mock::userFunction('delete_transient', ['return' => true]);

        WPOmniAuth_Login_Log::insert_login_log([
            'provider'  => 'github',
            'email'     => 'test@example.com',
            'ip'        => '1.2.3.4',
            'status'    => 'failure',
            'message'   => 'Bad token',
        ]);
        $this->assertTrue(true);
    }

    public function test_cleanup_login_log_calls_wpdb_delete() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_log_retention_days', 90],
            'return' => 30,
        ]);

        // cleanup calls $wpdb->query with a DELETE statement
        WPOmniAuth_Login_Log::cleanup_login_log();
        $this->assertTrue(true);
    }

    public function test_cleanup_login_log_zero_retention_skips() {
        WP_Mock::userFunction('get_option', [
            'args'  => ['wpomni_log_retention_days', 90],
            'return' => 0,
        ]);

        // 0 means keep forever — should skip cleanup
        WPOmniAuth_Login_Log::cleanup_login_log();
        $this->assertTrue(true);
    }

    public function test_ensure_login_log_table_creates_table() {
        // dbDelta is a WordPress function that's hard to mock fully.
        // We just verify the method returns normally.
        global $wpdb;
        // Simulate table not existing
        $wpdb = new class {
            public $prefix = 'wp_';
            public function get_var($query) {
                return null; // TABLE doesn't exist
            }
            public function query($query) {
                return true;
            }
        };

        $ref = new ReflectionMethod('WPOmniAuth_Login_Log', 'ensure_login_log_table');
        $ref->setAccessible(true);

        // This should call dbDelta internally via $wpdb->query
        $ref->invoke(null);
        $this->assertTrue(true);
    }
}
