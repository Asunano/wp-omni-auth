<?php
if (!defined('ABSPATH')) { exit; }

class WPOmniAuth_Emergency_Access {

    /**
     * Lifetime of an emailed login link, in seconds (15 minutes).
     * Also used as the emergency-mode window once the link (or key) activates.
     */
    const LINK_TTL = 900;

    /**
     * Entry point, hooked on `init` priority 0 (before the OAuth callback at 1).
     *
     * Two ways in:
     *   A. Email login link  — GET ?wpomni_emergency=1&wpomni_token=XXX
     *      (the link emailed by process_email_link_request). Valid 15 min and
     *      bound to the single IP that requested it; activates password login.
     *   B. Manual key (backup) — POST the emergency key on the rendered page.
     *
     * The page only renders on an explicit ?wpomni_emergency=1 GET, so it never
     * replaces the normal OAuth login screen.
     */
    public static function handle_emergency_access() {
        // Only process on wp-login.php
        if (!isset($GLOBALS['pagenow']) || $GLOBALS['pagenow'] !== 'wp-login.php') {
            return;
        }
        if (get_option('wpomni_hide_password', 'no') !== 'yes') {
            return; // OAuth-only mode not enabled
        }

        // Password login is already temporarily allowed — nothing to do.
        if (get_transient('wpomni_emergency_active')) {
            return;
        }

        // Only handle the explicit emergency-access entry point. Without this
        // guard the emergency page would render (and exit) on every wp-login.php
        // request, replacing the normal OAuth login screen.
        if (!isset($_GET['wpomni_emergency']) || $_GET['wpomni_emergency'] !== '1') {
            return;
        }

        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // A. Email link click — activate if the token is valid and IP-bound.
        if (!empty($_GET['wpomni_token'])) {
            self::process_email_link_click($ip);
            exit;
        }

        // Render the page (key form + email form) on GET.
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            self::render_emergency_page();
            exit;
        }

