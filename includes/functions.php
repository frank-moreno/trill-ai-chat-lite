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
function trill_chat_lite_get_plugin() {
    return \TrillChatLite\Plugin::get_instance();
}

/**
 * Log debug information.
 *
 * Only logs when WP_DEBUG is enabled.
 *
 * @param mixed  $message Message to log.
 * @param string $level   Log level (info, warning, error, debug).
 * @param array  $context Optional context data.
 */
function trill_chat_lite_log( $message, string $level = 'info', array $context = [] ): void {
    if ( ! WP_DEBUG ) {
        return;
    }

    if ( is_array( $message ) || is_object( $message ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional debug output
        $message = print_r( $message, true );
    }

    $prefix = '[Trill Chat Lite] ';
    $label  = strtoupper( $level === 'warning' || $level === 'error' || $level === 'debug' ? $level : 'INFO' );
    $entry  = $prefix . $label . ': ' . $message;

    if ( ! empty( $context ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional debug context
        $entry .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
    error_log( $entry );
}

/**
 * Check if the chat widget should be displayed.
 *
 * @return bool
 */
function trill_chat_lite_should_display_widget(): bool {
    // Don't show in admin.
    if ( is_admin() ) {
        return false;
    }

    // Don't show if disabled.
    if ( get_option( 'tclw_chat_enabled', '1' ) !== '1' ) {
        return false;
    }

    // Don't show on checkout page by default.
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        return apply_filters( 'trill_chat_lite_display_on_checkout', false );
    }

    // Allow filtering.
    return apply_filters( 'trill_chat_lite_display_widget', true );
}

/**
 * Get chat widget configuration.
 *
 * @return array
 */
function trill_chat_lite_get_widget_config(): array {
    return [
        'position'        => get_option( 'tclw_widget_position', 'bottom-right' ),
        'color'           => get_option( 'tclw_widget_color', '#10B981' ),
        'welcome_message' => get_option(
            'tclw_welcome_message',
            __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-chat-lite' )
        ),
        'api_endpoint'    => rest_url( 'tclw/v1/message' ),
        'nonce'           => wp_create_nonce( 'wp_rest' ),
    ];
}

/**
 * Sanitize chat message.
 *
 * @param string $message Raw message.
 * @return string Sanitized message.
 */
function trill_chat_lite_sanitize_message( string $message ): string {
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
function trill_chat_lite_format_price( float $price ): string {
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
function trill_chat_lite_get_product_context( int $product_id ): array {
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
function trill_chat_lite_get_version(): string {
    return defined( 'TRILL_CHAT_LITE_VERSION' ) ? TRILL_CHAT_LITE_VERSION : '1.0.0';
}

/**
 * Check if running in development mode.
 *
 * @return bool
 */
function trill_chat_lite_is_development(): bool {
    return defined( 'WP_DEBUG' ) && WP_DEBUG;
}
