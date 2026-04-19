<?php
/**
 * Frontend functionality
 *
 * Handles chat widget rendering, asset enqueueing, and shortcodes.
 * Simplified for Lite: no GDPR modal, no custom avatar, opt-in "Powered by" badge.
 *
 * @package TrillChatLite\Frontend
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Loader;
use TrillChatLite\Lite\LiteConfig;
use TrillChatLite\Utils\AssetVersioner;
use TrillChatLite\Admin\Settings;

/**
 * Frontend class.
 *
 * Handles all public-facing functionality.
 */
class Frontend {

    /**
     * Loader instance.
     *
     * @var Loader
     */
    private Loader $loader;

    /**
     * Plugin version.
     *
     * @var string
     */
    private string $version;

    /**
     * Lazy-built asset versioner.
     *
     * @var AssetVersioner|null
     */
    private ?AssetVersioner $asset_versioner = null;

    /**
     * Constructor.
     *
     * @param Loader $loader  Plugin loader.
     * @param string $version Plugin version.
     */
    public function __construct( Loader $loader, string $version ) {
        $this->loader  = $loader;
        $this->version = $version;
    }

    /**
     * Lazily build (or return) the AssetVersioner instance.
     *
     * Kept lazy so the file-hash computations only happen for requests
     * that actually enqueue frontend assets.
     */
    private function get_asset_versioner(): AssetVersioner {
        if ( null === $this->asset_versioner ) {
            $this->asset_versioner = new AssetVersioner(
                TRCL_PLUGIN_DIR,
                TRCL_PLUGIN_URL,
                $this->version
            );
        }
        return $this->asset_versioner;
    }

    /**
     * Register all frontend hooks.
     */
    public function register_hooks(): void {
        $this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend_assets' );
        $this->loader->add_action( 'wp_footer', $this, 'render_chat_widget' );
        $this->loader->add_shortcode( 'trill_chat', $this, 'render_chat_shortcode' );

        // WooCommerce cart fragment support.
        $this->loader->add_filter( 'woocommerce_add_to_cart_fragments', $this, 'add_cart_fragments' );
    }

    /**
     * Decide whether the chat widget should be enqueued on the current request.
     *
     * Skips wp-login, feeds, REST, AJAX, and (optionally) checkout/account pages.
     * Exposes the `trcl_should_enqueue_widget` filter so developers can override
     * the decision without modifying the plugin.
     *
     * SOLID: Single Responsibility — encapsulates only the enqueue decision.
     * OWASP: no user input is evaluated here; only stored options and WP context.
     *
     * @return bool True if the widget should load on this request.
     */
    private function should_enqueue_widget(): bool {
        // 1. Global kill-switch (admin setting).
        if ( \get_option( 'trcl_chat_enabled', '1' ) !== '1' ) {
            return false;
        }

        // 2. Never on feeds (RSS/Atom).
        if ( \is_feed() ) {
            return false;
        }

        // 3. Never on REST requests.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        // 4. Never on AJAX calls.
        if ( \wp_doing_ajax() ) {
            return false;
        }

        // 5. Never on WP cron.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }

        // 6. Never on wp-login / wp-register pages (defence-in-depth; wp_enqueue_scripts
        //    is not fired there normally, but a theme could mis-hook).
        global $pagenow;
        if ( isset( $pagenow ) && in_array( $pagenow, [ 'wp-login.php', 'wp-register.php' ], true ) ) {
            return false;
        }

        // 7. Optional skip on WooCommerce checkout (admin opt-in).
        if ( \get_option( 'trcl_skip_checkout', '0' ) === '1'
            && function_exists( 'is_checkout' )
            && \is_checkout() ) {
            return false;
        }

        // 8. Optional skip on WooCommerce My Account pages (admin opt-in).
        if ( \get_option( 'trcl_skip_account', '0' ) === '1'
            && function_exists( 'is_account_page' )
            && \is_account_page() ) {
            return false;
        }

        /**
         * Filter whether the Trill Chat widget should enqueue on the current request.
         *
         * Developers can return false to skip the widget on specific pages,
         * or true to force-load it where the default logic would skip.
         *
         * @since 1.2.3
         *
         * @param bool $should_enqueue Current decision (true = enqueue).
         */
        return (bool) \apply_filters( 'trcl_should_enqueue_widget', true );
    }

