<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security utilities for WP-OmniAuth.
 *
 * Extracted from WPOmniAuth_Manager so the logic is independently testable and
 * the Manager can delegate to it. Every public method here mirrors the
 * corresponding WPOmniAuth_Manager method exactly (same signatures), so the
 * Manager keeps a thin delegation layer and no caller/behavior changes.
 */
class WPOmniAuth_Security {

    /**
     * Get client IP address.
     *
     * When "trusted proxy" mode is disabled (default), only REMOTE_ADDR is
     * used — this is safe for sites not behind a CDN/reverse proxy, because
     * REMOTE_ADDR cannot be spoofed by the client.
     *
     * When enabled, the client IP is taken from a configurable source so the
     * plugin can adapt to different CDNs (Cloudflare, EdgeOne, ESA, generic
     * reverse proxies) which expose the real client IP via different headers
     * or different positions within X-Forwarded-For. See `wpomni_client_ip_source`.
     */
    public static function get_client_ip() {
        $trusted_proxy = get_option('wpomni_trusted_proxy', 'no');
        if ($trusted_proxy !== 'yes') {
            return self::sanitize_ip($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }

        // When a trusted-proxy IP allowlist is configured, only trust proxy
        // headers when the direct connection actually originates from one of
        // those proxy addresses. Without this, any client could spoof
        // X-Forwarded-For / X-Real-IP / CF-Connecting-IP to impersonate an
        // arbitrary (non-blacklisted) IP and bypass IP-based rate limiting or
        // bans. An empty allowlist preserves the old behaviour (trust headers).
        $allowlist = get_option('wpomni_trusted_proxy_ips', []);
        if (!empty($allowlist) && !self::ip_in_allowlist($_SERVER['REMOTE_ADDR'] ?? '', $allowlist)) {
            return self::sanitize_ip($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }

        $source = get_option('wpomni_client_ip_source', 'auto');
        $ip     = '';

        switch ($source) {
            case 'cloudflare':
                // Cloudflare overwrites this header with the real client IP.
                $ip = self::header_value('CF-Connecting-IP');
                break;
            case 'edgeone':
                // EdgeOne exposes the real client IP via EO-Connecting-IP.
                $ip = self::header_value('EO-Connecting-IP');
                break;
            case 'esa':
                // ESA / generic CDN: prefer the last X-Forwarded-For hop, then X-Real-IP.
                $ip = self::header_value('X-Forwarded-For', 'last') ?: self::header_value('X-Real-IP');
                break;
            case 'xfwd_first':
                // Legacy/single-proxy setups where the proxy sets XFF to the client only.
                $ip = self::header_value('X-Forwarded-For', 'first');
                break;
            case 'xfwd_last':
                // Behind chained proxies/CDNs that append — the rightmost IP is
                // the one the trusted edge added from its TCP peer (harder to spoof).
                $ip = self::header_value('X-Forwarded-For', 'last');
                break;
            case 'xrealip':
                $ip = self::header_value('X-Real-IP');
                break;
            case 'custom':
                // Fully user-defined header + position. Different CDNs (EdgeOne,
                // Cloudflare, ESA, …) populate X-Forwarded-For inconsistently —
                // some append the client IP at the left (first), some at the
                // right (last) — so let the admin pick both the header name and
                // which segment to trust. Defaults to the last segment for back
                // compatibility.
                $custom   = get_option('wpomni_client_ip_custom_header', '');
                $position = get_option('wpomni_client_ip_custom_position', 'last');
                if ($custom !== '') {
                    $ip = self::header_value($custom, $position === 'first' ? 'first' : 'last');
                }
                break;
            case 'auto':
            default:
                // Back-compat priority chain (original behaviour).
                $ip = self::header_value('CF-Connecting-IP')
                    ?: self::header_value('X-Forwarded-For', 'first')
                    ?: self::header_value('X-Real-IP');
                break;
        }

        $ip = self::sanitize_ip($ip);
        if ($ip === '') {
            // Fall back to the direct connection when the configured header is
            // missing or malformed, so logging/rate-limiting never breaks.
            $ip = self::sanitize_ip($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }
        return $ip;
    }

    /**
     * Read a request header value, optionally picking a segment from a
     * comma-separated chain such as X-Forwarded-For.
     *
     * @param string $name     Header name, e.g. 'X-Forwarded-For'.
     * @param string $position 'first' or 'last' (used for chained headers).
     * @return string
     */
    private static function header_value($name, $position = 'first') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (empty($_SERVER[$key])) {
            return '';
        }
        $raw = (string) $_SERVER[$key];

        if (strpos($raw, ',') !== false) {
            $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
            if (empty($parts)) {
                return '';
            }
            $val = ($position === 'last') ? end($parts) : reset($parts);
        } else {
            $val = $raw;
        }

        return $val;
    }

    /**
     * Validate and normalize an IP address. Returns '' if invalid so callers
     * can fall back to a trusted source.
     *
     * @param string $ip
     * @return string
     */
    private static function sanitize_ip($ip) {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }
        // Drop an appended port (e.g. from a misconfigured X-Real-IP).
        if (substr_count($ip, ':') === 1 && strpos($ip, '[') === false) {
            $ip = explode(':', $ip)[0];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Check if an IP is blacklisted.
     * Supports exact IP match and CIDR range match.
     */
    public static function is_ip_blacklisted($ip) {
        $blacklist = get_option('wpomni_blacklisted_ips', []);
        if (empty($blacklist) || !is_array($blacklist)) {
            return false;
        }

        $now = time();
        $changed = false;
        foreach ($blacklist as $i => $entry) {
            $blocked_ip = $entry['ip'] ?? '';
            if (empty($blocked_ip)) {
                continue;
            }
            // Time-limited bans: drop expired entries (manual bans without an
            // `expired_at` key remain permanent).
            if (!empty($entry['expired_at']) && strtotime($entry['expired_at']) < $now) {
                unset($blacklist[$i]);
                $changed = true;
                continue;
            }
            // Exact match
            if ($blocked_ip === $ip) {
                return true;
            }
            // CIDR match
            if (strpos($blocked_ip, '/') !== false && self::ip_in_cidr($ip, $blocked_ip)) {
                return true;
            }
        }

        if ($changed) {
            update_option('wpomni_blacklisted_ips', array_values($blacklist));
        }

        return false;
    }

    /**
     * Check if an IP falls within a CIDR range (IPv4 only for simplicity).
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
     * Whether a direct-connection IP matches the trusted-proxy allowlist
     * (exact IP or CIDR, IPv4).
     *
     * @param string $ip
     * @param array  $allowlist
     * @return bool
     */
    private static function ip_in_allowlist($ip, $allowlist) {
        if (empty($ip) || !is_array($allowlist)) {
            return false;
        }
        $ip = self::sanitize_ip($ip);
        if ($ip === '') {
            return false;
        }
        foreach ($allowlist as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                if (self::ip_in_cidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === self::sanitize_ip($entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check rate limit for an IP.
     * Returns true if rate limit exceeded.
     */
    public static function check_rate_limit($ip) {
        $ip_hash = hash('sha256', $ip);

        // Per-IP rate limit
        $per_ip_limit = (int) get_option('wpomni_rate_limit_per_ip', 10);
        if ($per_ip_limit > 0) {
            $key = 'wpomni_rate_' . $ip_hash;
            $count = (int) get_transient($key);
            if ($count >= $per_ip_limit) {
                return true;
            }
        }

        // Global rate limit
        $global_limit = (int) get_option('wpomni_rate_limit_global', 60);
        if ($global_limit > 0) {
            $count = (int) get_transient('wpomni_rate_global');
            if ($count >= $global_limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Increment rate limit counters for an IP.
     */
    public static function increment_rate_limit($ip) {
        $ip_hash = hash('sha256', $ip);

        // Per-IP counter
        $key = 'wpomni_rate_' . $ip_hash;
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 60);

        // Global counter
        $global = (int) get_transient('wpomni_rate_global');
        set_transient('wpomni_rate_global', $global + 1, 60);
    }

    /**
     * Check per-provider rate limit.
     * Protects a single OAuth app from being overwhelmed by too many
     * authorization requests (e.g. a user/bot hammering the login button).
     * Returns true if exceeded.
     */
    public static function check_rate_limit_per_provider($slug) {
        $limit = (int) get_option('wpomni_rate_limit_per_provider', 30);
        if ($limit <= 0) {
            return false;
        }
        $key = 'wpomni_rate_provider_' . hash('sha256', $slug);
        return (int) get_transient($key) >= $limit;
    }

    /**
     * Increment per-provider rate limit counter.
     */
    public static function increment_rate_limit_per_provider($slug) {
        $key = 'wpomni_rate_provider_' . hash('sha256', $slug);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 60);
    }

    /**
     * Check per-identity rate limit (by OAuth email / id).
     *
     * Throttle-only: caps how often a *single* OAuth identity may attempt login
     * within a short window. This blunts a malicious/looping account without
     * permanently locking out a legitimate user (no auto-ban here — that is
     * reserved for IP-level abuse). Returns true if exceeded.
     *
     * @param string $identity Email or OAuth id (already known only after the
     *                         provider returns the profile, so this guards the
     *                         callback step, not login initiation).
     */
    public static function check_rate_limit_per_identity($identity) {
        $identity = trim((string) $identity);
        if ($identity === '') {
            return false;
        }
        $limit = (int) get_option('wpomni_rate_limit_per_identity', 10);
        if ($limit <= 0) {
            return false;
        }
        $key = 'wpomni_rate_identity_' . hash('sha256', $identity);
        return (int) get_transient($key) >= $limit;
    }

    /**
     * Increment per-identity rate limit counter (short 60s window).
     *
     * @param string $identity Email or OAuth id.
     */
    public static function increment_rate_limit_per_identity($identity) {
        $identity = trim((string) $identity);
        if ($identity === '') {
            return;
        }
        $key = 'wpomni_rate_identity_' . hash('sha256', $identity);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 60);
    }

    /**
     * Check if an IP should be auto-banned after too many failures.
     * Called after recording a failure login log entry.
     */
    public static function maybe_auto_ban($ip) {
        $threshold = (int) get_option('wpomni_auto_ban_threshold', 10);
        if ($threshold <= 0) {
            return;
        }

        // Already blacklisted? Skip.
        if (self::is_ip_blacklisted($ip)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wpomni_login_log';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
            return;
        }

        // Count recent failures from this IP
        $ban_window = (int) get_option('wpomni_auto_ban_window', 24); // hours
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE ip = %s AND status = 'failure' AND created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $ip,
            $ban_window
        ));

        if ($count >= $threshold) {
            $blacklist = get_option('wpomni_blacklisted_ips', []);
            if (!is_array($blacklist)) {
                $blacklist = [];
            }
            // Time-limited ban (default 24h) so a shared/NAT IP is NOT locked out
            // forever when one abuser on the same address trips the threshold.
            $duration_hours = (int) get_option('wpomni_auto_ban_duration', 24);
            if ($duration_hours < 1) {
                $duration_hours = 24;
            }
            $expires_ts = time() + ($duration_hours * HOUR_IN_SECONDS);
            $blacklist[] = [
                'ip'         => $ip,
                'reason'     => __('Auto-banned', 'wp-omni-auth'),
                'created_at' => current_time('mysql'),
                'expired_at' => gmdate('Y-m-d H:i:s', $expires_ts),
            ];
            update_option('wpomni_blacklisted_ips', $blacklist);
            self::log_debug('Auto-banned IP: ' . $ip . ' for ' . $duration_hours . 'h after ' . $count . ' failures');
        }
    }

    /**
     * Log a debug message (reuses the plugin's debug log).
     */
    private static function log_debug($message) {
        if (get_option('wpomni_debug_mode', 'no') !== 'yes') {
            return;
        }
        $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
        $time = current_time('mysql');
        $log_dir = dirname($log_file);
        if (is_writable($log_dir) || (is_dir($log_dir) && wp_mkdir_p($log_dir))) {
            file_put_contents($log_file, "[{$time}] {$message}\n", FILE_APPEND);
        }
    }
}
