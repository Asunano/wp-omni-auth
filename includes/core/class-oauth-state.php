<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth state management — CSRF protection via single-use nonces.
 *
 * Extracted from WPOmniAuth_Manager so the logic is independently testable and
 * the Manager can delegate to it. Every public method here mirrors the
 * corresponding WPOmniAuth_Manager method exactly (same signatures), so the
 * Manager keeps a thin delegation layer and no caller/behavior changes.
 */
class WPOmniAuth_OAuth_State {

    /**
     * Create an OAuth state nonce for CSRF protection.
     *
     * Stores a server-side transient keyed by a hash of the nonce, associated
     * with the provider slug and a 10-minute expiry. The transient is deleted
     * on successful verification, so it cannot be replayed. This prevents OAuth
     * login-CSRF where an attacker tricks a victim into completing an attacker-
     * initiated flow.
     *
     * @param string $slug Provider slug.
     * @return string Random state nonce.
     */
    public static function create($slug) {
        $nonce = bin2hex(random_bytes(16));
        $store_key = 'wpomni_oauth_state_' . hash('sha256', $nonce);
        set_transient($store_key, [
            'slug' => $slug,
            'exp'  => time() + 600,
        ], 600);
        return $nonce;
    }

    /**
     * Verify an OAuth state value returned by the provider.
     *
     * Looks up the server-side transient, ensures it exists (i.e. was issued
     * by us), is not expired, and matches the expected provider slug. The
     * transient is deleted on first verification so the state is single-use.
     *
     * @param string $state State nonce returned by the provider.
     * @param string $slug  Provider slug.
     * @return bool
     */
    public static function verify($state, $slug) {
        $state = (string) $state;
        if ($state === '') {
            WPOmniAuth_Logger::debug_log('OAuth_State ERROR: Empty state', null, 'OAuthState');
            return false;
        }

        $store_key = 'wpomni_oauth_state_' . hash('sha256', $state);
        $stored = get_transient($store_key);
        if (!is_array($stored)) {
            WPOmniAuth_Logger::debug_log('OAuth_State ERROR: State not found or already consumed', null, 'OAuthState');
            return false;
        }

        // Single-use: delete immediately to prevent replay.
        delete_transient($store_key);

        if (($stored['exp'] ?? 0) < time()) {
            WPOmniAuth_Logger::debug_log(
                'OAuth_State ERROR: State expired',
                ['exp' => $stored['exp'] ?? 0, 'now' => time()],
                'OAuthState'
            );
            return false;
        }

        if (($stored['slug'] ?? '') !== $slug) {
            WPOmniAuth_Logger::debug_log(
                'OAuth_State ERROR: State slug mismatch',
                ['expected' => $slug, 'stored' => $stored['slug'] ?? ''],
                'OAuthState'
            );
            return false;
        }

        return true;
    }
}
