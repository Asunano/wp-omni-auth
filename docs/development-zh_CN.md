# WP-OmniAuth 开发者指南

本文档涵盖插件架构、命名规范以及如何添加新的 OAuth 提供者。

---

## 架构概览

WP-OmniAuth 采用基于提供者的架构。每个 OAuth 提供者（GitHub、Google 或任意自定义提供者）都是一个自包含的类，负责处理该服务的完整 OAuth 2.0 流程。插件核心自动发现、注册和编排所有提供者。

### 组件关系图

```
wp-omni-auth.php (入口文件)
    |
    +-- includes/core/
    |   +-- class-oauth-provider.php     抽象基类（契约）
    |   +-- class-oauth-manager.php      编排器：钩子、OAuth 流程、用户匹配、
    |   |                                设置区块注册表、提供者自动发现
    |   +-- class-security.php           抽取的安全工具（IP 黑名单、限流、自动封禁）
    |   +-- class-login-guard.php        全局认证/应急钩子（原内联闭包）
    |   +-- class-emergency-access.php   两步应急密钥后门（?wpomni_emergency=1）
    |   +-- class-event-dispatcher.php   事件与 Webhook 分发器
    |   |
|   ├── includes/providers/
|   |   +-- class-*-provider.php         内置提供者（自动发现，见下方「内置提供者清单」）
|   |   +-- class-custom-provider.php    动态提供者（来自数据库配置）
    |   |
    |   └── includes/admin/
    |       +-- class-settings-page.php     后台 UI：组合三个 trait
    |       +-- class-github-updater.php    GitHub Releases 自更新器
    |       +-- traits/
    |           +-- class-settings-registration.php  注册/清理/保存
    |           +-- class-settings-views.php          渲染方法
    |           +-- class-settings-ajax.php           ajax_* 处理器
```

### 目录结构

```
wp-omni-auth/
├── wp-omni-auth.php              # 主插件文件、常量定义、懒加载
├── uninstall.php                 # 插件删除时的完整清理
├── readme.txt                    # WordPress.org 说明文件
├── includes/
│   ├── core/
│   │   ├── class-oauth-provider.php   # 所有提供者继承的抽象基类
│   │   ├── class-oauth-manager.php    # 核心编排器（单例）；安全方法委托给 Security
│   │   ├── class-security.php         # 抽取的安全工具（IP 黑名单/CIDR、限流、自动封禁、客户端 IP）
│   │   ├── class-login-guard.php      # 全局 authenticate/应急钩子（从入口文件抽取）
│   │   ├── class-emergency-access.php # 两步应急访问后门（email→code→key / 直接 key）
│   │   └── class-event-dispatcher.php # 事件与 Webhook 分发器
│   ├── providers/
│   │   ├── class-*-provider.php  # 内置提供者（自动发现；每个 class-{name}-provider.php 即一个提供者）
│   │   └── class-custom-provider.php  # 用户配置的提供者（来自数据库）
│   ├── views/
│   │   ├── oauth-login-screen.php  # OAuth-Only 登录页（前端）
│   │   ├── callback-page.php       # OAuth 回调结果页（前端）
│   │   └── emergency-page.php      # 应急访问 UI（前端）
│   └── admin/
│       ├── class-settings-page.php    # 后台设置（组合三个 trait；__construct/add_admin_menu/admin_scripts）
│       ├── class-github-updater.php   # GitHub Releases 自更新器
│       └── traits/
│           ├── class-settings-registration.php  # 区块/字段注册、清理回调、保存处理器
│           ├── class-settings-views.php          # 设置页与区块/标签/卡片渲染
│           └── class-settings-ajax.php           # ajax_* 处理器方法
├── assets/
│   ├── css/login-styles.css      # 登录页按钮样式（前端 + 应急页）
│   ├── css/admin-settings.css    # 后台设置页样式（由内联 admin_head 抽取）
│   └── js/admin-settings.js      # 后台 JS（添加/移除提供者、日志查看器）
├── languages/
│   ├── wp-omni-auth.pot          # 翻译模板
│   ├── wp-omni-auth-zh_CN.po     # 中文翻译
│   └── wp-omni-auth-zh_CN.mo     # 编译后的翻译
└── docs/
    ├── development.md            # 英文开发指南
    └── development-zh_CN.md      # 本文件
```

