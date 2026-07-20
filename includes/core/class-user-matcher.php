<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * User matching logic for OAuth login — multi-pass lookup by provider-specific
 * binding meta, legacy provider+ID meta, stored OAuth email, and WP email.
 *
 * Extracted from WPOmniAuth_Manager so the logic is independently testable and
 * the Manager can delegate to it.
 */
class WPOmniAuth_User_Matcher {

    /**
     * Multi-pass user lookup by OAuth identity.
     *
     * Pass 0: per-provider binding meta (wpomni_{slug}_id).
     * Pass 1: legacy OAuth provider + ID meta (wpomni_provider + wpomni_id).
     * Pass 2: stored OAuth email meta (wpomni_email).
     * Pass 3: WP account email fallback.
     *
     * @param string $slug     Provider slug.
     * @param string $oauth_id OAuth provider's user ID.
     * @param string $email    Email address from the provider.
     * @return WP_User|null
     */
    public static function find($slug, $oauth_id, $email) {
        // Pass 0: per-provider binding meta.
        if (!empty($oauth_id)) {
            $per_provider = new WP_User_Query([
                'meta_query' => [
                    [
                        'key'   => 'wpomni_' . $slug . '_id',
                        'value' => $oauth_id,
                    ],
                ],
                'fields' => 'ID',
                'number' => 1,
            ]);

            $pp_results = $per_provider->get_results();
            if (!empty($pp_results)) {
                $user_id = is_array($pp_results) ? $pp_results[0] : $pp_results;
                self::log('Found user by per-provider binding', ['user_id' => $user_id, 'provider' => $slug, 'oauth_id' => $oauth_id]);
                return get_user_by('id', $user_id);
            }
        }

        // Pass 1: legacy OAuth provider + ID meta.
        if (!empty($oauth_id)) {
            $user_query = new WP_User_Query([
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'   => 'wpomni_provider',
                        'value' => $slug,
                    ],
                    [
                        'key'   => 'wpomni_id',
                        'value' => $oauth_id,
                    ],
                ],
                'fields' => 'ID',
                'number' => 1,
            ]);

            $results = $user_query->get_results();
            if (!empty($results)) {
                $user_id = is_array($results) ? $results[0] : $results;
                self::log('Found user by OAuth provider + ID', ['user_id' => $user_id, 'oauth_id' => $oauth_id]);
                return get_user_by('id', $user_id);
            }
        }

        // Pass 2: stored OAuth email meta (wpomni_email).
        if (!empty($email)) {
            $email_query = new WP_User_Query([
                'meta_query' => [
                    [
                        'key'   => 'wpomni_email',
                        'value' => $email,
                    ],
                ],
                'fields' => 'ID',
                'number' => 1,
            ]);

            $email_results = $email_query->get_results();
            if (!empty($email_results)) {
                $uid = is_array($email_results) ? $email_results[0] : $email_results;
                self::log('Found user by OAuth email (wpomni_email)', ['user_id' => $uid, 'email' => $email]);
                return get_user_by('id', $uid);
            }
        }

        // Pass 3: WP account email fallback.
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                self::log('Found user by WP email', ['user_id' => $user->ID, 'email' => $email]);
                return $user;
            }
        }

        return null;
    }

    /**
     * Log a debug message via the plugin's logger.
     */
    private static function log($message, $data = null) {
        WPOmniAuth_Logger::debug_log($message, $data, 'UserMatcher');
    }
}