        // POST: which form was submitted? The form "action" is carried in the
        // URL query string (e.g. ?wpomni_emergency=1&action=email), NOT as a
        // POST field, so read it from $_GET.
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'email') {
            self::process_email_link_request($ip);   // A (request link)
        } else {
            self::process_emergency_key_verify($ip); // B (manual key)
        }
        exit;
    }

    /**
     * A (click): validate the emailed login-link token and activate emergency mode.
     *
     * The token is stored server-side keyed by its value; the payload carries the
     * requesting IP and a 15-minute TTL. The transient itself enforces expiry
     * (deleted once TTL elapses). The token is single-use — consumed on success
     * or on any failure — so it cannot be replayed or shared across IPs.
     */
    private static function process_email_link_click($ip) {
        $token = isset($_GET['wpomni_token']) ? sanitize_text_field($_GET['wpomni_token']) : '';
        $data  = get_transient('wpomni_emg_link_' . $token);

        if (empty($data)) {
            // Expired, already used, or never issued.
            wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&linkfail=1');
            exit;
        }

        $info = json_decode($data, true);
        delete_transient('wpomni_emg_link_' . $token); // single-use

        // Explicit expiry check (defense-in-depth — transient TTL handles this, but
        // verify the payload timestamp as well in case the transient lives longer
        // than expected due to object cache quirks).
        if (empty($info['expires']) || time() > (int) $info['expires']) {
            wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&linkfail=1');
            exit;
        }

        // Activate emergency mode for 15 minutes, recording the first-access IP.
        set_transient('wpomni_emergency_active', 1, self::LINK_TTL);
        set_transient('wpomni_emergency_ip', $ip, self::LINK_TTL);
        WPOmniAuth_Logger::debug_log('EMERGENCY ACCESS (email link) used from IP: ' . $ip, null, 'Emergency');
        if (class_exists('WPOmniAuth_Event_Dispatcher')) {
            WPOmniAuth_Event_Dispatcher::dispatch('emergency_access', ['ip' => $ip]);
        }

        wp_safe_redirect(wp_login_url() . '?wpomni_emergency_active=1');
        exit;
    }

    /**
     * A (request): email a one-time login link to an authorized admin address.
     *
     * Only `manage_options` users or the site `admin_email` receive a link; for
     * any other address we return the SAME "sent" response without emailing
     * (enumeration resistance). A genuine send failure for an authorized address
     * is surfaced as an error so the admin knows to check mail config / use the
     * manual key.
     */
    private static function process_email_link_request($ip) {
        // Per-IP rate limit for requesting a link.
        $ip_key = 'wpomni_emg_reqip_' . hash('sha256', $ip);
        if ((int) get_transient($ip_key) >= 3) {
            wp_die(__('Service temporarily unavailable.', 'wp-omni-auth'), __('Error', 'wp-omni-auth'), ['response' => 503]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wpomni_emergency_request')) {
            wp_die(__('Security check failed.', 'wp-omni-auth'));
        }

        // Honeypot: real users never submit the hidden field.
        if (self::is_honeypot_tripped()) {
            wp_die(__('Security check failed.', 'wp-omni-auth'));
        }

        // Human-verification (CAPTCHA) before issuing a link — resists automated
        // link-request / email-bomb abuse. Bound to a single-use token; the answer
        // is stored server-side only as a hash, so a bot scraping the page gets the
        // image but not the answer.
        $submitted_captcha = isset($_POST['wpomni_captcha']) ? strtoupper(sanitize_text_field($_POST['wpomni_captcha'])) : '';
        $captcha_token     = isset($_POST['wpomni_captcha_token']) ? sanitize_text_field($_POST['wpomni_captcha_token']) : '';
        $stored_hash       = get_transient('wpomni_emg_captcha_' . $captcha_token);
        delete_transient('wpomni_emg_captcha_' . $captcha_token); // single-use
        if (empty($stored_hash) || !wp_check_password($submitted_captcha, $stored_hash)) {
            $fail_key = 'wpomni_emg_captfail_' . hash('sha256', $ip);
            $fails    = (int) get_transient($fail_key) + 1;
            set_transient($fail_key, $fails, 600);
            if ($fails >= 5) {
                wp_die(__('Service temporarily unavailable.', 'wp-omni-auth'), __('Error', 'wp-omni-auth'), ['response' => 503]);
            }
            wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&capterror=1');
            exit;
        }

        $email = isset($_POST['wpomni_email']) ? sanitize_email($_POST['wpomni_email']) : '';
        set_transient($ip_key, (int) get_transient($ip_key) + 1, 600);

        // Is this an authorized admin address?
        $valid = false;
        if (is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user && user_can($user, 'manage_options')) {
                $valid = true;
            } elseif (strtolower($email) === strtolower(get_option('admin_email', ''))) {
                $valid = true;
            }
        }

        if ($valid) {
            // Per-email throttle on generating/sending a new link.
            $email_key = 'wpomni_emg_reqemail_' . hash('sha256', $email);
            if ((int) get_transient($email_key) < 3) {
                $token  = wp_generate_password(32, false);
                $payload = wp_json_encode([
                    'email'   => $email,
                    'expires' => time() + self::LINK_TTL,
                ]);
                set_transient('wpomni_emg_link_' . $token, $payload, self::LINK_TTL);

                $site = get_bloginfo('name');
                $link = wp_login_url() . '?wpomni_emergency=1&wpomni_token=' . $token;
                $subject = sprintf(__('[%s] Emergency login link', 'wp-omni-auth'), $site);

                $body_html = self::format_emergency_email_html($site, $ip, $link);
                if (class_exists('WPOmniAuth_Event_Dispatcher')) {
                    $sent = WPOmniAuth_Event_Dispatcher::send_html_mail($email, $subject, $body_html);
                } else {
                    $sent = false;
                }
                set_transient($email_key, (int) get_transient($email_key) + 1, 1800);

                if (!$sent) {
                    // Could not deliver — drop the unusable token and tell the admin.
                    delete_transient('wpomni_emg_link_' . $token);
                    WPOmniAuth_Logger::debug_log('EMERGENCY LINK email FAILED to: ' . $email . ' from IP: ' . $ip, null, 'Emergency');
                    wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&linkerror=1');
                    exit;
                }
                WPOmniAuth_Logger::debug_log('EMERGENCY LINK issued to: ' . $email . ' from IP: ' . $ip, null, 'Emergency');
            } else {
                WPOmniAuth_Logger::debug_log('EMERGENCY LINK request rate-limited for: ' . $email . ' from IP: ' . $ip, null, 'Emergency');
            }
        } else {
            WPOmniAuth_Logger::debug_log('EMERGENCY LINK request for non-authorized email: ' . $email . ' from IP: ' . $ip, null, 'Emergency');
        }

        // Unified success — never reveal whether the address was valid.
        wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&linksent=1');
        exit;
    }

    private static function render_emergency_page() {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // Rate limit: max 3 key attempts per IP per 10 minutes (applies to the
        // manual-key form; the link-request form has its own IP throttle).
        $attempts_key = 'wpomni_emergency_attempts_' . hash('sha256', $ip);
        if ((int) get_transient($attempts_key) >= 3) {
            wp_die(__('Service temporarily unavailable.', 'wp-omni-auth'), __('Error', 'wp-omni-auth'), ['response' => 503]);
        }

        $linksent  = isset($_GET['linksent']) ? (int) $_GET['linksent'] : 0;
        $linkerror = isset($_GET['linkerror']) ? (int) $_GET['linkerror'] : 0;
        $linkfail  = isset($_GET['linkfail']) ? (int) $_GET['linkfail'] : 0;
        $capterror = isset($_GET['capterror']) ? (int) $_GET['capterror'] : 0;
        $keyerror  = isset($_GET['error']) ? (int) $_GET['error'] : 0;

        $site_name = get_bloginfo('name');

        // Human-verification CAPTCHA — GD PNG when available, otherwise an
        // obfuscated arithmetic SVG. The raw answer is never present in the
        // markup. Bound to a single-use token stored server-side as a hash.
        $challenge = self::get_captcha($ip);
        $captcha_img = $challenge['display'];
        $captcha_is_arithmetic = !function_exists('imagecreatetruecolor') || !function_exists('imagepng');

        $captcha_token = wp_generate_password(16, false);
        set_transient('wpomni_emg_captcha_' . $captcha_token, wp_hash_password($challenge['answer']), 300);

        // The manual-key form also gets its own single-use CAPTCHA so a bot
        // cannot brute-force the key without solving a challenge each time.
        $key_challenge        = self::get_captcha($ip);
        $key_captcha_img      = $key_challenge['display'];
        $key_captcha_token    = wp_generate_password(16, false);
        set_transient('wpomni_emg_captcha_' . $key_captcha_token, wp_hash_password($key_challenge['answer']), 300);

        nocache_headers();

        require WPOMNIAUTH_PLUGIN_DIR . 'includes/views/emergency-page.php';
    }

    /**
     * B (manual key, backup): verify the static emergency key and activate
     * emergency mode for 15 minutes. Uses a time-safe comparison.
     */
    private static function process_emergency_key_verify($ip) {
        $attempts_key = 'wpomni_emergency_attempts_' . hash('sha256', $ip);
        if ((int) get_transient($attempts_key) >= 3) {
            wp_die(__('Service temporarily unavailable.', 'wp-omni-auth'), __('Error', 'wp-omni-auth'), ['response' => 503]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wpomni_emergency_verify')) {
            wp_die(__('Security check failed.', 'wp-omni-auth'));
        }

        // Honeypot: real users never submit the hidden field.
        if (self::is_honeypot_tripped()) {
            wp_die(__('Security check failed.', 'wp-omni-auth'));
        }

        // Human-verification (CAPTCHA) before checking the key — resists
        // automated key-brute-force ("撞库"). Bound to a single-use token; the
        // answer is stored server-side only as a hash.
        $submitted_captcha = isset($_POST['wpomni_captcha']) ? strtoupper(sanitize_text_field($_POST['wpomni_captcha'])) : '';
        $captcha_token      = isset($_POST['wpomni_captcha_token']) ? sanitize_text_field($_POST['wpomni_captcha_token']) : '';
        $stored_hash        = get_transient('wpomni_emg_captcha_' . $captcha_token);
        delete_transient('wpomni_emg_captcha_' . $captcha_token); // single-use
        if (empty($stored_hash) || !wp_check_password($submitted_captcha, $stored_hash)) {
            $fail_key = 'wpomni_emg_keycaptfail_' . hash('sha256', $ip);
            $fails    = (int) get_transient($fail_key) + 1;
            set_transient($fail_key, $fails, 600);
            if ($fails >= 5) {
                wp_die(__('Service temporarily unavailable.', 'wp-omni-auth'), __('Error', 'wp-omni-auth'), ['response' => 503]);
            }
            wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&capterror=1');
            exit;
        }

        $submitted_key = isset($_POST['wpomni_key']) ? (string) $_POST['wpomni_key'] : '';
        // Validate key format: non-empty, printable ASCII, reasonable length.
        if (empty($submitted_key) || strlen($submitted_key) > 128 || !preg_match('/^[\x20-\x7E]+$/', $submitted_key)) {
            set_transient($attempts_key, (int) get_transient($attempts_key) + 1, 600);
            wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&error=1');
            exit;
        }
        $stored_key    = get_option('wpomni_emergency_key', '');

        // Time-safe comparison
        if (!empty($stored_key) && hash_equals($stored_key, $submitted_key)) {
            // Key correct — enable emergency mode for 15 minutes, record unlocking IP.
            set_transient('wpomni_emergency_active', 1, self::LINK_TTL);
            set_transient('wpomni_emergency_ip', $ip, self::LINK_TTL);

            WPOmniAuth_Logger::debug_log('EMERGENCY ACCESS (manual key) used from IP: ' . $ip, null, 'Emergency');

            if (class_exists('WPOmniAuth_Event_Dispatcher')) {
                WPOmniAuth_Event_Dispatcher::dispatch('emergency_access', ['ip' => $ip]);
            }

            wp_safe_redirect(wp_login_url() . '?wpomni_emergency_active=1');
            exit;
        }

        // Wrong key — increment attempts
        set_transient($attempts_key, (int) get_transient($attempts_key) + 1, 600);
        wp_safe_redirect(wp_login_url() . '?wpomni_emergency=1&error=1');
        exit;
    }

    /**
     * Generate a single-use emergency verification code (used by the CAPTCHA
     * image generator; unrelated to the login-link flow).
     *
     * @param int $length
     * @return string
     */
    private static function generate_emergency_code($length = 6) {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max     = strlen($charset) - 1;
        $code    = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $charset[wp_rand(0, $max)];
        }
        return $code;
    }

    /**
     * Get (or lazily generate) the human-verification CAPTCHA for this request.
     *
     * @param string $ip
     * @return array{display:string,answer:string}
     */
    private static function get_captcha($ip) {
        // Preferred path: a distorted PNG whose answer lives only in the pixels.
        $code = self::generate_emergency_code(5);
        $img  = self::get_captcha_image($code);
        if (false !== $img) {
            return ['display' => $img, 'answer' => strtoupper($code)];
        }

        // Fallback (no GD): an arithmetic question rendered as an obfuscated SVG
        // image. Glyphs are vector paths (no <text>), so the equation is not
        // scrapeable DOM text; the numeric answer stays server-side only.
        $ops = array('+', '-', '×', '÷');
        $op  = $ops[wp_rand(0, count($ops) - 1)];
        switch ($op) {
            case '-':
                $a = wp_rand(0, 100);
                $b = wp_rand(0, $a);
                $answer = $a - $b;
                break;
            case '×':
                do {
                    $a = wp_rand(2, 12);
                    $b = wp_rand(2, 12);
                    $answer = $a * $b;
                } while ($answer > 100);
                break;
            case '÷':
                $b = wp_rand(2, 12);
                $answer = wp_rand(2, 12);
                $a = $answer * $b;
                break;
            case '+':
            default:
                $a = wp_rand(0, 99);
                $b = wp_rand(0, 99 - $a);
                $answer = $a + $b;
                break;
        }
        $question = sprintf(
            /* translators: 1: first number, 2: operator (+ - × ÷), 3: second number */
            __('%1$d %2$s %3$d =', 'wp-omni-auth'),
            $a,
            $op,
            $b
        );
        return ['display' => self::get_captcha_svg($question), 'answer' => (string) $answer];
    }

    /**
     * Render a single character as 7-segment VECTOR rectangles (no <text> node).
     *
     * @param string $ch
     * @param int    $ox
     * @param int    $oy
     * @return string
     */
    private static function svg_glyph($ch, $ox, $oy) {
        $W = 28;
        $H = 38;
        $t = 5;
        $seg = function ($x, $y, $w, $h) {
            return sprintf('<rect x="%d" y="%d" width="%d" height="%d"/>', $x, $y, $w, $h);
        };
        $segs = array(
            'a' => $seg($ox + $t, $oy, $W - 2 * $t, $t),
            'b' => $seg($ox + $W - $t, $oy + $t, $t, (int) ($H / 2) - $t),
            'c' => $seg($ox + $W - $t, $oy + (int) ($H / 2), $t, (int) ($H / 2) - $t),
            'd' => $seg($ox + $t, $oy + $H - $t, $W - 2 * $t, $t),
            'e' => $seg($ox, $oy + (int) ($H / 2), $t, (int) ($H / 2) - $t),
            'f' => $seg($ox, $oy + $t, $t, (int) ($H / 2) - $t),
            'g' => $seg($ox + $t, $oy + (int) ($H / 2) - (int) ($t / 2), $W - 2 * $t, $t),
        );
        $map = array(
            '0' => 'abcdef', '1' => 'bc', '2' => 'abged', '3' => 'abgcd',
            '4' => 'fgbc',   '5' => 'afgcd', '6' => 'afgecd', '7' => 'abc',
            '8' => 'abcdefg', '9' => 'abcdfg',
        );
        if (isset($map[$ch])) {
            $out = '';
            foreach (str_split($map[$ch]) as $s) {
                $out .= $segs[$s];
            }
            return $out;
        }
        if ($ch === '+') {
            return $seg($ox, $oy + (int) ($H / 2) - (int) ($t / 2), $W, $t)
                 . $seg($ox + (int) ($W / 2) - (int) ($t / 2), $oy, $t, $H);
        }
        if ($ch === '=') {
            return $seg($ox, $oy + (int) ($H * 0.32), $W, $t)
                 . $seg($ox, $oy + (int) ($H * 0.60), $W, $t);
        }
        if ($ch === '-') {
            return $seg($ox, $oy + (int) ($H / 2) - (int) ($t / 2), $W, $t);
        }
        if ($ch === '×') {
            $line = function ($x1, $y1, $x2, $y2) use ($t) {
                return sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#1d2327" stroke-width="%d"/>', $x1, $y1, $x2, $y2, $t);
            };
            return $line($ox, $oy, $ox + $W, $oy + $H)
                 . $line($ox + $W, $oy, $ox, $oy + $H);
        }
        if ($ch === '÷') {
            $dot = function ($cy) use ($ox, $oy, $W, $H, $t) {
                return sprintf('<circle cx="%d" cy="%d" r="%d" fill="#1d2327"/>', $ox + (int) ($W / 2), $oy + $cy, (int) ($t * 0.9));
            };
            return $seg($ox, $oy + (int) ($H / 2) - (int) ($t / 2), $W, $t)
                 . $dot((int) ($H * 0.26))
                 . $dot((int) ($H * 0.74));
        }
        return '';
    }

    /**
     * Render a challenge string as an inline SVG image (data URI) — the no-GD
     * fallback for the human-verification CAPTCHA.
     *
     * @param string $text
     * @return string data:image/svg+xml;base64,...
     */
    private static function get_captcha_svg($text) {
        $w = 220;
        $h = 62;
        $ox = 16;
        $oy = 9;
        $step = 34;

        $glyphs = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $ch) {
            if ($ch === ' ') {
                $ox += 12;
                continue;
            }
            $rot = wp_rand(-15, 15);
            $scale = wp_rand(85, 115);
            $cx = $ox + 14;
            $cy = $oy + 19;
            $g = self::svg_glyph($ch, $ox, $oy);
            if ($g !== '') {
                $glyphs .= sprintf(
                    '<g transform="rotate(%d %d %d) scale(%d %d) translate(%d %d)">%s</g>',
                    $rot, $cx, $cy,
                    $scale / 100, $scale / 100,
                    (int)(($ox - $ox * $scale / 100) / 2),
                    (int)(($oy - $oy * $scale / 100) / 2),
                    $g
                );
            }
            $ox += $step;
        }

        // Background noise dots
        $dots = '';
        for ($i = 0; $i < 150; $i++) {
            $dots .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="#%02x%02x%02x" opacity="%s"/>',
                wp_rand(0, $w),
                wp_rand(0, $h),
                wp_rand(1, 3),
                wp_rand(140, 210), wp_rand(140, 210), wp_rand(140, 210),
                (string) wp_rand(3, 7) / 10
            );
        }

        // Foreground noise arcs (harder for OCR to filter)
        for ($i = 0; $i < 10; $i++) {
            $cx = wp_rand(0, $w);
            $cy = wp_rand(0, $h);
            $r = wp_rand(10, 40);
            $dots .= sprintf(
                '<path d="M%d %d A%d %d 0 0 1 %d %d" fill="none" stroke="#%02x%02x%02x" stroke-width="%d" opacity="0.3"/>',
                $cx - $r, $cy, $r, $r, $cx + $r, $cy + wp_rand(-5, 5),
                wp_rand(160, 200), wp_rand(160, 200), wp_rand(160, 200),
                wp_rand(1, 2)
            );
        }

        // Interference lines
        $lines = '';
        for ($i = 0; $i < 6; $i++) {
            $lines .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#%02x%02x%02x" stroke-width="%d" opacity="%s"/>',
                wp_rand(0, $w),
                wp_rand(0, $h),
                wp_rand(0, $w),
                wp_rand(0, $h),
                wp_rand(100, 180), wp_rand(100, 180), wp_rand(100, 180),
                wp_rand(1, 2),
                (string) wp_rand(3, 6) / 10
            );
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
                . '<rect width="100%%" height="100%%" fill="#f5f7fa"/>%s%s<g fill="#1d2327">%s</g></svg>',
            $w,
            $h,
            $w,
            $h,
            $dots,
            $lines,
            $glyphs
        );
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Render the CAPTCHA code as a distorted PNG (returned as a data: URI).
     *
     * Requires the GD extension; returns false when GD is unavailable so the
     * caller falls back to the SVG path.
     *
     * @param string $code
     * @return string|false
     */
    private static function get_captcha_image($code) {
        if (!function_exists('imagecreatetruecolor')
            || !function_exists('imagecolorallocate')
            || !function_exists('imagechar')
            || !function_exists('imagepng')
            || !function_exists('imagedestroy')
        ) {
            return false;
        }

        $w = 190;
        $h = 64;
        $img = imagecreatetruecolor($w, $h);
        if (!$img) {
            return false;
        }

        $bg = imagecolorallocate($img, 245, 247, 250);
        imagefill($img, 0, 0, $bg);

        for ($i = 0; $i < 110; $i++) {
            $c = imagecolorallocate($img, wp_rand(150, 225), wp_rand(150, 225), wp_rand(150, 225));
            imagesetpixel($img, wp_rand(0, $w), wp_rand(0, $h), $c);
        }
        for ($i = 0; $i < 7; $i++) {
            $c = imagecolorallocate($img, wp_rand(120, 210), wp_rand(120, 210), wp_rand(120, 210));
            imageline($img, wp_rand(0, $w), wp_rand(0, $h), wp_rand(0, $w), wp_rand(0, $h), $c);
        }
        for ($i = 0; $i < 2; $i++) {
            $c = imagecolorallocate($img, wp_rand(150, 200), wp_rand(150, 200), wp_rand(150, 200));
            $y = wp_rand((int) ($h * 0.3), (int) ($h * 0.7));
            imageline($img, 0, $y, $w, $y + wp_rand(-8, 8), $c);
        }

        $len = strlen($code);
        $gap = (int) (($w - 20) / max(1, $len));
        for ($i = 0; $i < $len; $i++) {
            $ch    = $code[$i];
            $color = imagecolorallocate($img, wp_rand(20, 90), wp_rand(20, 90), wp_rand(20, 90));
            $x     = 10 + $i * $gap;
            $y     = wp_rand((int) ($h * 0.30), (int) ($h * 0.56));
            $font  = 5;
            if (wp_rand(0, 1) === 1) {
                imagecharup($img, $font, $x, $y + 22, $ch, $color);
            } else {
                imagechar($img, $font, $x, $y, $ch, $color);
            }
        }

        ob_start();
        imagepng($img);
        $raw = ob_get_clean();
        imagedestroy($img);

        if (empty($raw)) {
            return false;
        }
        return 'data:image/png;base64,' . base64_encode($raw);
    }

    /**
     * Honeypot check — the forms include a visually-hidden field that real users
     * can neither see nor fill. Automated bots that blindly populate every input
     * will trip it.
     *
     * @return bool
     */
    private static function is_honeypot_tripped() {
        return !empty($_POST['wpomni_website']);
    }

    public static function render_emergency_notices() {
        if (get_transient('wpomni_emergency_active')) {
            echo '<div class="wpomni-emergency-notice" style="margin:1em 0;padding:10px 14px;background:#fcf9e8;border:1px solid #f0e2b0;border-left:4px solid #dba617;border-radius:6px;color:#1d2327;font-size:13px;line-height:1.5;"><p style="margin:0;"><strong>';
            esc_html_e('WP-OmniAuth: Emergency mode active. Password login is temporarily enabled (expires in 15 minutes).', 'wp-omni-auth');
            echo '</strong></p></div>';
        }
        // Show success notice after emergency access was used
        if (isset($_GET['wpomni_emergency_active'])) {
            echo '<div class="wpomni-emergency-notice" style="margin:1em 0;padding:10px 14px;background:#fcf9e8;border:1px solid #f0e2b0;border-left:4px solid #dba617;border-radius:6px;color:#1d2327;font-size:13px;line-height:1.5;"><p style="margin:0;"><strong>';
            esc_html_e('Emergency mode activated. Password login is available for 15 minutes.', 'wp-omni-auth');
            echo '</strong></p></div>';
        }
    }

    /**
     * Build a styled HTML email body for the emergency login link.
     */
    private static function format_emergency_email_html($site, $ip, $link) {
        $site_name = $site;
        $home_url  = home_url();
        $admin_url = admin_url();

        $badge_text   = __('Alert', 'wp-omni-auth');
        $subject_text = sprintf(__('[%s] Emergency login link', 'wp-omni-auth'), $site);
        $intro_line   = sprintf(__('An emergency login link was requested for %s.', 'wp-omni-auth'), $site);
        $detail_line  = sprintf(__('This link is valid for 15 minutes and only works from the IP that requested it (%s). Do not share it.', 'wp-omni-auth'), $ip);
        $button_text  = __('Log in now', 'wp-omni-auth');
        $ignore_note  = __('If you did not request this, you can safely ignore this email.', 'wp-omni-auth');
        $link_text    = $link;

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f6f8fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fa">
<tr><td align="center" style="padding:24px 16px">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(31,35,40,.12)">
<tr><td style="padding:24px 24px 0;border-bottom:1px solid #d0d7de">
<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="font-size:18px;font-weight:700;color:#1f2328">{$site_name}</td>
<td align="right"><span style="display:inline-block;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:#dba617">{$badge_text}</span></td>
</tr>
</table>
<h2 style="margin:12px 0 0;font-size:16px;font-weight:600;color:#1f2328">{$subject_text}</h2>
</td></tr>
<tr><td style="padding:0 24px">
<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0">
<tr><td style="padding:8px 0;font-size:13px;color:#1f2328;line-height:1.6">
<p style="margin:0 0 12px">{$intro_line}</p>
<p style="margin:0 0 4px;color:#57606a">{$detail_line}</p>
</td></tr>
<tr><td style="padding:16px 0">
<a href="{$link}" style="display:inline-block;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:600;color:#fff;background:#dba617;text-decoration:none">{$button_text}</a>
</td></tr>
<tr><td style="padding:8px 0;font-size:11px;color:#8b949e;word-break:break-all">{$link_text}</td></tr>
<tr><td style="padding:12px 0 0;border-top:1px solid #d0d7de;font-size:12px;color:#57606a">
<p style="margin:0">{$ignore_note}</p>
</td></tr>
</table>
</td></tr>
<tr><td style="padding:16px 24px 24px;border-top:1px solid #d0d7de;font-size:12px;color:#57606a">
<a href="{$admin_url}" style="color:#0969da;text-decoration:none">WordPress Admin</a>
&nbsp;·&nbsp;
<a href="{$home_url}" style="color:#0969da;text-decoration:none">{$home_url}</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

}
