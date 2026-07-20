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
            <title><?php echo esc_html(sprintf(__('Sign in &lsaquo; %s', 'wp-omni-auth'), $site_name)); ?></title>
            <?php WPOmniAuth_Login_Guard::render_login_head(true); ?>
        </head>
        <body class="login wpomni-oauth-page">
            <main class="wpomni-oauth-card">
                <?php echo $error_html; ?>

                <h1 class="wpomni-oauth-title"><?php echo esc_html($site_name); ?></h1>
                <p class="wpomni-oauth-intro"><?php esc_html_e('Choose a provider to continue.', 'wp-omni-auth'); ?></p>
                <div class="wpomni-oauth-buttons">
                    <?php echo $buttons; ?>
                </div>
                <p class="wpomni-oauth-emergency">
                    <a href="<?php echo esc_url($emergency_url); ?>"><?php esc_html_e('Can&rsquo;t sign in? Use emergency access.', 'wp-omni-auth'); ?></a>
                </p>
                <p class="wpomni-oauth-footer"><a href="<?php echo esc_url($home_url); ?>"><?php echo esc_html(sprintf(__('&larr; Back to %s', 'wp-omni-auth'), $site_name)); ?></a></p>
            </main>

            <script>
                // Translations for the inline script below (no registered script
                // handle on the login screen, so we localize them here via PHP).
                var wpOmniAuthI18n = <?php echo wp_json_encode(array(
                    'redirecting'        => __('Redirecting to %s', 'wp-omni-auth'),
                    'redirecting_status' => __('Redirecting', 'wp-omni-auth'),
                )); ?>;
                // Global fade-out: when any link inside the card is clicked,
                // ease the whole screen out before navigating (progressive
                // enhancement — links still work without JS). If the click does
                // not actually leave the page (e.g. a same-page anchor, or the
                // navigation is blocked), the fade is released so the screen is
                // never left stuck/floated.
                (function () {
                    var body = document.body;

                    function isSamePage(href) {
                        if (!href || href.charAt(0) === '#') {
                            return true;
                        }
                        try {
                            var url = new URL(href, window.location.href);
                            return url.origin === window.location.origin
                                && url.pathname === window.location.pathname
                                && url.search === window.location.search;
                        } catch (e) {
                            return false;
                        }
                    }

                    document.addEventListener('click', function (e) {
                        if (body.classList.contains('wpomni-leaving')) {
                            return;
                        }
                        var a = e.target.closest('a');
                        if (!a || !a.getAttribute('href') || (a.target && a.target !== '_self')) {
                            return;
                        }

                        // Login-method buttons: swap the card's contents for a
                        // "redirecting" state (spinner + message) in place, then
                        // navigate — instead of fading the whole screen out.
                        if (a.classList.contains('wpomni-btn')) {
                            e.preventDefault();
                            if (a.blur) {
                                a.blur();
                            }
                            var card = document.querySelector('.wpomni-oauth-card');
                            var href = a.href;
                            var iconSvg = a.querySelector('svg');
                            var icon = iconSvg ? iconSvg.outerHTML : '';
                            var name = a.getAttribute('data-provider-name') || '';
                            if (card) {
                                // Lock the card height so swapping in the (shorter)
                                // redirect state does NOT resize the card — elements
                                // switch in place inside the same blank card.
                                card.style.minHeight = card.offsetHeight + 'px';
                                // Quick fade-out of the current card content,
                                // then swap in the redirecting state (which fades
                                // in via CSS) — a smooth "switch".
                                card.classList.add('switching');
                                window.setTimeout(function () {
                                    card.innerHTML = ''
                                        + '<div class="wpomni-redirecting">'
                                        +   (icon ? '<div class="wpomni-redirect-icon">' + icon + '</div>' : '')
                                        +   '<p class="wpomni-redirecting-text">' + wpOmniAuthI18n.redirecting.replace('%s', name) + '</p>'
                                        +   '<div class="wpomni-spinner" role="status" aria-label="' + wpOmniAuthI18n.redirecting_status + '"></div>'
                                        + '</div>';
                                    card.classList.remove('switching');
                                }, 180);
                            }
                            window.setTimeout(function () {
                                window.location.href = href;
                            }, 300);
                            return;
                        }

                        // Other links (emergency / back-to-site): keep the
                        // existing fade-out, then navigate.
                        e.preventDefault();
                        if (a.blur) {
                            a.blur();
                        }
                        body.classList.add('wpomni-leaving');
                        var href2 = a.href;
                        window.setTimeout(function () {
                            if (isSamePage(href2)) {
                                // Same-page link: don't navigate away, just
                                // release the fade so the card returns to rest.
                                body.classList.remove('wpomni-leaving');
                                return;
                            }
                            // Navigate. Keep the fade in place until the old page
                            // actually unloads — a slow emergency page must NOT
                            // flash the login card back (which would make the user
                            // think the click did nothing). The script dies with
                            // the old document on a successful navigation, so no
                            // timer-based release is needed (one would misfire on a
                            // slow load, since the old doc is still "visible").
                            window.location.href = href2;
                        }, 400);
                    });

                    // Recovery: if we land back on this exact document still
                    // faded (e.g. a bfcache restore of the login page
                    // after navigating away), release the fade so the card
                    // returns to rest instead of looking stuck/blank.
                    window.addEventListener('pageshow', function () {
                        if (body.classList.contains('wpomni-leaving')) {
                            body.classList.remove('wpomni-leaving');
                        }
                    });
                })();
            </script>
        </body>
        </html>