---

## 内置提供者清单

插件内置以下提供者（位于 `includes/providers/`，由 `WPOmniAuth_Manager::init_providers()` 自动发现，新增文件即生效，无需改动其它代码）：

| Slug | 名称 | 说明 |
|---|---|---|
| `github` | GitHub | 全球开发者主流 |
| `google` | Google | 全球通用 |
| `apple` | Apple | iOS 应用上架强制项 |
| `microsoft` | Microsoft | 企业 / Azure AD |
| `gitlab` | GitLab | 代码托管 |
| `discord` | Discord | 游戏 / 社群 |
| `wechat` | 微信 | 中国市场必备 |
| `facebook` | Facebook | 国际社交主流 |
| `linkedin` | LinkedIn | 职场 / B2B |
| `qq` | QQ | 国内三大之一 |
| `weibo` | 微博 | 国内三大之一 |
| `wecom` | 企业微信 | 企业场景 |

> 企业级 OIDC / SSO 提供者（如 **Okta、Auth0、Keycloak、Authentik**）未做内置，但均可用后台的「自定义提供者」通过标准 OIDC 端点配置，无需新增代码。

## 添加新的内置 OAuth 提供者

添加一个新的内置提供者（如 Microsoft、Discord、GitLab）只需要创建**一个文件**并遵循命名约定。无需修改任何其他文件。

### 第一步：创建提供者文件

在 `includes/` 目录下创建 `class-{name}-provider.php`，其中 `{name}` 为提供者名称的小写形式（如 `microsoft`、`discord`、`gitlab`）。

### 第二步：实现类

该类必须：
- 命名为 `WPOmniAuth_{Name}_Provider`（文件名转 PascalCase）
- 继承 `WPOmniAuth_Provider`
- 调用 `parent::__construct()` 传入 slug、显示名称和 SVG 图标
- 实现全部 4 个抽象方法
- 实现 `get_settings_fields()` 声明其设置表单
- （推荐）重写 `get_button_color()` 定义登录按钮的品牌颜色（见下文）

### 定义登录按钮品牌颜色

登录按钮的颜色**不再硬编码**（早期版本只对 GitHub、Google 写死样式，其余内置提供者一律使用后台主题蓝）。现在每个提供者都通过 `get_button_color()` 返回自己的品牌色，由统一的 CSS 规则 `.wpomni-btn-brand` 上色——内置提供者和自定义提供者的处理路径完全一致。

- 重写 `get_button_color()` 返回十六进制颜色（如 `'#24292e'`），按钮即使用该品牌色，文字颜色会自动选取对比度最高的黑/白。
- 不重写（返回空字符串 `''`）时，按钮回退为站点后台主题色。
- 极少数品牌（如 Google 白底深字 + 浅灰边框）需要覆盖 `get_button_text_color()` 与 `get_button_border_color()`，否则沿用背景色推断。

```php
public function get_button_color() {
    return '#5865F2'; // Discord Blurple
}

// 可选：仅在品牌要求特定文字/边框色时重写
public function get_button_text_color() {
    return ''; // 留空 = 自动根据背景色计算
}

public function get_button_border_color() {
    return ''; // 留空 = 与背景色相同
}
```

> 自定义提供者（后台 UI 添加）的按钮颜色由「Button Color」设置项 (`wpomni_{slug}_color`) 决定，其 `get_button_color()` 已自动读取该选项，无需额外代码。

