/**
 * Trill Chat Lite — Chat Widget
 *
 * Handles the frontend chat widget: message sending, UI rendering,
 * product cards, quick replies, and session management.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @version 1.1.0 — Mobile responsive improvements
 * @license GPL-2.0-or-later
 */

/* global jQuery, tcl_ajax */
(function ($) {
    'use strict';

    /**
     * Chat Widget module.
     */
    window.TCLChatWidget = {

        /** State */
        sessionId: null,
        isOpen: false,
        isLoading: false,
        limitReached: false,
        isMobile: false,

        /**
         * Initialise the chat widget.
         */
        init: function () {
            if (tcl_ajax.enabled !== '1') {
                return;
            }

            this.isMobile = window.innerWidth <= 480;
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
                '<div class="tcl-chat-widget" id="tcl-chat-widget">' +
                    '<!-- Toggle Button -->' +
                    '<button class="tcl-chat-toggle" id="tcl-chat-toggle" aria-label="' + this.str('chat_with_us') + '">' +
                        '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>' +
                        '</svg>' +
                    '</button>' +
                    '<!-- Chat Window -->' +
                    '<div class="tcl-chat-window" id="tcl-chat-window">' +
                        '<!-- Header -->' +
                        '<div class="tcl-chat-header">' +
                            '<div class="tcl-chat-avatar"><img src="' + tcl_ajax.plugin_url + 'assets/images/avatar.png" srcset="' + tcl_ajax.plugin_url + 'assets/images/avatar2x.png 2x" alt="Robin" width="40" height="40" /></div>' +
                            '<div class="tcl-chat-header-info">' +
                                '<div class="tcl-chat-header-name">' + this.str('assistant_name') + '</div>' +
                                '<div class="tcl-chat-header-status">' +
                                    '<span class="tcl-status-dot"></span> ' + this.str('online') +
                                '</div>' +
                            '</div>' +
                            '<button class="tcl-chat-close" id="tcl-chat-close" aria-label="' + this.str('close_chat') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Messages -->' +
                        '<div class="tcl-chat-messages" id="tcl-chat-messages"></div>' +
                        '<!-- Quick Replies -->' +
                        '<div class="tcl-quick-replies" id="tcl-quick-replies"></div>' +
                        '<!-- Input -->' +
                        '<div class="tcl-chat-input-area">' +
                            '<input type="text" class="tcl-chat-input" id="tcl-chat-input" placeholder="' + this.str('type_message') + '" maxlength="500" enterkeyhint="send" />' +
                            '<button class="tcl-chat-send" id="tcl-chat-send" aria-label="' + this.str('send') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Powered By -->' +
                        (tcl_ajax.branding && tcl_ajax.branding.show_powered_by ?
                            '<a href="' + tcl_ajax.branding.powered_by_url + '" target="_blank" rel="noopener" class="tcl-powered-by">' +
                                tcl_ajax.branding.powered_by_text +
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
            $(document).on('click', '#tcl-chat-toggle', function () {
                self.toggleWidget();
            });

            // Close chat.
            $(document).on('click', '#tcl-chat-close', function () {
                self.closeWidget();
            });

            // Send message.
            $(document).on('click', '#tcl-chat-send', function () {
                self.sendMessage();
            });

            // Enter key.
            $(document).on('keypress', '#tcl-chat-input', function (e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Quick reply click.
            $(document).on('click', '.tcl-quick-reply', function () {
                var value = $(this).data('value');
                if (value) {
                    $('#tcl-chat-input').val(value);
                    self.sendMessage();
                }
            });

            // Product card add-to-cart.
            $(document).on('click', '.tcl-product-card-action', function (e) {
                e.preventDefault();
                var productId = $(this).data('product-id');
                if (productId) {
                    self.addToCart(productId, $(this));
                }
            });

            // Mobile: handle input focus (keyboard appearing).
            $(document).on('focus', '#tcl-chat-input', function () {
                if (self.isMobile) {
                    self.handleMobileKeyboardOpen();
                }
            });

            // Mobile: handle input blur (keyboard closing).
            $(document).on('blur', '#tcl-chat-input', function () {
                if (self.isMobile) {
                    self.handleMobileKeyboardClose();
                }
            });

            // Track viewport changes (orientation, resize).
            window.addEventListener('resize', function () {
                self.isMobile = window.innerWidth <= 480;
                if (self.isOpen) {
                    self.scrollToBottom();
                }
            });

            // Handle visualViewport resize (keyboard open/close on modern mobile browsers).
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', function () {
                    if (self.isOpen && self.isMobile) {
                        self.handleViewportResize();
                    }
                });
            }
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
            $('#tcl-chat-window').addClass('tcl-chat-window--open');
            $('#tcl-chat-toggle').hide();
            this.isOpen = true;

            // On mobile, prevent body scroll when chat is open.
            if (this.isMobile) {
                $('body').css('overflow', 'hidden');
            }

            // Show welcome message if no messages.
            if ($('#tcl-chat-messages').children().length === 0) {
                this.addMessage('assistant', this.str('welcome_message'));
            }

            // Focus input (slight delay for animation).
            setTimeout(function () {
                $('#tcl-chat-input').focus();
            }, 300);
        },

        /**
         * Close the widget.
         */
        closeWidget: function () {
            $('#tcl-chat-window').removeClass('tcl-chat-window--open');
            $('#tcl-chat-toggle').show();
            this.isOpen = false;

            // Restore body scroll.
            if (this.isMobile) {
                $('body').css('overflow', '');
            }

            // Blur input to dismiss keyboard.
            $('#tcl-chat-input').blur();
        },

        /**
         * Handle mobile keyboard open.
         * Scrolls messages to bottom when keyboard appears.
         */
        handleMobileKeyboardOpen: function () {
            var self = this;
            // Small delay to let the keyboard animation finish.
            setTimeout(function () {
                self.scrollToBottom();
            }, 300);
        },

        /**
         * Handle mobile keyboard close.
         */
        handleMobileKeyboardClose: function () {
            // Scroll to ensure input area is visible.
            this.scrollToBottom();
        },

        /**
         * Handle visualViewport resize (keyboard open/close).
         */
        handleViewportResize: function () {
            var viewport = window.visualViewport;
            if (!viewport) {
                return;
            }

            var $window = $('#tcl-chat-window');

            // Adjust chat window height to match visual viewport.
            // This prevents the keyboard from overlapping the input.
            $window.css('height', viewport.height + 'px');

            this.scrollToBottom();
        },

        /**
         * Scroll messages to bottom.
         */
        scrollToBottom: function () {
            var $messages = $('#tcl-chat-messages');
            if ($messages.length && $messages[0].scrollHeight) {
                $messages.scrollTop($messages[0].scrollHeight);
            }
        },

        /**
         * Send a message.
         */
        sendMessage: function () {
            var self = this;
            var $input = $('#tcl-chat-input');
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
            $('#tcl-chat-send').prop('disabled', true);

            // Clear quick replies.
            $('#tcl-quick-replies').empty();

            // Send API request.
            $.ajax({
                url: tcl_ajax.rest_url + 'message',
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
                    xhr.setRequestHeader('X-WP-Nonce', tcl_ajax.nonce);
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
                    $('#tcl-chat-send').prop('disabled', false);

                    // Keep focus on input for mobile continuity.
                    if (!self.isMobile) {
                        $('#tcl-chat-input').focus();
                    }
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
            var $messages = $('#tcl-chat-messages');
            var sanitised = $('<div>').text(content).html();

            // Basic markdown-like formatting.
            sanitised = sanitised.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            sanitised = sanitised.replace(/\n/g, '<br>');

            var $msg = $('<div class="tcl-message tcl-message--' + role + '">' + sanitised + '</div>');
            $messages.append($msg);
            this.scrollToBottom();
        },

        /**
         * Show typing indicator.
         */
        showTyping: function () {
            var $messages = $('#tcl-chat-messages');
            $messages.append(
                '<div class="tcl-typing" id="tcl-typing">' +
                    '<span class="tcl-typing-dot"></span>' +
                    '<span class="tcl-typing-dot"></span>' +
                    '<span class="tcl-typing-dot"></span>' +
                '</div>'
            );
            this.scrollToBottom();
        },

        /**
         * Hide typing indicator.
         */
        hideTyping: function () {
            $('#tcl-typing').remove();
        },

        /**
         * Render product cards.
         *
         * @param {Array} products Product data.
         */
        renderProductCards: function (products) {
            var $container = $('<div class="tcl-product-cards"></div>');

            products.forEach(function (product) {
                var $card = $(
                    '<div class="tcl-product-card">' +
                        (product.image ? '<img class="tcl-product-card-image" src="' + product.image + '" alt="" />' : '') +
                        '<div class="tcl-product-card-body">' +
                            '<p class="tcl-product-card-name">' + $('<span>').text(product.name).html() + '</p>' +
                            '<span class="tcl-product-card-price">' + (product.price_html || product.price) + '</span>' +
                        '</div>' +
                        (product.add_to_cart ?
                            '<button class="tcl-product-card-action" data-product-id="' + product.id + '">Add to Cart</button>' :
                            '<a href="' + product.url + '" class="tcl-product-card-action" target="_blank">View</a>') +
                    '</div>'
                );
                $container.append($card);
            });

            $('#tcl-chat-messages').append($container);
            this.scrollToBottom();
        },

        /**
         * Render quick reply buttons.
         *
         * @param {Array} replies Quick reply data.
         */
        renderQuickReplies: function (replies) {
            var $container = $('#tcl-quick-replies');
            $container.empty();

            replies.forEach(function (reply) {
                $container.append(
                    '<button class="tcl-quick-reply" data-value="' + $('<span>').text(reply.value).html() + '">' +
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
                url: tcl_ajax.ajax_url,
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
                this.showLimitReached(tcl_ajax.upgrade_url);
            }
        },

        /**
         * Show limit reached banner.
         *
         * @param {string} upgradeUrl URL to upgrade page.
         */
        showLimitReached: function (upgradeUrl) {
            this.limitReached = true;
            $('#tcl-chat-input').prop('disabled', true).attr('placeholder', this.str('limit_reached'));
            $('#tcl-chat-send').prop('disabled', true);

            var $banner = $(
                '<div class="tcl-limit-banner">' +
                    '<p>' + this.str('limit_reached') + '</p>' +
                    '<a href="' + (upgradeUrl || tcl_ajax.upgrade_url) + '" target="_blank">' +
                        this.str('upgrade_now') +
                    '</a>' +
                '</div>'
            );

            $('.tcl-chat-input-area').before($banner);
        },

        /**
         * Show upgrade prompt (soft upsell from proxy meta).
         */
        showUpgradePrompt: function () {
            // Subtle message, not blocking.
            this.addMessage('assistant',
                'You\'re approaching your monthly limit. ' +
                '<a href="' + tcl_ajax.upgrade_url + '" target="_blank">Upgrade for unlimited conversations</a>.'
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
                this.showLimitReached(response.upgrade_url || tcl_ajax.upgrade_url);
                this.addMessage('assistant', errorMsg);
            } else {
                this.addMessage('assistant', errorMsg);
            }
        },

        /**
         * Apply theme colour from settings.
         */
        applyThemeColour: function () {
            var $widget = $('#tcl-chat-widget');
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
                    localStorage.setItem('tcl_session_id', this.sessionId);
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
                this.sessionId = localStorage.getItem('tcl_session_id') || null;
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
            return (tcl_ajax.strings && tcl_ajax.strings[key]) || key;
        }
    };

    // Initialise on DOM ready.
    $(document).ready(function () {
        if (typeof tcl_ajax !== 'undefined') {
            TCLChatWidget.init();
        }
    });

})(jQuery);