    /**
     * Enqueue frontend assets.
     *
     * Lazy-load strategy (since 1.2.3):
     *   - On page load we only ship the launcher bootstrap (`chat-launcher.css`
     *     + `chat-launcher.js`, ~4–5 KB gzip, no jQuery).
     *   - The full widget bundle (`chat-widget.css`, `chat-widget.js` and,
     *     if needed, jQuery) is fetched dynamically by `chat-launcher.js` on
     *     first user intent (hover / focus / touch / click) or on idle.
     *   - `trcl_ajax` is localised on the launcher so it is available both
     *     to the bootstrap AND, later, to the full widget when it executes.
     */
    public function enqueue_frontend_assets(): void {
        if ( ! $this->should_enqueue_widget() ) {
            return;
        }

        // Launcher bootstrap — loaded on every eligible page request.
        //
        // Asset URLs + cache-busting versions come from AssetVersioner: it
        // serves the .min.* variant in production (unless SCRIPT_DEBUG is on)
        // and uses a short content-hash so browsers only re-download a file
        // when its bytes actually changed.
        $av = $this->get_asset_versioner();

        \wp_enqueue_style(
            'trcl-chat-launcher',
            $av->url( 'assets/css/chat-launcher.css' ),
            [],
            $av->version( 'assets/css/chat-launcher.css' )
        );

        \wp_enqueue_script(
            'trcl-chat-launcher',
            $av->url( 'assets/js/chat-launcher.js' ),
            [], // Vanilla JS — no jQuery dependency at first paint.
            $av->version( 'assets/js/chat-launcher.js' ),
            true
        );

        // Build localisation data.
        $localize_data = [
            'ajax_url'        => \admin_url( 'admin-ajax.php' ),
            'rest_url'        => \rest_url( 'trcl/v1/' ),
            'nonce'           => \wp_create_nonce( 'wp_rest' ),
            'enabled'         => \get_option( 'trcl_chat_enabled', '1' ),
            'widget_position' => \get_option( 'trcl_widget_position', 'bottom-right' ),
            'widget_color'    => \get_option( 'trcl_widget_color', '#10B981' ),
            'plugin_url'      => TRCL_PLUGIN_URL,
            // Lazy-load targets — consumed by chat-launcher.js on first click.
            // versioned_url() appends ?ver=<content-hash> so the cache is
            // invalidated per-file when its bytes change, and picks the
            // .min.* variant automatically in production.
            'widget_js_url'   => $av->versioned_url( 'assets/js/chat-widget.js' ),
            'widget_css_url'  => $av->versioned_url( 'assets/css/chat-widget.css' ),
            'jquery_url'      => \includes_url( 'js/jquery/jquery.min.js' ),
            'strings'  => [
                'type_message'    => __( 'Type your message...', 'trill-ai-chat-lite' ),
                'send'            => __( 'Send', 'trill-ai-chat-lite' ),
                'chat_with_us'    => __( 'Chat with us!', 'trill-ai-chat-lite' ),
                'welcome_message' => $this->get_welcome_message(),
                'error_message'   => __( 'Sorry, I encountered an error. Please try again.', 'trill-ai-chat-lite' ),
                'connection_error' => __( 'Connection error. Please check your internet and try again.', 'trill-ai-chat-lite' ),
                'assistant_name'  => __( 'Robin', 'trill-ai-chat-lite' ),
                'assistant_role'  => __( 'AI Assistant', 'trill-ai-chat-lite' ),
                'online'          => __( 'Online', 'trill-ai-chat-lite' ),
                'typing'          => __( 'Robin is typing...', 'trill-ai-chat-lite' ),
                'close_chat'      => __( 'Close chat', 'trill-ai-chat-lite' ),
                'limit_reached'   => __( 'Monthly Limit Reached', 'trill-ai-chat-lite' ),
                'upgrade_now'     => __( 'Upgrade Now', 'trill-ai-chat-lite' ),
            ],
            'branding' => [
                'powered_by_text' => LiteConfig::POWERED_BY_TEXT,
                'powered_by_url'  => LiteConfig::get_powered_by_url(),
                'show_powered_by' => LiteConfig::get_show_powered_by(),
            ],
            'upgrade_url' => LiteConfig::getUpgradeUrl( 'widget' ),
        ];

        // WooCommerce data.
        if ( function_exists( 'WC' ) ) {
            $localize_data['currency']           = \get_woocommerce_currency_symbol();
            $localize_data['currency_position']   = \get_option( 'woocommerce_currency_pos', 'left' );
            $localize_data['shop_url']            = \wc_get_page_permalink( 'shop' );
            $localize_data['cart_url']            = \wc_get_cart_url();
        }

        $localize_data['product_card'] = [
            'max_products'    => 4,
            'show_add_to_cart' => true,
        ];

        // Initial quick replies — starter chips shown when the chat opens.
        // Delegated to Settings::get_initial_quick_replies() so the parsing +
        // cap rules live in a single place (SRP). Filter hook lets themes or
        // other plugins customise per-page.
        $settings_controller = new Settings();
        $initial_quick_replies = $settings_controller->get_initial_quick_replies();

        /**
         * Filter the initial quick-reply chips shown when the chat opens.
         *
         * Each entry is an associative array with `label` and `value` keys.
         * Return an empty array to disable the feature on the current page.
         *
         * @since 1.2.3
         *
         * @param array<int, array{label:string, value:string}> $initial_quick_replies
         */
        $initial_quick_replies = \apply_filters( 'trcl_initial_quick_replies', $initial_quick_replies );

        $localize_data['initial_quick_replies'] = is_array( $initial_quick_replies )
            ? array_values( $initial_quick_replies )
            : [];

        /**
         * Filter localised script data.
         *
         * @param array $localize_data Localised data.
         */
        $localize_data = \apply_filters( 'trcl_localize_script_data', $localize_data );

        \wp_localize_script( 'trcl-chat-launcher', 'trcl_ajax', $localize_data );

        // Dynamic widget styles (colour and position).
        $color    = \get_option( 'trcl_widget_color', '#10B981' );
        $position = \get_option( 'trcl_widget_position', 'bottom-right' );
        $is_left  = ( 'bottom-left' === $position );

        $inline_css = sprintf(
            ':root { --trcl-primary: %s; }
            .trcl-chat-widget { right: %s; left: %s; }
            .trcl-chat-window { right: %s; left: %s; }
            .trcl-noscript-message { right: %s; left: %s; }',
            esc_attr( $color ),
            $is_left ? 'auto' : '20px',
            $is_left ? '20px' : 'auto',
            $is_left ? 'auto' : '0',
            $is_left ? '0' : 'auto',
            $is_left ? 'auto' : '20px',
            $is_left ? '20px' : 'auto'
        );

        // Attach dynamic colour/position CSS to the launcher stylesheet so
        // `--trcl-primary` and the bottom-left override are available on the
        // first paint. The `.trcl-chat-window` rules are harmless no-ops until
        // the full widget CSS loads.
        \wp_add_inline_style( 'trcl-chat-launcher', $inline_css );
    }