### 完整示例：Microsoft 提供者

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
     * 声明后台设置表单的字段。
     * 这些字段会自动出现在设置页面中。
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
                'description' => __('Azure AD 租户 ID，多租户填 "common"。', 'wp-omni-auth'),
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
        // 统一入口：自动脱敏访问令牌与密钥
        WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
    }
}
```

就这么简单。把这个文件放到 `includes/providers/` 目录下，插件会自动：

- **发现并加载类** — `WPOmniAuth_Manager::init_providers()` 运行 `glob('includes/providers/class-*-provider.php')`（跳过 `class-custom-provider.php`），`require_once` 每个文件，并按文件名推导类名：`class-{name}-provider.php` → `WPOmniAuth_{Name}_Provider`。`{name}` 部分同时决定了类名与 slug，因此**文件名、类名、slug 必须保持一致**（如 `class-microsoft-provider.php` → `WPOmniAuth_Microsoft_Provider` → slug `microsoft`）。
- **注册为可用提供者**，并在 **OAuth Providers** 标签页渲染其配置 UI（列表视图 + 每个提供者的详情视图，由 `get_settings_fields()` 驱动）。
- **保存配置** — Providers 标签页表单提交到 `admin-post.php`（`action=wpomni_save_providers`），由 `save_providers()` 直接对每个字段 `update_option`（密钥走 `sanitize_secret`、URL 走 `sanitize_url`），不经过 WordPress Settings API。
- **在登录页显示登录按钮**（启用后），CSS 类为 `wpomni-btn-{slug}`。

> 无需修改其他文件。要移除某个提供者，只需删除其文件（以及已保存的 `wpomni_{slug}_*` 选项）。

---

## 命名规范

| 元素 | 约定 | 示例 |
|---|---|---|
| 文件名 | `class-{name}-provider.php` | `class-microsoft-provider.php` |
| 类名 | `WPOmniAuth_{Name}_Provider` | `WPOmniAuth_Microsoft_Provider` |
| Slug（构造参数） | 小写，无空格 | `microsoft` |
| 选项名 | `wpomni_{slug}_{key}` | `wpomni_microsoft_client_id` |
| CSS 类名（登录按钮） | `wpomni-btn-{slug}` | `wpomni-btn-microsoft` |
| 登录按钮品牌色 | 重写 `get_button_color()` 返回十六进制色（空 = 后台主题色） | `'#5e5e5e'` |
| 回调参数 | `wpomni_callback={slug}` | `wpomni_callback=microsoft` |
| 调试日志标签 | `[{Name}]` | `[Microsoft]` |

---

## 提供者 API 参考

### 抽象方法（必须实现）

#### `get_authorization_url($state): string`

构建 OAuth 授权 URL。用户会被重定向到这里开始授权流程。

- `$state` — Manager 生成的 CSRF 令牌，必须作为 `state` 参数传递。
- 返回完整的 URL，包含 `client_id`、`redirect_uri`、`scope`、`response_type=code` 和 `state`。
- 使用 `$this->get_redirect_uri()` 获取回调地址（或用 `add_query_arg('wpomni_callback', $this->slug, wp_login_url())` 自行构建）。

#### `get_access_token($code): ?string`

用授权码换取访问令牌。

- `$code` — 回调中的授权码。
- 使用 `$this->remote_post()` 发送 HTTP 请求。
- 返回访问令牌字符串，失败返回 `null`。

#### `get_user_data($access_token): ?array`

使用访问令牌获取用户资料数据。

- `$access_token` — 有效的 OAuth 访问令牌。
- 使用 `$this->remote_get()` 发送 HTTP 请求。
- 返回解码后的 JSON 关联数组，失败返回 `null`。
- 如果提供者返回的响应中包含访问令牌，将其存为 `$data['_access_token']`，以便 `get_email_from_user_data()` 在二次 API 调用中使用。

#### `get_email_from_user_data($user_data): string`

从用户数据中提取邮箱地址。

- `$user_data` — `get_user_data()` 返回的数组，已追加 `_access_token`。
- 返回邮箱字符串，未找到返回空字符串。
- 这里放置提供者特有的邮箱提取逻辑（如 GitHub 的独立邮箱 API 调用）。

#### `get_button_color(): string`

返回登录按钮的品牌背景色（十六进制，如 `'#24292e'`）。返回空字符串 `''` 时按钮使用站点后台主题色。内置提供者应重写为各自品牌色；自定义提供者的实现已自动读取「Button Color」设置项，一般无需重写。

#### `get_button_text_color(): string`

可选的显式文字颜色（十六进制）。返回空字符串时由 Manager 根据背景亮度自动选取黑/白。仅当品牌要求特定文字色（如 Google 深灰 `#3c4043`）时重写。

