# WP-OmniAuth Developer Guide

This guide covers the plugin architecture, naming conventions, and how to add new OAuth providers.

---

## Architecture Overview

WP-OmniAuth uses a provider-based architecture. Each OAuth provider (GitHub, Google, or any custom provider) is a self-contained class that handles the entire OAuth 2.0 flow for that service. The plugin core discovers, registers, and orchestrates providers automatically.

### Component Diagram

```
wp-omni-auth.php (entry point)
    |
    +-- includes/core/
    |   +-- class-oauth-provider.php     Abstract base class (contract)
    |   +-- class-oauth-manager.php      Orchestrator: hooks, OAuth flow, user matching,
    |   |                                settings section registry, provider auto-discovery
    |   +-- class-security.php           Extracted security utils (IP blacklist, rate limit, auto-ban)
    |   +-- class-login-guard.php        Global auth/emergency hooks (was inline closures)
    |   +-- class-emergency-access.php   Two-step emergency key backdoor (?wpomni_emergency=1)
    |   +-- class-event-dispatcher.php   Event/webhook dispatcher
    |   |
|   ├── includes/providers/
|   |   +-- class-*-provider.php         Built-in providers (auto-discovered; see "Built-in Providers")
|   |   +-- class-custom-provider.php    Dynamic provider (from DB config)
    |   |
    |   └── includes/admin/
    |       +-- class-settings-page.php     Admin UI: composes three traits
    |       +-- class-github-updater.php    GitHub Releases self-updater
    |       +-- traits/
    |           +-- class-settings-registration.php  Registration/sanitize/save
    |           +-- class-settings-views.php          Rendering methods
    |           +-- class-settings-ajax.php           ajax_* handlers
```

### Directory Structure

```
wp-omni-auth/
├── wp-omni-auth.php              # Main plugin file, constants, lazy-loading
├── uninstall.php                 # Complete cleanup on plugin deletion
├── readme.txt                    # WordPress.org readme
├── includes/
│   ├── core/
│   │   ├── class-oauth-provider.php   # Abstract base class all providers extend
│   │   ├── class-oauth-manager.php    # Core orchestrator (singleton); security methods delegate to Security
│   │   ├── class-security.php         # Extracted security utilities (IP blacklist/CIDR, rate limit, auto-ban, client IP)
│   │   ├── class-login-guard.php      # Global authenticate/emergency hooks (extracted from entry file)
│   │   ├── class-emergency-access.php # Two-step emergency access backdoor (email→code→key / direct key)
│   │   └── class-event-dispatcher.php # Event + webhook dispatcher
│   ├── providers/
│   │   ├── class-*-provider.php  # Built-in providers (auto-discovered; each class-{name}-provider.php is one provider)
│   │   └── class-custom-provider.php  # User-configured providers (from DB)
│   ├── views/
│   │   ├── oauth-login-screen.php  # OAuth-only login screen (front-end)
│   │   ├── callback-page.php       # OAuth callback result page (front-end)
│   │   └── emergency-page.php      # Emergency access UI (front-end)
│   └── admin/
│       ├── class-settings-page.php    # Admin settings (composes three traits; __construct/add_admin_menu/admin_scripts)
│       ├── class-github-updater.php   # GitHub Releases-based self-updater
│       └── traits/
│           ├── class-settings-registration.php  # Section/field registration, sanitize callbacks, save handlers
│           ├── class-settings-views.php          # Settings page + section/tab/card rendering
│           └── class-settings-ajax.php           # ajax_* handler methods
├── assets/
│   ├── css/login-styles.css      # Login page button styles (front-end + emergency page)
│   ├── css/admin-settings.css    # Admin settings page styles (extracted from inline admin_head)
│   └── js/admin-settings.js      # Admin page JS (add/remove providers, log viewer)
├── languages/
│   ├── wp-omni-auth.pot          # Translation template
│   ├── wp-omni-auth-zh_CN.po     # Chinese translation
│   └── wp-omni-auth-zh_CN.mo     # Compiled translation
└── docs/
    └── development.md            # This file
```

---

## Built-in Providers

