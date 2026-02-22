/**
 * Trill Chat Lite — Admin Scripts
 *
 * Handles admin UI interactions: settings save, colour preview, etc.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

/* global jQuery, tclAdmin */
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
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function () {
            // Settings form AJAX save.
            $(document).on('submit', '#tcl-settings-form', this.handleSettingsSave.bind(this));

            // Colour picker change.
            $(document).on('input change', '#tclw_widget_color', this.updateColourPreview.bind(this));

            // Product reindex button.
            $(document).on('click', '#tcl-reindex-btn', this.handleReindex.bind(this));
        },

        /**
         * Handle product reindex button click.
         */
        handleReindex: function () {
            var $btn    = $('#tcl-reindex-btn');
            var $status = $('#tcl-reindex-status');
            var strings = tclAdmin.strings || {};

            $btn.prop('disabled', true).text(strings.indexing || 'Indexing...');
            $status.show().css('color', '#50575e').text(strings.please_wait || 'Please wait...');

            $.post(tclAdmin.ajaxurl, {
                action: 'tclw_reindex_products',
                nonce:  tclAdmin.nonce
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

            $button.val(tclAdmin.strings.saving || 'Saving...').prop('disabled', true);

            $.ajax({
                url: tclAdmin.ajax_url,
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
            var $input = $('#tclw_widget_color');
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
            var $preview = $('.tcl-colour-preview');

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
            $('.tcl-settings-wrap .notice, .tcl-dashboard-wrap .notice').remove();

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
