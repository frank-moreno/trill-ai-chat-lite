/**
 * GSPLTD Chat Lite — Chat Widget
 *
 * Handles the frontend chat widget: message sending, UI rendering,
 * product cards, quick replies, and session management.
 *
 * @package GspltdChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

/* global jQuery, gcl_ajax */
(function ($) {
    'use strict';

    /**
     * Chat Widget module.
     */
    window.GCLChatWidget = {

        /** State */
        sessionId: null,
        isOpen: false,
        isLoading: false,
        limitReached: false,

        /**
         * Initialise the chat widget.
         */
        init: function () {
            if (gcl_ajax.enabled !== '1') {
                return;
            }

            this.render();
            this.bindEvents();
            this.loadSession();
            this.applyThemeColour();
        },

        /**
         * Render the widget HTML.
         */
        render: function () {
            var html = '' +
                '<div class="gcl-chat-widget" id="gcl-chat-widget">' +
                    '<!-- Toggle Button -->' +
                    '<button class="gcl-chat-toggle" id="gcl-chat-toggle" aria-label="' + this.str('chat_with_us') + '">' +
                        '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>' +
                        '</svg>' +
                    '</button>' +
                    '<!-- Chat Window -->' +
                    '<div class="gcl-chat-window" id="gcl-chat-window">' +
                        '<!-- Header -->' +
                        '<div class="gcl-chat-header">' +
                            '<div class="gcl-chat-avatar">R</div>' +
                            '<div class="gcl-chat-header-info">' +
                                '<div class="gcl-chat-header-name">' + this.str('assistant_name') + '</div>' +
                                '<div class="gcl-chat-header-status">' +
                                    '<span class="gcl-status-dot"></span> ' + this.str('online') +
                                '</div>' +
                            '</div>' +
                            '<button class="gcl-chat-close" id="gcl-chat-close" aria-label="' + this.str('close_chat') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Messages -->' +
                        '<div class="gcl-chat-messages" id="gcl-chat-messages"></div>' +
                        '<!-- Quick Replies -->' +
                        '<div class="gcl-quick-replies" id="gcl-quick-replies"></div>' +
                        '<!-- Input -->' +
                        '<div class="gcl-chat-input-area">' +
                            '<input type="text" class="gcl-chat-input" id="gcl-chat-input" placeholder="' + this.str('type_message') + '" maxlength="500" />' +
                            '<button class="gcl-chat-send" id="gcl-chat-send" aria-label="' + this.str('send') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Powered By -->' +
                        (gcl_ajax.branding && gcl_ajax.branding.show_powered_by ?
                            '<a href="' + gcl_ajax.branding.powered_by_url + '" target="_blank" rel="noopener" class="gcl-powered-by">' +
                                gcl_ajax.branding.powered_by_text +
                            '</a>' : '') +
                    '</div>' +
                '</div>';

            $('body').append(html);
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function () {
            var self = this;

            // Toggle chat.
            $(document).on('click', '#gcl-chat-toggle', function () {
                self.toggleWidget();
            });

            // Close chat.
            $(document).on('click', '#gcl-chat-close', function () {
                self.closeWidget();
            });

            // Send message.
            $(document).on('click', '#gcl-chat-send', function () {
                self.sendMessage();
            });

            // Enter key.
            $(document).on('keypress', '#gcl-chat-input', function (e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Quick reply click.
            $(document).on('click', '.gcl-quick-reply', function () {
                var value = $(this).data('value');
                if (value) {
                    $('#gcl-chat-input').val(value);
                    self.sendMessage();
                }
            });

            // Product card add-to-cart.
            $(document).on('click', '.gcl-product-card-action', function (e) {
                e.preventDefault();
                var productId = $(this).data('product-id');
                if (productId) {
                    self.addToCart(productId, $(this));
                }
            });
        },

        /**
         * Toggle widget open/closed.
         */
        toggleWidget: function () {
            if (this.isOpen) {
                this.closeWidget();
            } else {
                this.openWidget();
            }
        },

        /**
         * Open the widget.
         */
        openWidget: function () {
            $('#gcl-chat-window').addClass('gcl-chat-window--open');
            $('#gcl-chat-toggle').hide();
            this.isOpen = true;

            // Show welcome message if no messages.
            if ($('#gcl-chat-messages').children().length === 0) {
                this.addMessage('assistant', this.str('welcome_message'));
            }

            // Focus input.
            setTimeout(function () {
                $('#gcl-chat-input').focus();
            }, 100);
        },

        /**
         * Close the widget.
         */
        closeWidget: function () {
            $('#gcl-chat-window').removeClass('gcl-chat-window--open');
            $('#gcl-chat-toggle').show();
            this.isOpen = false;
        },

        /**
         * Send a message.
         */
        sendMessage: function () {
            var self = this;
            var $input = $('#gcl-chat-input');
            var message = $.trim($input.val());

            if (!message || this.isLoading || this.limitReached) {
                return;
            }

            // Add user message to chat.
            this.addMessage('user', message);
            $input.val('');

            // Show typing indicator.
            this.showTyping();
            this.isLoading = true;
            $('#gcl-chat-send').prop('disabled', true);

            // Clear quick replies.
            $('#gcl-quick-replies').empty();

            // Send API request.
            $.ajax({
                url: gcl_ajax.rest_url + 'message',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    message: message,
                    session_id: this.sessionId || '',
                    context: {
                        page_url: window.location.href,
                        page_title: document.title
                    }
                }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', gcl_ajax.nonce);
                },
                success: function (response) {
                    self.hideTyping();

                    if (response.success) {
                        // Store session ID.
                        if (response.session_id) {
                            self.sessionId = response.session_id;
                            self.saveSession();
                        }

                        // Add AI response.
                        var content = response.message ? response.message.content : response.response;
                        self.addMessage('assistant', content);

                        // Show product cards.
                        if (response.products && response.products.length > 0) {
                            self.renderProductCards(response.products);
                        }

                        // Show quick replies.
                        if (response.quick_replies && response.quick_replies.length > 0) {
                            self.renderQuickReplies(response.quick_replies);
                        }

                        // Check usage.
                        if (response.usage) {
                            self.checkUsageLimit(response.usage);
                        }

                        // Check proxy meta for upgrade prompt.
                        if (response.meta && response.meta.upgrade_prompt) {
                            self.showUpgradePrompt();
                        }
                    } else {
                        self.handleError(response);
                    }
                },
                error: function (xhr) {
                    self.hideTyping();

                    if (xhr.status === 429) {
                        var body = xhr.responseJSON || {};
                        if (body.error_code === 'LIMIT_REACHED') {
                            self.showLimitReached(body.upgrade_url);
                            return;
                        }
                    }

                    self.addMessage('assistant', self.str('error_message'));
                },
                complete: function () {
                    self.isLoading = false;
                    $('#gcl-chat-send').prop('disabled', false);
                }
            });
        },

        /**
         * Add a message to the chat.
         *
         * @param {string} role    Message role (user|assistant).
         * @param {string} content Message content.
         */
        addMessage: function (role, content) {
            var $messages = $('#gcl-chat-messages');
            var sanitised = $('<div>').text(content).html();

            // Basic markdown-like formatting.
            sanitised = sanitised.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            sanitised = sanitised.replace(/\n/g, '<br>');

            var $msg = $('<div class="gcl-message gcl-message--' + role + '">' + sanitised + '</div>');
            $messages.append($msg);
            $messages.scrollTop($messages[0].scrollHeight);
        },

        /**
         * Show typing indicator.
         */
        showTyping: function () {
            var $messages = $('#gcl-chat-messages');
            $messages.append(
                '<div class="gcl-typing" id="gcl-typing">' +
                    '<span class="gcl-typing-dot"></span>' +
                    '<span class="gcl-typing-dot"></span>' +
                    '<span class="gcl-typing-dot"></span>' +
                '</div>'
            );
            $messages.scrollTop($messages[0].scrollHeight);
        },

        /**
         * Hide typing indicator.
         */
        hideTyping: function () {
            $('#gcl-typing').remove();
        },

        /**
         * Render product cards.
         *
         * @param {Array} products Product data.
         */
        renderProductCards: function (products) {
            var $container = $('<div class="gcl-product-cards"></div>');

            products.forEach(function (product) {
                var $card = $(
                    '<div class="gcl-product-card">' +
                        (product.image ? '<img class="gcl-product-card-image" src="' + product.image + '" alt="" />' : '') +
                        '<div class="gcl-product-card-body">' +
                            '<p class="gcl-product-card-name">' + $('<span>').text(product.name).html() + '</p>' +
                            '<span class="gcl-product-card-price">' + (product.price_html || product.price) + '</span>' +
                        '</div>' +
                        (product.add_to_cart ?
                            '<button class="gcl-product-card-action" data-product-id="' + product.id + '">Add to Cart</button>' :
                            '<a href="' + product.url + '" class="gcl-product-card-action" target="_blank">View</a>') +
                    '</div>'
                );
                $container.append($card);
            });

            $('#gcl-chat-messages').append($container);
            $('#gcl-chat-messages').scrollTop($('#gcl-chat-messages')[0].scrollHeight);
        },

        /**
         * Render quick reply buttons.
         *
         * @param {Array} replies Quick reply data.
         */
        renderQuickReplies: function (replies) {
            var $container = $('#gcl-quick-replies');
            $container.empty();

            replies.forEach(function (reply) {
                $container.append(
                    '<button class="gcl-quick-reply" data-value="' + $('<span>').text(reply.value).html() + '">' +
                        $('<span>').text(reply.label).html() +
                    '</button>'
                );
            });
        },

        /**
         * Add product to cart via AJAX.
         *
         * @param {number} productId Product ID.
         * @param {jQuery} $button   Button element.
         */
        addToCart: function (productId, $button) {
            var originalText = $button.text();
            $button.text('Adding...').prop('disabled', true);

            $.ajax({
                url: gcl_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'woocommerce_add_to_cart',
                    product_id: productId,
                    quantity: 1
                },
                success: function () {
                    $button.text('Added!');
                    $(document.body).trigger('wc_fragment_refresh');

                    setTimeout(function () {
                        $button.text(originalText).prop('disabled', false);
                    }, 2000);
                },
                error: function () {
                    $button.text('Error').prop('disabled', false);
                    setTimeout(function () {
                        $button.text(originalText);
                    }, 2000);
                }
            });
        },

        /**
         * Check and display usage limit status.
         *
         * @param {Object} usage Usage stats.
         */
        checkUsageLimit: function (usage) {
            if (usage.remaining <= 0) {
                this.showLimitReached(gcl_ajax.upgrade_url);
            }
        },

        /**
         * Show limit reached banner.
         *
         * @param {string} upgradeUrl URL to upgrade page.
         */
        showLimitReached: function (upgradeUrl) {
            this.limitReached = true;
            $('#gcl-chat-input').prop('disabled', true).attr('placeholder', this.str('limit_reached'));
            $('#gcl-chat-send').prop('disabled', true);

            var $banner = $(
                '<div class="gcl-limit-banner">' +
                    '<p>' + this.str('limit_reached') + '</p>' +
                    '<a href="' + (upgradeUrl || gcl_ajax.upgrade_url) + '" target="_blank">' +
                        this.str('upgrade_now') +
                    '</a>' +
                '</div>'
            );

            $('.gcl-chat-input-area').before($banner);
        },

        /**
         * Show upgrade prompt (soft upsell from proxy meta).
         */
        showUpgradePrompt: function () {
            // Subtle message, not blocking.
            this.addMessage('assistant',
                'You\'re approaching your monthly limit. ' +
                '<a href="' + gcl_ajax.upgrade_url + '" target="_blank">Upgrade for unlimited conversations</a>.'
            );
        },

        /**
         * Handle API error response.
         *
         * @param {Object} response Error response.
         */
        handleError: function (response) {
            var errorMsg = response.error || this.str('error_message');

            if (response.error_code === 'LIMIT_REACHED') {
                this.showLimitReached(response.upgrade_url || gcl_ajax.upgrade_url);
                this.addMessage('assistant', errorMsg);
            } else {
                this.addMessage('assistant', errorMsg);
            }
        },

        /**
         * Apply theme colour from settings.
         */
        applyThemeColour: function () {
            var $widget = $('#gcl-chat-widget');
            if ($widget.length) {
                // The colour is applied via CSS custom property, set in wp_head if customised.
                // Default falls back to #10B981 in CSS.
            }
        },

        /**
         * Save session ID to localStorage.
         */
        saveSession: function () {
            try {
                if (this.sessionId) {
                    localStorage.setItem('gcl_session_id', this.sessionId);
                }
            } catch (e) {
                // localStorage not available.
            }
        },

        /**
         * Load session ID from localStorage.
         */
        loadSession: function () {
            try {
                this.sessionId = localStorage.getItem('gcl_session_id') || null;
            } catch (e) {
                this.sessionId = null;
            }
        },

        /**
         * Get a translated string.
         *
         * @param {string} key String key.
         * @return {string} Translated string.
         */
        str: function (key) {
            return (gcl_ajax.strings && gcl_ajax.strings[key]) || key;
        }
    };

    // Initialise on DOM ready.
    $(document).ready(function () {
        if (typeof gcl_ajax !== 'undefined') {
            GCLChatWidget.init();
        }
    });

})(jQuery);
