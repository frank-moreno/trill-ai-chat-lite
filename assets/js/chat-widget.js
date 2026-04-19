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

/* global jQuery, trcl_ajax */
(function ($) {
    'use strict';

    /**
     * Chat Widget module.
     */
    window.TRCLChatWidget = {

        /** State */
        sessionId: null,
        isOpen: false,
        isLoading: false,
        limitReached: false,
        isMobile: false,

        /**
         * In-memory conversation transcript for the current tab.
         *
         * Mirrors what's rendered in #trcl-chat-messages and is serialised
         * to sessionStorage so that refreshing the page (or closing and
         * reopening the widget in the same tab) restores the visible
         * conversation instead of re-starting with the welcome message.
         *
         * @since 1.2.3
         * @type {Array<{role:string, content:string}>}
         */
        messageHistory: [],

        /**
         * Guard flag set while rehydrateHistory() replays cached messages
         * through addMessage(). Prevents saveHistory() from writing on
         * every single replayed message — we save once at the end.
         *
         * @since 1.2.3
         * @type {boolean}
         */
        suppressHistory: false,

        /** sessionStorage key + schema version for the transcript cache. */
        HISTORY_STORAGE_KEY: 'trcl_history',
        HISTORY_VERSION: 1,

        /** Soft caps applied before persisting, to keep storage bounded. */
        MAX_HISTORY_MESSAGES: 20,
        MAX_HISTORY_CHARS: 10000,

        /**
         * Initialise the chat widget.
         */
        init: function () {
            if (trcl_ajax.enabled !== '1') {
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
                '<div class="trcl-chat-widget" id="trcl-chat-widget">' +
                    '<!-- Toggle Button — Trill AI brand launcher -->' +
                    '<button class="trcl-chat-toggle" id="trcl-chat-toggle" aria-label="' + this.str('chat_with_us') + '">' +
                        '<svg class="trcl-launcher-svg" viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">' +
                            '<path class="trcl-launcher-bubble" d="M140.0 68.5 L139.9 79.9 L139.6 85.9 L139.0 90.7 L138.2 94.8 L137.2 98.4 L135.9 101.7 L134.5 104.6 L132.8 107.3 L130.8 109.7 L128.6 111.9 L126.2 113.8 L123.5 115.6 L120.5 117.1 L117.2 118.4 L113.5 119.5 L109.4 120.4 L104.8 121.1 L99.5 121.6 L92.8 121.9 L80.0 122.0 L67.2 121.9 L45.0 144.0 L42.8 118.4 L39.5 117.1 L36.5 115.6 L33.8 113.8 L31.4 111.9 L29.2 109.7 L27.2 107.3 L25.5 104.6 L24.1 101.7 L22.8 98.4 L21.8 94.8 L21.0 90.7 L20.4 85.9 L20.1 79.9 L20.0 68.5 L20.1 57.1 L20.4 51.1 L21.0 46.3 L21.8 42.2 L22.8 38.6 L24.1 35.3 L25.5 32.4 L27.2 29.7 L29.2 27.3 L31.4 25.1 L33.8 23.2 L36.5 21.4 L39.5 19.9 L42.8 18.6 L46.5 17.5 L50.6 16.6 L55.2 15.9 L60.5 15.4 L67.2 15.1 L80.0 15.0 L92.8 15.1 L99.5 15.4 L104.8 15.9 L109.4 16.6 L113.5 17.5 L117.2 18.6 L120.5 19.9 L123.5 21.4 L126.2 23.2 L128.6 25.1 L130.8 27.3 L132.8 29.7 L134.5 32.4 L135.9 35.3 L137.2 38.6 L138.2 42.2 L139.0 46.3 L139.6 51.1 L139.9 57.1 Z"/>' +
                            '<g stroke="#FFFFFF" stroke-opacity="0.51" stroke-linecap="round" stroke-width="2">' +
                                '<line x1="52" y1="48" x2="86" y2="37"/>' +
                                '<line x1="52" y1="48" x2="90" y2="72"/>' +
                                '<line x1="86" y1="37" x2="90" y2="72"/>' +
                                '<line x1="90" y1="72" x2="109" y2="84"/>' +
                                '<line x1="52" y1="48" x2="64" y2="92"/>' +
                                '<line x1="90" y1="72" x2="64" y2="92"/>' +
                            '</g>' +
                            '<circle cx="52" cy="48" r="14" fill="#FFFFFF"/>' +
                            '<circle cx="86" cy="37" r="9" fill="#FFFFFF"/>' +
                            '<circle cx="90" cy="72" r="12" fill="#FFFFFF"/>' +
                            '<circle cx="64" cy="92" r="9" fill="#FFFFFF"/>' +
                            '<circle cx="109" cy="84" r="7" fill="#F5A623"/>' +
                        '</svg>' +
                    '</button>' +
                    '<!-- Chat Window -->' +
                    '<div class="trcl-chat-window" id="trcl-chat-window">' +
                        '<!-- Header -->' +
                        '<div class="trcl-chat-header">' +
                            '<div class="trcl-chat-avatar"><img src="' + trcl_ajax.plugin_url + 'assets/images/avatar.png" srcset="' + trcl_ajax.plugin_url + 'assets/images/avatar2x.png 2x" alt="Robin" width="40" height="40" /></div>' +
                            '<div class="trcl-chat-header-info">' +
                                '<div class="trcl-chat-header-name">' + this.str('assistant_name') + '</div>' +
                                '<div class="trcl-chat-header-status">' +
                                    '<span class="trcl-status-dot"></span> ' + this.str('online') +
                                '</div>' +
                            '</div>' +
                            '<button class="trcl-chat-close" id="trcl-chat-close" aria-label="' + this.str('close_chat') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Messages -->' +
                        '<div class="trcl-chat-messages" id="trcl-chat-messages"></div>' +
                        '<!-- Quick Replies -->' +
                        '<div class="trcl-quick-replies" id="trcl-quick-replies"></div>' +
                        '<!-- Input -->' +
                        '<div class="trcl-chat-input-area">' +
                            '<input type="text" class="trcl-chat-input" id="trcl-chat-input" placeholder="' + this.str('type_message') + '" maxlength="500" enterkeyhint="send" />' +
                            '<button class="trcl-chat-send" id="trcl-chat-send" aria-label="' + this.str('send') + '">' +
                                '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>' +
                                '</svg>' +
                            '</button>' +
                        '</div>' +
                        '<!-- Powered By -->' +
                        (trcl_ajax.branding && trcl_ajax.branding.show_powered_by ?
                            '<a href="' + trcl_ajax.branding.powered_by_url + '" target="_blank" rel="noopener" class="trcl-powered-by">' +
                                trcl_ajax.branding.powered_by_text +
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
            $(document).on('click', '#trcl-chat-toggle', function () {
                self.toggleWidget();
            });

            // Close chat.
            $(document).on('click', '#trcl-chat-close', function () {
                self.closeWidget();
            });

            // Send message.
            $(document).on('click', '#trcl-chat-send', function () {
                self.sendMessage();
            });

            // Enter key.
            $(document).on('keypress', '#trcl-chat-input', function (e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Quick reply click.
            $(document).on('click', '.trcl-quick-reply', function () {
                var value = $(this).data('value');
                if (value) {
                    $('#trcl-chat-input').val(value);
                    self.sendMessage();
                }
            });

            // Product card add-to-cart.
            $(document).on('click', '.trcl-product-card-action', function (e) {
                e.preventDefault();
                var productId = $(this).data('product-id');
                if (productId) {
                    self.addToCart(productId, $(this));
                }
            });

            // Mobile: handle input focus (keyboard appearing).
            $(document).on('focus', '#trcl-chat-input', function () {
                if (self.isMobile) {
                    self.handleMobileKeyboardOpen();
                }
            });

            // Mobile: handle input blur (keyboard closing).
            $(document).on('blur', '#trcl-chat-input', function () {
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
            $('#trcl-chat-window').addClass('trcl-chat-window--open');
            $('#trcl-chat-toggle').hide();
            this.isOpen = true;

            // On mobile, prevent body scroll when chat is open.
            if (this.isMobile) {
                $('body').css('overflow', 'hidden');
            }

            // Show welcome message if no messages. If we have a cached
            // transcript for this tab (same sessionId), replay it instead
            // so the conversation feels continuous across reloads.
            if ($('#trcl-chat-messages').children().length === 0) {
                if (this.messageHistory.length > 0) {
                    this.rehydrateHistory();
                } else {
                    this.addMessage('assistant', this.str('welcome_message'));
                }
            }

            // Starter chips: shown only when the shopper hasn't spoken yet
            // in this tab. Once there's at least one user turn, the server's
            // own quick_replies take over and these stay out of the way.
            this.renderInitialQuickReplies();

            // Focus input (slight delay for animation).
            setTimeout(function () {
                $('#trcl-chat-input').focus();
            }, 300);
        },

        /**
         * Close the widget.
         */
        closeWidget: function () {
            $('#trcl-chat-window').removeClass('trcl-chat-window--open');
            $('#trcl-chat-toggle').show();
            this.isOpen = false;

            // Restore body scroll.
            if (this.isMobile) {
                $('body').css('overflow', '');
            }

            // Blur input to dismiss keyboard.
            $('#trcl-chat-input').blur();
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

            var $window = $('#trcl-chat-window');

            // Adjust chat window height to match visual viewport.
            // This prevents the keyboard from overlapping the input.
            $window.css('height', viewport.height + 'px');

            this.scrollToBottom();
        },

        /**
         * Scroll messages to bottom.
         */
        scrollToBottom: function () {
            var $messages = $('#trcl-chat-messages');
            if ($messages.length && $messages[0].scrollHeight) {
                $messages.scrollTop($messages[0].scrollHeight);
            }
        },

        /**
         * Send a message.
         */
        sendMessage: function () {
            var self = this;
            var $input = $('#trcl-chat-input');
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
            $('#trcl-chat-send').prop('disabled', true);

            // Clear quick replies.
            $('#trcl-quick-replies').empty();

            // Send API request.
            $.ajax({
                url: trcl_ajax.rest_url + 'message',
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
                    xhr.setRequestHeader('X-WP-Nonce', trcl_ajax.nonce);
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

                        // Check proxy meta for upgrade prompt (server-side limits).
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
                        if (body.code === 'SERVICE_LIMIT_REACHED') {
                            self.showLimitReached(body.data && body.data.upgrade_url ? body.data.upgrade_url : trcl_ajax.upgrade_url);
                            return;
                        }
                    }

                    self.addMessage('assistant', self.str('error_message'));
                },
                complete: function () {
                    self.isLoading = false;
                    $('#trcl-chat-send').prop('disabled', false);

                    // Keep focus on input for mobile continuity.
                    if (!self.isMobile) {
                        $('#trcl-chat-input').focus();
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
            var $messages = $('#trcl-chat-messages');
            var sanitised = $('<div>').text(content).html();

            // Basic markdown-like formatting.
            sanitised = sanitised.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            sanitised = sanitised.replace(/\n/g, '<br>');

            var $msg = $('<div class="trcl-message trcl-message--' + role + '">' + sanitised + '</div>');
            $messages.append($msg);
            this.scrollToBottom();

            // Track every rendered turn so we can restore it on reload.
            // During rehydrateHistory() we push but skip the write; the
            // replay code calls saveHistory() once at the end.
            this.messageHistory.push({ role: role, content: content });
            if (!this.suppressHistory) {
                this.saveHistory();
            }
        },

        /**
         * Show typing indicator.
         */
        showTyping: function () {
            var $messages = $('#trcl-chat-messages');
            $messages.append(
                '<div class="trcl-typing" id="trcl-typing">' +
                    '<span class="trcl-typing-dot"></span>' +
                    '<span class="trcl-typing-dot"></span>' +
                    '<span class="trcl-typing-dot"></span>' +
                '</div>'
            );
            this.scrollToBottom();
        },

        /**
         * Hide typing indicator.
         */
        hideTyping: function () {
            $('#trcl-typing').remove();
        },

        /**
         * Render product cards.
         *
         * @param {Array} products Product data.
         */
        renderProductCards: function (products) {
            var $container = $('<div class="trcl-product-cards"></div>');

            products.forEach(function (product) {
                var $card = $(
                    '<div class="trcl-product-card">' +
                        (product.image ? '<img class="trcl-product-card-image" src="' + product.image + '" alt="" />' : '') +
                        '<div class="trcl-product-card-body">' +
                            '<p class="trcl-product-card-name">' + $('<span>').text(product.name).html() + '</p>' +
                            '<span class="trcl-product-card-price">' + (product.price_html || product.price) + '</span>' +
                        '</div>' +
                        (product.add_to_cart ?
                            '<button class="trcl-product-card-action" data-product-id="' + product.id + '">Add to Cart</button>' :
                            '<a href="' + product.url + '" class="trcl-product-card-action" target="_blank">View</a>') +
                    '</div>'
                );
                $container.append($card);
            });

            $('#trcl-chat-messages').append($container);
            this.scrollToBottom();
        },

        /**
         * Render quick reply buttons.
         *
         * @param {Array} replies Quick reply data.
         */
        renderQuickReplies: function (replies) {
            var $container = $('#trcl-quick-replies');
            $container.empty();

            replies.forEach(function (reply) {
                $container.append(
                    '<button class="trcl-quick-reply" data-value="' + $('<span>').text(reply.value).html() + '">' +
                        $('<span>').text(reply.label).html() +
                    '</button>'
                );
            });
        },

        /**
         * Render the admin-configured starter chips.
         *
         * Gate conditions (fail silently, never throw):
         *   - trcl_ajax.initial_quick_replies must be a non-empty array.
         *   - The shopper must not have sent any message yet in this tab —
         *     once a user turn exists in messageHistory, the server's own
         *     per-turn quick_replies take priority.
         *
         * SOLID: reuses renderQuickReplies() so chip markup lives in one place.
         *
         * @since 1.2.3
         */
        renderInitialQuickReplies: function () {
            var config = (typeof trcl_ajax !== 'undefined') ? trcl_ajax.initial_quick_replies : null;
            if (!config || !Array.isArray(config) || config.length === 0) {
                return;
            }

            // If the shopper has already sent a message in this tab, keep the
            // conversation clean and defer to server-driven quick_replies.
            var hasUserTurn = false;
            for (var i = 0; i < this.messageHistory.length; i++) {
                if (this.messageHistory[i].role === 'user') {
                    hasUserTurn = true;
                    break;
                }
            }
            if (hasUserTurn) {
                return;
            }

            // Filter to well-formed entries and cap at 3 as a safety net.
            var replies = config
                .filter(function (r) {
                    return r && typeof r.label === 'string' && r.label.length > 0;
                })
                .slice(0, 3)
                .map(function (r) {
                    return {
                        label: r.label,
                        value: (typeof r.value === 'string' && r.value.length > 0) ? r.value : r.label
                    };
                });

            if (replies.length === 0) {
                return;
            }

            this.renderQuickReplies(replies);
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
                url: trcl_ajax.ajax_url,
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
         * Show limit reached banner (triggered by server-side proxy 429).
         *
         * @param {string} upgradeUrl URL to upgrade page.
         */
        showLimitReached: function (upgradeUrl) {
            this.limitReached = true;
            $('#trcl-chat-input').prop('disabled', true).attr('placeholder', this.str('limit_reached'));
            $('#trcl-chat-send').prop('disabled', true);

            var $banner = $(
                '<div class="trcl-limit-banner">' +
                    '<p>' + this.str('limit_reached') + '</p>' +
                    '<a href="' + (upgradeUrl || trcl_ajax.upgrade_url) + '" target="_blank">' +
                        this.str('upgrade_now') +
                    '</a>' +
                '</div>'
            );

            $('.trcl-chat-input-area').before($banner);
        },

        /**
         * Show upgrade prompt (soft upsell from proxy meta).
         */
        showUpgradePrompt: function () {
            // Subtle message, not blocking.
            this.addMessage('assistant',
                'You\'re approaching your monthly limit. ' +
                '<a href="' + trcl_ajax.upgrade_url + '" target="_blank">Upgrade for unlimited conversations</a>.'
            );
        },

        /**
         * Handle API error response.
         *
         * @param {Object} response Error response.
         */
        handleError: function (response) {
            var errorMsg = response.error || this.str('error_message');

            if (response.error_code === 'SERVICE_LIMIT_REACHED' || response.code === 'SERVICE_LIMIT_REACHED') {
                this.showLimitReached(response.upgrade_url || trcl_ajax.upgrade_url);
                this.addMessage('assistant', errorMsg);
            } else {
                this.addMessage('assistant', errorMsg);
            }
        },

        /**
         * Apply theme colour from settings.
         */
        applyThemeColour: function () {
            var $widget = $('#trcl-chat-widget');
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
                    localStorage.setItem('trcl_session_id', this.sessionId);
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
                this.sessionId = localStorage.getItem('trcl_session_id') || null;
            } catch (e) {
                this.sessionId = null;
            }

            // Transcript cache is read after the session ID so we can
            // compare and discard stale history from a previous session.
            this.loadHistory();
        },

        /**
         * Persist the in-memory transcript to sessionStorage.
         *
         * Applies two caps before writing so the payload stays bounded:
         *   1. Count cap (MAX_HISTORY_MESSAGES) — keep only the latest N.
         *   2. Character cap (MAX_HISTORY_CHARS) — drop oldest turns until
         *      the total content length fits, always keeping at least the
         *      last message.
         *
         * Storage:
         *   - sessionStorage, not localStorage — the transcript is visible
         *     only in the tab that produced it and vanishes when the tab
         *     closes. Minimises the privacy footprint on shared devices.
         *   - Schema is versioned so we can roll out payload changes
         *     without corrupting clients mid-upgrade (see loadHistory()).
         *
         * @since 1.2.3
         */
        saveHistory: function () {
            try {
                var messages = this.messageHistory.slice();

                // Cap by count.
                if (messages.length > this.MAX_HISTORY_MESSAGES) {
                    messages = messages.slice(messages.length - this.MAX_HISTORY_MESSAGES);
                }

                // Cap by total characters (drop oldest first).
                var total = 0;
                for (var i = 0; i < messages.length; i++) {
                    total += (messages[i].content || '').length;
                }
                while (messages.length > 1 && total > this.MAX_HISTORY_CHARS) {
                    total -= (messages.shift().content || '').length;
                }

                var payload = {
                    version: this.HISTORY_VERSION,
                    session_id: this.sessionId || null,
                    messages: messages
                };
                window.sessionStorage.setItem(this.HISTORY_STORAGE_KEY, JSON.stringify(payload));
            } catch (e) {
                // sessionStorage unavailable (private mode, quota) — fail silently.
            }
        },

        /**
         * Load the transcript cache from sessionStorage.
         *
         * Discard conditions:
         *   - No cached payload.
         *   - Version mismatch (forward/backward compatibility gate).
         *   - session_id mismatch — the cache belongs to an earlier
         *     conversation on this tab; starting fresh is safer than
         *     replaying unrelated turns into the current thread.
         *   - Shape validation — each entry must have string role + content.
         *
         * @since 1.2.3
         */
        loadHistory: function () {
            try {
                var raw = window.sessionStorage.getItem(this.HISTORY_STORAGE_KEY);
                if (!raw) {
                    return;
                }
                var data = JSON.parse(raw);
                if (!data || data.version !== this.HISTORY_VERSION) {
                    return;
                }
                // If both sides carry a session_id and they disagree, bail.
                if (data.session_id && this.sessionId && data.session_id !== this.sessionId) {
                    return;
                }
                if (Array.isArray(data.messages)) {
                    this.messageHistory = data.messages.filter(function (m) {
                        return m && typeof m.role === 'string' && typeof m.content === 'string';
                    });
                }
            } catch (e) {
                // Storage unavailable or payload corrupted — start clean.
                this.messageHistory = [];
            }
        },

        /**
         * Replay the cached transcript into the DOM.
         *
         * Called from openWidget() when there's cached history for this
         * tab. Uses the normal addMessage() render path so any formatting
         * tweaks remain in a single place (SRP). Sets suppressHistory so
         * addMessage() skips per-message writes; we save once at the end
         * to persist the post-rehydration (possibly trimmed) state.
         *
         * @since 1.2.3
         */
        rehydrateHistory: function () {
            if (!this.messageHistory.length) {
                return;
            }
            var original = this.messageHistory.slice();
            // Let addMessage() rebuild the list as it renders each turn.
            this.messageHistory = [];
            this.suppressHistory = true;
            for (var i = 0; i < original.length; i++) {
                this.addMessage(original[i].role, original[i].content);
            }
            this.suppressHistory = false;
            // Single write after the full replay completes.
            this.saveHistory();
        },

        /**
         * Get a translated string.
         *
         * @param {string} key String key.
         * @return {string} Translated string.
         */
        str: function (key) {
            return (trcl_ajax.strings && trcl_ajax.strings[key]) || key;
        }
    };

    // Initialise on DOM ready.
    $(document).ready(function () {
        if (typeof trcl_ajax !== 'undefined') {
            TRCLChatWidget.init();
        }
    });

})(jQuery);