#### `get_button_border_color(): string`

可选的显式边框颜色（十六进制）。返回空字符串时复用背景色作为边框。仅当品牌按钮需要独立边框（如 Google 浅灰 `#dadce0`）时重写。

### 配置方法

#### `get_settings_fields(): array`

返回后台设置表单的字段定义数组。每个字段是一个关联数组：

```php
[
    'key'         => 'field_name',       // 选项键后缀（最终为 wpomni_{slug}_{key}）
    'label'       => __('Label', 'wp-omni-auth'),
    'type'        => 'text',             // 见下方字段类型
    'default'     => '',                 // 默认值
    'class'       => 'regular-text',     // 输入框 CSS 类（可选）
    'placeholder' => '',                 // 占位文本（可选）
    'description' => '',                 // 字段下方的帮助文本（可选）
    'options'     => [],                 // select 类型专用：['value' => 'Label']（可选）
]
```

**字段类型：**

| 类型 | 渲染为 | 说明 |
|---|---|---|
| `text` | `<input type="text">` | 标准文本输入框 |
| `password` | `<input type="password">` | 密钥已配置时显示"已配置"占位符。提交为空时保留现有值。 |
| `url` | `<input type="url">` | 验证 HTTPS。使用提供者的 `sanitize_url()`。 |
| `toggle` | `<input type="checkbox">` | 隐藏字段确保取消勾选时提交 "no"。值："yes" / "no"。 |
| `select` | `<select>` | 需提供 `options` 数组：`['value' => 'Label']` |

### 继承的辅助方法

以下方法通过基类在所有提供者中可用：

| 方法 | 说明 |
|---|---|
| `$this->get_slug()` | 返回提供者 slug |
| `$this->get_name()` | 返回显示名称 |
| `$this->get_icon()` | 返回 SVG 图标 HTML |
| `$this->is_enabled()` | 检查 `wpomni_{slug}_enabled` 选项 |
| `$this->get_client_id()` | 返回 `wpomni_{slug}_client_id` 选项 |
| `$this->get_client_secret()` | 返回 `wpomni_{slug}_client_secret` 选项 |
| `$this->get_option($key, $default)` | 返回 `wpomni_{slug}_{key}` 选项 |
| `$this->remote_post($url, $args)` | HTTP POST，含错误检查，返回解码 JSON 或 null |
| `$this->remote_get($url, $args)` | HTTP GET，含错误检查，返回解码 JSON 或 null |
| `$this->sanitize_url($value)` | 验证 URL 格式正确且为 HTTPS |
| `$this->sanitize_secret($value)` | 提交为空时保留现有密钥 |

---

## 设置系统工作原理

### 区块注册架构

设置页面由**单一数据源**——Manager 的区块注册表——驱动，视图层只做少量呈现粘合：

1. **Manager 区块注册表** — `WPOmniAuth_Manager::register_settings_section()` 保存每个区块（`slug`、`title`、`render_callback`、`register_callback`、`priority`，以及可选的 `sub_tab` / `sub_tab_label`）。`get_settings_sections()` 按优先级返回已排序的区块，是"有哪些区块、如何注册与渲染"的唯一真相来源。`register_settings()`（优先级 10）遍历注册表并调用每个 `register_callback`。
2. **视图层按 `sub_tab` 自动派生子标签** — `WPOmniAuth_Settings_Page::render_settings_page()`（位于 `class-settings-views.php`）按每个区块声明的 `sub_tab` 对区块分组，并为每个子标签渲染一个 `.wpomni-subtab-content` 包裹层。若某区块声明了一个侧栏尚不存在的 `sub_tab`，会自动加入（使用 `sub_tab_label`，否则回退到区块 `title`）。**没有任何需要维护的硬编码"区块→子标签"白名单**——新注册的区块在注册后立即出现。

