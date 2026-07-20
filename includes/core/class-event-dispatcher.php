<?php
/**
 * Event Dispatcher — routes login events to handlers (email, webhook, etc.)
 *
 * Each handler is independent: one handler failing does not affect others.
 *
 * @package WP-OmniAuth
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPOmniAuth_Event_Dispatcher {

    /**
     * Dispatch an event to all registered handlers.
     *
     * @param string $event Event name (e.g. 'login_success', 'login_failure', 'access_denied', 'ip_blocked')
     * @param array  $data  Event data (user_id, provider, email, ip, user_agent, message, etc.)
     */
    public static function dispatch($event, $data = []) {
        $handlers = [
            [__CLASS__, 'handle_email'],
            [__CLASS__, 'handle_webhook'],
        ];

        foreach ($handlers as $handler) {
            try {
                call_user_func($handler, $event, $data);
            } catch (\Throwable $e) {
                self::log_error($handler, $e);
            }
        }

        // Also fire WordPress action hooks for third-party extensibility
        do_action('wpomni_auth/' . $event, $data);
    }

    // ================================================================
    // Email Notification Handler
    // ================================================================

    /**
     * Send email notification based on settings.
     */
    public static function handle_email($event, $data) {
        $notify_enabled = get_option('wpomni_email_notify_enabled', 'no');
        if ($notify_enabled !== 'yes') {
            return;
        }

        $notify_events = get_option('wpomni_email_notify_events', ['login_failure', 'access_denied', 'ip_blocked', 'provider_bind']);
        if (!in_array($event, $notify_events, true)) {
            return;
        }

        $to = get_option('wpomni_email_notify_to', get_option('admin_email'));
        $site_name = get_option('blogname');

        // Bind events — always controlled by the user's per-event checkboxes now.
        if ($event === 'provider_bind') {
            $subject = sprintf('[%s] %s — %s', $site_name, __('Provider Bound', 'wp-omni-auth'), __('Binding successful', 'wp-omni-auth'));
            $user = isset($data['user_id']) ? get_userdata($data['user_id']) : null;
            $display_name = $user ? $user->display_name : __('Admin', 'wp-omni-auth');
            $body = self::format_html_email(
                $event,
                $site_name,
                $subject,
                [
                    ['label' => __('User', 'wp-omni-auth'), 'value' => $display_name . ' (#' . ($data['user_id'] ?? '') . ')'],
                    ['label' => __('Provider', 'wp-omni-auth'), 'value' => $data['provider'] ?? ''],
                    ['label' => __('OAuth ID', 'wp-omni-auth'), 'value' => $data['oauth_id'] ?? ''],
                    ['label' => __('Email', 'wp-omni-auth'), 'value' => $data['email'] ?? '—'],
                    ['label' => __('Time', 'wp-omni-auth'), 'value' => current_time('mysql')],
                    ['label' => __('IP', 'wp-omni-auth'), 'value' => $data['ip'] ?? ''],
                ]
            );
            self::send_html_mail($to, $subject, $body);
            return;
        }

        if ($event === 'login_success') {
            $throttle_key = 'wpomni_throttle_ok_' . hash('sha256', ($data['user_id'] ?? 0) . '_' . ($data['ip'] ?? ''));
            if (get_transient($throttle_key)) {
                return;
            }

            $user = isset($data['user_id']) ? get_userdata($data['user_id']) : null;
            $display_name = $user ? $user->display_name : ($data['email'] ?? __('Unknown', 'wp-omni-auth'));

            $subject = sprintf('[%s] %s — %s', $site_name, __('New Login', 'wp-omni-auth'), __('Login successful', 'wp-omni-auth'));
            $body = self::format_html_email(
                $event,
                $site_name,
                $subject,
                [
                    ['label' => __('User', 'wp-omni-auth'), 'value' => $display_name],
                    ['label' => __('Email', 'wp-omni-auth'), 'value' => $data['email'] ?? ''],
                    ['label' => __('Provider', 'wp-omni-auth'), 'value' => $data['provider'] ?? ''],
                    ['label' => __('Time', 'wp-omni-auth'), 'value' => current_time('mysql')],
                    ['label' => __('IP', 'wp-omni-auth'), 'value' => $data['ip'] ?? ''],
                ]
            );
            self::send_html_mail($to, $subject, $body);
            set_transient($throttle_key, 1, 86400);
            return;
        }

        if ($event === 'login_failure' || $event === 'access_denied' || $event === 'ip_blocked') {
            $throttle_key = 'wpomni_throttle_fail_' . hash('sha256', ($data['ip'] ?? '') . '_' . ($data['message'] ?? ''));
            if (get_transient($throttle_key)) {
                return;
            }

            $event_labels = [
                'login_failure' => __('Login Failed', 'wp-omni-auth'),
                'access_denied' => __('Access Denied', 'wp-omni-auth'),
                'ip_blocked'    => __('IP Blocked', 'wp-omni-auth'),
            ];
            $status_labels = [
                'login_failure' => __('Authentication failed', 'wp-omni-auth'),
                'access_denied' => __('Access denied', 'wp-omni-auth'),
                'ip_blocked'    => __('Access denied', 'wp-omni-auth'),
            ];
            $event_label = $event_labels[$event] ?? __('Login Failed', 'wp-omni-auth');
            $status_label = $status_labels[$event] ?? __('Authentication failed', 'wp-omni-auth');

            $subject = sprintf('[%s] %s — %s', $site_name, $event_label, $status_label);
            $body = self::format_html_email(
                $event,
                $site_name,
                $subject,
                [
                    ['label' => __('Attempted Email', 'wp-omni-auth'), 'value' => $data['email'] ?? '—'],
                    ['label' => __('Provider', 'wp-omni-auth'), 'value' => $data['provider'] ?? ''],
                    ['label' => __('Time', 'wp-omni-auth'), 'value' => current_time('mysql')],
                    ['label' => __('IP', 'wp-omni-auth'), 'value' => $data['ip'] ?? ''],
                    ['label' => __('Reason', 'wp-omni-auth'), 'value' => $data['message'] ?? ''],
                ]
            );
            self::send_html_mail($to, $subject, $body);
            set_transient($throttle_key, 1, 600);
        }
    }

    // ================================================================
    // HTML Email Helper
    // ================================================================

    /**
     * Build a styled HTML email body.
     *
     * @param string $event    Event name (used for badge color).
     * @param string $site_name
     * @param string $subject
     * @param array  $rows     Array of ['label' => ..., 'value' => ...].
     * @return string Full HTML document ready for wp_mail.
     */
    private static function format_html_email($event, $site_name, $subject, $rows) {
        $admin_url = admin_url();
        $home_url  = home_url();

        $is_positive = in_array($event, ['login_success', 'provider_bind'], true);
        $badge_color = $is_positive ? '#1a7f37' : '#cf222e';
        $badge_text  = $is_positive ? __('Success', 'wp-omni-auth') : __('Alert', 'wp-omni-auth');

        $rows_html = '';
        foreach ($rows as $r) {
            $rows_html .= sprintf(
                '<tr><td style="padding:8px 12px;font-size:13px;color:#57606a;border-bottom:1px solid #d0d7de;width:120px;vertical-align:top">%s</td>'
                . '<td style="padding:8px 12px;font-size:13px;color:#1f2328;border-bottom:1px solid #d0d7de">%s</td></tr>',
                esc_html($r['label']),
                esc_html($r['value'])
            );
        }

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
<td align="right"><span style="display:inline-block;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:{$badge_color}">{$badge_text}</span></td>
</tr>
</table>
<h2 style="margin:12px 0 0;font-size:16px;font-weight:600;color:#1f2328">{$subject}</h2>
</td></tr>
<tr><td style="padding:0 24px">
<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0">
{$rows_html}
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

    /**
     * Send a multipart email with HTML body (and optional plain-text fallback).
     * Public so other classes (e.g. Emergency_Access) can reuse the HTML sender.
     *
     * @param string $to
     * @param string $subject
     * @param string $html_body
     * @return bool Whether the email was sent successfully.
     */
    public static function send_html_mail($to, $subject, $html_body) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return wp_mail($to, $subject, $html_body, $headers);
    }

    // ================================================================
    // Webhook Handler
    // ================================================================

    /**
     * Send webhook notification based on settings.
     */
    public static function handle_webhook($event, $data) {
        $webhook_url = get_option('wpomni_webhook_url', '');
        if (empty($webhook_url)) {
            return;
        }

        // Validate webhook URL: must be HTTPS with a valid host.
        $parsed = wp_parse_url($webhook_url);
        if (empty($parsed['host']) || $parsed['scheme'] !== 'https') {
            return;
        }

        $webhook_events = get_option('wpomni_webhook_events', []);
        if (!is_array($webhook_events) || !in_array($event, $webhook_events, true)) {
            return;
        }

        $payload = [
            'event' => $event,
            'timestamp' => current_time('c'), // ISO 8601
            'user_id' => $data['user_id'] ?? 0,
            'user_email' => $data['email'] ?? '',
            'provider' => $data['provider'] ?? '',
            'ip' => $data['ip'] ?? '',
            'user_agent' => $data['user_agent'] ?? '',
            'message' => $data['message'] ?? '',
        ];

        // Schedule async delivery via WP-Cron
        wp_schedule_single_event(time(), 'wpomni_send_webhook', [$webhook_url, $payload]);
    }

    /**
     * WP-Cron handler: actually send the webhook HTTP request.
     * Registered globally in wp-omni-auth.php.
     */
    public static function cron_send_webhook($url, $payload, $attempt = 1) {
        $secret = get_option('wpomni_webhook_secret', '');
        $json_body = wp_json_encode($payload);

        $args = [
            'body' => $json_body,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
        ];

        // Add HMAC signature if secret is configured
        if (!empty($secret)) {
            $signature = hash_hmac('sha256', $json_body, $secret);
            $args['headers']['X-WPOmniAuth-Signature'] = $signature;
            $args['headers']['X-WPOmniAuth-Event'] = $payload['event'] ?? '';
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response) && $attempt < 3) {
            // Retry with increasing delay
            $delays = [30, 120, 600]; // seconds
            $delay = $delays[min($attempt - 1, count($delays) - 1)];
            wp_schedule_single_event(time() + $delay, 'wpomni_send_webhook', [$url, $payload, $attempt + 1]);
        }
    }

    // ================================================================
    // Error Logging
    // ================================================================

    private static function log_error($handler, \Throwable $e) {
        if (get_option('wpomni_debug_mode', 'no') !== 'yes') {
            return;
        }
        $handler_name = is_array($handler) ? implode('::', $handler) : (string) $handler;
        $log_file = WP_CONTENT_DIR . '/.wp-omni-auth-debug.log';
        $time = current_time('mysql');
        $msg = sprintf("[{$time}] EventDispatcher ERROR in %s: %s", $handler_name, $e->getMessage());
        $log_dir = dirname($log_file);
        if (@is_writable($log_dir) || @mkdir($log_dir, 0755, true)) {
            file_put_contents($log_file, $msg . "\n", FILE_APPEND);
        }
    }
}