    /**
     * Render chat widget in footer.
     */
    public function render_chat_widget(): void {
        if ( ! $this->should_enqueue_widget() ) {
            return;
        }

        // Dynamic CSS is injected via wp_add_inline_style() in enqueue_frontend_assets().
        ?>
        <noscript>
            <div class="trcl-noscript-message">
                <?php esc_html_e( 'JavaScript is required for the chat widget.', 'trill-ai-chat-lite' ); ?>
            </div>
        </noscript>
        <?php
    }

    /**
     * Render chat shortcode.
     *
     * Usage: [trill_chat]
     *
     * @param array $atts Shortcode attributes.
     * @return string Shortcode output.
     */
    public function render_chat_shortcode( array $atts = [] ): string {
        $atts = \shortcode_atts( [
            'style'       => 'inline',
            'title'       => __( 'Chat with us', 'trill-ai-chat-lite' ),
            'button_text' => __( 'Open Chat', 'trill-ai-chat-lite' ),
        ], $atts, 'trill_chat' );

        if ( \get_option( 'trcl_chat_enabled', '1' ) !== '1' ) {
            return '';
        }

        $this->enqueue_frontend_assets();

        ob_start();

        if ( $atts['style'] === 'button' ) {
            ?>
            <button type="button" class="trcl-shortcode-trigger button"
                    onclick="window.TRCLChatLauncher && window.TRCLChatLauncher.open()">
                <?php echo esc_html( $atts['button_text'] ); ?>
            </button>
            <?php
        } else {
            ?>
            <div class="trcl-shortcode-inline">
                <p><strong><?php echo esc_html( $atts['title'] ); ?></strong></p>
                <button type="button" class="trcl-shortcode-trigger button"
                        onclick="window.TRCLChatLauncher && window.TRCLChatLauncher.open()">
                    <?php esc_html_e( 'Start Chat', 'trill-ai-chat-lite' ); ?>
                </button>
            </div>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * Get welcome message.
     *
     * @return string
     */
    private function get_welcome_message(): string {
        $custom = \get_option( 'trcl_welcome_message', '' );

        if ( ! empty( $custom ) ) {
            return $custom;
        }

        return __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-ai-chat-lite' );
    }

    /**
     * Add cart count fragment for AJAX updates.
     *
     * @param array $fragments Cart fragments.
     * @return array Modified fragments.
     */
    public function add_cart_fragments( array $fragments ): array {
        $fragments['.trcl-cart-count'] = sprintf(
            '<span class="trcl-cart-count">%d</span>',
            \WC()->cart ? \WC()->cart->get_cart_contents_count() : 0
        );

        return $fragments;
    }
}