> 新增区块现在是**免改文件**的：用 Manager 注册并声明 `sub_tab` 即可，你无需编辑 `class-settings-views.php`。（旧版本需要手工修改 `$section_to_sub` 白名单，现已移除。）

默认区块（目前 **5** 个）由 `WPOmniAuth_Settings_Page::register_default_sections()` 在 `admin_init` 优先级 5 注册。外部代码可在优先级 < 5 时调用 `register_settings_section()` 注册更多。

### 默认区块

| 优先级 | 区块 slug | `sub_tab` | 说明 |
|---|---|---|---|
| 10 | `general` | `general` | 通用设置 |
| 20 | `debug_log` | `debug` | 调试日志查看器 |
| 50 | `security` | `security` | IP 黑名单、限流、自动封禁 |
| 60 | `notifications` | `notifications` | 邮件 + Webhook 通知 |
| 70 | `data` | `data` | 数据管理（清理日志 / 完全重置） |

> 提供者配置（内置 + 自定义）**不是**一个已注册的 Settings 区块——它在独立的 **OAuth Providers** 标签页通过专用的列表/详情视图渲染。提供者保存经由 `save_providers()`（`admin-post.php`）而非 Settings API，因此不需要为提供者注册 Settings 区块。

### 注册流程

1. WordPress 触发 `admin_init`。
2. `register_default_sections()`（优先级 5）向 Manager 注册 5 个默认区块。
3. `register_settings()`（优先级 10）遍历已注册区块并调用每个 `register_callback`（若有）→ 每个在其下注册选项，分组为 `wpomni_home` 或 `wpomni_providers`。
4. （外部）任意在 `admin_init` 优先级 < 5 调用 `register_settings_section()` 都会向注册表追加条目。

### 渲染流程（Settings 标签页）

1. `render_settings_page()` 读取当前 `sub` 查询参数与侧栏子标签列表（`general` / `security` / `notifications` / `debug` / `data`，外加任何动态新增的子标签）。
2. 它按每个区块的 `sub_tab` 对 `get_settings_sections()` 分组，再为当前激活的子标签通过 `render_callback` 渲染对应的 `.wpomni-subtab-content` 块。
3. 所有区块都在同一个 `options.php` 表单内，因此保存一个子标签不会清空其它子标签。

### 视图模板（HTML 与逻辑分离）

为了让 trait 保持可读，后台 UI 每一处的实际 HTML 标记都放在 `includes/views/settings/` 下的**纯 PHP 模板文件**里。 `class-settings-views.php` 中的每个渲染方法都是瘦*控制器*：它负责收集数据（选项、数据库查询、提供者对象），然后调用 `render_template()` 输出对应的模板。

`render_template($template, $vars = [])` 是一个私有助手：

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

模板只通过 `$vars` 数组接收数据（`extract` 后可用）。**模板中禁止引用 `$this`**——任何模板需要的值都由对应控制器显式传入。这与 `includes/views/` 下运行时页面（`oauth-login-screen.php`、`callback-page.php`、`emergency-page.php`）的约定保持一致。

**控制器 → 模板映射：**

| 控制器（位于 `class-settings-views.php`） | 模板 |
|---|---|
| `render_settings_page()` | `settings-page.php`（页面外壳：标签页、侧栏、弹窗） |
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

**对你自己的区块意味着什么：** 你注册的 `render_callback`（见下文）仍然可以直接内联输出 HTML，如示例所示——这条路径完全可用且不需要模板。上面的模板拆分是插件*内部*用于保持自身渲染方法精简的约定。如果你希望自己的区块也遵循同样约定，请把 HTML 写到你自己的模板文件里，并在 `render_callback` 中直接 `require` 它（`render_template()` 是页面实例的私有助手，外部回调应自行 `require` 自己的模板，而非调用它）。

