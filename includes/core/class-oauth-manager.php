<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Manager {
    private static $instance = null;
    private $providers = [];
    private $custom_providers = [];
    private $settings_sections = [];

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_providers();
        $this->init_hooks();
    }

    private function init_providers() {
        // Auto-discover built-in providers from includes/providers/ directory.
        // Adding a new provider: just drop class-{name}-provider.php in includes/providers/.
        $includes_dir = WPOMNIAUTH_PLUGIN_DIR . 'includes/providers/';
        $files = glob($includes_dir . 'class-*-provider.php');

        // Files to skip: custom provider (loaded from DB; abstract base no longer lives here)
        $skip_files = ['class-custom-provider.php'];

        if ($files) {
            sort($files); // Consistent load order (alphabetical)
            foreach ($files as $file) {
                if (in_array(basename($file), $skip_files, true)) {
                    continue;
                }

                require_once $file;
                $basename = basename($file, '.php');           // e.g. "class-github-provider"
                // Convert "class-github-provider" → "WPOmniAuth_Github_Provider"
                $parts = explode('-', $basename);
                array_shift($parts); // Remove "class" prefix
                $parts = array_map('ucfirst', $parts);
                $fqcn = 'WPOmniAuth_' . implode('_', $parts);

                if (class_exists($fqcn)) {
                    $instance = new $fqcn();
                    $this->providers[$instance->get_slug()] = $instance;
                }
            }
        }

        $this->load_custom_providers();
    }

    private function load_custom_providers() {
        $custom_slugs = get_option('wpomni_custom_providers', []);
        foreach ($custom_slugs as $slug) {
            $this->custom_providers[$slug] = new WPOmniAuth_Custom_Provider($slug);
        }
    }

    private function init_hooks() {
        add_action('login_form', [$this, 'render_login_buttons']);
        add_action('register_form', [$this, 'render_login_buttons']);
        add_action('login_init', [$this, 'maybe_render_oauth_login']);
        add_action('login_enqueue_scripts', [$this, 'enqueue_login_styles']);
        add_filter('wp_login_errors', [$this, 'add_login_error']);
        add_filter('authenticate', [$this, 'maybe_block_password_login'], 30, 3);
        // Wire the standalone provider configuration-check module.
        WPOmniAuth_Provider_Checker::instance()->register_hooks();
    }

    /**
     * Create a CSRF-protected OAuth state.
     *
     * Generates a cryptographically random nonce and stores it server-side in
     * a transient (10-minute TTL, single-use) keyed by the nonce's hash. The
     * nonce itself is returned to the caller and sent to the provider.
     *
     * Unlike a deterministic time+hash value, this state is unguessable and is
     * bound to the specific provider slug. It is consumed (deleted) on first
     * successful verification, so it cannot be replayed. This prevents OAuth
     * login-CSRF where an attacker tricks a victim into completing an attacker-
     * initiated flow.
     *
     * @param string $slug Provider slug.
     * @return string Random state nonce.
     */
    private function create_oauth_state($slug) {
        return WPOmniAuth_OAuth_State::create($slug);
    }

    /**
     * Verify an OAuth state value returned by the provider.
     *
     * Looks up the server-side transient, ensures it exists (i.e. was issued
     * by us), is not expired, and matches the expected provider slug. The
     * transient is deleted on first verification so the state is single-use.
     *
     * Delegates to WPOmniAuth_OAuth_State.
     *
     * @param string $state State nonce returned by the provider.
     * @param string $slug  Provider slug.
     * @return bool
     */
    private function verify_oauth_state($state, $slug) {
        return WPOmniAuth_OAuth_State::verify($state, $slug);
    }

    /**
     * If icon is a URL, convert to img tag for HTML display.
     */
    private function normalize_icon_for_display($icon) {
        return WPOmniAuth_Login_Buttons::normalize_icon_for_display($icon);
    }

    private function get_safe_icon($provider) {
        return WPOmniAuth_Login_Buttons::get_icon($provider);
    }

    public function is_debug_enabled() { return WPOmniAuth_Logger::is_debug_enabled(...func_get_args()); }


    /**
     * Unified, redaction-aware debug log entry point.
     *
     * Call this from the Manager and from providers so that every log line
     * goes through sanitize_log_data() and never leaks tokens or secrets.
     *
     * @param string      $message Log message.
     * @param array|null  $data    Optional structured data (redacted before write).
     * @param string      $tag     Source tag shown in the log (e.g. provider name).
     */
    public static function debug_log($message, $data = null, $tag = 'Manager') { return WPOmniAuth_Logger::debug_log(...func_get_args()); }


    /**
     * Redact sensitive keys from log data before it is written.
     *
     * Delegates to WPOmniAuth_Logger::sanitize_log_data(); exposed on the
     * Manager so callers (and tests) can rely on a single entry point.
     *
     * @param array $data Structured log data.
     * @return array Redacted copy.
     */
    public static function sanitize_log_data($data) { return WPOmniAuth_Logger::sanitize_log_data($data); }


    private function log($message, $data = null) { return WPOmniAuth_Logger::debug_log($message, $data, 'Manager'); }


    public function get_log_file_path() { return WPOmniAuth_Logger::get_log_file_path(...func_get_args()); }


    public function clear_log() { return WPOmniAuth_Logger::clear_log(...func_get_args()); }


    public function get_log_content($lines = 100) { return WPOmniAuth_Logger::get_log_content(...func_get_args()); }


    public function get_all_providers() {
        $all = $this->providers;
        foreach ($this->custom_providers as $slug => $provider) {
            $all[$slug] = $provider;
        }
        return $all;
    }

    public function get_provider($slug) {
        if (isset($this->providers[$slug])) {
            return $this->providers[$slug];
        }
        if (isset($this->custom_providers[$slug])) {
            return $this->custom_providers[$slug];
        }
        return null;
    }

    /**
     * Thin delegation to the provider configuration checker.
     * Returns the cached (or live) configuration status for a provider.
     *
     * @param string $slug
     * @return array|null
     */
    public function get_provider_status($slug) {
        return WPOmniAuth_Provider_Checker::instance()->get_status($slug);
    }

    /**
     * Register a settings section for the admin page.
     *
     * @param array $section {
     *     @type string   $slug             Unique section identifier.
     *     @type string   $title            Section heading (already escaped / translatable).
     *     @type callable $render_callback  Outputs the section body HTML (inside .wpomni-section-body).
     *     @type callable $register_callback Called on admin_init to register WP Settings API fields. Optional.
     *     @type string   $sub_tab          Sidebar sub-tab key to render under (general/security/notifications/debug, or a new key). Omit to keep the section off the Settings tab.
     *     @type string   $sub_tab_label    Sidebar label when $sub_tab is a brand-new key. Falls back to $title.
     *     @type int      $priority         Lower = earlier. Default 100.
     * }
     */
    public function register_settings_section($section) {
        $defaults = [
            'slug'             => '',
            'title'            => '',
            'render_callback'  => null,
            'register_callback' => null,
            'priority'         => 100,
        ];
        $section = wp_parse_args($section, $defaults);
        if (empty($section['slug'])) {
            return;
        }
        if (!empty($section['render_callback']) && !is_callable($section['render_callback'])) {
            return;
        }
        $this->settings_sections[$section['slug']] = $section;
    }

    /**
     * Return all registered settings sections sorted by priority.
     */
    public function get_settings_sections() {
        $sections = $this->settings_sections;
        uasort($sections, function ($a, $b) {
            return ($a['priority'] ?? 100) - ($b['priority'] ?? 100);
        });
        return $sections;
    }

    public function render_login_buttons() {
        echo $this->build_oauth_buttons();
    }

    /**
     * Build the OAuth provider-button markup (used both when appending buttons to
     * the default wp-login form and when rendering the full OAuth-only screen).
     *
     * @return string
     */
    public function build_oauth_buttons() {
        return WPOmniAuth_Login_Buttons::render($this->get_all_providers());
    }

    public function enqueue_login_styles() {
        $css_url = method_exists('WPOmniAuth_Login_Guard', 'login_styles_base_url')
            ? WPOmniAuth_Login_Guard::login_styles_base_url()
            : (WPOMNIAUTH_PLUGIN_URL . 'assets/css/login-styles.css');
        $ver = method_exists('WPOmniAuth_Login_Guard', 'login_styles_version')
            ? WPOmniAuth_Login_Guard::login_styles_version()
            : WPOMNIAUTH_VERSION;

        wp_enqueue_style(
            'wpomni-login',
            $css_url,
            [],
            $ver
        );
    }

    /**
     * [C2] In OAuth-only mode, replace the entire wp-login screen with a custom
     * "OAuth only" screen (provider buttons + emergency-access link). This avoids
     * the fragile CSS-hiding approach and works regardless of the active theme's
     * login markup, which greatly reduces adaptation work for new themes.
     *
     * Runs on login_init (early, before the default form is rendered) and exits.
     * Password-recovery / logout actions are deliberately left untouched so users
     * can still reset a password. When emergency mode is active we do NOT replace
     * the screen, so the normal password form is shown for the 15-minute window.
     */
    public function maybe_render_oauth_login() {
        // Only relevant in OAuth-only mode.
        if (get_option('wpomni_hide_password', 'no') !== 'yes') {
            return;
        }
        // Emergency mode is active → show the normal password form instead.
        if (class_exists('WPOmniAuth_Login_Guard') && WPOmniAuth_Login_Guard::is_emergency_active()) {
            return;
        }
        // Safety net: never lock out if no providers are configured.
        if (!$this->has_enabled_providers()) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : 'login';
        // Leave WordPress-native flows (password recovery, logout, etc.) intact.
        $preserve_actions = ['lostpassword', 'retrievepassword', 'resetpass', 'rp', 'postpass', 'logout', 'confirmaction', 'conflict'];
        if (in_array($action, $preserve_actions, true)) {
            return;
        }
        // Interim login (comment iframe) and any POST should fall through to core.
        if (isset($_REQUEST['interim-login']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
            return;
        }

        $this->render_oauth_login_screen();
        exit;
    }

    /**
     * Output the custom full-page OAuth login screen and exit.
     */
    private function render_oauth_login_screen() {
        nocache_headers();

        $site_name = get_bloginfo('name');
        $home_url = home_url('/');
        $buttons = $this->build_oauth_buttons();
        $emergency_url = wp_login_url() . '?wpomni_emergency=1';

        $error_html = '';
        if (isset($_GET['wpomni_error'])) {
            $error_msg = sanitize_text_field($_GET['wpomni_error']);
            $error_html = '<div id="login_error" class="wpomni-oauth-error">' . esc_html($error_msg) . '</div>';
        }

        require WPOMNIAUTH_PLUGIN_DIR . 'includes/views/oauth-login-screen.php';

    }

    private function has_enabled_providers() {
        $providers = $this->get_all_providers();
        foreach ($providers as $provider) {
            if ($provider->is_enabled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * [S5] Block password login from ALL authentication vectors when OAuth-only mode is on.
     * Previously only blocked wp-login.php form submissions, leaving XML-RPC and REST API open.
     */
    public function maybe_block_password_login($user, $username, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        $hide_password = get_option('wpomni_hide_password', 'no');
        if ($hide_password !== 'yes') {
            return $user;
        }

        // Allow empty credentials through (WordPress initial probe, not a real login attempt)
        if (empty($username) && empty($password)) {
            return $user;
        }

        // Emergency mode: temporarily allow password login.
        if (class_exists('WPOmniAuth_Login_Guard') && WPOmniAuth_Login_Guard::is_emergency_active()) {
            return $user;
        }

        // Safety net: if no providers are enabled, don't block password login (prevents lockout).
        if (!$this->has_enabled_providers()) {
            return $user;
        }

        $this->log('Auth: Blocking password login', ['username' => $username]);
        return new WP_Error(
            'oauth_only_mode',
            __('Password login is disabled. Please use OAuth to log in.', 'wp-omni-auth')
        );
    }

    public function add_login_error($error) {
        if (isset($_GET['wpomni_error'])) {
            $error_msg = sanitize_text_field($_GET['wpomni_error']);
            $error = new WP_Error('wpomni_error', $error_msg);
        }
        return $error;
    }

    public function handle_oauth_callback() {
        if (!isset($_GET['wpomni_callback'])) {
            return;
        }

        $slug = sanitize_text_field($_GET['wpomni_callback']);
        $this->log('OAuth callback received', ['slug' => $slug]);
        $ip = self::get_client_ip();
        $ua = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Security: IP blacklist check
        if (self::is_ip_blacklisted($ip)) {
            $this->log('Blocked: IP blacklisted', ['ip' => $ip]);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'IP blacklisted.']);
            $this->render_callback_page('error', __('Access denied. Your IP address has been blocked.', 'wp-omni-auth'));
            return;
        }

        // Security: Rate limit check
        if (self::check_rate_limit($ip)) {
            $this->log('Blocked: Rate limit exceeded', ['ip' => $ip]);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Rate limited.']);
            $this->render_callback_page('error', __('Too many requests. Please try again later.', 'wp-omni-auth'));
            return;
        }

        // Increment rate limit counter for this request
        self::increment_rate_limit($ip);

        $provider = $this->get_provider($slug);

        if (!$provider || !$provider->is_enabled()) {
            $this->log('ERROR: Invalid or disabled provider', ['slug' => $slug]);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Invalid OAuth provider.']);
            $this->render_callback_page('error', __('Invalid OAuth provider.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        if (!isset($_GET['code']) && !isset($_POST['code'])) {
            $this->log('ERROR: Missing code parameter');
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Invalid OAuth response.']);
            $this->render_callback_page('error', __('Invalid OAuth response.', 'wp-omni-auth'));
            return;
        }

        // Some providers (e.g. Apple) use response_mode=form_post, sending
        // code/state in the POST body rather than GET query string.
        $code  = sanitize_text_field($_GET['code'] ?? $_POST['code'] ?? '');
        $state = sanitize_text_field($_GET['state'] ?? $_POST['state'] ?? '');
        if (!$this->verify_oauth_state($state, $slug)) {
            $this->log('ERROR: State verification failed', ['slug' => $slug]);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Security verification failed.']);
            $this->render_callback_page('error', __('Security verification failed.', 'wp-omni-auth'));
            return;
        }

        $this->log('State verified successfully');

        // [C4] Atomic check-and-set for replay protection.
        // Uses a transient lock to minimize the race window for concurrent callbacks.
        $lock_key = 'wpomni_code_lock_' . substr(hash("sha256", $code), 0, 12);
        if (get_transient($lock_key)) {
            $this->log('ERROR: Code already being processed (concurrent lock)');
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Login already in progress.']);
            $this->render_callback_page('error', __('Login already in progress. Please try again.', 'wp-omni-auth'));
            return;
        }
        set_transient($lock_key, 1, 60);

        $used_codes = get_option('wpomni_used_codes', []);
        if (isset($used_codes[$code])) {
            $this->log('ERROR: Code already used', ['code' => substr($code, 0, 10)]);
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Login already in progress.']);
            $this->render_callback_page('error', __('Login already in progress. Please try again.', 'wp-omni-auth'));
            return;
        }
        $used_codes[$code] = time();
        $used_codes = array_filter($used_codes, function($t) { return time() - $t < 300; });
        update_option('wpomni_used_codes', $used_codes);
        $this->log('Exchanging code for access token', ['code' => substr($code, 0, 10) . '...']);

        $access_token = $provider->get_access_token($code);

        if (empty($access_token)) {
            $this->log('ERROR: Failed to get access token');
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Failed to get access token.']);
            $this->render_callback_page('error', __('Failed to get access token.', 'wp-omni-auth'));
            return;
        }

        $this->log('Access token received', ['token_length' => strlen($access_token)]);

        $user_data = $provider->get_user_data($access_token);
        if (empty($user_data)) {
            $this->log('ERROR: Failed to get user data');
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Failed to get user data.']);
            $this->render_callback_page('error', __('Failed to get user data.', 'wp-omni-auth'));
            return;
        }

        // [S1] Log user data without access token
        $safe_user_data = $user_data;
        unset($safe_user_data['_access_token']);
        $this->log('User data received', ['user_data' => $safe_user_data]);

        $user_data['_access_token'] = $access_token;
        $email = $provider->get_email_from_user_data($user_data);
        $oauth_id = $provider->get_user_id_from_user_data($user_data);

        // ---- BIND MODE --------------------------------------------------
        // A logged-in admin initiated this OAuth flow via "Test connection &
        // bind". We already proved the config works (we reached this point),
        // now bind their account to the provider-returned stable identity.
        // No login happens and the allowlist is not checked.
        $bind_uid = get_transient('wpomni_bind_' . hash('sha256', $state));
        if ($bind_uid) {
            $this->handle_bind_callback($slug, $provider, $bind_uid, $oauth_id, $email, $state, $lock_key, $ip, $ua);
            return;
        }
        // -----------------------------------------------------------------

        // Email is now OPTIONAL: a provider that returns a stable identity but
        // no email (e.g. WeChat, Apple relay) can still match via that identity.
        if (empty($email) && empty($oauth_id)) {
            $this->log('ERROR: No email and no stable identity found in user data');
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'No identity found in OAuth response.']);
            $this->render_callback_page('error', __('No user identity found in OAuth response.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        $this->log('Email extracted', ['email' => $email, 'oauth_id' => $oauth_id]);

        // [T5] Identity-based throttle: cap how often a *single* OAuth identity
        // may attempt login within a short window. Throttle-only (no permanent
        // ban) so a looping/compromised account cannot be abused to DoS a
        // legitimate user, while still blunting malicious retry storms.
        // Use email when available, otherwise fall back to the stable oauth_id.
        $identity_key = !empty($email) ? $email : $oauth_id;
        if (self::check_rate_limit_per_identity($identity_key)) {
            $this->log('Blocked: Identity rate limit exceeded', ['identity' => $identity_key]);
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'email' => $email, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Identity rate limited.']);
            $this->render_callback_page('error', __('Too many login attempts. Please try again later.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }
        self::increment_rate_limit_per_identity($identity_key);

        $user = $this->find_user_by_oauth($slug, $oauth_id, $email);

        $user_mode = get_option('wpomni_user_mode', 'allowlist');

        if (!$user) {
            $this->log('ERROR: User not found', ['email' => $email, 'oauth_id' => $oauth_id]);
            delete_transient($lock_key);
            self::insert_login_log(['provider' => $slug, 'email' => $email, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Account not found.']);
            $this->render_callback_page('error', __('Account not found. Please use the correct OAuth account.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        // Authorization check based on user mode
        if ($user_mode === 'allowlist') {
            $allowed_ids = get_option('wpomni_allowed_user_ids', []);
            if (!is_array($allowed_ids)) {
                $allowed_ids = [];
            }
            // Backward compat: if array is empty, try migrating from old single ID
            if (empty($allowed_ids)) {
                $old_id = (int) get_option('wpomni_allowed_user_id', 1);
                if ($old_id > 0) {
                    $allowed_ids = [$old_id];
                }
            }
            if (!in_array($user->ID, array_map('intval', $allowed_ids), true)) {
                $this->log('ERROR: User not allowed', [
                    'user_id' => $user->ID,
                    'user_login' => $user->user_login,
                    'allowed_user_ids' => $allowed_ids,
                ]);
                delete_transient($lock_key);
                self::insert_login_log(['user_id' => $user->ID, 'provider' => $slug, 'email' => $email, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Access denied.']);
                $this->render_callback_page('error', __('Access denied. This account is not authorized.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
                return;
            }
        }
        // mode === 'all_users': no additional restriction, any matched WP user is allowed

        $this->log('User matched and allowed', ['user_id' => $user->ID, 'user_login' => $user->user_login]);

        // Store OAuth provider + ID + email + binding time in user meta for future lookups
        $existing_provider = get_user_meta($user->ID, 'wpomni_provider', true);
        $existing_oauth_id = get_user_meta($user->ID, 'wpomni_id', true);
        if ($existing_provider !== $slug || $existing_oauth_id != $oauth_id) {
            update_user_meta($user->ID, 'wpomni_provider', $slug);
            if (!empty($oauth_id)) {
                update_user_meta($user->ID, 'wpomni_id', $oauth_id);
                // Per-provider binding meta (supports binding multiple providers
                // to one WordPress account).
                update_user_meta($user->ID, 'wpomni_' . $slug . '_id', $oauth_id);
            }
            if (!empty($email)) {
                update_user_meta($user->ID, 'wpomni_email', $email);
            }
            update_user_meta($user->ID, 'wpomni_binding_time', current_time('mysql'));
            $this->log('Stored OAuth meta for user', ['user_id' => $user->ID, 'provider' => $slug]);
        }

        // [S4] Validate redirect_to — only allow same-site URLs
        $redirect = admin_url();
        if (isset($_GET['redirect_to'])) {
            $raw_redirect = esc_url_raw($_GET['redirect_to']);
            $validated = wp_validate_redirect($raw_redirect, admin_url());
            $redirect = $validated ?: admin_url();
        }

        // Prevent any caching layer from stripping Set-Cookie headers
        nocache_headers();

        wp_set_current_user($user->ID);

        $headers_already_sent = headers_sent($sent_file, $sent_line);

        wp_set_auth_cookie($user->ID, true);

        $this->log('Cookie set', [
            'user_id'           => $user->ID,
            'logged_in'         => is_user_logged_in(),
            'redirect'          => $redirect,
            'is_ssl'            => is_ssl(),
            'site_url'          => site_url(),
            'cookie_domain'     => COOKIE_DOMAIN,
            'cookie_path'       => COOKIEPATH,
            'headers_sent'      => $headers_already_sent,
            'headers_sent_at'   => $headers_already_sent ? "$sent_file:$sent_line" : 'none',
        ]);

        delete_transient($lock_key);

        // Log successful login
        self::insert_login_log([
            'user_id'    => $user->ID,
            'provider'   => $slug,
            'email'      => $email,
            'ip'         => $ip,
            'user_agent' => $ua,
            'status'     => 'success',
        ]);

        // Use 200 + JS redirect instead of 302.
        // Some intermediaries (proxies, CDN, security modules) strip Set-Cookie
        // from 302 responses. A 200 response ensures the browser fully processes
        // Set-Cookie before JS navigates to the target URL.
        $this->render_callback_page('success', '', esc_url($redirect), $this->normalize_icon_for_display($provider->get_icon()), $user->display_name);
    }

    /**
     * Build the context array passed to render_callback_page so the callback
     * page can show *which* provider was involved in a failed login. Only the
     * provider identity (name/icon) is exposed — never the user's email, to
     * avoid leaking account information on the error screen.
     *
     * @param string $slug Provider slug.
     * @return array Context with provider_slug/provider_name/provider_icon.
     */
    protected function build_callback_context($slug) {
        $provider = $this->get_provider($slug);
        return [
            'provider_slug' => $slug,
            'provider_name' => $provider ? $provider->get_name() : $slug,
            'provider_icon' => $provider ? $this->normalize_icon_for_display($provider->get_icon()) : '',
        ];
    }

    /**
     * Render the OAuth callback result page (success or error).
     *
     * @param string $type      'success' or 'error'
     * @param string $message   Error message (sanitized before display)
     * @param string $redirect  URL for success redirect
     * @param string $icon_html Provider SVG icon HTML (for success only)
     * @param string $user_name Display name of the logged-in user (for success only)
     */
    protected function render_callback_page($type, $message = '', $redirect = '', $icon_html = '', $user_name = '', $context = []) {
        $is_success = ($type === 'success');
        $title = $is_success
            ? __('Login Successful', 'wp-omni-auth')
            : __('Login Failed', 'wp-omni-auth');
        $safe_message = $is_success ? '' : esc_html($message);
        $safe_redirect = $is_success ? esc_url($redirect) : wp_login_url();

        // Sanitize icon HTML
        $safe_icon = wp_kses($icon_html, [
            'svg'  => ['viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true],
            'path' => ['fill' => true, 'd' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true],
            'g'    => ['transform' => true, 'fill' => true, 'stroke' => true],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'transform' => true],
            'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true, 'transform' => true],
            'img'  => ['src' => true, 'alt' => true, 'class' => true, 'width' => true, 'height' => true],
        ]);

        require WPOMNIAUTH_PLUGIN_DIR . 'includes/views/callback-page.php';

        exit;
    }

    /**
     * [A] Server-side "begin login" endpoint (?wpomni_login={slug}).
     *
     * The login buttons now point here instead of directly at the provider's
     * authorization URL. We throttle login *initiation* (per-IP, global, and
     * per-provider) BEFORE redirecting the browser to the OAuth provider, so a
     * user or bot hammering the login button cannot overwhelm the provider's
     * authorization endpoint / get the OAuth app rate-limited or flagged.
     */
    public function begin_oauth_login() {
        if (!isset($_GET['wpomni_login'])) {
            return;
        }

        $slug = sanitize_text_field($_GET['wpomni_login']);
        $ip   = self::get_client_ip();
        $ua   = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');

        // IP blacklist — block outright.
        if (self::is_ip_blacklisted($ip)) {
            $this->log('Blocked: IP blacklisted (login init)', ['ip' => $ip]);
            $this->render_callback_page('error', __('Access denied. Your IP address has been blocked.', 'wp-omni-auth'));
            return;
        }

        // Throttle: per-IP and per-provider (reads counters; invalid slugs are
        // still throttled at the IP level to blunt flooding).
        if (self::check_rate_limit($ip) || self::check_rate_limit_per_provider($slug)) {
            $this->log('Blocked: Rate limit exceeded (login init)', ['ip' => $ip, 'slug' => $slug]);
            $this->render_callback_page('error', __('Too many login attempts. Please try again later.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        self::increment_rate_limit($ip);

        // Only consume the per-provider quota for a real, enabled provider,
        // so probing non-existent/invalid slugs does not exhaust it.
        $provider = $this->get_provider($slug);
        if (!$provider || !$provider->is_enabled()) {
            $this->render_callback_page('error', __('Invalid OAuth provider.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        self::increment_rate_limit_per_provider($slug);

        $state = $this->create_oauth_state($slug);
        $url   = $provider->get_authorization_url($state);
        if (empty($url)) {
            $this->render_callback_page('error', __('Invalid OAuth provider.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        // wp_redirect (not wp_safe_redirect) is required: the target is an
        // external provider domain, which wp_safe_redirect would reject.
        wp_redirect($url);
        exit;
    }

    /**
     * [BIND] Server-side "begin bind" endpoint (?action=wpomni_begin_bind).
     *
     * Triggered by the "Test connection & bind" button on a provider's settings
     * page. The clicking admin's session must be intact (current_user_can
     * manage_options). We persist a short-lived (10-min) marker that ties the
     * upcoming OAuth state to this admin user, then redirect into the real
     * OAuth flow. The callback detects the marker and performs a BIND instead
     * of a login. The provider config must already be saved (the JavaScript
     * handler AJAX-saves the form first) so the callback can use real secrets.
     */
    public function begin_oauth_bind() {
        check_ajax_referer('wpomni_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You must be an administrator to perform this action.', 'wp-omni-auth'));
        }

        $slug = isset($_REQUEST['slug']) ? sanitize_text_field($_REQUEST['slug']) : '';
        $provider = $this->get_provider($slug);
        if (!$provider || !$provider->is_enabled()) {
            wp_die(esc_html__('Invalid or disabled OAuth provider.', 'wp-omni-auth'));
        }

        $state = $this->create_oauth_state($slug);
        // Marker: this state initiates a BIND to the current admin user.
        set_transient('wpomni_bind_' . hash('sha256', $state), get_current_user_id(), 600);

        $url = $provider->get_authorization_url($state);
        if (empty($url)) {
            wp_die(esc_html__('Invalid OAuth provider.', 'wp-omni-auth'));
        }

        wp_redirect($url);
        exit;
    }

    /**
     * [BIND] Handle the callback when it is in "bind" mode.
     *
     * A logged-in admin just completed the OAuth flow to (a) prove the provider
     * config works and (b) bind their WordPress account to the provider-returned
     * stable identity. No login is performed and the allowlist is not checked.
     *
     * @param string $slug      Provider slug.
     * @param object $provider  Provider instance.
     * @param int    $bind_uid  User ID the bind marker was created for.
     * @param string $oauth_id  Stable provider identity from user data.
     * @param string $email     Email from user data (may be empty).
     * @param string $state     OAuth state nonce (for transient cleanup).
     * @param string $lock_key  Replay-protection transient lock key.
     * @param string $ip        Client IP (for logging).
     * @param string $ua        User agent (for logging).
     */
    private function handle_bind_callback($slug, $provider, $bind_uid, $oauth_id, $email, $state, $lock_key, $ip, $ua) {
        $bind_key = 'wpomni_bind_' . hash('sha256', $state);

        // The admin who started the bind must still be logged in and be the
        // same user the marker was created for (their session must survive the
        // OAuth redirect round-trip).
        if (!is_user_logged_in() || (int) get_current_user_id() !== (int) $bind_uid) {
            delete_transient($lock_key);
            delete_transient($bind_key);
            self::insert_login_log(['user_id' => $bind_uid, 'provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Bind session expired.']);
            $this->render_callback_page('error', __('Your login session expired during binding. Please log in and try again.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        if (empty($oauth_id)) {
            delete_transient($lock_key);
            delete_transient($bind_key);
            self::insert_login_log(['user_id' => $bind_uid, 'provider' => $slug, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'failure', 'message' => 'Provider returned no stable identity.']);
            $this->render_callback_page('error', __('The provider did not return a stable user ID, so it cannot be bound.', 'wp-omni-auth'), '', '', '', $this->build_callback_context($slug));
            return;
        }

        // Write the per-provider binding meta (supports multiple providers per
        // account) plus the legacy keys for backward compatibility.
        update_user_meta($bind_uid, 'wpomni_' . $slug . '_id', $oauth_id);
        update_user_meta($bind_uid, 'wpomni_provider', $slug);
        update_user_meta($bind_uid, 'wpomni_id', $oauth_id);
        if (!empty($email)) {
            update_user_meta($bind_uid, 'wpomni_email', $email);
        }
        update_user_meta($bind_uid, 'wpomni_binding_time', current_time('mysql'));

        $this->log('Bound provider to user', ['user_id' => $bind_uid, 'provider' => $slug, 'oauth_id' => $oauth_id]);

        // Single-use lock + mark code consumed + drop bind marker.
        delete_transient($lock_key);
        delete_transient($bind_key);
        $used_codes = get_option('wpomni_used_codes', []);
        if (isset($_GET['code'])) {
            $code = sanitize_text_field($_GET['code']);
            $used_codes[$code] = time();
            $used_codes = array_filter($used_codes, function ($t) { return time() - $t < 300; });
            update_option('wpomni_used_codes', $used_codes);
        }

        self::insert_login_log(['user_id' => $bind_uid, 'provider' => $slug, 'email' => $email, 'ip' => $ip, 'user_agent' => $ua, 'status' => 'success']);

        // Dispatch binding event (separate from login events).
        WPOmniAuth_Event_Dispatcher::dispatch('provider_bind', [
            'user_id'     => $bind_uid,
            'provider'    => $provider->get_name(),
            'slug'        => $slug,
            'email'       => $email,
            'oauth_id'    => $oauth_id,
            'ip'          => $ip,
            'user_agent'  => $ua,
        ]);

        // Redirect back to the provider's settings detail on success, using the
        // unified deep-link (?provider=slug) so the config stays open.
        $return_url = admin_url('options-general.php?page=wp-omni-auth&tab=providers&provider=' . rawurlencode($slug));
        $this->render_callback_page('success', '', esc_url($return_url), $this->normalize_icon_for_display($provider->get_icon()), '', [
            'mode'           => 'bind',
            'provider_name' => $provider->get_name(),
        ]);
    }

    /**
     * [P2] Optimized user lookup: single meta_query instead of query + per-user get_user_meta.
     */
    protected function find_user_by_oauth($slug, $oauth_id, $email) {
        return WPOmniAuth_User_Matcher::find($slug, $oauth_id, $email);
    }

    /**
     * Get client IP address.
     *
     * Delegates to WPOmniAuth_Security (logic extracted for testability).
     * In trusted-proxy mode it reads the configured proxy/CDN header or the
     * chosen X-Forwarded-For segment; otherwise it falls back to REMOTE_ADDR.
     *
     * @see WPOmniAuth_Security::get_client_ip()
     */
    public static function get_client_ip() {
        return WPOmniAuth_Security::get_client_ip();
    }

    /**
     * Check if an IP is blacklisted.
     * Delegates to WPOmniAuth_Security.
     * @see WPOmniAuth_Security::is_ip_blacklisted()
     */
    public static function is_ip_blacklisted($ip) {
        return WPOmniAuth_Security::is_ip_blacklisted($ip);
    }

    /**
     * Check rate limit for an IP.
     * Delegates to WPOmniAuth_Security.
     * @see WPOmniAuth_Security::check_rate_limit()
     */
    public static function check_rate_limit($ip) {
        return WPOmniAuth_Security::check_rate_limit($ip);
    }

    /**
     * Increment rate limit counters for an IP.
     * Delegates to WPOmniAuth_Security.
     * @see WPOmniAuth_Security::increment_rate_limit()
     */
    public static function increment_rate_limit($ip) {
        WPOmniAuth_Security::increment_rate_limit($ip);
    }

    /**
     * Check if an IP should be auto-banned after too many failures.
     * Delegates to WPOmniAuth_Security.
     * @see WPOmniAuth_Security::maybe_auto_ban()
     */
    public static function maybe_auto_ban($ip) {
        WPOmniAuth_Security::maybe_auto_ban($ip);
    }

    /**
     * Per-provider rate limit check.
     * @see WPOmniAuth_Security::check_rate_limit_per_provider()
     */
    public static function check_rate_limit_per_provider($slug) {
        return WPOmniAuth_Security::check_rate_limit_per_provider($slug);
    }

    /**
     * Increment per-provider rate limit counter.
     * @see WPOmniAuth_Security::increment_rate_limit_per_provider()
     */
    public static function increment_rate_limit_per_provider($slug) {
        WPOmniAuth_Security::increment_rate_limit_per_provider($slug);
    }

    /**
     * Per-identity rate limit check (by email / OAuth id).
     * @see WPOmniAuth_Security::check_rate_limit_per_identity()
     */
    public static function check_rate_limit_per_identity($identity) {
        return WPOmniAuth_Security::check_rate_limit_per_identity($identity);
    }

    /**
     * Increment per-identity rate limit counter (short window, throttle-only).
     * @see WPOmniAuth_Security::increment_rate_limit_per_identity()
     */
    public static function increment_rate_limit_per_identity($identity) {
        WPOmniAuth_Security::increment_rate_limit_per_identity($identity);
    }

    /**
     * Check if an IP falls within a CIDR range (IPv4 only for simplicity).
     *
     * Kept in the Manager (in addition to WPOmniAuth_Security::ip_in_cidr) so the
     * existing Test_Security::test_ip_in_cidr() keeps passing without changes;
     * the canonical implementation lives in WPOmniAuth_Security.
     */
    private static function ip_in_cidr($ip, $cidr) {
        list($subnet, $mask) = explode('/', $cidr, 2);
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) {
            return false;
        }
        $mask_long = -1 << (32 - $mask);
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * Insert a login log entry into the custom table.
     *
     * @param array $args {
     *     @type int    $user_id    WordPress user ID (0 if unknown)
     *     @type string $provider   Provider slug (e.g. 'google')
     *     @type string $email      Email from OAuth response
     *     @type string $ip         Client IP address
     *     @type string $status     'success' or 'failure'
     *     @type string $message    Error message (for failures)
     *     @type string $user_agent Browser user agent
     * }
     */
    public static function insert_login_log($args) { return WPOmniAuth_Login_Log::insert_login_log(...func_get_args()); }


    /**
     * Cron handler: delete login log entries older than the retention period.
     * Default retention: 90 days. Configurable via wpomni_log_retention_days option.
     */
    public static function cleanup_login_log() { return WPOmniAuth_Login_Log::cleanup_login_log(...func_get_args()); }


    /**
     * [C6] Use add_option() instead of update_option() to preserve existing config on re-activation.
     */
    /**
     * Seed all default options. Uses add_option() so existing values are
     * never overwritten. Shared by both activate() (fresh installs) and the
     * upgrade routine (existing installs reached via GitHub self-update, which
     * does NOT call activate()).
     */
    private static function add_default_options() {
        add_option('wpomni_hide_password', 'no');
        add_option('wpomni_user_mode', 'allowlist');
        add_option('wpomni_allowed_user_ids', [1]);
        add_option('wpomni_github_enabled', 'no');
        add_option('wpomni_google_enabled', 'no');
        add_option('wpomni_custom_providers', []);
        // Security defaults
        add_option('wpomni_trusted_proxy', 'no');
        add_option('wpomni_auto_ban_threshold', 10);
        add_option('wpomni_auto_ban_window', 24);
        add_option('wpomni_auto_ban_duration', 24);
        add_option('wpomni_rate_limit_per_ip', 10);
        add_option('wpomni_rate_limit_global', 60);
        add_option('wpomni_blacklisted_ips', []);
        // Notification defaults
        add_option('wpomni_email_notify_enabled', 'no');
        add_option('wpomni_email_notify_on', 'failures');
        add_option('wpomni_webhook_url', '');
        add_option('wpomni_webhook_events', []);
        add_option('wpomni_webhook_secret', '');
        // Update mirror source (gh-proxy) — off by default
        add_option('wpomni_use_mirror', 'no');
        // Emergency backdoor key (48 chars, 192+ bit entropy)
        add_option('wpomni_emergency_key', wp_generate_password(48, false));
    }

    public static function activate() {
        self::add_default_options();
        // Record installed version so upgrade routines only run for existing installs
        add_option('wpomni_version', WPOMNIAUTH_VERSION);

        // Create login log table (fresh installs skip upgrade routines)
        self::ensure_login_log_table();

        // Schedule login log cleanup cron
        if (!wp_next_scheduled('wpomni_cleanup_login_log')) {
            wp_schedule_event(time(), 'daily', 'wpomni_cleanup_login_log');
        }
        // Schedule periodic provider configuration health check
        WPOmniAuth_Provider_Checker::instance()->schedule();
    }

    /**
     * Plugin deactivation: only clean up ephemeral runtime data.
     * User configuration (secrets, settings) is preserved so re-activation
     * restores the plugin to its previous state.
     * Full cleanup happens in uninstall.php.
     */
    public static function deactivate() {
        // Clean up any stale transient locks (they expire in 60s, but just in case)
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpomni_code_lock_%'"
        );
        // Clean up used codes (they expire in 5min, but clear on deactivate)
        delete_option('wpomni_used_codes');
        // Clear scheduled cron tasks
        wp_clear_scheduled_hook('wpomni_cleanup_login_log');
        WPOmniAuth_Provider_Checker::instance()->unschedule();
    }

    /**
     * Check if plugin was updated and run upgrade routines if needed.
     * Called on admin_init — only runs in admin context, zero frontend overhead.
     *
     * Existing installs upgrading from < 0.1.0 will have wpomni_version default
     * to '0.1.0' (option doesn't exist yet), triggering no upgrade routine.
     * Fresh installs have wpomni_version set to WPOMNIAUTH_VERSION in activate(),
     * so no upgrade routines run.
     */
    public static function maybe_upgrade() {
        $installed_version = get_option('wpomni_version', '0.1.0');
        if (version_compare($installed_version, WPOMNIAUTH_VERSION, '>=')) {
            return;
        }

        self::run_upgrade_routines($installed_version, WPOMNIAUTH_VERSION);
        update_option('wpomni_version', WPOMNIAUTH_VERSION);
    }

    /**
     * Run upgrade routines step by step.
     * Each routine is guarded by version_compare so it only runs once.
     */
    private static function run_upgrade_routines($from, $to) {
        // 0.1.0: Login history table, multi-user migration, default options, etc.
        if (version_compare($from, '0.1.0', '<')) {
            self::upgrade_to_1_1_0();
        }

        // Future versions:
        // if (version_compare($from, '1.2.0', '<')) {
        //     self::upgrade_to_1_2_0();
        // }
    }

    /**
     * Create the login log table if it doesn't exist.
     * Called from both activate() (fresh installs) and upgrade_to_1_1_0() (existing installs).
     */
    public static function ensure_login_log_table() { return WPOmniAuth_Login_Log::ensure_login_log_table(...func_get_args()); }


    /**
     * Upgrade to v0.1.0 (originally v1.1.0 migration):
     * - Seed default options for existing installs (shared with activate())
     * - Create login_log table
     * - Migrate wpomni_allowed_user_id → wpomni_allowed_user_ids
     * - Schedule login log cleanup cron
     */
    private static function upgrade_to_1_1_0() {
        // Seed all 0.1.0 default options for existing installs. GitHub
        // self-updates run this routine instead of activate(), so the defaults
        // must be created here too — otherwise new options stay absent and the
        // corresponding features silently default off until a manual reactivate.
        self::add_default_options();

        // 1. Create login_log table
        self::ensure_login_log_table();

        // 2. Migrate single user ID to array
        if (get_option('wpomni_allowed_user_id') !== false && get_option('wpomni_allowed_user_ids') === false) {
            $old_id = (int) get_option('wpomni_allowed_user_id', 1);
            update_option('wpomni_allowed_user_ids', [$old_id]);
            delete_option('wpomni_allowed_user_id');
        }

        // 3. Schedule login log cleanup cron (if not already scheduled)
        if (!wp_next_scheduled('wpomni_cleanup_login_log')) {
            wp_schedule_event(time(), 'daily', 'wpomni_cleanup_login_log');
        }

        // 4. Clean up old dashboard stats transient (v1 → v2 rename)
        delete_transient('wpomni_dashboard_stats');
    }
}
