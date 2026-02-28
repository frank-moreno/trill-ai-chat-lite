/**
 * Trill Chat Lite — Upgrade CTA Scripts
 *
 * Handles dismiss notice AJAX and upgrade CTA interactions.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

/* global jQuery, trclAdmin */
(function ($) {
    'use strict';

    /**
     * Upsell module.
     */
    var TCLUpsell = {

        /**
         * Initialise upsell scripts.
         */
        init: function () {
            this.bindDismissNotice();
        },

        /**
         * Bind dismiss notice handler.
         *
         * When the WordPress dismissible notice is closed, send AJAX to record dismissal.
         */
        bindDismissNotice: function () {
            $(document).on('click', '.trcl-upgrade-notice .notice-dismiss', function () {
                var $notice = $(this).closest('.trcl-upgrade-notice');
                var nonce = $notice.data('nonce');

                if (!nonce || typeof trclAdmin === 'undefined') {
                    return;
                }

                $.ajax({
                    url: trclAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'trcl_dismiss_notice',
                        nonce: nonce
                    }
                });
            });
        }
    };

    // Initialise on DOM ready.
    $(document).ready(function () {
        TCLUpsell.init();
    });

})(jQuery);
