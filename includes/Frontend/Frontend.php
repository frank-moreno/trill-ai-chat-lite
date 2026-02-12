<?php
/**
 * Frontend functionality
 *
 * Handles chat widget rendering, asset enqueueing, and shortcodes.
 * Simplified for Lite: no GDPR modal, no custom avatar, mandatory "Powered by" badge.
 *
 * @package GspltdChatLite\Frontend
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GspltdChatLite\Loader;
use GspltdChatLite\Lite\LiteConfig;

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
        $this->loader->add_shortcode( 'gspltd_chat', $this, 'render_chat_shortcode' );

        // WooCommerce cart fragment support.
        $this->loader->add_filter( 'woocommerce_add_to_cart_fragments', $this, 'add_cart_fragments' );
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets(): void {
        if ( \get_option( 'gcl_chat_enabled', '1' ) !== '1' ) {
            return;
        }

        // Main widget CSS.
        \wp_enqueue_style(
            'gcl-chat-widget',
            GCL_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            $this->version
        );

        // Main widget JavaScript.
        \wp_enqueue_script(
            'gcl-chat-widget',
            GCL_PLUGIN_URL . 'assets/js/chat-widget.js',
            [ 'jquery' ],
            $this->version,
            true
        );

        // Build localisation data.
        $localize_data = [
            'ajax_url' => \admin_url( 'admin-ajax.php' ),
            'rest_url' => \rest_url( 'gcl/v1/' ),
            'nonce'    => \wp_create_nonce( 'wp_rest' ),
            'enabled'  => \get_option( 'gcl_chat_enabled', '1' ),
            'strings'  => [
                'type_message'    => __( 'Type your message...', 'gspltd-chat-lite' ),
                'send'            => __( 'Send', 'gspltd-chat-lite' ),
                'chat_with_us'    => __( 'Chat with us!', 'gspltd-chat-lite' ),
                'welcome_message' => $this->get_welcome_message(),
                'error_message'   => __( 'Sorry, I encountered an error. Please try again.', 'gspltd-chat-lite' ),
                'connection_error' => __( 'Connection error. Please check your internet and try again.', 'gspltd-chat-lite' ),
                'assistant_name'  => __( 'Robin', 'gspltd-chat-lite' ),
                'assistant_role'  => __( 'AI Assistant', 'gspltd-chat-lite' ),
                'online'          => __( 'Online', 'gspltd-chat-lite' ),
                'typing'          => __( 'Robin is typing...', 'gspltd-chat-lite' ),
                'close_chat'      => __( 'Close chat', 'gspltd-chat-lite' ),
                'limit_reached'   => __( 'Monthly Limit Reached', 'gspltd-chat-lite' ),
                'upgrade_now'     => __( 'Upgrade Now', 'gspltd-chat-lite' ),
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
        $localize_data = \apply_filters( 'gcl_localize_script_data', $localize_data );

        \wp_localize_script( 'gcl-chat-widget', 'gcl_ajax', $localize_data );
    }

    /**
     * Render chat widget in footer.
     */
    public function render_chat_widget(): void {
        if ( \get_option( 'gcl_chat_enabled', '1' ) !== '1' ) {
            return;
        }
        ?>
        <noscript>
            <div class="gcl-noscript-message" style="position: fixed; bottom: 20px; right: 20px; background: #10B981; color: white; padding: 15px 20px; border-radius: 8px; font-family: sans-serif; font-size: 14px; z-index: 9999;">
                <?php esc_html_e( 'JavaScript is required for the chat widget.', 'gspltd-chat-lite' ); ?>
            </div>
        </noscript>
        <?php
    }

    /**
     * Render chat shortcode.
     *
     * Usage: [gspltd_chat]
     *
     * @param array $atts Shortcode attributes.
     * @return string Shortcode output.
     */
    public function render_chat_shortcode( array $atts = [] ): string {
        $atts = \shortcode_atts( [
            'style'       => 'inline',
            'title'       => __( 'Chat with us', 'gspltd-chat-lite' ),
            'button_text' => __( 'Open Chat', 'gspltd-chat-lite' ),
        ], $atts, 'gspltd_chat' );

        if ( \get_option( 'gcl_chat_enabled', '1' ) !== '1' ) {
            return '';
        }

        $this->enqueue_frontend_assets();

        ob_start();

        if ( $atts['style'] === 'button' ) {
            ?>
            <button type="button" class="gcl-shortcode-trigger button"
                    onclick="window.TCLChatWidget && window.TCLChatWidget.openWidget()">
                <?php echo esc_html( $atts['button_text'] ); ?>
            </button>
            <?php
        } else {
            ?>
            <div class="gcl-shortcode-inline">
                <p><strong><?php echo esc_html( $atts['title'] ); ?></strong></p>
                <button type="button" class="gcl-shortcode-trigger button"
                        onclick="window.TCLChatWidget && window.TCLChatWidget.openWidget()">
                    <?php esc_html_e( 'Start Chat', 'gspltd-chat-lite' ); ?>
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
        $custom = \get_option( 'gcl_welcome_message', '' );

        if ( ! empty( $custom ) ) {
            return $custom;
        }

        return __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'gspltd-chat-lite' );
    }

    /**
     * Add cart count fragment for AJAX updates.
     *
     * @param array $fragments Cart fragments.
     * @return array Modified fragments.
     */
    public function add_cart_fragments( array $fragments ): array {
        $fragments['.gcl-cart-count'] = sprintf(
            '<span class="gcl-cart-count">%d</span>',
            \WC()->cart ? \WC()->cart->get_cart_contents_count() : 0
        );

        return $fragments;
    }
}
