# WP-OmniAuth

<p align="center">
  ⭐ 如果这个项目对你有帮助，请给它一个 Star！⭐
</p>

<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.0+-%232171b1?logo=wordpress" alt="WordPress: 6.0+">
  <img src="https://img.shields.io/badge/PHP-7.4+-%23777BB4?logo=php" alt="PHP: 7.4+">
  <img src="https://img.shields.io/badge/License-GPL%20v2+-blue.svg" alt="License: GPL v2+">
  <img src="https://img.shields.io/badge/Status-Stable-brightgreen.svg" alt="Status: Stable">
</p>

<p align="center">
  <strong>为 WordPress 提供 OAuth 2.0 登录的轻量插件，11 个内置提供商 + 自定义支持</strong>
</p>

<p align="center">
  <a href="#功能特性">功能</a> •
  <a href="#内置提供商">提供商</a> •
  <a href="#安装">安装</a> •
  <a href="#常见问题">FAQ</a>
</p>

<p align="center">
  📝 详细教程：<a href="https://blog.drxian.cn/archives/1465">WP-OmniAuth：全能 OAuth 登录插件</a>
</p>

---

## 项目简介

**WP-OmniAuth** 是一个轻量级 WordPress 插件，为你的站点添加 OAuth 2.0 登录能力。支持 GitHub、Google、微信等 11 个主流平台一键登录，也支持通过后台配置接入任意 OAuth 2.0 服务商。无需 Composer、无构建步骤，标准「拖入即用」的 WordPress 插件。

### 为什么选择 WP-OmniAuth？

| 优势 | 说明 |
|------|------|
| **开箱即用** | 11 个内置提供商，后台勾选即启用 |
| **灵活扩展** | 自定义提供商可接入任意 OAuth 2.0 服务 |
| **安全可靠** | CSRF 防护 + 限流 + 黑名单 + 日志脱敏 |
| **应急兜底** | 两步式应急入口，被锁门外也能恢复 |
| **原生更新** | 接入 WordPress 原生更新系统，后台一键升级 |

---

## 功能特性

### 自定义提供商

不限数量的自定义 OAuth 2.0 提供商。通过后台界面配置即可接入任意合规服务商：

- 支持标准 Authorization Code Grant 流程
- 可配置 Authorization URL / Token URL / User Info URL
- 支持 Bearer Header 和 Query Parameter 两种 Token 传递方式
- 支持嵌套 JSON 字段提取邮箱
- 支持 URL-encoded Token 响应回退

### OAuth-Only 模式

可完全隐藏 WordPress 默认的用户名/密码登录框，仅展示 OAuth 按钮。适合纯 SSO 场景。

### 完整的安全体系

- **CSRF 防护**：OAuth `state` 参数 + SHA-256 令牌 + 10 分钟过期 + 一次性消费
- **重放保护**：transient 锁 + 已用 code 列表
- **三层次限流**：Per-IP / Per-Provider / Per-Identity 独立配置
- **IP 黑名单**：支持精确 IP 和 CIDR 范围，自动过期
- **自动封禁**：失败次数超阈值自动临时封禁
- **代理安全**：可信代理 IP 白名单，防止 X-Forwarded-For 伪造
- **日志脱敏**：自动清洗 token、secret、access_token 等敏感字段
- **强制 HTTPS**：支持反代/Nginx/Caddy/CloudFlare 后的协议识别

### 登录日志与统计

- 持久化登录历史记录（成功/失败/IP/时间）
- 后台仪表盘展示每日/每周趋势统计
- 支持日志清理和数据管理

### 应急访问

OAuth-Only 模式下若配置失误被锁门外：
1. 通过管理员邮箱获取一次性登录链接（有效期 15 分钟）
2. 使用预设的紧急密钥临时恢复密码登录

两种方式均含 CAPTCHA 人机验证 + 限流保护。

### 事件通知

- **邮件通知**：登录成功/失败/访问拒绝/IP 封禁/提供商绑定等事件
- **Webhook**：HMAC 签名验证，自动重试（最多 3 次）
- **WordPress Action Hook**：`wpomni_auth/{event}` 供第三方扩展

