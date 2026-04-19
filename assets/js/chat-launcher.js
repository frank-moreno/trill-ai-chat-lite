/**
 * Trill Chat Lite — Chat Launcher (lazy-load bootstrap)
 *
 * Minimal vanilla-JS launcher (~3KB) that renders the bubble button on page
 * load and defers the full chat widget bundle (CSS + JS + jQuery if missing)
 * until the user shows intent (hover / focus / touch / click) or the browser
 * becomes idle.
 *
 * SOLID:
 *   - Single Responsibility: only launcher rendering + bundle bootstrap.
 *   - Open/Closed: exposes window.TRCLChatLauncher.open() for shortcode reuse.
 *
 * OWASP:
 *   - No user input is evaluated; all config comes from the server-side
 *     trcl_ajax localisation object (escaped in PHP).
 *   - Dynamic <script> / <link> srcs are server-issued URLs, never built
 *     from page content.
 *
 * @package TrillChatLite
 * @since 1.2.3
 * @license GPL-2.0-or-later
 */

/* global trcl_ajax */
(function () {
    'use strict';

    // Bail silently if the config is missing (enqueue skipped or JS inlined out of order).
    if (typeof window.trcl_ajax === 'undefined') {
        return;
    }

    var cfg = window.trcl_ajax;

    // Admin kill-switch — belt-and-braces (PHP already skips enqueue when disabled).
    if (cfg.enabled !== '1') {
        return;
    }

    // Bundle URLs are required. If PHP did not localise them, bail.
    if (!cfg.widget_js_url || !cfg.widget_css_url) {
        return;
    }

    /* ----------------------------------------------------------------------
     * Bundle loader state
     * ---------------------------------------------------------------------- */

    var bundleLoaded = false;
    var bundleLoading = false;
    var loadCallbacks = [];
    var prefetched = false;

    /* ----------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    function injectStylesheet(url) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
    }

    function injectScript(url, onLoad) {
        var s = document.createElement('script');
        s.src = url;
        // Preserve execution order when we chain (jQuery → widget).
        s.async = false;
        s.onload = onLoad || null;
        s.onerror = function () {
            if (window.console && window.console.error) {
                window.console.error('Trill Chat: failed to load ' + url);
            }
        };
        document.head.appendChild(s);
    }

    function injectPrefetch(url, asType) {
        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        if (asType) {
            link.as = asType;
        }
        document.head.appendChild(link);
    }

    /**
     * Warm the browser cache without executing anything.
     * Fired on user-intent signals (hover / focus / touch) and on idle.
     */
    function warmBundles() {
        if (prefetched || bundleLoaded || bundleLoading) {
            return;
        }
        prefetched = true;
        injectPrefetch(cfg.widget_css_url, 'style');
        injectPrefetch(cfg.widget_js_url, 'script');
        if (typeof window.jQuery === 'undefined' && cfg.jquery_url) {
            injectPrefetch(cfg.jquery_url, 'script');
        }
    }

    /**
     * Load the full chat bundle (CSS + jQuery if needed + chat-widget.js).
     *
     * @param {Function} cb Callback once the bundle is ready AND TRCLChatWidget is initialised.
     */
    function loadBundle(cb) {
        if (bundleLoaded) {
            if (cb) { cb(); }
            return;
        }
        if (cb) {
            loadCallbacks.push(cb);
        }
        if (bundleLoading) {
            return;
        }
        bundleLoading = true;

        // CSS is non-render-blocking for JS execution — start it immediately.
        injectStylesheet(cfg.widget_css_url);

        // chat-widget.js binds $(document).ready() which, in modern jQuery, schedules
        // init() via setTimeout(0) when the DOM is already ready. We therefore defer
        // our consumer callbacks one macrotask to ensure init() has run first.
        var onWidgetScriptLoaded = function () {
            bundleLoaded = true;
            window.setTimeout(function () {
                while (loadCallbacks.length) {
                    var fn = loadCallbacks.shift();
                    try { fn(); } catch (e) { /* ignore consumer errors */ }
                }
            }, 0);
        };

        var loadWidgetJs = function () {
            injectScript(cfg.widget_js_url, onWidgetScriptLoaded);
        };

        // Load jQuery only if it's not already on the page (WooCommerce themes
        // almost always ship it, so this branch is rarely taken).
        if (typeof window.jQuery === 'undefined' && cfg.jquery_url) {
            injectScript(cfg.jquery_url, loadWidgetJs);
        } else {
            loadWidgetJs();
        }
    }

    /* ----------------------------------------------------------------------
     * Launcher rendering
     * ---------------------------------------------------------------------- */

    function getLabel() {
        return (cfg.strings && cfg.strings.chat_with_us) || 'Chat with us!';
    }

    /**
     * Build the launcher DOM.
     *
     * The SVG markup is kept byte-identical to chat-widget.js::render() so
     * there is no visual shift when the full widget takes over.
     */
    function buildLauncher() {
        var wrapper = document.createElement('div');
        wrapper.className = 'trcl-chat-widget';
        wrapper.id = 'trcl-launcher-bootstrap';

        var btn = document.createElement('button');
        btn.className = 'trcl-chat-toggle';
        btn.type = 'button';
        btn.setAttribute('aria-label', getLabel());

        btn.innerHTML = '' +
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
            '</svg>';

        wrapper.appendChild(btn);
        return { wrapper: wrapper, btn: btn };
    }

    /**
     * Load the bundle then open the full chat window.
     */
    function loadAndOpen(bootstrapWrapper) {
        loadBundle(function () {
            if (window.TRCLChatWidget && typeof window.TRCLChatWidget.openWidget === 'function') {
                window.TRCLChatWidget.openWidget();
            }
            // Remove our bootstrap launcher — the full widget has rendered its own.
            if (bootstrapWrapper && bootstrapWrapper.parentNode) {
                bootstrapWrapper.parentNode.removeChild(bootstrapWrapper);
            }
        });
    }

    function renderLauncher() {
        var parts = buildLauncher();
        var wrapper = parts.wrapper;
        var btn = parts.btn;

        document.body.appendChild(wrapper);

        // User-intent signals — prefetch bundles without executing.
        var oncePrefetch = function () { warmBundles(); };
        btn.addEventListener('mouseenter', oncePrefetch, { once: true, passive: true });
        btn.addEventListener('focus', oncePrefetch, { once: true });
        btn.addEventListener('touchstart', oncePrefetch, { once: true, passive: true });

        // Click — load the bundle and open the window.
        btn.addEventListener('click', function () {
            loadAndOpen(wrapper);
        });

        // Idle prefetch: warm the cache after first paint if the user hasn't interacted.
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(warmBundles, { timeout: 4000 });
        } else {
            window.setTimeout(warmBundles, 3000);
        }

        /* ------------------------------------------------------------------
         * Public API for shortcode buttons and 3rd-party code.
         *
         * Replaces the old "window.TRCLChatWidget.openWidget()" pattern,
         * which no longer works before the bundle is loaded.
         * ------------------------------------------------------------------ */
        window.TRCLChatLauncher = {
            open: function () {
                loadAndOpen(wrapper);
            },
            prefetch: warmBundles
        };
    }

    /* ----------------------------------------------------------------------
     * Boot
     * ---------------------------------------------------------------------- */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderLauncher);
    } else {
        renderLauncher();
    }
})();
