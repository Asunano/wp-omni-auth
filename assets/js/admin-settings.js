(function ($) {
    'use strict';

    // Translation helper: use named keys from wpomni_admin.i18n
    var _admin = (typeof wpomni_admin !== 'undefined') ? wpomni_admin : {};
    var i18n = _admin.i18n || {};
    function __(key) {
        return i18n[key] || key;
    }

    // Fallback for ajaxurl if not defined by WordPress admin
    if (typeof ajaxurl === 'undefined') {
        window.ajaxurl = (typeof _admin.ajaxurl !== 'undefined') ? _admin.ajaxurl : '/wp-admin/admin-ajax.php';
    }

    // ============================================================
    // Modal Helpers
    // ============================================================
    function openModal(id) {
        $(id).addClass('active');
        var $input = $(id).find('input[type="text"]:visible');
        if ($input.length) {
            setTimeout(function () { $input.focus(); }, 50);
        }
    }

    function closeModal(id) {
        $(id).removeClass('active');
        $(id).find('input[type="text"]').val('');
    }

    function closeAllModals() {
        $('.wpomni-modal-overlay').removeClass('active');
        $('.wpomni-modal-overlay').find('input[type="text"]').val('');
    }

    $(document).ready(function () {
        // ============================================================
        // Provider List → Detail Navigation (with URL deep-link)
        // ============================================================
        // Show a provider's detail view and persist it in the URL (?provider=slug)
        // so a page refresh / bind-redirect keeps the config open.
        function openProviderDetail(slug) {
            var $detail = $('#wpomni-detail-' + slug);
            if (!$detail.length) {
                return;
            }
            $('#wpomni-provider-list').addClass('hidden');
            $('.wpomni-provider-detail').removeClass('active');
            $detail.addClass('active');

            try {
                var url = new URL(window.location.href);
                url.searchParams.set('provider', slug);
                window.history.replaceState({}, '', url.toString());
            } catch (err) { /* non-fatal: deep-link is a convenience */ }
        }

        function closeProviderDetail() {
            $('.wpomni-provider-detail').removeClass('active');
            $('#wpomni-provider-list').removeClass('hidden');

            try {
                var url = new URL(window.location.href);
                url.searchParams.delete('provider');
                window.history.replaceState({}, '', url.toString());
            } catch (err) { /* non-fatal */ }
        }

        $(document).on('click', '.wpomni-configure-provider', function () {
            openProviderDetail($(this).data('slug'));
        });

        // ============================================================
        // IP masking eye toggle (dashboard recent-logins table)
        // ============================================================
        $(document).on('click', '.wpomni-ip-toggle', function () {
            var $cell = $(this).closest('.wpomni-ip-cell');
            var $value = $cell.find('.wpomni-ip-value');
            var $masked = $cell.find('.wpomni-ip-masked');
            var $svg = $(this).find('.wpomni-eye-icon');
            var isHidden = $value.is(':visible');

            // Toggle: show hidden IP or hide it back
            $value.toggle();
            $masked.toggle();

            // Swap eye icon between closed (currently shown) and open
            if (isHidden) {
                // Currently visible → switch to closed eye
                $svg.html('<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>');
                $(this).attr('title', $(this).data('show-title') || 'Show');
            } else {
                // Currently hidden → switch to open eye
                $svg.html('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>');
                $(this).attr('title', $(this).data('hide-title') || 'Hide');
            }
        });

        // Back to list
        $(document).on('click', '[data-back-to-list]', function (e) {
            e.preventDefault();
            closeProviderDetail();
        });

        // Top-level tab navigation: the `provider` deep-link must NEVER leak
        // across tabs. Clicking any tab strips it, so switching away from (or
        // back to) Providers shows the plain list instead of auto-opening a
        // previously deep-linked provider. The only ways `provider` stays in the
        // URL are an explicit "configure" click (handled below) or a direct
        // deep-link / bind-redirect hit.
        $(document).on('click', '.wpomni-tab-item', function (e) {
            e.preventDefault();
            try {
                var url = new URL(this.href, window.location.href);
                url.searchParams.delete('provider');
                window.location.href = url.toString();
            } catch (err) {
                window.location.href = this.href;
            }
        });

        // Drop the ?provider=slug deep-link from the address bar whenever we're
        // NOT on the Providers tab, so it doesn't linger as a meaningless
        // suffix (e.g. ?tab=dashboard&provider=github). The server already
        // ignores it for non-providers tabs; this just keeps the URL clean.
        try {
            var cleanParams = new URLSearchParams(window.location.search);
            if (cleanParams.has('provider') && cleanParams.get('tab') !== 'providers') {
                cleanParams.delete('provider');
                var cleanQuery = cleanParams.toString();
                var cleanUrl = window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash;
                window.history.replaceState({}, '', cleanUrl);
            }
        } catch (err) { /* non-fatal */ }

        // Auto-open the deep-linked provider on load (e.g. after a refresh or
        // returning from "Test connection & bind"). Only take over when the user
        // is actually on (or hasn't chosen) the Providers tab, so an explicit
        // Dashboard/Settings/About tab is never overridden.
        try {
            var params = new URLSearchParams(window.location.search);
            var deepSlug = params.get('provider');
            var tabParam = params.get('tab');
            if (deepSlug && (tabParam === null || tabParam === 'providers')) {
                // Ensure the Providers tab is the active one.
                $('.wpomni-tab-item').removeClass('active').removeAttr('aria-current');
                $('.wpomni-tab-content').removeClass('active');
                $('.wpomni-tab-item[href*="tab=providers"]').addClass('active').attr('aria-current', 'page');
                $('#wpomni-tab-providers').addClass('active');
                openProviderDetail(deepSlug);
            }
        } catch (err) { /* non-fatal */ }

        // ============================================================
        // Custom Provider: Add (via modal)
        // ============================================================
        $('#wpomni-add-provider').on('click', function () {
            openModal('#wpomni-modal-add');
        });

        // Confirm add
        $('#wpomni-modal-add-confirm').on('click', function () {
            var name = $('#wpomni-new-provider-name').val().trim();
            if (!name) {
                $('#wpomni-new-provider-name').focus();
                return;
            }

            $.post(ajaxurl, {
                action: 'wpomni_add_custom_provider',
                nonce: _admin.nonce,
                name: name
            }, function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(__('failed_add') + (response.data || __('unknown_error')));
                }
            }).fail(function () {
                alert(__('network_error_retry'));
            });

            closeModal('#wpomni-modal-add');
        });

        // ============================================================
        // Custom Provider: Remove (via modal)
        // ============================================================
        $(document).on('click', '.wpomni-remove-provider', function (e) {
            e.preventDefault();
            var slug = $(this).data('slug');
            $('#wpomni-remove-provider-slug').val(slug);
            openModal('#wpomni-modal-remove');
        });

        // Confirm remove
        $('#wpomni-modal-remove-confirm').on('click', function () {
            var slug = $('#wpomni-remove-provider-slug').val();
            if (!slug) return;

            $.post(ajaxurl, {
                action: 'wpomni_remove_custom_provider',
                nonce: _admin.nonce,
                slug: slug
            }, function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(__('failed_remove') + (response.data || __('unknown_error')));
                }
            }).fail(function () {
                alert(__('network_error_retry'));
            });

            closeModal('#wpomni-modal-remove');
        });

        // ============================================================
        // Modal: Close on overlay click / cancel button / ESC
        // ============================================================
        $(document).on('click', '.wpomni-modal-overlay', function (e) {
            if ($(e.target).hasClass('wpomni-modal-overlay')) {
                $(this).removeClass('active');
            }
        });

        $(document).on('click', '[data-modal-close]', function () {
            closeAllModals();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });

        // Enter key in add modal input triggers confirm
        $('#wpomni-new-provider-name').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#wpomni-modal-add-confirm').trigger('click');
            }
        });

        // ============================================================
        // Custom Provider: Icon Preview
        // ============================================================
        function updateIconPreview($input) {
            var $preview = $input.closest('tr').find('[data-icon-preview-area]');
            if (!$preview.length) return;

            var val = $input.val().trim();
            if (!val) {
                $preview.html('<span class="wpomni-text-faint">—</span>');
                return;
            }
            if (/^https?:\/\//i.test(val)) {
                $preview.html('<img src="' + val.replace(/["<>]/g, '') + '" alt="" class="wpomni-preview-img" onerror="this.outerHTML=\'<span class=wpomni-preview-invalid>Invalid URL</span>\'">');
            } else if (val.charAt(0) === '<') {
                $preview.html(val);
            } else {
                $preview.html('<span class="wpomni-text-faint">—</span>');
            }
        }

        // Initialize previews on page load
        $('[data-icon-input]').each(function () {
            updateIconPreview($(this));
        });

        // Live update on input
        $(document).on('input', '[data-icon-input]', function () {
            updateIconPreview($(this));
        });

        // ============================================================
        // Debug Log: View
        // ============================================================
        $('#wpomni-view-log').on('click', function () {
            var $btn = $(this);
            var $logContent = $('#wpomni-log-content');
            var $status = $('#wpomni-log-status');

            $btn.prop('disabled', true);
            $status.text(__('loading')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');

            $.post(ajaxurl, {
                action: 'wpomni_view_log',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    var log = response.data.log || '';
                    if (log.trim() === '') {
                        $logContent.find('textarea').val(__('log_empty_paren'));
                        $status.text(__('log_empty')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');
                    } else {
                        $logContent.find('textarea').val(log);
                        $status.text(__('log_loaded').replace('%s', log.split('\n').length)).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    }
                    $logContent.slideDown(200);
                } else {
                    $status.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function (xhr) {
                $btn.prop('disabled', false);
                var msg = __('network_error');
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    msg = xhr.responseJSON.data;
                }
                $status.text(__('failed') + msg).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // Debug Log: Clear
        // ============================================================
        $('#wpomni-clear-log').on('click', function () {
            if (!confirm(__('confirm_clear_log'))) return;

            var $btn = $(this);
            var $status = $('#wpomni-log-status');

            $btn.prop('disabled', true);
            $status.text(__('clearing')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');

            $.post(ajaxurl, {
                action: 'wpomni_clear_log',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $('#wpomni-log-content textarea').val(__('log_empty_paren'));
                    $status.text(__('log_cleared')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                } else {
                    $status.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function (xhr) {
                $btn.prop('disabled', false);
                var msg = __('network_error');
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    msg = xhr.responseJSON.data;
                }
                $status.text(__('failed') + msg).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // User Mode: Toggle allowlist visibility
        // ============================================================
        $('input[name="wpomni_user_mode"]').on('change', function () {
            var mode = $(this).filter(':checked').val();
            if (mode === 'allowlist') {
                $('#wpomni-user-allowlist').slideDown(150);
            } else {
                $('#wpomni-user-allowlist').slideUp(150);
            }
        });

        // ============================================================
        // Emergency Key: Modal Management
        // ============================================================
        var $keyModal = $('#wpomni-modal-emergency-key');
        var keyHideTimer = null;
        var $keyDisplay = $('#wpomni-modal-key-display');
        var $keyHint = $('#wpomni-modal-key-hint');
        var $keyStatus = $('#wpomni-modal-key-status');
        var originalMasked = '';

        // Open modal — populate with masked key
        $('#wpomni-emergency-key-manager').on('click', function () {
            originalMasked = $(this).data('masked') || '—';
            $keyDisplay.text(originalMasked).css('cursor', 'default');
            $keyHint.hide();
            $keyStatus.text('').removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');
            if (keyHideTimer) { clearTimeout(keyHideTimer); keyHideTimer = null; }
            openModal('#wpomni-modal-emergency-key');
        });

        // View full key via AJAX → replace display inline
        $('#wpomni-modal-view-key').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $keyStatus.text(__('loading')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');

            $.post(ajaxurl, {
                action: 'wpomni_view_emergency_key',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $keyDisplay.text(response.data.key).css('cursor', 'pointer');
                    $keyHint.fadeIn(150);
                    $keyStatus.text('');
                    if (keyHideTimer) clearTimeout(keyHideTimer);
                    keyHideTimer = setTimeout(function () {
                        $keyDisplay.text(originalMasked).css('cursor', 'default');
                        $keyHint.fadeOut(150);
                        $keyStatus.text('');
                    }, 60000);
                } else {
                    $keyStatus.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $keyStatus.text(__('network_error_retry')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // Click key display to copy
        $keyDisplay.on('click', function () {
            var text = $(this).text();
            if (!text || text === '—') return;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () {
                    $keyHint.text(__('copied')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    setTimeout(function () {
                        $keyHint.text(__('click_to_copy')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    }, 2000);
                });
            } else {
                // Fallback: select text for manual copy
                var range = document.createRange();
                range.selectNodeContents(this);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });

        // Regenerate key → open custom confirm modal
        $('#wpomni-modal-regen-key').on('click', function () {
            openModal('#wpomni-modal-confirm-regen');
        });

        // Confirm regeneration
        $('#wpomni-confirm-regen-yes').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $keyStatus.text(__('generating')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');
            closeModal('#wpomni-modal-confirm-regen');

            $.post(ajaxurl, {
                action: 'wpomni_regenerate_emergency_key',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    // Update masked display on button and modal
                    $('#wpomni-emergency-key-manager').data('masked', response.data.masked);
                    originalMasked = response.data.masked;
                    $keyDisplay.text(response.data.masked).css('cursor', 'default');
                    $keyHint.hide();
                    // Show full key briefly for the admin to copy
                    $keyStatus.text(__('key_regenerated')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    if (keyHideTimer) clearTimeout(keyHideTimer);
                    // Auto-show full key for 60s
                    $keyDisplay.text(response.data.full).css('cursor', 'pointer');
                    $keyHint.text(__('click_to_copy')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text').fadeIn(150);
                    keyHideTimer = setTimeout(function () {
                        $keyDisplay.text(originalMasked).css('cursor', 'default');
                        $keyHint.fadeOut(150);
                        $keyStatus.text('');
                    }, 60000);
                } else {
                    $keyStatus.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $keyStatus.text(__('network_error_retry')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // About: Check for Updates
        // ============================================================
        $('#wpomni-check-update').on('click', function () {
            var $btn = $(this);
            var $status = $('#wpomni-update-status');

            $btn.prop('disabled', true);
            $status.text(__('checking')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');

            $.post(ajaxurl, {
                action: 'wpomni_check_update',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    var data = response.data;
                    if (data.has_update) {
                        $status.html(
                            '<span class="wpomni-danger-text">' +
                            __('new_version_available').replace('%s', data.latest_version) +
                            ' <a href="' + data.update_url + '">' + __('go_to_plugins') + '</a></span>'
                        );
                    } else {
                        var msg = __('up_to_date');
                        if (data.latest_version) {
                            msg += ' (' + __('latest') + ': ' + data.latest_version + ')';
                        }
                        $status.html('<span class="wpomni-success-text">' + msg + '</span>');
                    }
                } else {
                    $status.text(__('check_failed')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $status.text(__('network_error_retry')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // About tab: Update source — custom dropdown (mirror) — save immediately
        // ============================================================
        (function () {
            var $select = $('#wpomni-use-mirror');
            if (!$select.length) {
                return;
            }
            var $trigger = $select.find('.wpomni-select-trigger');
            var $value   = $select.find('.wpomni-select-value');
            var $menu    = $select.find('.wpomni-select-menu');
            var $options = $menu.find('.wpomni-select-option');
            var $status  = $('#wpomni-mirror-status');

            function currentVal() {
                return $select.data('value');
            }

            function render() {
                var val = currentVal();
                $options.each(function () {
                    var $opt = $(this);
                    var selected = ($opt.data('value') === val);
                    $opt.attr('aria-selected', selected ? 'true' : 'false');
                    if (selected) {
                        $value.text($opt.find('.wpomni-select-option-label').text());
                    }
                });
            }

            function openMenu() {
                $select.addClass('is-open');
                $trigger.attr('aria-expanded', 'true');
                $menu.removeAttr('hidden').show();
                $options.removeClass('is-active');
                $options.filter('[aria-selected="true"]').addClass('is-active');
            }

            function closeMenu() {
                $select.removeClass('is-open');
                $trigger.attr('aria-expanded', 'false');
                $menu.attr('hidden', 'hidden').hide();
            }

            function toggleMenu() {
                if ($select.hasClass('is-open')) {
                    closeMenu();
                } else {
                    openMenu();
                }
            }

            function save(val) {
                $status.text(__('saving')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');
                $.post(ajaxurl, {
                    action: 'wpomni_save_mirror',
                    nonce: _admin.nonce,
                    wpomni_use_mirror: val
                }, function (response) {
                    if (response.success) {
                        $select.data('value', val);
                        render();
                        $status.text(__('saved')).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    } else {
                        $status.text(__('save_failed')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                    }
                    setTimeout(function () {
                        $status.text('').removeClass('wpomni-success-text wpomni-danger-text');
                    }, 2000);
                }).fail(function () {
                    $status.text(__('save_failed')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                });
            }

            $trigger.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });

            $options.on('click', function () {
                var val = $(this).data('value');
                closeMenu();
                if (val !== currentVal()) {
                    save(val);
                }
            });

            $(document).on('click', function (e) {
                if ($select.hasClass('is-open') && !$select[0].contains(e.target)) {
                    closeMenu();
                }
            });

            $(document).on('keydown', function (e) {
                if (!$select.hasClass('is-open')) {
                    return;
                }
                if (e.key === 'Escape') {
                    closeMenu();
                    $trigger.focus();
                } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var idx = $options.index($options.filter('.is-active'));
                    $options.removeClass('is-active');
                    if (e.key === 'ArrowDown') {
                        idx = (idx + 1) % $options.length;
                    } else {
                        idx = (idx - 1 + $options.length) % $options.length;
                    }
                    $options.eq(idx).addClass('is-active');
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var $active = $options.filter('.is-active');
                    if ($active.length) {
                        var val = $active.data('value');
                        closeMenu();
                        if (val !== currentVal()) {
                            save(val);
                        }
                    }
                }
            });

            render();
        })();

        // ============================================================
        // Data Management: Clear Selected
        // ============================================================
        $('#wpomni-clean-selected').on('click', function () {
            var $btn = $(this);
            var $status = $('#wpomni-clean-status');
            var selected = [];
            $('input[name="wpomni_clean[]"]:checked').each(function () {
                selected.push($(this).val());
            });

            if (selected.length === 0) {
                $status.text(__('select_at_least_one')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                return;
            }

            $btn.prop('disabled', true);
            $status.text(__('clearing')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');

            $.post(ajaxurl, {
                action: 'wpomni_clean_data',
                nonce: _admin.nonce,
                categories: selected
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text(response.data.message).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    // Uncheck cleared checkboxes
                    $('input[name="wpomni_clean[]"]:checked').prop('checked', false);
                } else {
                    $status.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $status.text(__('network_error_retry')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // Data Management: Full Reset
        // ============================================================
        $('#wpomni-reset-all-data').on('click', function () {
            openModal('#wpomni-modal-confirm-reset');
        });

        $('#wpomni-confirm-reset-yes').on('click', function () {
            var $btn = $(this);
            var $status = $('#wpomni-reset-status');
            $btn.prop('disabled', true);
            $status.text(__('resetting')).removeClass('wpomni-success-text wpomni-danger-text').addClass('wpomni-muted');
            closeModal('#wpomni-modal-confirm-reset');

            $.post(ajaxurl, {
                action: 'wpomni_reset_all_data',
                nonce: _admin.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text(response.data.message).removeClass('wpomni-muted wpomni-danger-text').addClass('wpomni-success-text');
                    // Reload page after short delay to reflect reset state
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    $status.text(__('failed') + (response.data || __('unknown_error'))).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $status.text(__('network_error_retry')).removeClass('wpomni-muted wpomni-success-text').addClass('wpomni-danger-text');
            });
        });

        // ============================================================
        // Data Management: Import from JSON backup
        // ============================================================
        $('#wpomni-import-data').on('click', function () {
            var $btn = $(this);
            var $file = $('#wpomni-import-file');
            var $status = $('#wpomni-import-status');
            var $result = $('#wpomni-import-result');

            if (!$file[0].files.length) {
                $status.text(__('select_file') || 'Select a file first').css('color', '#d63638');
                return;
            }

            var fd = new FormData();
            fd.append('action', 'wpomni_import_data');
            fd.append('nonce', _admin.nonce);
            fd.append('file', $file[0].files[0]);

            $btn.prop('disabled', true);
            $status.text(__('importing') || 'Importing...').css('color', '');
            $result.empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (response) {
                    $btn.prop('disabled', false);
                    $status.text('');
                    if (response.success) {
                        $result.html('<span class="wpomni-success-text">' + (response.data.message || 'OK') + '</span>');
                        $file.val('');
                    } else {
                        $result.html('<span class="wpomni-danger-text">' + (response.data || response.data.message || 'Error') + '</span>');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false);
                    $status.text('');
                    $result.html('<span class="wpomni-danger-text">' + (__('network_error_retry') || 'Network error') + '</span>');
                }
            });
        });

        // ============================================================
        // Provider settings: AJAX save.
        // Intercept the providers form so the submission goes through
        // admin-ajax.php over the CURRENT page's scheme/origin. This avoids
        // mixed-content failures (admin_url may resolve to http behind a
        // proxy/Cloudflare) and WAF blocks on admin-post.php, and guarantees
        // the user always gets feedback instead of a silent "no reaction".
        // ============================================================
        $('#wpomni-form-providers').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.wpomni-save-btn');
            $btn.prop('disabled', true);

            // Build a same-origin admin-ajax URL: keep only the path from the
            // (possibly http) ajaxurl and prefix it with the current origin.
            var ajaxUrl;
            try {
                var parsed = new URL(ajaxurl, window.location.href);
                ajaxUrl = window.location.origin + parsed.pathname;
            } catch (err) {
                ajaxUrl = window.location.origin + '/wp-admin/admin-ajax.php';
            }

            $.post(ajaxUrl, $form.serialize(), function (response) {
                $btn.prop('disabled', false);
                if (response && response.success) {
                    var toasts = (response.data && response.data.toasts) || [];
                    if (toasts.length) {
                        $.each(toasts, function (i, t) {
                            wpomniToast(t.message, t.type || 'info', { title: t.title });
                        });
                    } else {
                        wpomniToast(__('saved') || 'Saved', 'success');
                    }
                } else {
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : (__('save_failed') || 'Save failed. Please try again.');
                    wpomniToast(msg, 'error');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                wpomniToast(__('network_error_retry') || 'Network error. Please try again.', 'error');
            });
        });

        // ============================================================
        // Provider "Test connection & bind" button.
        // Persist the form first (so the OAuth callback can read the real
        // secrets), then redirect into the real OAuth flow carrying a bind
        // marker. The callback stores the provider-returned stable ID against
        // the current admin — no email required.
        // ============================================================
        $('#wpomni-form-providers').on('click', '.wpomni-test-bind-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $form = $('#wpomni-form-providers');
            var slug = $btn.data('slug');
            var originalLabel = $btn.text();

            var ajaxUrl;
            try {
                var parsed = new URL(ajaxurl, window.location.href);
                ajaxUrl = window.location.origin + parsed.pathname;
            } catch (err) {
                ajaxUrl = window.location.origin + '/wp-admin/admin-ajax.php';
            }

            $btn.prop('disabled', true).text('保存中…');

            $.post(ajaxUrl, $form.serialize(), function (response) {
                if (response && response.success) {
                    wpomniToast(__('saved') || 'Saved', 'success');
                    var sep = (ajaxUrl.indexOf('?') === -1) ? '?' : '&';
                    var bindUrl = ajaxUrl + sep + 'action=wpomni_begin_bind&slug='
                        + encodeURIComponent(slug) + '&nonce=' + encodeURIComponent(_admin.nonce);
                    // Open the OAuth flow in a new tab so the settings page
                    // stays intact in the current tab.
                    window.open(bindUrl, '_blank');
                } else {
                    $btn.prop('disabled', false).text(originalLabel);
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : (__('save_failed') || 'Save failed. Please try again.');
                    wpomniToast(msg, 'error');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(originalLabel);
                wpomniToast(__('network_error_retry') || 'Network error. Please try again.', 'error');
            });
        });
    });

    /* ============================================ */
    /* Toast notifications                          */
    /* ============================================ */
    var TOAST_ICONS = {
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    var TOAST_MAX_VISIBLE = 4;
    var toastQueue = [];
    var toastVisible = 0;
    var toastContainer = null;

    function toastEnsureContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'wpomni-toasts';
            toastContainer.setAttribute('role', 'region');
            toastContainer.setAttribute('aria-live', 'polite');
            // Inline styles as fallback — ensure positioning even if CSS fails to load
            toastContainer.style.cssText = 'position:fixed !important;top:48px !important;right:20px !important;left:auto !important;bottom:auto !important;z-index:999999 !important;display:flex;flex-direction:column;gap:12px;max-width:380px;width:calc(100vw - 40px);pointer-events:none;';
            if (document.body) {
                document.body.appendChild(toastContainer);
            }
        }
        return toastContainer;
    }

    function toastDismiss(el) {
        if (!el || el.classList.contains('is-leaving')) {
            return;
        }
        el.classList.add('is-leaving');
        setTimeout(function () {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
            toastVisible = Math.max(0, toastVisible - 1);
            toastPump();
        }, 200);
    }

    function toastPump() {
        while (toastVisible < TOAST_MAX_VISIBLE && toastQueue.length) {
            toastRender(toastQueue.shift());
        }
    }

    function toastRender(opts) {
        toastEnsureContainer();
        var type = opts.type || 'info';
        var timeout = (typeof opts.timeout === 'number') ? opts.timeout : 6000;
        var el = document.createElement('div');
        el.className = 'wpomni-toast wpomni-toast--' + type;

        var html = '<span class="wpomni-toast__icon">' + (TOAST_ICONS[type] || TOAST_ICONS.info) + '</span>' +
            '<div class="wpomni-toast__body">';
        if (opts.title) {
            html += '<p class="wpomni-toast__title"></p>';
        }
        html += '<p class="wpomni-toast__msg"></p></div>' +
            '<button type="button" class="wpomni-toast__close" aria-label="X">&times;</button>';
        if (timeout > 0) {
            html += '<span class="wpomni-toast__progress"></span>';
        }
        el.innerHTML = html;

        // Safe text assignment (no HTML injection from server strings).
        if (opts.title) {
            el.querySelector('.wpomni-toast__title').textContent = opts.title;
        }
        el.querySelector('.wpomni-toast__msg').textContent = opts.message;

        if (timeout > 0) {
            var prog = el.querySelector('.wpomni-toast__progress');
            prog.style.animation = 'wpomni-toast-progress ' + timeout + 'ms linear forwards';
            var timer = setTimeout(function () { toastDismiss(el); }, timeout);
            el.addEventListener('mouseenter', function () {
                clearTimeout(timer);
                prog.style.animationPlayState = 'paused';
            });
            el.addEventListener('mouseleave', function () {
                prog.style.animationPlayState = 'running';
            });
        }

        el.querySelector('.wpomni-toast__close').addEventListener('click', function () {
            toastDismiss(el);
        });

        toastContainer.appendChild(el);
        toastVisible++;
    }

    function wpomniToast(message, type, opts) {
        opts = opts || {};
        if (typeof type === 'object') {
            opts = type;
            type = opts.type || 'info';
        }
        toastQueue.push({ message: message, type: type || 'info', title: opts.title, timeout: opts.timeout });
        toastPump();
    }

    // Render any toasts queued server-side (localized via wp_localize_script).
    $(function () {
        var initial = (window.wpomni_toasts && window.wpomni_toasts.length) ? window.wpomni_toasts : [];
        $.each(initial, function (i, t) {
            wpomniToast(t.message, t.type || 'info', { title: t.title });
        });
        window.wpomni_toasts = [];
    });

    // Public API for runtime use (e.g. after AJAX responses).
    window.wpomniToast = wpomniToast;
    window.WP_Omni_Toasts = { push: wpomniToast, dismiss: toastDismiss };

})(jQuery);