The plugin ships with the following built-in providers (in `includes/providers/`, auto-discovered by `WPOmniAuth_Manager::init_providers()` — dropping in a new file is enough, no other code changes):

| Slug | Name | Notes |
|---|---|---|
| `github` | GitHub | Global developer mainstream |
| `google` | Google | Global, universal |
| `apple` | Apple | Required for many iOS apps |
| `microsoft` | Microsoft | Enterprise / Azure AD |
| `gitlab` | GitLab | Code hosting |
| `discord` | Discord | Gaming / community |
| `wechat` | WeChat | Essential for the China market |
| `facebook` | Facebook | International social mainstream |
| `linkedin` | LinkedIn | Workplace / B2B |
| `qq` | QQ | One of China's big three |
| `weibo` | Weibo | One of China's big three |
| `wecom` | WeCom (企业微信) | Enterprise scenarios |

> Enterprise OIDC / SSO providers (e.g. **Okta, Auth0, Keycloak, Authentik**) are intentionally NOT built-in — they can all be configured via the admin "Custom Provider" using standard OIDC endpoints, with no new code.

## Adding a New Built-in OAuth Provider

Adding a new built-in provider (e.g., Microsoft, Discord, GitLab) requires creating **one file** and following the naming convention. No other files need modification.

### Step 1: Create the Provider File

Create `includes/providers/class-{name}-provider.php` where `{name}` is the lowercase provider name (e.g., `microsoft`, `discord`, `gitlab`).

### Step 2: Implement the Class

The class must:
- Be named `WPOmniAuth_{Name}_Provider` (PascalCase of the filename)
- Extend `WPOmniAuth_Provider`
- Call `parent::__construct()` with slug, display name, and SVG icon
- Implement all 4 abstract methods
- Implement `get_settings_fields()` to declare its settings form
- (Recommended) Override `get_button_color()` to define the login button's brand color (see below)

### Defining the Login Button Brand Color

The login button color is **no longer hardcoded**. Earlier versions baked in special styles only for GitHub and Google, while every other built-in provider fell back to the admin theme blue. Now every provider returns its own brand color via `get_button_color()`, and a single CSS rule (`.wpomni-btn-brand`) paints the button — built-in and custom providers follow the exact same path.

- Override `get_button_color()` and return a hex color (e.g. `'#24292e'`); the button uses that brand color and the text color is auto-picked for the best black/white contrast.
- If you don't override it (returns `''`), the button falls back to the site's admin theme color.
- A few brands (e.g. Google: white background, dark text, light-grey border) need to override `get_button_text_color()` and `get_button_border_color()`; otherwise they are derived from the background.

```php
public function get_button_color() {
    return '#5865F2'; // Discord Blurple
}

// Optional: only override when the brand demands a specific text/border color
public function get_button_text_color() {
    return ''; // empty = auto-computed from the background
}

public function get_button_border_color() {
    return ''; // empty = same as the background
}
```

> Custom providers (added via the admin UI) take their button color from the "Button Color" setting (`wpomni_{slug}_color`). Their `get_button_color()` already reads that option automatically — no extra code needed.