> **天生模块化。** 后台 UI 完全由数据驱动：区块只在 Manager 注册表中声明一次（没有硬编码的区块列表或 `sub_tab` 白名单），而每个区块的展示被隔离在独立的「模板 + 控制器」配对中。新增或移除区块永远不会改动 `class-settings-views.php`，也不会重复任何渲染逻辑——视图层会根据注册表自动派生子标签。

### 添加自定义设置区块（分步）

新增设置区块现在只需接触**你自己的代码**——无需编辑任何插件原有文件。

**第一步 — 用 Manager 注册区块**，声明 `sub_tab`（已有键，或用 `sub_tab_label` 声明全新子标签）与 `priority`：

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
        'sub_tab'  => 'general',  // <-- 复用已有子标签，立即出现
        'priority' => 80,         // 在内置 10–70 之后
    ]);
}, 4); // 优先级 4 = 在默认区块注册（优先级 5）之前
```

就这样——该区块现在出现在 **General** 子标签下。无需编辑白名单，无需改动其它文件。

如果想要**一个属于自己的全新子标签**，给它一个唯一的 `sub_tab` 加上 `sub_tab_label`，它会自动加入侧栏：

```php
WPOmniAuth_Manager::instance()->register_settings_section([
    'slug'           => 'my_feature',
    'title'          => __('My Feature Settings', 'wp-omni-auth'),
    'render_callback' => function() { /* ...主体 HTML... */ },
    'register_callback' => function() { /* ...register_setting()... */ },
    'sub_tab'       => 'my_feature',                  // <-- 新子标签键
    'sub_tab_label' => __('My Feature', 'wpomni-auth'), // <-- 侧栏显示标签
    'priority'      => 80,
]);
```

**区块数组字段：**

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `slug` | string | 是 | 区块唯一标识符 |
| `title` | string | 是 | 卡片头部显示的区块标题 |
| `render_callback` | callable | 是* | 输出区块主体 HTML（在 `.wpomni-section-body` 内） |
| `register_callback` | callable | 否 | 在 `admin_init` 时调用，用于注册 WP Settings API 字段 |
| `sub_tab` | string | 否* | 渲染到的侧栏子标签键（`general`/`security`/`notifications`/`debug`/`data`，或新键）。省略则区块不出现在 Settings 标签页 |
| `sub_tab_label` | string | 否 | 当 `sub_tab` 为新键时侧栏使用的标签，回退到 `title` |
| `priority` | int | 否 | 排序顺序。数值越小越靠前。默认 100。内置区块使用 10–70 |

\* `render_callback` 可省略：若区块只需注册设置项而不在 Settings 页渲染（例如配置在其它标签页呈现），可只提供 `register_callback`。省略 `sub_tab` 的区块仍会被注册，其 `register_callback` 仍会执行，但不会在 Settings 标签页显示。

### 密钥保留机制

当密码字段提交为空时，消毒回调（`sanitize_secret`）返回数据库中的现有值而非空字符串。这允许用户保存其他设置而无需重新输入密钥。

---

## OAuth 流程

```
用户点击 "Login with {Provider}"
        |
        v
重定向到提供者的授权 URL
（携带 state 令牌用于 CSRF 防护）
        |
        v
用户在提供者处完成认证
        |
        v
提供者回调重定向到：
wp-login.php?wpomni_callback={slug}&code=...&state=...
        |
        v
Manager::handle_oauth_callback() 在 'init' 钩子优先级 1 触发
        |
        v
