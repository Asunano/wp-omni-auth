<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version-controlled auto-updater for WP-OmniAuth.
 *
 * Instead of hitting the GitHub Releases *API* (rate-limited to 60 requests/hour
 * unauthenticated), the plugin reads a static version.json hosted in the
 * repository and served via raw.githubusercontent.com:
 *
 *     https://raw.githubusercontent.com/<repo>/<branch>/version.json
 *
 * version.json is generated automatically by .github/workflows/release.yml on
 * each release, so it always reflects the latest published version. The plugin
 * only needs the version + a direct download URL — no API token required.
 *
 * Hooks into WordPress's native plugin update system:
 * - pre_set_site_transient_update_plugins: inject update availability
 * - plugins_api: provide "View Details" info
 *
 * Caches the parsed version.json for 12 hours to avoid repeated fetches.
 */
class WPOmniAuth_GitHub_Updater {

    private $slug;
    private $basename;
    private $version;
    private $repo;
    private $branch;
    private $cache_key = 'wpomni_version_json';
    private $cache_ttl = 43200; // 12 hours

    public function __construct() {
        $this->slug     = 'wp-omni-auth';
        $this->basename = plugin_basename(WPOMNIAUTH_PLUGIN_DIR . 'wp-omni-auth.php');
        $this->version  = WPOMNIAUTH_VERSION;
        $this->repo     = apply_filters('wpomni_update_repo', 'Asunano/wp-omni-auth');
        $this->branch   = apply_filters('wpomni_update_branch', 'main');

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update'], 10, 1);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    // ================================================================
    // Core: Fetch version.json (cached)
    // ================================================================

    /**
     * Fetch & parse version.json from raw.githubusercontent.com (cached).
     *
     * @return object|null Normalized {version, download_url, details_url,
     *                     requires, tested, requires_php, changelog} or null
     *                     on any failure (caller should treat as "no update").
     */
    public function get_remote_version() {
        // Check cache
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/version.json',
            $this->repo,
            $this->branch
        );
        // Allow overriding the full URL (e.g. a custom CDN or branch).
        $url = apply_filters('wpomni_version_json_url', $url, $this->repo, $this->branch);

        // When the mirror source is enabled, route the fetch through gh-proxy
        // so update checks work from mainland China.
        $url = $this->mirror_url($url);

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'WP-OmniAuth/' . $this->version,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log('version.json fetch error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log('version.json returned HTTP ' . $code);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) || empty($data->version) || empty($data->download_url)) {
            $this->log('version.json invalid (missing version or download_url)');
            return null;
        }

        $result = (object) [
            'version'      => (string) $data->version,
            'download_url' => (string) $data->download_url,
            'details_url'  => isset($data->details_url) ? (string) $data->details_url : '',
            'requires'     => isset($data->requires) ? (string) $data->requires : '6.0',
            'tested'       => isset($data->tested) ? (string) $data->tested : '',
            'requires_php' => isset($data->requires_php) ? (string) $data->requires_php : '7.4',
            'changelog'    => isset($data->changelog) ? (string) $data->changelog : '',
        ];

        set_transient($this->cache_key, $result, $this->cache_ttl);

        return $result;
    }

    /**
     * Clear the version.json cache (forces a fresh fetch).
     */
    public static function clear_cache() {
        delete_transient('wpomni_version_json');
    }

    // ================================================================
    // WordPress Update System Integration
    // ================================================================

    /**
     * Inject update data into WordPress's plugin update transient.
     */
    public function check_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            $transient = new stdClass;
        }
        if (!isset($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update)) {
            $transient->no_update = [];
        }

        $remote = $this->get_remote_version();

        if ($remote === null) {
            return $transient;
        }

        $plugin_data = (object) [
            'slug'         => $this->slug,
            'new_version'  => $remote->version,
            'url'          => $this->mirror_url($remote->details_url),
            'package'      => $this->mirror_url($remote->download_url),
            'tested'       => $remote->tested ?: get_bloginfo('version'),
            'requires'     => $remote->requires,
            'requires_php' => $remote->requires_php,
            'icons'        => [],
            'banners'      => [],
        ];

        if (version_compare($remote->version, $this->version, '>')) {
            $transient->response[$this->basename] = $plugin_data;
            // Remove from no_update if present
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $plugin_data;
            // Remove from response if present
            unset($transient->response[$this->basename]);
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" thickbox.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $remote = $this->get_remote_version();

        if ($remote === null) {
            return $result;
        }

        return (object) [
            'name'           => 'WP-OmniAuth',
            'slug'           => $this->slug,
            'plugin_name'    => 'WP-OmniAuth',
            'version'        => $remote->version,
            'author'         => 'Asunano',
            'homepage'       => $this->mirror_url($remote->details_url ?: "https://github.com/{$this->repo}"),
            'requires'       => $remote->requires,
            'tested'         => $remote->tested ?: get_bloginfo('version'),
            'requires_php'   => $remote->requires_php,
            'last_updated'   => '',
            'sections'       => [
                'description' => __('Unified OAuth 2.0 login for WordPress with 12 built-in providers (GitHub, Google, Apple, Discord, Facebook, GitLab, LinkedIn, Microsoft, QQ, WeChat, WeCom, Weibo), unlimited custom OAuth providers, a security/rate-limit layer, login history, and self-updates via the native WordPress update system. Supports OAuth-only mode, emergency access, debug logging, and trusted proxy IP allowlisting.', 'wp-omni-auth'),
                'changelog'   => $this->format_changelog($remote->changelog),
            ],
            'download_link'  => $this->mirror_url($remote->download_url),
            'external'       => true,
        ];
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * Prepend the gh-proxy mirror to a URL when the "mirror source" option is on.
     *
     * Used for both fetching version.json and downloading the update package so
     * that sites in mainland China can reach GitHub through the proxy.
     *
     * @param string $url Target URL (may be empty).
     * @return string Mirrored URL, or the original when mirroring is disabled.
     */
    private function mirror_url($url) {
        if (empty($url)) {
            return $url;
        }
        if (get_option('wpomni_use_mirror', 'no') !== 'yes') {
            return $url;
        }
        $proxy = 'https://v4.gh-proxy.org/';
        if (strpos($url, $proxy) === 0) {
            return $url;
        }
        return $proxy . $url;
    }


    /**
     * Convert basic markdown (headers, lists, links) to HTML for the changelog section.
     */
    private function format_changelog($body) {
        if (empty($body)) {
            return '';
        }

        // Headers
        $body = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $body);
        $body = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $body);

        // Bold
        $body = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body);

        // Links
        $body = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $body);

        // Lists
        $body = preg_replace('/^- (.+)$/m', '<li>$1</li>', $body);
        $body = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $body);

        return wpautop($body);
    }

    private function log($message) {
        if (get_option('wpomni_debug_mode', 'no') === 'yes') {
            $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
            $time = current_time('mysql');
            @file_put_contents($log_file, "[{$time}] Updater: {$message}\n", FILE_APPEND);
        }
    }
}

new WPOmniAuth_GitHub_Updater();