### Complete Example: Microsoft Provider

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Microsoft_Provider extends WPOmniAuth_Provider {
    public function __construct() {
        parent::__construct(
            'microsoft',
            'Microsoft',
            '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>'
        );
    }

    /**
     * Declare settings fields for the admin form.
     * These appear automatically in the settings page.
     */
    public function get_settings_fields() {
        return [
            [
                'key'     => 'client_id',
                'label'   => __('Client ID', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => '',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'client_secret',
                'label'   => __('Client Secret', 'wp-omni-auth'),
                'type'    => 'password',
                'default' => '',
                'class'   => 'regular-text',
            ],
            [
                'key'     => 'tenant',
                'label'   => __('Tenant ID', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'common',
                'class'   => 'regular-text',
                'description' => __('Azure AD tenant ID, or "common" for multi-tenant.', 'wp-omni-auth'),
            ],
            [
                'key'     => 'scope',
                'label'   => __('Scope', 'wp-omni-auth'),
                'type'    => 'text',
                'default' => 'openid email profile',
                'class'   => 'regular-text',
            ],
        ];
    }

    public function get_authorization_url($state) {
        $tenant = $this->get_option('tenant', 'common');
        $params = [
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $this->get_redirect_uri(),
            'scope'         => $this->get_option('scope', 'openid email profile'),
            'response_type' => 'code',
            'state'         => $state,
        ];
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?"
            . http_build_query($params);
    }

    public function get_access_token($code) {
        $tenant = $this->get_option('tenant', 'common');
        $response = $this->remote_post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            [
                'body' => [
                    'client_id'     => $this->get_client_id(),
                    'client_secret' => $this->get_client_secret(),
                    'code'          => $code,
                    'redirect_uri'  => $this->get_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ],
            ]
        );

        if (empty($response) || isset($response['error'])) {
            $this->log('ERROR: Microsoft token error', [
                'error' => $response['error'] ?? 'empty response',
            ]);
            return null;
        }

        return $response['access_token'] ?? null;
    }

    public function get_user_data($access_token) {
        return $this->remote_get('https://graph.microsoft.com/v1.0/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);
    }

    public function get_email_from_user_data($user_data) {
        if (!is_array($user_data)) {
            return '';
        }
        return $user_data['mail'] ?? $user_data['userPrincipalName'] ?? '';
    }

    private function get_redirect_uri() {
        return add_query_arg('wpomni_callback', $this->slug, wp_login_url());
    }

    private function log($message, $data = null) {
        // Unified entry point: redacts access tokens / secrets automatically.
        WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
    }
}
```

That's it. Drop this file into `includes/providers/` and the plugin automatically:

- **Discovers & loads the class** — `WPOmniAuth_Manager::init_providers()` runs `glob('includes/providers/class-*-provider.php')` (skipping `class-custom-provider.php`), `require_once`s each file, and instantiates the class by converting the filename to the FQCN: `class-{name}-provider.php` → `WPOmniAuth_{Name}_Provider`. The `{name}` part drives the class name, so **the filename, class name, and slug must stay consistent** (e.g. `class-microsoft-provider.php` → `WPOmniAuth_Microsoft_Provider` → slug `microsoft`).
- **Registers it as an available provider** and renders its configuration UI on the **OAuth Providers** tab (a list view plus a per-provider detail view driven by `get_settings_fields()`).
- **Registers all its settings** with the WordPress Settings API via `register_provider_settings()`, which loops `get_settings_fields()` and picks the right sanitizer (`sanitize_secret` for `client_secret`, `sanitize_url` for `url`, checkbox handling for `toggle`).
- **Shows its login button** on the login page (when enabled) using the CSS class `wpomni-btn-{slug}`.

> No other files need to be touched. To remove a provider, delete its file (and any stored `wpomni_{slug}_*` options).

---

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Filename | `class-{name}-provider.php` | `class-microsoft-provider.php` |
| Class name | `WPOmniAuth_{Name}_Provider` | `WPOmniAuth_Microsoft_Provider` |
| Slug (constructor arg) | Lowercase, no spaces | `microsoft` |
| Option names | `wpomni_{slug}_{key}` | `wpomni_microsoft_client_id` |
| CSS class (login button) | `wpomni-btn-{slug}` | `wpomni-btn-microsoft` |
| Login button brand color | Override `get_button_color()` returning a hex color (empty = admin theme color) | `'#5e5e5e'` |
| Callback parameter | `wpomni_callback={slug}` | `wpomni_callback=microsoft` |
| Debug log tag | `[{Name}]` | `[Microsoft]` |

---

## Provider API Reference

### Abstract Methods (must implement)

#### `get_authorization_url($state): string`

Build the OAuth authorization URL. Redirect the user here to start the flow.

- `$state` — CSRF token generated by the Manager. Must be included as the `state` parameter.
- Return the full URL including `client_id`, `redirect_uri`, `scope`, `response_type=code`, and `state`.
- Use `$this->get_redirect_uri()` for the redirect URI (or build your own using `add_query_arg('wpomni_callback', $this->slug, wp_login_url())`).

#### `get_access_token($code): ?string`

Exchange the authorization code for an access token.

- `$code` — The authorization code from the callback.
- Use `$this->remote_post()` for the HTTP request.
- Return the access token string, or `null` on failure.

#### `get_user_data($access_token): ?array`

Fetch user profile data using the access token.

- `$access_token` — Valid OAuth access token.
- Use `$this->remote_get()` for the HTTP request.
- Return the decoded JSON response as an associative array, or `null` on failure.
- If the provider returns the access token in the user data response, store it as `$data['_access_token']` so `get_email_from_user_data()` can use it for secondary API calls.

#### `get_email_from_user_data($user_data): string`

Extract the user's email address from the user data response.

- `$user_data` — The array returned by `get_user_data()`, with `_access_token` appended.
- Return the email string. Return empty string if not found.
- This is where provider-specific email extraction logic lives (e.g., GitHub's separate emails API).

#### `get_button_color(): string`

Returns the login button's brand background color (hex, e.g. `'#24292e'`). Returning an empty string `''` makes the button use the site's admin theme color. Built-in providers should override this with their brand color; the custom provider implementation already reads the "Button Color" setting automatically, so it usually needs no override.

#### `get_button_text_color(): string`

Optional explicit text color (hex). Returning an empty string lets the Manager auto-pick black/white based on background luminance. Override only when the brand demands a specific text color (e.g. Google's dark grey `#3c4043`).

#### `get_button_border_color(): string`

Optional explicit border color (hex). Returning an empty string reuses the background color as the border. Override only when the brand button needs a distinct border (e.g. Google's light grey `#dadce0`).

### Configuration Method

#### `get_settings_fields(): array`

Return an array of field definitions for the admin settings form. Each field is an associative array:

```php
[
    'key'         => 'field_name',       // Option key suffix (becomes wpomni_{slug}_{key})
    'label'       => __('Label', 'wp-omni-auth'),
    'type'        => 'text',             // See field types below
    'default'     => '',                 // Default value
    'class'       => 'regular-text',     // CSS class for the input (optional)
    'placeholder' => '',                 // Placeholder text (optional)
    'description' => '',                 // Help text below the field (optional)
    'options'     => [],                 // For 'select' type: ['value' => 'Label'] (optional)
]
```

**Field types:**

| Type | Renders as | Notes |
|---|---|---|
| `text` | `<input type="text">` | Standard text input |
| `password` | `<input type="password">` | Shows "Already configured" placeholder when secret exists. Empty submission preserves existing value. |
| `url` | `<input type="url">` | Validated for HTTPS. Uses provider's `sanitize_url()`. |
| `toggle` | `<input type="checkbox">` | Hidden field ensures "no" is submitted when unchecked. Values: "yes" / "no". |
| `select` | `<select>` | Provide `options` array: `['value' => 'Label']` |

### Inherited Helper Methods

These are available on all providers via the base class:

| Method | Description |
|---|---|
| `$this->get_slug()` | Returns the provider slug |
| `$this->get_name()` | Returns the display name |
| `$this->get_icon()` | Returns the SVG icon HTML |
| `$this->is_enabled()` | Checks `wpomni_{slug}_enabled` option |
| `$this->get_client_id()` | Returns `wpomni_{slug}_client_id` option |
| `$this->get_client_secret()` | Returns `wpomni_{slug}_client_secret` option |
| `$this->get_option($key, $default)` | Returns `wpomni_{slug}_{key}` option |
| `$this->remote_post($url, $args)` | HTTP POST with error checking, returns decoded JSON or null |
| `$this->remote_get($url, $args)` | HTTP GET with error checking, returns decoded JSON or null |
| `$this->sanitize_url($value)` | Validates URL is well-formed and HTTPS |
| `$this->sanitize_secret($value)` | Preserves existing secret when empty |

---

## How the Settings System Works

### Section Registration Architecture

The settings page is driven by a single source of truth — the **Manager section registry** — plus a small amount of presentation glue in the view layer:

1. **Manager section registry** — `WPOmniAuth_Manager::register_settings_section()` stores every section (`slug`, `title`, `render_callback`, `register_callback`, `priority`, and an optional `sub_tab` / `sub_tab_label`). `get_settings_sections()` returns them sorted by priority. This is the *source of truth* for what sections exist and how to register/render them. `register_settings()` (priority 10) loops this registry and calls each `register_callback`.
2. **View layer derives sub-tabs automatically** — `WPOmniAuth_Settings_Page::render_settings_page()` (in `class-settings-views.php`) groups registered sections by the `sub_tab` each section declares, and renders one `.wpomni-subtab-content` wrapper per sub-tab. If a section declares a `sub_tab` not yet in the sidebar, it is added automatically (using `sub_tab_label`, else the section `title`). There is **no hardcoded section→sub-tab whitelist to maintain** — a newly registered section appears as soon as it is registered.

> Adding a section is now **file-free**: register it with the Manager and declare its `sub_tab`. You do **not** edit `class-settings-views.php`. (Earlier versions required hand-editing a `$section_to_sub` whitelist; that is gone.)

The default sections (currently **5**) are registered by `WPOmniAuth_Settings_Page::register_default_sections()` at `admin_init` priority 5. External code can register more by calling `register_settings_section()` at priority < 5.

### Default sections

| Priority | Section slug | `sub_tab` | Notes |
|---|---|---|---|
| 10 | `general` | `general` | General settings |
| 20 | `debug_log` | `debug` | Debug log viewer |
| 50 | `security` | `security` | IP blacklist, rate limit, auto-ban |
| 60 | `notifications` | `notifications` | Email + webhook notifications |
| 70 | `data` | `data` | Data Management (clear logs / full reset) |

> Provider configuration (built-in + custom) is **not** a registered Settings section — it is rendered on the separate **OAuth Providers** tab via dedicated list/detail views. Provider saving goes through `save_providers()` (`admin-post.php`), not the Settings API, so no Settings-section registration is needed for providers.

### Registration Flow

1. WordPress fires `admin_init`.
2. `register_default_sections()` (priority 5) registers the 5 default sections with the Manager.
3. `register_settings()` (priority 10) loops over registered sections and calls each `register_callback` (if present) → each registers its options under the `wpomni_home` or `wpomni_providers` group.
4. (External) any `register_settings_section()` call at `admin_init` priority < 5 adds more entries to the registry.

### Rendering Flow (Settings tab)

1. `render_settings_page()` reads the current `sub` query arg and the sidebar sub-tab list (`general` / `security` / `notifications` / `debug` / `data`, plus any dynamically added sub-tabs).
2. It groups `get_settings_sections()` by each section's `sub_tab`, then for the active sub-tab renders the matching `.wpomni-subtab-content` block(s) via `render_callback`.
3. All sections live inside one `options.php` form, so saving one sub-tab does not clear the others.

### View Templates (HTML / Logic Separation)

To keep the trait readable, the actual HTML markup for every part of the admin UI lives in **plain-PHP template files** under `includes/views/settings/`. Each render method in `class-settings-views.php` is a thin *controller*: it gathers data (options, DB queries, provider objects) and then calls `render_template()` to emit the matching template.

`render_template($template, $vars = [])` is a private helper:

```php
private function render_template($template, $vars = []) {
    if (!empty($vars)) {
        extract($vars);
    }
    ob_start();
    require WPOMNIAUTH_PLUGIN_DIR . 'includes/views/settings/' . $template;
    return ob_get_clean();
}
```

Templates receive data only through the `$vars` array (made available via `extract`). **Templates must not reference `$this`** — any value a template needs is passed explicitly by its controller. This is the same convention used by the runtime screens in `includes/views/` (`oauth-login-screen.php`, `callback-page.php`, `emergency-page.php`).

**Controller → template map:**

| Controller (in `class-settings-views.php`) | Template |
|---|---|
| `render_settings_page()` | `settings-page.php` (page shell: tabs, sidebar, modals) |
| `render_dashboard_tab()` | `dashboard-tab.php` |
| `render_about_tab()` | `about-tab.php` |
| `render_general_section()` | `general-section.php` |
| `render_security_section()` | `security-section.php` |
| `render_debug_log_section()` | `debug-log-section.php` |
| `render_notifications_section()` | `notifications-section.php` |
| `render_data_management_section()` | `data-management-section.php` |
| `render_provider_list_view()` | `provider-list-view.php` |
| `get_provider_card_html()` | `provider-card.php` |
| `render_provider_detail_view()` | `provider-detail-view.php` |
| `get_field_html()` | `field.php` |
| `render_user_profile_oauth_section()` | `user-profile-oauth-section.php` |
| `maybe_show_health_check()` | `health-check.php` |
| `maybe_show_unbind_notice()` | `unbind-notice.php` |

**Implication for your own sections:** the `render_callback` you register (see below) is still free to output HTML inline, as the example shows — that path works and needs no template. The template split above is the *plugin's internal* convention for keeping its own render methods small. If you want your section to follow the same convention, write the HTML in your own template file and `require` it directly from your `render_callback` (the page instance's `render_template()` helper is private, so external callbacks should `require` their own template rather than call it).

> **Modular by design.** The admin UI is fully data-driven: sections are declared once in the Manager registry (no hardcoded section list or `sub_tab` whitelist), and each section's presentation is isolated in its own template + controller pair. Adding or removing a section never touches `class-settings-views.php` or duplicates rendering logic — the view layer derives sub-tabs from the registry automatically.

### Adding a Custom Settings Section (step by step)

Adding a new settings section now requires touching **only your own code** — no existing plugin file needs editing.

**Step 1 — Register the section with the Manager**, declaring a `sub_tab` (an existing key, or a new one with `sub_tab_label`) and the `priority`:

```php
add_action('admin_init', function() {
    WPOmniAuth_Manager::instance()->register_settings_section([
        'slug'           => 'my_feature',
        'title'          => __('My Feature Settings', 'wp-omni-auth'),
        'render_callback' => function() {
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Option A', 'wp-omni-auth'); ?></th>
                    <td>
                        <input type="text" name="wpomni_my_option_a"
                            value="<?php echo esc_attr(get_option('wpomni_my_option_a', '')); ?>"
                            class="regular-text">
                    </td>
                </tr>
            </table>
            <?php
        },
        'register_callback' => function() {
            register_setting('wpomni_home', 'wpomni_my_option_a', [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        },
        'sub_tab'  => 'general',  // <-- existing sub-tab → appears immediately
        'priority' => 70,         // After the built-in 10–60
    ]);
}, 4); // priority 4 = before default sections register at priority 5
```

That's it — the section now renders under the **General** sub-tab. No whitelist edit, no other file change.

If you want a **brand-new sub-tab** of its own, give it a unique `sub_tab` plus a `sub_tab_label`; it is added to the sidebar automatically:

```php
WPOmniAuth_Manager::instance()->register_settings_section([
    'slug'           => 'my_feature',
    'title'          => __('My Feature Settings', 'wpomni-auth'),
    'render_callback' => function() { /* ...body HTML... */ },
    'register_callback' => function() { /* ...register_setting()... */ },
    'sub_tab'       => 'my_feature',                  // <-- new sub-tab key
    'sub_tab_label' => __('My Feature', 'wpomni-auth'), // <-- label shown in the sidebar
    'priority'      => 70,
]);
```

**Section array fields:**

| Field | Type | Required | Description |
|---|---|---|---|
| `slug` | string | Yes | Unique identifier for the section |
| `title` | string | Yes | Section heading displayed in the card header |
| `render_callback` | callable | Yes | Outputs the section body HTML (inside `.wpomni-section-body`) |
| `register_callback` | callable | No | Called on `admin_init` to register WP Settings API fields |
| `sub_tab` | string | No* | Sidebar sub-tab key to render under (`general` / `security` / `notifications` / `debug`, or a new key). Omit to keep the section out of the Settings tab (e.g. provider sections rendered elsewhere). |
| `sub_tab_label` | string | No | Label for the sidebar when `sub_tab` is a brand-new key. Falls back to `title`. |
| `priority` | int | No | Sort order. Lower = earlier. Default 100. Built-in sections use 10–60 |

\* A section without `sub_tab` is still registered and its `register_callback` still runs, but it will not be shown on the Settings tab.



### Secret Preservation

When a password field is submitted empty, the sanitize callback (`sanitize_secret`) returns the existing value from the database instead of overwriting it with an empty string. This allows users to save other settings without re-entering secrets.

---

## OAuth Flow

```
User clicks "Login with {Provider}"
        |
        v
Redirected to provider's authorization URL
(with state token for CSRF protection)
        |
        v
User authenticates with provider
        |
        v
Provider redirects back to:
wp-login.php?wpomni_callback={slug}&code=...&state=...
        |
        v
Manager::handle_oauth_callback() fires on 'init' at priority 1
        |
        v
1. Verify state token (CSRF check, 10-minute expiry)
2. Check replay protection (transient lock + used codes list)
3. Exchange code for access token via provider
4. Fetch user data via provider
5. Extract email via provider
6. Match user by OAuth meta (wpomni_provider + wpomni_id) or email
7. Verify user is in the allowed list
8. Store/update OAuth meta in user table
9. Set auth cookie via wp_set_auth_cookie()
10. Redirect to admin dashboard (200 + JS redirect to preserve cookies)
```

---

## Debug Logging

Enable debug mode in settings to log all OAuth operations. Logs are written to `wp-content/.wp-omni-auth-debug.log` (dot-prefix prevents web access).

### Adding Log Entries in a Provider

```php
private function log($message, $data = null) {
    // Unified entry point: redacts access tokens / secrets automatically.
    WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
}
```

Call `$this->log('message')` or `$this->log('message', ['key' => 'value'])` at key points in your provider. `WPOmniAuth_Manager::debug_log()` routes every line through `sanitize_log_data()`, so access tokens and secrets are always redacted before being written.

---

## Security Checklist for New Providers

- [ ] All endpoints use HTTPS (enforced by `sanitize_url()`)
- [ ] State parameter is passed through and verified (CSRF protection)
- [ ] Client secret is never logged or exposed in responses
- [ ] Access token is not logged in plain text
- [ ] User data is validated before extracting email
- [ ] Error responses from the provider are handled gracefully (return null, don't crash)
- [ ] The redirect URI exactly matches what is registered with the OAuth provider
- [ ] No user-controlled data is used without sanitization

---

## Testing a New Provider

1. **Add the file** to `includes/providers/class-{name}-provider.php`
2. **Register with the OAuth provider** (e.g., Azure Portal, Discord Developer Console):
   - Set the redirect URI to: `https://your-site.com/wp-login.php?wpomni_callback={slug}`
   - Note: If behind Cloudflare, the callback must hit `wp-login.php` directly
3. **Enable debug mode** in WP-OmniAuth settings
4. **Configure the provider** in Settings > OAuth Login:
   - Enter Client ID and Client Secret
   - Verify the settings section appears correctly
   - Save and reload to confirm secrets persist
5. **Test the login flow**:
   - Log out of WordPress
   - Click the new provider's login button
   - Verify redirect to provider's auth page
   - Complete authentication
   - Verify redirect back and successful login
6. **Check the debug log** for any errors or unexpected behavior
7. **Test edge cases**:
   - Denied authorization (user clicks "deny")
   - Expired state token (wait >10 minutes)
   - Replay attack (reuse the same code)
   - User not in allowed list
   - Provider returns no email

---

## Custom Providers (User-Configured)

In addition to built-in providers, users can add arbitrary OAuth providers through the admin UI. These use the `WPOmniAuth_Custom_Provider` class, which is a single implementation that reads all its configuration from the database.

Custom providers support configurable: authorization URL, token URL, userinfo URL, email field name, scope, token delivery method (header vs query parameter), and token response key.

Built-in providers differ from custom providers in that they have hardcoded endpoints and can implement provider-specific logic (like GitHub's two-step email lookup).

---

## Plugin Lifecycle

| Event | What Happens |
|---|---|
| **Activation** | `add_option()` sets defaults (does not overwrite existing values) |
| **Normal request** | Plugin does nothing (lazy-loaded, no overhead) |
| **Login page** | Manager instantiated, buttons rendered, password form hidden if OAuth-only |
| **OAuth callback** | Manager instantiated early (priority 1 on `init`), full OAuth flow executes |
| **Admin settings** | Manager + Settings Page instantiated, dynamic form rendered |
| **Deactivation** | Only ephemeral data cleaned (transient locks, used codes). Config preserved. |
| **Uninstall (delete)** | `uninstall.php` removes ALL options, user meta, transients, and log files |
