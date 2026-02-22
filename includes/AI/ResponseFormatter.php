<?php
/**
 * Response Formatter for AI responses.
 *
 * Formats raw AI responses for the chat widget frontend,
 * including product card data and quick replies.
 *
 * @package TrillChatLite\AI
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Response Formatter — formats AI responses for frontend.
 *
 * SOLID: Single Responsibility — only response formatting.
 */
class ResponseFormatter {

    /**
     * Maximum product cards to return.
     */
    private const MAX_PRODUCT_CARDS = 4;

    /**
     * Format a complete API response.
     *
     * @param string $session_id     Session UUID.
     * @param string $ai_content     AI response content.
     * @param int    $message_id     Stored message ID.
     * @param float  $processing_time Processing time in seconds.
     * @param array  $products       Product search results.
     * @return array Formatted response.
     */
    public function format(
        string $session_id,
        string $ai_content,
        int $message_id,
        float $processing_time,
        array $products = []
    ): array {
        $response = [
            'success'    => true,
            'session_id' => $session_id,
            'message'    => [
                'id'        => $message_id,
                'role'      => 'assistant',
                'content'   => $ai_content,
                'timestamp' => \current_time( 'c' ),
            ],
            'processing_time' => round( $processing_time, 3 ),
        ];

        // Add product cards if available.
        if ( ! empty( $products ) ) {
            $response['products'] = $this->format_products( $products );
        }

        // Add quick replies based on context.
        $response['quick_replies'] = $this->determine_quick_replies( $ai_content, $products );

        return $response;
    }

    /**
     * Format an error response.
     *
     * @param string $error_message Human-readable error message.
     * @param string $error_code    Machine-readable error code.
     * @param int    $status_code   HTTP status code.
     * @return \WP_REST_Response Error response.
     */
    public function format_error( string $error_message, string $error_code, int $status_code = 400 ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'success'    => false,
            'error'      => $error_message,
            'error_code' => $error_code,
        ], $status_code );
    }

    /**
     * Format product search results for the frontend.
     *
     * @param array $products Raw product data.
     * @return array Formatted product cards.
     */
    public function format_products( array $products ): array {
        $formatted = [];

        foreach ( $products as $product_data ) {
            $product_id = $product_data['product_id'] ?? null;

            if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
                continue;
            }

            $product = \wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $formatted[] = [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'price'       => $product->get_price(),
                'price_html'  => $product->get_price_html(),
                'image'       => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: '',
                'url'         => $product->get_permalink(),
                'in_stock'    => $product->is_in_stock(),
                'add_to_cart' => $product->is_purchasable() && $product->is_in_stock(),
            ];

            if ( count( $formatted ) >= self::MAX_PRODUCT_CARDS ) {
                break;
            }
        }

        return $formatted;
    }

    /**
     * Determine quick reply buttons based on context.
     *
     * @param string $ai_content AI response text.
     * @param array  $products   Product results.
     * @return array Quick reply buttons.
     */
    private function determine_quick_replies( string $ai_content, array $products = [] ): array {
        // If products were shown, offer product-related actions.
        if ( ! empty( $products ) ) {
            return [
                [ 'label' => __( 'Show more products', 'trill-ai-chat-lite' ), 'value' => 'show more products' ],
                [ 'label' => __( 'View my cart', 'trill-ai-chat-lite' ),       'value' => 'view my cart' ],
                [ 'label' => __( 'Help with sizing', 'trill-ai-chat-lite' ),   'value' => 'help with sizing' ],
            ];
        }

        // Default quick replies.
        return [
            [ 'label' => __( 'Browse products', 'trill-ai-chat-lite' ),       'value' => 'show me your products' ],
            [ 'label' => __( "What's on sale?", 'trill-ai-chat-lite' ),       'value' => "what's on sale" ],
            [ 'label' => __( 'Shipping info', 'trill-ai-chat-lite' ),         'value' => 'tell me about shipping' ],
        ];
    }
}
