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
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets(): void {
        if ( \get_option( 'trcl_chat_enabled', '1' ) !== '1' ) {
            return;
        }

        // Main widget CSS.
        \wp_enqueue_style(
            'trcl-chat-widget',
            TRCL_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            $this->version
        );

        // Main widget JavaScript.
        \wp_enqueue_script(
            'trcl-chat-widget',
            TRCL_PLUGIN_URL . 'assets/js/chat-widget.js',
            [ 'jquery' ],
            $this->version,
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

        /**
         * Filter localised script data.
         *
         * @param array $localize_data Localised data.
         */
        $localize_data = \apply_filters( 'trcl_localize_script_data', $localize_data );

        \wp_localize_script( 'trcl-chat-widget', 'trcl_ajax', $localize_data );

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

        \wp_add_inline_style( 'trcl-chat-widget', $inline_css );
    }

    /**
     * Render chat widget in footer.
     */
    public function render_chat_widget(): void {
        if ( \get_option( 'trcl_chat_enabled', '1' ) !== '1' ) {
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
                    onclick="window.TRCLChatWidget && window.TRCLChatWidget.openWidget()">
                <?php echo esc_html( $atts['button_text'] ); ?>
            </button>
            <?php
        } else {
            ?>
            <div class="trcl-shortcode-inline">
                <p><strong><?php echo esc_html( $atts['title'] ); ?></strong></p>
                <button type="button" class="trcl-shortcode-trigger button"
                        onclick="window.TRCLChatWidget && window.TRCLChatWidget.openWidget()">
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