### 原生自动更新

通过 `version.json` 接入 WordPress 原生更新系统，后台「插件」页面即可收到更新通知，像官方插件一样一键升级。无需 GitHub Token、无 API 限流。

---

## 内置提供商

| 提供商 | 类型 | 说明 |
|--------|------|------|
| **Apple** | 内置 | 通过 Apple ID 登录 |
| **Authentik** | 内置 | 自建身份提供商 |
| **DingTalk（钉钉）** | 内置 | 国内企业协作 |
| **Feishu（飞书）** | 内置 | 企业协作平台 |
| **Gitee（码云）** | 内置 | 国内代码托管 |
| **GitHub** | 内置 | 开发者首选 |
| **Google** | 内置 | 全球最广泛 |
| **Microsoft** | 内置 | 企业/Office 365 |
| **QQ** | 内置 | 国内社交平台 |
| **WeChat（微信）** | 内置 | 扫码登录 |
| **Weibo（微博）** | 内置 | 社交媒体登录 |
| **Custom Provider** | 自定义 | 任意 OAuth 2.0 服务 |

---

## 技术架构

### 分层架构

```
┌─────────────────────────────────────────┐
│           入口层（Entry）               │
│  wp-omni-auth.php / uninstall.php      │
├─────────────────────────────────────────┤
│         编排层（Orchestrator）          │
│  Manager / Provider_Checker           │
├─────────────────────────────────────────┤
│       提供商层（Providers）            │
│  11 个内置 Provider + Custom_Provider  │
├─────────────────────────────────────────┤
│         核心层（Core）                 │
│  Security / Logger / LoginLog         │
│  OAuth_State / User_Matcher           │
│  Event_Dispatcher / Emergency_Access  │
│  Login_Guard / Login_Buttons          │
├─────────────────────────────────────────┤
│         管理界面层（Admin）            │
│  Settings_Page / Settings_Views       │
│  Settings_Registration / Settings_Ajax│
│  GitHub_Updater                       │
└─────────────────────────────────────────┘
```

### 技术栈

- **语言**：PHP 7.4+（100% PHP）
- **架构**：单例编排 + 分层委托
- **测试**：PHPUnit + WP_Mock（20+ 测试类，100+ 测试方法）
- **国际化**：WordPress gettext（`.pot` / `.po` / `.mo`）
- **CI/CD**：GitHub Actions（测试 + 翻译 + 自动发布）
- **更新**：WordPress 原生 `pre_set_site_transient_update_plugins`

---

## 安装

### 方式一：ZIP 上传

1. 下载最新的 Release ZIP
2. WordPress 后台 → 插件 → 安装插件 → 上传插件 → 选择 ZIP 文件
3. 启用插件

### 方式二：手动部署

```
1. 将插件目录上传到 /wp-content/plugins/wp-omni-auth/
2. WordPress 后台 → 插件 → 启用 WP-OmniAuth
3. 进入 设置 → WP-OmniAuth 配置提供商
```

> 手动上传时请排除 `build/`、`vendor/`、`.codebuddy/`、`.workbuddy/`、`tests/` 等目录。

---

## 配置教程

每个提供商的详细配置步骤和使用说明请查阅在线教程：

