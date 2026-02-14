<?php
/**
 * Frontend functionality
 *
 * Handles chat widget rendering, asset enqueueing, and shortcodes.
 * Simplified for Lite: no GDPR modal, no custom avatar, mandatory "Powered by" badge.
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
        if ( \get_option( 'tcl_chat_enabled', '1' ) !== '1' ) {
            return;
        }

        // Main widget CSS.
        \wp_enqueue_style(
            'tcl-chat-widget',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            $this->version
        );

        // Main widget JavaScript.
        \wp_enqueue_script(
            'tcl-chat-widget',
            TRILL_CHAT_LITE_PLUGIN_URL . 'assets/js/chat-widget.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Build localisation data.
        $localize_data = [
            'ajax_url'        => \admin_url( 'admin-ajax.php' ),
            'rest_url'        => \rest_url( 'tcl/v1/' ),
            'nonce'           => \wp_create_nonce( 'wp_rest' ),
            'enabled'         => \get_option( 'tcl_chat_enabled', '1' ),
            'widget_position' => \get_option( 'tcl_widget_position', 'bottom-right' ),
            'widget_color'    => \get_option( 'tcl_widget_color', '#10B981' ),
            'plugin_url'      => TRILL_CHAT_LITE_PLUGIN_URL,
            'strings'  => [
                'type_message'    => __( 'Type your message...', 'trill-chat-lite' ),
                'send'            => __( 'Send', 'trill-chat-lite' ),
                'chat_with_us'    => __( 'Chat with us!', 'trill-chat-lite' ),
                'welcome_message' => $this->get_welcome_message(),
                'error_message'   => __( 'Sorry, I encountered an error. Please try again.', 'trill-chat-lite' ),
                'connection_error' => __( 'Connection error. Please check your internet and try again.', 'trill-chat-lite' ),
                'assistant_name'  => __( 'Robin', 'trill-chat-lite' ),
                'assistant_role'  => __( 'AI Assistant', 'trill-chat-lite' ),
                'online'          => __( 'Online', 'trill-chat-lite' ),
                'typing'          => __( 'Robin is typing...', 'trill-chat-lite' ),
                'close_chat'      => __( 'Close chat', 'trill-chat-lite' ),
                'limit_reached'   => __( 'Monthly Limit Reached', 'trill-chat-lite' ),
                'upgrade_now'     => __( 'Upgrade Now', 'trill-chat-lite' ),
            ],
            'branding' => [
                'powered_by_text' => LiteConfig::POWERED_BY_TEXT,
                'powered_by_url'  => LiteConfig::POWERED_BY_URL,
                'show_powered_by' => LiteConfig::SHOW_POWERED_BY,
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
        $localize_data = \apply_filters( 'trill_chat_lite_localize_script_data', $localize_data );

        \wp_localize_script( 'tcl-chat-widget', 'tcl_ajax', $localize_data );
    }

    /**
     * Render chat widget in footer.
     */
    public function render_chat_widget(): void {
        if ( \get_option( 'tcl_chat_enabled', '1' ) !== '1' ) {
            return;
        }

        $position = \get_option( 'tcl_widget_position', 'bottom-right' );
        $color    = \get_option( 'tcl_widget_color', '#10B981' );

        // Inject CSS custom properties so the widget CSS/JS can use them.
        ?>
        <style id="tcl-widget-vars">
            :root {
                --tcl-primary: <?php echo esc_attr( $color ); ?>;
            }
            .tcl-chat-widget {
                right: <?php echo $position === 'bottom-left' ? 'auto' : '20px'; ?>;
                left: <?php echo $position === 'bottom-left' ? '20px' : 'auto'; ?>;
            }
            .tcl-chat-window {
                right: <?php echo $position === 'bottom-left' ? 'auto' : '0'; ?>;
                left: <?php echo $position === 'bottom-left' ? '0' : 'auto'; ?>;
            }
        </style>
        <noscript>
            <div class="tcl-noscript-message" style="position: fixed; bottom: 20px; right: 20px; background: #10B981; color: white; padding: 15px 20px; border-radius: 8px; font-family: sans-serif; font-size: 14px; z-index: 9999;">
                <?php esc_html_e( 'JavaScript is required for the chat widget.', 'trill-chat-lite' ); ?>
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
            'title'       => __( 'Chat with us', 'trill-chat-lite' ),
            'button_text' => __( 'Open Chat', 'trill-chat-lite' ),
        ], $atts, 'trill_chat' );

        if ( \get_option( 'tcl_chat_enabled', '1' ) !== '1' ) {
            return '';
        }

        $this->enqueue_frontend_assets();

        ob_start();

        if ( $atts['style'] === 'button' ) {
            ?>
            <button type="button" class="tcl-shortcode-trigger button"
                    onclick="window.TCLChatWidget && window.TCLChatWidget.openWidget()">
                <?php echo esc_html( $atts['button_text'] ); ?>
            </button>
            <?php
        } else {
            ?>
            <div class="tcl-shortcode-inline">
                <p><strong><?php echo esc_html( $atts['title'] ); ?></strong></p>
                <button type="button" class="tcl-shortcode-trigger button"
                        onclick="window.TCLChatWidget && window.TCLChatWidget.openWidget()">
                    <?php esc_html_e( 'Start Chat', 'trill-chat-lite' ); ?>
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
        $custom = \get_option( 'tcl_welcome_message', '' );

        if ( ! empty( $custom ) ) {
            return $custom;
        }

        return __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-chat-lite' );
    }

    /**
     * Add cart count fragment for AJAX updates.
     *
     * @param array $fragments Cart fragments.
     * @return array Modified fragments.
     */
    public function add_cart_fragments( array $fragments ): array {
        $fragments['.tcl-cart-count'] = sprintf(
            '<span class="tcl-cart-count">%d</span>',
            \WC()->cart ? \WC()->cart->get_cart_contents_count() : 0
        );

        return $fragments;
    }
}