1. 验证 state 令牌（CSRF 检查，10 分钟过期）
2. 检查重放保护（transient 锁 + 已用代码列表）
3. 通过提供者用 code 换取 access token
4. 通过提供者获取用户数据
5. 通过提供者提取邮箱
6. 通过 OAuth meta（wpomni_provider + wpomni_id）或邮箱匹配用户
7. 验证用户在允许列表中
8. 存储/更新用户表中的 OAuth meta
9. 通过 wp_set_auth_cookie() 设置认证 Cookie
10. 重定向到后台首页（200 + JS 重定向以确保 Cookie 生效）
```

---

## 调试日志

在设置中启用调试模式以记录所有 OAuth 操作。日志写入 `wp-content/.wp-omni-auth-debug.log`（点前缀防止 Web 直接访问）。

### 在提供者中添加日志

```php
private function log($message, $data = null) {
    // 统一入口：自动脱敏访问令牌与密钥
    WPOmniAuth_Manager::debug_log($message, $data, $this->get_name());
}
```

在提供者的关键位置调用 `$this->log('message')` 或 `$this->log('message', ['key' => 'value'])`。`WPOmniAuth_Manager::debug_log()` 会让每一行都经过 `sanitize_log_data()`，因此访问令牌和密钥在写入前始终被脱敏。

---

## 新提供者安全检查清单

- [ ] 所有端点使用 HTTPS（由 `sanitize_url()` 强制）
- [ ] State 参数正确传递和验证（CSRF 防护）
- [ ] Client Secret 不在日志或响应中暴露
- [ ] 访问令牌不以明文记录
- [ ] 提取邮箱前验证用户数据
- [ ] 提供者的错误响应被优雅处理（返回 null，不崩溃）
- [ ] 回调 URI 与在 OAuth 提供者处注册的完全一致
- [ ] 用户可控数据均经过消毒处理

---

## 测试新提供者

1. **添加文件** 到 `includes/providers/class-{name}-provider.php`
2. **在 OAuth 提供者处注册应用**（如 Azure Portal、Discord Developer Console）：
   - 回调 URI 设为：`https://your-site.com/wp-login.php?wpomni_callback={slug}`
   - 注意：如果使用了 Cloudflare，回调必须直接命中 `wp-login.php`
3. **启用调试模式**（WP-OmniAuth 设置中）
4. **配置提供者**（Settings > OAuth Login）：
   - 输入 Client ID 和 Client Secret
   - 验证设置区块显示正确
   - 保存并刷新，确认密钥持久化
5. **测试登录流程**：
   - 退出 WordPress
   - 点击新提供者的登录按钮
   - 验证重定向到提供者的授权页面
   - 完成认证
   - 验证回调重定向和登录成功
6. **检查调试日志** 是否有错误或异常
7. **测试边界情况**：
   - 拒绝授权（用户点击"deny"）
   - State 令牌过期（等待 >10 分钟）
   - 重放攻击（重复使用同一 code）
   - 用户不在允许列表中
   - 提供者未返回邮箱

---

## 自定义提供者（用户配置）

除了内置提供者外，用户还可以通过后台 UI 添加任意 OAuth 提供者。这些使用 `WPOmniAuth_Custom_Provider` 类，这是一个单一实现，所有配置从数据库读取。

自定义提供者支持配置：授权 URL、令牌 URL、用户信息 URL、邮箱字段名、Scope、令牌传递方式（Header 或 Query Parameter）以及令牌响应键名。

内置提供者与自定义提供者的区别在于：内置提供者有硬编码的端点，可以实现提供者特有的逻辑（如 GitHub 的两步邮箱查询）。

---

## 插件生命周期

| 事件 | 行为 |
|---|---|
| **激活** | `add_option()` 设置默认值（不覆盖已有配置） |
| **普通请求** | 插件不执行任何操作（懒加载，零开销） |
| **登录页面** | Manager 实例化，渲染按钮，OAuth-Only 模式下隐藏密码表单 |
| **OAuth 回调** | Manager 提前实例化（`init` 优先级 1），执行完整 OAuth 流程 |
| **后台设置** | Manager + Settings Page 实例化，动态渲染设置表单 |
| **停用** | 仅清理临时数据（transient 锁、已用代码）。配置保留。 |
| **卸载（删除）** | `uninstall.php` 删除所有选项、用户 meta、transient 和日志文件 |
