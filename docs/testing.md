# WP-OmniAuth 测试指南

本插件使用 **PHPUnit + WP_Mock** 进行轻量级单元测试，无需本地 WordPress 或数据库。WP_Mock 拦截 WordPress 全局函数（`get_option`、`get_transient`、`wp_salt` 等），使纯逻辑（OAuth 回调的安全分支、速率限制、IP 黑名单、日志脱敏）可在隔离环境中验证。

> 注意：本文档位于 `docs/`，发布 zip 时会被排除（`release.yml` 的 rsync 排除列表含 `docs/`），因此不会随插件分发。

## 环境要求

- PHP `>= 7.4`（与插件最低要求一致）
- Composer
- （可选）`gettext`，用于重新编译翻译文件

## 安装

```bash
composer install
```

`composer install` 会拉取开发依赖：

- `phpunit/phpunit` (`^9.6`)
- `10up/wp_mock` (`^1.0`)

## 运行测试

```bash
# 方式一：通过 composer 脚本
composer test

# 方式二：直接调用 phpunit
vendor/bin/phpunit

# 仅运行某个测试类
vendor/bin/phpunit tests/Test_Security.php

# 生成覆盖率报告（需要 xdebug 或 pcov）
vendor/bin/phpunit --coverage-html build/coverage
```

测试入口为 `phpunit.xml.dist`，引导文件为 `tests/bootstrap.php`（它定义 WordPress 常量桩、启动 `WP_Mock`、递归加载 `includes/` 下的所有类，含 `core/`、`providers/`、`admin/`、`admin/traits/`）。`phpunit.xml.dist` 显式列出各测试文件以确保 `composer test` 能发现并运行它们。

### 持续集成（CI）

`.github/workflows/test.yml` 会在推送到 `main`/`master` 分支以及所有 Pull Request 时自动运行：检出代码 → `shivammathur/setup-php@v2` 安装 PHP 7.4 + Composer → `composer install` → `vendor/bin/phpunit`。提交前请确保 `composer test` 本地全绿。

## 目录结构

```
tests/
├── bootstrap.php              # WP_Mock 引导 + 递归加载 includes/ 下所有类（core/providers/admin/traits）
├── class-testable-manager.php # 测试助手：Testable_Manager + CallbackPageException
├── Test_Sanitizers.php        # Provider 基类脱敏器
├── Test_Security.php          # Manager/ Security 安全工具
├── Test_OAuth_Callback.php    # OAuth 回调入口的安全分支
└── Test_Login_Guard.php       # WPOmniAuth_Login_Guard 密码拦截/应急分支
```

### 测试助手

`class-testable-manager.php` 提供：

- `Testable_Manager` — 继承 `WPOmniAuth_Manager`，公开构造函数（跳过真实 hook 注册与 provider 自动发现），并：
  - 重写 `render_callback_page()` 为**抛出异常** `CallbackPageException`，使回调流程中的错误早退分支可被断言；
  - 重写 `get_provider()` 返回受控的 mock provider；
  - 重写 `find_user_by_oauth()` 返回受控的假用户。
- `CallbackPageException` — 携带 `page_type`，供测试断言终止原因。

> 为支持可测试性，`render_callback_page` 与 `find_user_by_oauth` 由 `private` 改为 `protected`（无对外行为变化）。

## 测试覆盖面

| 测试类 | 覆盖内容 |
|---|---|
| `Test_Sanitizers` | `sanitize_url()`（HTTP 被拒、非法被拒、HTTPS 通过）；`sanitize_secret()`（空值保留已有密钥，非空值覆盖） |
| `Test_Security` | `is_ip_blacklisted()`（精确匹配 + CIDR）；`ip_in_cidr()`（私有方法，反射调用）；`check_rate_limit()` / `increment_rate_limit()`；`get_client_ip()`（直连与可信代理头）；`sanitize_log_data()`（token / secret / `_access_token` 被 `***REDACTED***`） |
| `Test_OAuth_Callback` | `handle_oauth_callback()` 安全早退：IP 黑名单、速率限制、provider 禁用、缺失 `code`/`state`、`state` 校验失败、重放保护；以及成功路径（断言 `wp_set_auth_cookie` 被调用） |
| `Test_Login_Guard` | `WPOmniAuth_Login_Guard::maybe_block_password_login()`：OAuth-only 关闭、空凭据、应急模式放行、无可启用 provider 的安全网放行、有启用 provider 时拦截 |

`insert_login_log()` 在测试中使用 `$wpdb` 桩：表存在性检查（`SHOW TABLES`）返回空，从而**提前返回**，不触达真实数据库，也不触发 `maybe_auto_ban` 的 DB 查询。

## 编写新测试

1. 在 `tests/` 下新建 `Test_Xxx.php`，继承 `PHPUnit\Framework\TestCase`。
2. 在 `setUp()` 调用 `WP_Mock::setUp()`，`tearDown()` 调用 `WP_Mock::tearDown()`。
3. 用 `WP_Mock::userFunction()` 模拟被调用的 WordPress 函数：
   - 带 `'args'` 的更具体，会被优先匹配；
   - `'return'` 可为值或闭包；
   - 用 `'times'` 断言调用次数。
4. 测试私有/受保护静态方法时，通过 `ReflectionMethod::setAccessible(true)` 调用。

示例（断言某个选项被读取一次）：

```php
WP_Mock::userFunction('get_option', [
    'args'  => ['wpomni_debug_mode', 'no'],
    'return' => 'yes',
    'times'  => 1,
]);
```

## 已知限制

- 沙箱/CI 中若无 PHP 与 Composer，无法实际运行；需在含 PHP 的环境执行 `composer install && composer test` 做最终校验。
- 成功路径覆盖到设置认证 cookie 为止；涉及自定义表写入与 `maybe_auto_ban` 的部分由 `$wpdb` 桩跳过，建议在本地 WordPress 实例做手动/集成验证。
- 本套件不覆盖后台设置页渲染、前端按钮渲染等需 WP 环境的部分。
