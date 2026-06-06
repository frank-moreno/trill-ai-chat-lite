/**
 * Trill Chat Lite — Admin Scripts
 *
 * Handles admin UI interactions: settings save, colour preview, etc.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

/* global jQuery, trclAdmin */
(function ($) {
    'use strict';

    /**
     * Admin module.
     */
    const TCLAdmin = {

        /**
         * Initialise admin scripts.
         */
        init: function () {
            this.bindEvents();
            this.initColourPreview();
            this.initAppearanceControls();
        },

        /**
         * Map of colour field IDs to the CSS custom property each one
         * drives in the live preview (v2.1).
         */
        colorVarMap: {
            trcl_widget_color:       '--trcl-primary',
            trcl_widget_color_hover: '--trcl-primary-hover',
            trcl_user_bubble_color:  '--trcl-user-bubble',
            trcl_ai_bubble_color:    '--trcl-ai-bubble',
            trcl_header_text_color:  '--trcl-header-text',
            trcl_body_text_color:    '--trcl-body-text',
            trcl_widget_bg_color:    '--trcl-window-bg'
        },

        /**
         * Map of range slider IDs to preview CSS custom properties (px).
         */
        rangeVarMap: {
            trcl_widget_width:         '--trcl-widget-width',
            trcl_widget_height:        '--trcl-widget-height',
            trcl_widget_border_radius: '--trcl-radius'
        },

        /**
         * Set a CSS custom property on the live preview container.
         *
         * @param {string} prop  Custom property name (--trcl-*).
         * @param {string} value Value, or empty string to remove.
         */
        setPreviewVar: function (prop, value) {
            var pv = document.getElementById('trcl-pv');
            if (!pv) {
                return;
            }
            if (value) {
                pv.style.setProperty(prop, value);
            } else {
                pv.style.removeProperty(prop);
            }
        },

        /**
         * Initialise the Appearance tab controls (v2.1):
         * wp-color-picker on colour fields, live px read-outs next to the
         * range sliders, media-library avatar picker, and live preview
         * bindings for every control.
         */
        initAppearanceControls: function () {
            var self = this;

            // Colour pickers (wp-color-picker is enqueued only on the
            // settings screen; guard so other admin pages don't error).
            var $colors = $('.trcl-color-field');
            if ($colors.length && typeof $.fn.wpColorPicker === 'function') {
                $colors.each(function () {
                    var id = this.id;
                    var cssVar = self.colorVarMap[id];

                    $(this).wpColorPicker({
                        change: function (event, ui) {
                            if (cssVar && ui && ui.color) {
                                self.setPreviewVar(cssVar, ui.color.toString());
                            }
                        },
                        clear: function () {
                            var fallback = $('#' + id).data('default-color');
                            if (cssVar && fallback) {
                                self.setPreviewVar(cssVar, String(fallback));
                            }
                        }
                    });
                });
            }

            // Range sliders — live value display + preview var.
            $(document).on('input change', '.trcl-range', function () {
                var $out = $(this).siblings('.trcl-range-value').first();
                if ($out.length) {
                    $out.text($(this).val() + ($out.data('suffix') || ''));
                }
                var cssVar = self.rangeVarMap[this.id];
                if (cssVar) {
                    self.setPreviewVar(cssVar, $(this).val() + 'px');
                }
            });

            // Assistant name → preview header (text node, jQuery .text()
            // escapes by design).
            $(document).on('input', '#trcl_assistant_name', function () {
                var name = $.trim($(this).val()) || $(this).attr('placeholder') || 'Robin';
                $('.trcl-pv-name').text(name);
            });

            // Welcome message → first AI bubble.
            $(document).on('input', '#trcl_welcome_message', function () {
                var $bubble = $('#trcl-pv-welcome');
                if (!$bubble.length) {
                    return;
                }
                var text = $.trim($(this).val());
                if (text) {
                    $bubble.text(text);
                } else if ($bubble.data('fallback')) {
                    $bubble.text($bubble.data('fallback'));
                }
            });

            // Stash the server-rendered default greeting so clearing the
            // textarea restores it in the preview.
            var $welcomeBubble = $('#trcl-pv-welcome');
            if ($welcomeBubble.length) {
                $welcomeBubble.data('fallback', $welcomeBubble.text());
            }

            // Font select → preview font-family (stacks come from the
            // server-side whitelist via data-stack, never typed by hand).
            $(document).on('change', '#trcl_widget_font', function () {
                var stack = $(this).find('option:selected').data('stack') || '';
                var pv = document.getElementById('trcl-pv');
                if (pv) {
                    pv.style.fontFamily = stack ? String(stack) : '';
                }
            });

            // Launcher style → preview launcher swatch.
            $(document).on('change', 'input[name="trcl_launcher_style"]', function () {
                var isBubble = $(this).val() === 'bubble';
                $('#trcl-pv-launcher-brand').toggle(!isBubble);
                $('#trcl-pv-launcher-bubble').toggle(isBubble);
            });

            // Avatar — media library picker.
            this.initAvatarPicker();
        },

        /**
         * Media-library avatar picker (v2.1 APP-03).
         */
        initAvatarPicker: function () {
            var frame = null;

            $(document).on('click', '#trcl-avatar-upload', function (e) {
                e.preventDefault();

                if (typeof wp === 'undefined' || !wp.media) {
                    return;
                }

                if (!frame) {
                    frame = wp.media({
                        title: 'Select an avatar',
                        library: { type: 'image' },
                        multiple: false
                    });

                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;

                        $('#trcl_custom_avatar_id').val(att.id);
                        $('#trcl-avatar-preview img').attr('src', url);
                        $('.trcl-pv-avatar img').attr('src', url);
                        $('#trcl-avatar-remove').show();
                    });
                }

                frame.open();
            });

            $(document).on('click', '#trcl-avatar-remove', function (e) {
                e.preventDefault();

                var fallback = $('#trcl-avatar-preview').data('default');

                $('#trcl_custom_avatar_id').val('0');
                if (fallback) {
                    $('#trcl-avatar-preview img').attr('src', fallback);
                    $('.trcl-pv-avatar img').attr('src', fallback);
                }
                $(this).hide();
            });
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function () {
            // Settings form AJAX save.
            $(document).on('submit', '#trcl-settings-form', this.handleSettingsSave.bind(this));

            // Colour picker change.
            $(document).on('input change', '#trcl_widget_color', this.updateColourPreview.bind(this));

            // Product reindex button.
            $(document).on('click', '#trcl-reindex-btn', this.handleReindex.bind(this));
        },

        /**
         * Handle product reindex button click.
         */
        handleReindex: function () {
            var $btn    = $('#trcl-reindex-btn');
            var $status = $('#trcl-reindex-status');
            var strings = trclAdmin.strings || {};

            $btn.prop('disabled', true).text(strings.indexing || 'Indexing...');
            $status.show().css('color', '#50575e').text(strings.please_wait || 'Please wait...');

            $.post(trclAdmin.ajaxurl, {
                action: 'trcl_reindex_products',
                nonce:  trclAdmin.nonce
            }, function (response) {
                if (response.success) {
                    $status.css('color', '#00a32a').text(response.data.message);
                    // Reload after a short delay to refresh the table.
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    $status.css('color', '#d63638').text(response.data.message || strings.indexing_failed || 'Indexing failed.');
                }
            }).fail(function () {
                $status.css('color', '#d63638').text(strings.request_failed || 'Request failed. Please try again.');
            }).always(function () {
                $btn.prop('disabled', false).text(strings.reindex_now || 'Reindex Products Now');
            });
        },

        /**
         * Handle settings form AJAX save.
         *
         * @param {Event} e Submit event.
         */
        handleSettingsSave: function (e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $button = $form.find('.button-primary');
            var originalText = $button.val();

            $button.val(trclAdmin.strings.saving || 'Saving...').prop('disabled', true);

            $.ajax({
                url: trclAdmin.ajax_url,
                type: 'POST',
                data: $form.serialize(),
                success: function (response) {
                    if (response.success) {
                        TCLAdmin.showNotice('success', response.data.message || 'Settings saved.');
                    } else {
                        TCLAdmin.showNotice('error', response.data.message || 'Failed to save settings.');
                    }
                },
                error: function () {
                    TCLAdmin.showNotice('error', 'Connection error. Please try again.');
                },
                complete: function () {
                    $button.val(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Initialise colour preview.
         */
        initColourPreview: function () {
            var $input = $('#trcl_widget_color');
            if ($input.length) {
                this.updateColourPreview({ currentTarget: $input[0] });
            }
        },

        /**
         * Update colour preview swatch.
         *
         * @param {Event} e Input event.
         */
        updateColourPreview: function (e) {
            var colour = $(e.currentTarget).val();
            var $preview = $('.trcl-colour-preview');

            if ($preview.length && colour) {
                $preview.css('background-color', colour);
            }
        },

        /**
         * Show an admin notice.
         *
         * @param {string} type    Notice type (success|error|warning|info).
         * @param {string} message Notice message.
         */
        showNotice: function (type, message) {
            var $notice = $(
                '<div class="notice notice-' + type + ' is-dismissible">' +
                '<p>' + $('<span>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss">' +
                '<span class="screen-reader-text">Dismiss</span>' +
                '</button>' +
                '</div>'
            );

            // Remove existing notices.
            $('.trcl-settings-wrap .notice, .trcl-dashboard-wrap .notice').remove();

            // Insert notice.
            var $heading = $('h1').first();
            if ($heading.length) {
                $heading.after($notice);
            }

            // Auto-dismiss after 5 seconds.
            setTimeout(function () {
                $notice.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 5000);

            // Manual dismiss.
            $notice.find('.notice-dismiss').on('click', function () {
                $notice.fadeOut(300, function () {
                    $(this).remove();
                });
            });
        }
    };

    // Initialise on DOM ready.
    $(document).ready(function () {
        TCLAdmin.init();
    });

})(jQuery);
