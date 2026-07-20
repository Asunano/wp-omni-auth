=== WP-OmniAuth ===
Contributors: drxian
Tags: oauth, login, authentication, github, google, openid, sso, wechat, qq, microsoft, apple
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unified OAuth 2.0 login for WordPress — 11 built-in providers, custom OAuth, security layer, self-updates via native WP update system.

== Description ==

WP-OmniAuth adds OAuth 2.0 authentication to your WordPress site, letting users sign in with their existing accounts from popular platforms. It integrates directly into the WordPress native plugin update system — updates appear on the standard **Plugins → Installed Plugins** page, just like plugins from the official directory.

**Features:**

* **11 Built-in Providers:** Apple, Authentik, DingTalk, Feishu (Lark), Gitee, GitHub, Google, Microsoft, QQ, WeChat, Weibo
* **Custom Providers:** Add unlimited OAuth 2.0 providers via the admin interface (no coding required)
* **OAuth-Only Mode:** Hide the password login form and show only OAuth buttons
* **Security & Rate Limiting:** Per-IP, per-provider, and per-identity throttling; configurable auto-ban; IP blacklist with CIDR support; trusted proxy IP allowlist to prevent header spoofing
* **Login History:** Persistent login log with dashboard stats (daily/weekly success and failure tracking)
* **Emergency Access Backdoor:** Time-limited, cryptographically signed bypass for OAuth-only setups
* **Self-Updating:** Hooks into WordPress's native `pre_set_site_transient_update_plugins` — update notifications appear on the standard plugins page. Version data served via `version.json` on GitHub (no API token required). Optional mirror source (`gh-proxy.org`) for mainland China
* **Debug Logging:** Built-in log viewer with automatic secret and token redaction
* **Secure by Default:** State-parameter CSRF protection, replay-attack prevention, HTTPS enforcement for OAuth endpoints, open-redirect protection, all admin AJAX handlers require `manage_options` capability
* **Connection Test & Bind:** Admins can test provider configuration and bind their account live, without opening email-based matching
* **Administrator Binding:** Each provider can be bound to an admin account by its stable OAuth ID; the binding survives email changes on the provider side
* **Event Notification:** Email notifications + HMAC-signed webhooks for login events

**Supported OAuth Flow:**

* Authorization Code Grant (OAuth 2.0)
* Works with any OAuth 2.0 compliant provider

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/wp-omni-auth/`, or install through **Plugins → Add New** in the WordPress admin.
2. Activate the plugin.
3. Go to **Settings → WP-OmniAuth** and configure your OAuth providers.

Detailed configuration guides for each provider can be found at:
👉 [WP-OmniAuth Configuration Guide](https://blog.drxian.cn/archives/1465)

== Frequently Asked Questions ==

= How do updates work? =

WP-OmniAuth uses the **WordPress native plugin update system**. When a new version is released, an update notification appears on the **Plugins → Installed Plugins** page, just like any plugin from the official directory. The plugin fetches version metadata from a `version.json` file hosted on GitHub — no API key required. Sites in mainland China can enable the built-in mirror (`gh-proxy.org`) to route update checks and downloads through a local proxy.

= How do I configure OAuth providers? =

Detailed configuration guides for all 11 built-in providers and custom providers are available at:
[https://blog.drxian.cn/archives/1465](https://blog.drxian.cn/archives/1465)

The callback URL format for all providers is:
`https://yoursite.com/wp-login.php?wpomni_callback={slug}`

= Can I add custom OAuth providers? =

Yes. The admin interface supports unlimited custom OAuth 2.0 providers. You can configure any OAuth 2.0 compliant provider — including self-hosted solutions like Keycloak/Authentik or commercial platforms like Auth0 — without writing any code.

= What happens to existing users? =

Existing users can be linked via OAuth binding in two ways:
1. **Admin "Test Connection & Bind"** — a logged-in admin can bind their WordPress account to a provider using its stable OAuth ID (recommended, as it survives email changes).
2. **Email fallback** — if the OAuth provider returns an email address matching an existing user, they are logged into that account automatically.

New users can be automatically registered when the corresponding option is enabled.

= Is this plugin secure? =

Yes. The plugin implements industry-standard OAuth 2.0 security practices:
* **State parameter CSRF protection** with SHA-256 tokens, 10-minute expiry, and single-use consumption
* **Replay-attack prevention** via server-side lock and used-code tracking
* **HTTPS enforcement** on all OAuth endpoints
* **Secrets and tokens never written to logs** — automatically redacted by the logging layer
* **Open redirect protection** via `wp_validate_redirect`
* **IP-based security** — blacklist (CIDR), rate limiting (per-IP / per-provider / per-identity), auto-ban, and trusted proxy IP allowlist
* **Emergency backdoor** — time-limited, IP-bound, cryptographically signed access for OAuth-only setups
* **All admin AJAX endpoints** require `manage_options` capability and are verified with nonces

== Screenshots ==

1. Login page with OAuth provider buttons
2. Settings dashboard with login statistics
3. Provider configuration detail view

== Changelog ==

= 0.1.0 =
* Initial release
* 11 built-in OAuth providers: Apple, Authentik, DingTalk, Feishu, Gitee, GitHub, Google, Microsoft, QQ, WeChat, Weibo
* Unlimited custom OAuth providers via admin UI
* OAuth-only mode (disable password login)
* IP blacklist, rate limiting (per-IP, per-provider, per-identity), auto-ban
* Login history dashboard with daily/weekly stats
* Native WordPress update integration (pre_set_site_transient_update_plugins)
* Optional GitHub mirror source (gh-proxy.org) for mainland China
* Debug logging with automatic secret and token redaction
* Emergency access backdoor
* Provider-specific "test connection & bind" feature
* Trusted proxy IP allowlist (SSRF and IP-spoofing mitigation)
* Event notification system (email + webhook)
* Modular provider system — add new providers by dropping a single file
* Chinese (zh_CN) translation

== Disclaimer ==

The OAuth provider icons displayed in this plugin are used for identification purposes only and do not imply any affiliation, endorsement, or partnership with the respective brands. Some icons were created by the author based on publicly available materials and may differ from the official icons. All trademarks and brand names are the property of their respective owners.

If you believe any icon infringes upon your legal rights, contains inaccuracies, misleads users, or negatively impacts brand reputation, please contact us. We will review and address the matter promptly.

You are welcome to submit accurate official icon resources via our setup guide.
