<?php
/**
 * Test helpers for the OAuth callback flow.
 *
 * The production Manager has a private constructor (singleton) and terminates
 * the request via render_callback_page() on every error branch. This subclass
 * exposes a public constructor (skipping the real hook/provider bootstrap) and
 * turns render_callback_page() into a thrown exception so tests can assert the
 * early-exit security branches.
 */

class CallbackPageException extends \Exception {
    public $page_type;
    public function __construct($message = '', $page_type = 'error', $code = 0, $previous = null) {
        $this->page_type = $page_type;
        parent::__construct($message, $code, $previous);
    }
}

class Testable_Manager extends WPOmniAuth_Manager {
    /** @var WPOmniAuth_Provider|null */
    public $fake_provider = null;

    /** @var object|null Fake WP_User returned by find_user_by_oauth() */
    public $fake_user = null;

    /** @var WPOmniAuth_Provider[] Providers returned by get_all_providers() */
    public $fake_providers = [];

    public function __construct() {
        // Intentionally NOT calling parent::__construct() to skip hook
        // registration and provider auto-discovery. Set the log path only.
        $this->log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
    }

    /**
     * Return a controlled provider list (used by the login-button tests).
     */
    public function get_all_providers() {
        return $this->fake_providers;
    }

    /**
     * Convert the production "render an HTML page and exit" into an exception
     * so tests can detect and assert the termination reason.
     */
    public function render_callback_page($type, $message = '', $redirect = '', $icon_html = '', $user_name = '', $context = []) {
        throw new CallbackPageException($message, $type);
    }

    /**
     * Return a controlled provider instead of auto-discovered ones.
     */
    public function get_provider($slug) {
        return $this->fake_provider;
    }

    /**
     * Bypass the real user-query logic for the happy-path test.
     */
    public function find_user_by_oauth($slug, $oauth_id, $email) {
        return $this->fake_user;
    }
}
