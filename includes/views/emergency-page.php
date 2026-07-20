<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="robots" content="noindex, nofollow">
            <title><?php esc_html_e('Emergency Access', 'wp-omni-auth'); ?></title>
            <?php
            // Force-register WordPress core login styles for the PAGE BACKGROUND so
            // the emergency screen matches wp-login.php exactly.
            wp_register_style('login', admin_url('css/login.min.css'), array(), null);
            wp_enqueue_style('login');
            // render_login_head() loads our shared login-styles.css and prints all
            // enqueued styles once, so the card and the WP background render together.
            WPOmniAuth_Login_Guard::render_login_head(true);
            ?>
        </head>
        <body class="login wpomni-oauth-page wpomni-emergency-page">
            <?php
            // Status messages surface as floating toasts so the card stays compact.
            $wpomni_toast = null;
            if ($keyerror) {
                $wpomni_toast = array('type' => 'error', 'msg' => __('Incorrect emergency key. Please try again.', 'wp-omni-auth'));
            } elseif ($capterror) {
                $wpomni_toast = array('type' => 'error', 'msg' => __('Incorrect verification code. Please try again.', 'wp-omni-auth'));
            } elseif ($linkerror) {
                $wpomni_toast = array('type' => 'error', 'msg' => __('We could not send the email. Please check your mail configuration or use the manual key below.', 'wp-omni-auth'));
            } elseif ($linkfail) {
                $wpomni_toast = array('type' => 'error', 'msg' => __('This login link is invalid or has expired (wrong IP or timeout). Please request a new one.', 'wp-omni-auth'));
            } elseif ($linksent) {
                $wpomni_toast = array('type' => 'success', 'msg' => __('If that email is authorized, a one-time login link has been sent.', 'wp-omni-auth'));
            }
            ?>
            <div id="wpomni-toast-host" class="wpomni-toast-host" aria-live="polite" aria-atomic="true"></div>
            <main class="wpomni-oauth-card">

                <h1 class="wpomni-oauth-title"><?php esc_html_e('Emergency Access', 'wp-omni-auth'); ?></h1>

                <!-- Step 1: choose a method -->
                <div class="wpomni-emergency-choice" id="wpomni-choice">
                    <p class="wpomni-oauth-intro"><?php esc_html_e('Choose how to regain password login:', 'wp-omni-auth'); ?></p>
                    <div class="wpomni-emergency-options">
                        <button type="button" class="wpomni-btn wpomni-btn-email" id="wpomni-opt-email" aria-expanded="false" data-provider-name="<?php esc_attr_e('Email', 'wp-omni-auth'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <span class="wpomni-btn-label"><?php esc_html_e('Email login link', 'wp-omni-auth'); ?></span>
                        </button>
                        <button type="button" class="wpomni-btn wpomni-btn-key" id="wpomni-opt-key" aria-expanded="false" data-provider-name="<?php esc_attr_e('Key', 'wp-omni-auth'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            <span class="wpomni-btn-label"><?php esc_html_e('Emergency key', 'wp-omni-auth'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- A. Email login link (hidden until chosen) -->
                <section class="wpomni-emergency-block" id="wpomni-email-block" hidden>
                    <h2 class="wpomni-emergency-h"><?php esc_html_e('Request a login link', 'wp-omni-auth'); ?></h2>
                    <p class="wpomni-oauth-intro">
                        <?php esc_html_e('Enter your admin email to receive a one-time link (valid 15 min).', 'wp-omni-auth'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(wp_login_url() . '?wpomni_emergency=1&action=email'); ?>" id="wpomni-request-form">
                        <p class="wpomni-emergency-field">
                            <label for="wpomni_email"><?php esc_html_e('Email', 'wp-omni-auth'); ?></label>
                            <input type="email" name="wpomni_email" id="wpomni_email" autocomplete="off" required>
                        </p>
                        <div class="wpomni-emergency-captcha">
                            <img class="wpomni-captcha-img" src="<?php echo esc_attr($captcha_img); ?>" alt="<?php esc_attr_e('Human verification code', 'wp-omni-auth'); ?>">
                            <p class="wpomni-emergency-field wpomni-captcha-field">
                                <label for="wpomni_captcha"><?php esc_html_e('Code', 'wp-omni-auth'); ?></label>
                                <input type="text" name="wpomni_captcha" id="wpomni_captcha"
                                    <?php if ($captcha_is_arithmetic) : ?>
                                        inputmode="numeric" pattern="[0-9]*" data-arithmetic="1"
                                    <?php else : ?>
                                        inputmode="text" autocapitalize="characters" pattern="[A-Za-z0-9]*" data-arithmetic="0" data-alnum="1"
                                    <?php endif; ?>
                                    autocomplete="off" required>
                            </p>
                        </div>
                        <p class="wpomni-emergency-msg is-error wpomni-captcha-error" id="wpomni-captcha-error" hidden><?php echo $captcha_is_arithmetic ? esc_html__('Please enter the number shown (digits only, 100 or less).', 'wp-omni-auth') : esc_html__('Please enter the code shown (letters and numbers only).', 'wp-omni-auth'); ?></p>
                        <input type="hidden" name="wpomni_captcha_token" value="<?php echo esc_attr($captcha_token); ?>">
                        <?php wp_nonce_field('wpomni_emergency_request'); ?>
                        <p class="wpomni-hp" aria-hidden="true">
                            <label for="wpomni_website"><?php esc_html_e('Website', 'wp-omni-auth'); ?></label>
                            <input type="text" name="wpomni_website" id="wpomni_website" tabindex="-1" autocomplete="off">
                        </p>
                        <button type="submit" name="wp-submit" class="wpomni-btn"><?php esc_html_e('Send link', 'wp-omni-auth'); ?></button>
                    </form>
                    <a href="#" class="wpomni-emergency-back" data-wpomni-back-choice><?php esc_html_e('Back to choice', 'wp-omni-auth'); ?></a>
                </section>

                <!-- B. Manual key (hidden until chosen) -->
                <section class="wpomni-emergency-block" id="wpomni-key-block" hidden>
                    <h2 class="wpomni-emergency-h"><?php esc_html_e('Or use your emergency key', 'wp-omni-auth'); ?></h2>
                    <p class="wpomni-oauth-intro">
                        <?php esc_html_e('Enter the emergency access key to temporarily enable password login.', 'wp-omni-auth'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(wp_login_url() . '?wpomni_emergency=1'); ?>" id="wpomni-key-form">
                        <p class="wpomni-emergency-field">
                            <label for="wpomni_key"><?php esc_html_e('Emergency Key', 'wp-omni-auth'); ?></label>
                            <input type="password" name="wpomni_key" id="wpomni_key" autocomplete="off" required>
                        </p>
                        <div class="wpomni-emergency-captcha">
                            <img class="wpomni-captcha-img" src="<?php echo esc_attr($key_captcha_img); ?>" alt="<?php esc_attr_e('Human verification code', 'wp-omni-auth'); ?>">
                            <p class="wpomni-emergency-field wpomni-captcha-field">
                                <label for="wpomni_captcha"><?php esc_html_e('Code', 'wp-omni-auth'); ?></label>
                                <input type="text" name="wpomni_captcha" id="wpomni_key_captcha"
                                    <?php if ($captcha_is_arithmetic) : ?>
                                        inputmode="numeric" pattern="[0-9]*" data-arithmetic="1"
                                    <?php else : ?>
                                        inputmode="text" autocapitalize="characters" pattern="[A-Za-z0-9]*" data-alnum="1"
                                    <?php endif; ?>
                                    autocomplete="off" required>
                            </p>
                        </div>
                        <p class="wpomni-emergency-msg is-error wpomni-captcha-error" hidden><?php echo $captcha_is_arithmetic ? esc_html__('Please enter the number shown (digits only, 100 or less).', 'wp-omni-auth') : esc_html__('Please enter the code shown (letters and numbers only).', 'wp-omni-auth'); ?></p>
                        <input type="hidden" name="wpomni_captcha_token" value="<?php echo esc_attr($key_captcha_token); ?>">
                        <?php wp_nonce_field('wpomni_emergency_verify'); ?>
                        <p class="wpomni-hp" aria-hidden="true">
                            <label for="wpomni_website2"><?php esc_html_e('Website', 'wp-omni-auth'); ?></label>
                            <input type="text" name="wpomni_website" id="wpomni_website2" tabindex="-1" autocomplete="off">
                        </p>
                        <button type="submit" name="wp-submit" class="wpomni-btn"><?php esc_html_e('Verify', 'wp-omni-auth'); ?></button>
                    </form>
                    <a href="#" class="wpomni-emergency-back" data-wpomni-back-choice><?php esc_html_e('Back to choice', 'wp-omni-auth'); ?></a>
                </section>

                <p class="wpomni-oauth-footer"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php echo sprintf(__('&larr; Back to %s', 'wp-omni-auth'), esc_html($site_name)); ?></a></p>
            </main>

            <script>
                (function () {
                    // Two-step chooser: pick a method, then reveal only its card.
                    var choice     = document.getElementById('wpomni-choice');
                    var emailBlock = document.getElementById('wpomni-email-block');
                    var keyBlock   = document.getElementById('wpomni-key-block');
                    var optEmail   = document.getElementById('wpomni-opt-email');
                    var optKey     = document.getElementById('wpomni-opt-key');

                    // Reveal a panel and (re)start its entrance animation. Toggling
                    // the `hidden` attr restarts CSS animations in most browsers, but a
                    // forced reflow guarantees the slide+fade plays on every click.
                    function wpomniReveal(block) {
                        block.hidden = false;
                        block.style.animation = 'none';
                        void block.offsetWidth;
                        block.style.animation = '';
                    }

                    function showOnly(which) {
                        if (which === 'email') {
                            choice.hidden = true;
                            wpomniReveal(emailBlock);
                            keyBlock.hidden = true;
                            optEmail.setAttribute('aria-expanded', 'true');
                            optKey.setAttribute('aria-expanded', 'false');
                        } else if (which === 'key') {
                            choice.hidden = true;
                            wpomniReveal(keyBlock);
                            emailBlock.hidden = true;
                            optKey.setAttribute('aria-expanded', 'true');
                            optEmail.setAttribute('aria-expanded', 'false');
                        } else {
                            choice.hidden = false;
                            emailBlock.hidden = true;
                            keyBlock.hidden = true;
                            optEmail.setAttribute('aria-expanded', 'false');
                            optKey.setAttribute('aria-expanded', 'false');
                        }
                    }

                    // Proportional fit ("等比缩小"): scale the whole card down on small
                    // or short windows so the entire interface shrinks together. Uses
                    // the `scale` property (composes with the transform-based entrance
                    // animations). Unsupported browsers fall back to the @supports
                    // breakpoint overrides in login-styles.css.
                    function wpomniFitCard() {
                        var card = document.querySelector('body.wpomni-oauth-page .wpomni-oauth-card');
                        if (!card || !('scale' in card.style)) {
                            return;
                        }
                        var vw = window.innerWidth  || document.documentElement.clientWidth;
                        var vh = window.innerHeight || document.documentElement.clientHeight;
                        var s = Math.min(1, vw / 560, vh / 640);
                        card.style.scale = s;
                    }
                    window.addEventListener('resize', wpomniFitCard);
                    wpomniFitCard();

                    if (optEmail) {
                        optEmail.addEventListener('click', function () { showOnly('email'); });
                    }
                    if (optKey) {
                        optKey.addEventListener('click', function () { showOnly('key'); });
                    }
                    var backs = document.querySelectorAll('[data-wpomni-back-choice]');
                    backs.forEach(function (b) {
                        b.addEventListener('click', function (e) {
                            e.preventDefault();
                            showOnly('choice');
                        });
                    });

                    // Pre-validate the CAPTCHA answer on every emergency form so
                    // malformed input never reaches the server (defense in depth).
                    function wpomniBindCaptcha(form) {
                        if (!form) {
                            return;
                        }
                        var input = form.querySelector('input[name="wpomni_captcha"]');
                        var err   = form.querySelector('.wpomni-captcha-error');
                        if (!input) {
                            return;
                        }
                        form.addEventListener('submit', function (e) {
                            var raw = input.value.replace(/\s+/g, '');
                            var ok;
                            if (input.dataset.arithmetic === '1') {
                                ok = /^\d+$/.test(raw) && parseInt(raw, 10) <= 100;
                            } else if (input.dataset.alnum === '1') {
                                ok = /^[A-Za-z0-9]+$/.test(raw) && raw.length <= 16;
                            } else {
                                ok = raw.length > 0 && raw.length <= 32;
                            }
                            if (!ok) {
                                e.preventDefault();
                                if (err) {
                                    err.hidden = false;
                                }
                                input.focus();
                                return false;
                            }
                            if (err) {
                                err.hidden = true;
                            }
                        });
                    }
                    wpomniBindCaptcha(document.getElementById('wpomni-request-form'));
                    wpomniBindCaptcha(document.getElementById('wpomni-key-form'));

                    // Floating toast notifications — set from the query flags.
                    function wpomniShowToast(type, msg) {
                        var host = document.getElementById('wpomni-toast-host');
                        if (!host || !msg) {
                            return;
                        }
                        var el = document.createElement('div');
                        el.className = 'wpomni-toast wpomni-toast-' + (type || 'info');
                        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
                        el.textContent = msg;
                        host.appendChild(el);
                        el.offsetWidth;
                        requestAnimationFrame(function () {
                            el.classList.add('is-visible');
                        });
                        var hide = function () {
                            el.classList.remove('is-visible');
                            window.setTimeout(function () {
                                if (el.parentNode) {
                                    el.parentNode.removeChild(el);
                                }
                            }, 450);
                        };
                        el.addEventListener('click', hide);
                        window.setTimeout(hide, 4500);
                    }

                    var wpomniToastData = <?php echo wp_json_encode($wpomni_toast); ?>;
                    if (wpomniToastData && wpomniToastData.msg) {
                        wpomniShowToast(wpomniToastData.type, wpomniToastData.msg);
                    }

                    // Progressive-enhancement fade-out on "Back to login" navigation.
                    document.addEventListener('click', function (e) {
                        if (document.body.classList.contains('wpomni-leaving')) {
                            return;
                        }
                        var a = e.target.closest('a');
                        // In-page actions (e.g. "back to choice") are handled by
                        // their own listeners; don't trigger the leave-navigation.
                        if (a && a.hasAttribute('data-wpomni-back-choice')) {
                            return;
                        }
                        if (!a || !a.getAttribute('href') || (a.target && a.target !== '_self')) {
                            return;
                        }
                        e.preventDefault();
                        if (a.blur) {
                            a.blur();
                        }
                        document.body.classList.add('wpomni-leaving');
                        var href = a.href;
                        window.setTimeout(function () {
                            window.location.href = href;
                        }, 400);
                    });
                })();
            </script>
        </body>
        </html>
