<?php
/**
 * Global helper functions for Trill Chat Lite
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Direct access denied.' );
}

/**
 * Get the plugin instance.
 *
 * @return \TrillChatLite\Plugin
 */
function trcl_get_plugin() {
    return \TrillChatLite\Plugin::get_instance();
}

/**
 * Log debug information.
 *
 * Thin wrapper over TrillChatLite\Utils\Logger since 2.4.2, so there is a
 * single logging path: nothing is written unless WP_DEBUG and WP_DEBUG_LOG
 * are both on, and entries below TRCL_LOG_LEVEL (default 'info') are
 * dropped. Never pass visitor-authored content (messages, emails) in
 * $message or $context — log lengths, counts and ids instead.
 *
 * @param mixed  $message Message to log.
 * @param string $level   Log level (info, warning, error, debug).
 * @param array  $context Optional context data.
 */
function trcl_log( $message, string $level = 'info', array $context = [] ): void {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }

    // uninstall.php loads this file without the autoloader.
    if ( ! class_exists( \TrillChatLite\Utils\Logger::class ) ) {
        return;
    }

    if ( is_array( $message ) || is_object( $message ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional debug output
        $message = print_r( $message, true );
    }

    static $logger = null;
    if ( null === $logger ) {
        $logger = new \TrillChatLite\Utils\Logger( 'Plugin' );
    }

    switch ( $level ) {
        case 'debug':
            $logger->debug( (string) $message, $context );
            break;
        case 'warning':
            $logger->warning( (string) $message, $context );
            break;
        case 'error':
            $logger->error( (string) $message, $context );
            break;
        default:
            $logger->info( (string) $message, $context );
    }
}

/**
 * Check if the chat widget should be displayed.
 *
 * @return bool
 */
function trcl_should_display_widget(): bool {
    // Don't show in admin.
    if ( is_admin() ) {
        return false;
    }

    // Don't show if disabled.
    if ( get_option( 'trcl_chat_enabled', '1' ) !== '1' ) {
        return false;
    }

    // Don't show on checkout page by default.
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        return apply_filters( 'trcl_display_on_checkout', false );
    }

    // Allow filtering.
    return apply_filters( 'trcl_display_widget', true );
}

/**
 * Get chat widget configuration.
 *
 * @return array
 */
function trcl_get_widget_config(): array {
    return [
        'position'        => get_option( 'trcl_widget_position', 'bottom-right' ),
        'color'           => get_option( 'trcl_widget_color', '#10B981' ),
        'welcome_message' => get_option(
            'trcl_welcome_message',
            __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-ai-chat-lite' )
        ),
        'api_endpoint'    => rest_url( 'trcl/v1/message' ),
        'nonce'           => wp_create_nonce( 'wp_rest' ),
    ];
}

/**
 * Sanitize chat message.
 *
 * @param string $message Raw message.
 * @return string Sanitized message.
 */
function trcl_sanitize_message( string $message ): string {
    $message = wp_kses( $message, [
        'br'     => [],
        'p'      => [],
        'strong' => [],
        'em'     => [],
        'a'      => [ 'href' => [], 'target' => [] ],
    ] );

    // Limit length.
    return substr( $message, 0, 1000 );
}

/**
 * Format price for display.
 *
 * @param float $price Price value.
 * @return string Formatted price.
 */
function trcl_format_price( float $price ): string {
    if ( function_exists( 'wc_price' ) ) {
        return wc_price( $price );
    }

    return '$' . number_format( $price, 2 );
}

/**
 * Get product context for AI.
 *
 * @param int $product_id Product ID.
 * @return array Product data.
 */
function trcl_get_product_context( int $product_id ): array {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return [];
    }

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return [];
    }

    return [
        'id'           => $product->get_id(),
        'name'         => $product->get_name(),
        'price'        => $product->get_price(),
        'description'  => $product->get_short_description(),
        'stock_status' => $product->get_stock_status(),
        'permalink'    => $product->get_permalink(),
    ];
}

/**
 * Get plugin version.
 *
 * @return string
 */
function trcl_get_version(): string {
    return defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '1.0.0';
}

/**
 * Check if running in development mode.
 *
 * @return bool
 */
function trcl_is_development(): bool {
    return defined( 'WP_DEBUG' ) && WP_DEBUG;
}