👉 [**WP-OmniAuth：全能 OAuth 登录插件 — 配置指南**](https://blog.drxian.cn/archives/1465)

该教程涵盖所有 11 个内置提供商和自定义提供商的完整配置步骤、回调 URL 设置、注意事项等。

---

## 安全说明

### CSRF 防护

OAuth 流程使用标准的 `state` 参数 + SHA-256 哈希令牌：
- **一次性消费**：验证后立即删除，无法重放
- **10 分钟过期**：超时自动失效
- **Provider 绑定**：state 与 slug 绑定，防止跨提供商攻击

### 限流体系

| 维度 | 默认阈值 | 窗口 |
|------|---------|------|
| 全局 | 60 次 | 60 秒 |
| 每 IP | 10 次 | 60 秒 |
| 每 Provider | 30 次 | 60 秒 |
| 每 Identity | 10 次 | 60 秒 |

### 日志安全

- 日志路径：`wp-content/.wp-omni-auth-debug.log`（点前缀，不可 Web 访问）
- 自动脱敏：`access_token`、`client_secret`、`token` 等敏感字段自动替换为 `***REDACTED***`

---

## 常见问题 FAQ

### 已有用户会怎样？

若站点已有用户邮箱与 OAuth 账号邮箱一致，将直接登录该账号。开启「自动注册」后新用户也可自动建号。

### 支持哪些 OAuth 流程？

标准 Authorization Code Grant（OAuth 2.0），兼容任意合规服务商。

### 这插件安全吗？

是的。使用 WordPress nonce + state 参数双重 CSRF 防护，强制 HTTPS，日志脱敏，IP 黑名单，多层次限流与自动封禁。

### 被 OAuth-Only 锁在门外了怎么办？

访问 `wp-login.php?wpomni_emergency=1` 走两步式应急流程，可通过邮箱链接或紧急密钥临时恢复密码登录。

### Apple 登录需要注意什么？

- 需要付费的 Apple 开发者账号（$99/年）
- Apple 使用 `form_post` 响应模式，插件已兼容（支持从 POST body 读取 code/state）
- 仅首次授权返回真实邮箱，后续登录可能返回私有中继邮箱
- 需要开启 OpenSSL PHP 扩展（ES256 JWT 签名）

### 微信/QQ 登录不返回邮箱怎么办？

这些平台不提供用户邮箱。插件会自动从 OAuth 返回的稳定标识（openid/unionid）合成伪邮箱用于用户匹配，不会影响登录功能。

---

## 免责声明

本插件展示的 OAuth 提供商图标仅用于身份识别目的，不代表与相关品牌有任何关联、认可或合作关系。部分图标系作者根据公开资料制作，可能与官方图标存在差异。所有商标和品牌名称归各自权利人所有。

如您认为某图标侵犯了您的合法权益，或存在与事实不符、误导用户、影响品牌声誉的情形，请与我们联系，我们将在核实后尽快处理。

欢迎前往 [我们的设置指南](https://blog.drxian.cn/archives/1465) 提交准确的官方图标资源。

---

## 开发

```bash
composer install              # 安装开发依赖（phpunit / wp_mock）
composer test                 # 运行单元测试
php tools/build-translations.php   # 重新生成 .pot 语言模板
```

### 目录结构

```
wp-omni-auth/
├── wp-omni-auth.php              # 入口与懒加载
├── includes/
│   ├── core/                     # 核心逻辑
│   │   ├── class-oauth-manager.php    # 编排器
│   │   ├── class-oauth-provider.php   # 提供商抽象基类
│   │   ├── class-security.php         # 安全工具（限流/黑名单）
│   │   ├── class-logger.php           # 调试日志
│   │   ├── class-login-log.php        # 登录日志
│   │   ├── class-login-guard.php      # 登录守卫
│   │   ├── class-emergency-access.php # 应急访问
│   │   ├── class-event-dispatcher.php # 事件分发
│   │   ├── class-oauth-state.php      # OAuth State 管理
│   │   ├── class-user-matcher.php     # 用户匹配
│   │   └── class-login-buttons.php    # 登录按钮渲染
│   ├── providers/                 # 12 个提供商
│   ├── admin/                     # 后台管理界面
│   └── views/                     # 视图模板
├── assets/                        # 静态资源（CSS/JS）
├── languages/                     # 翻译文件
├── tests/                         # 单元测试
└── build/                         # Release ZIP 构建产物
```

---

## 许可证

WP-OmniAuth 以 **GPL-2.0-or-later** 协议发布。详见 [LICENSE](LICENSE)。

Copyright (C) 2026 WP-OmniAuth

---

## 赞助支持

如果这个项目对您有帮助，欢迎赞助支持！

<p align="center">
  <img src="https://blog.drxian.cn/wp-content/uploads/2026/06/img_donate_qr_zfb.jpg" alt="支付宝赞赏" width="200">
  &nbsp;&nbsp;&nbsp;
  <img src="https://blog.drxian.cn/wp-content/uploads/2026/06/img_donate_qr_wx.jpg" alt="微信赞赏" width="200">
</p>
