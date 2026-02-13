<?php
/**
 * Managed Proxy Client for Lite tier.
 *
 * Sends chat requests to the Trill AI proxy endpoint.
 * Uses site-based authentication (no licence key required).
 *
 * @package TrillChatLite\AI
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Lite\LiteConfig;

/**
 * Proxy Client — Lite managed proxy.
 *
 * SOLID: Single Responsibility — only API communication.
 * SOLID: Dependency Inversion — depends on LiteConfig abstraction.
 */
class ProxyClient {

    /**
     * Request timeout in seconds.
     */
    private const TIMEOUT = 30;

    /**
     * Generate site hash for authentication.
     *
     * @return string SHA-256 hash of site URL + AUTH_KEY salt.
     */
    private function generate_site_hash(): string {
        $site_url = \get_site_url();
        $salt     = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'tcl-default-salt';
        return hash( 'sha256', $site_url . $salt );
    }

    /**
     * Build request headers.
     *
     * @return array Headers array for wp_remote_post.
     */
    private function build_headers(): array {
        return [
            'Content-Type'         => 'application/json',
            'X-TCL-Site-URL'       => \get_site_url(),
            'X-TCL-Plugin-Version' => defined( 'TRILL_CHAT_LITE_VERSION' ) ? TRILL_CHAT_LITE_VERSION : '1.0.0',
            'X-TCL-Site-Hash'      => $this->generate_site_hash(),
        ];
    }

    /**
     * Send a chat message to the proxy.
     *
     * @param string $message         User message.
     * @param string $conversation_id Conversation UUID.
     * @param array  $context         Additional context (products, page, etc.).
     * @return array{success: bool, message?: array, error?: string, error_code?: string, meta?: array}
     */
    public function send_message( string $message, string $conversation_id, array $context = [] ): array {
        $url = LiteConfig::PROXY_BASE_URL . LiteConfig::PROXY_CHAT_PATH;

        $body = [
            'message'         => $message,
            'conversation_id' => $conversation_id,
            'context'         => $context,
        ];

        trill_chat_lite_log( 'ProxyClient: sending request', 'debug', [
            'url'             => $url,
            'conversation_id' => $conversation_id,
            'message_length'  => mb_strlen( $message ),
        ] );

        $response = \wp_remote_post( $url, [
            'headers' => $this->build_headers(),
            'body'    => \wp_json_encode( $body ),
            'timeout' => self::TIMEOUT,
        ] );

        // Handle connection errors.
        if ( \is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            trill_chat_lite_log( 'ProxyClient: connection error', 'error', [
                'error' => $error_message,
            ] );

            return [
                'success'    => false,
                'error'      => __( 'Unable to connect to AI service. Please try again later.', 'trill-chat-lite' ),
                'error_code' => 'CONNECTION_ERROR',
            ];
        }

        $status_code   = \wp_remote_retrieve_response_code( $response );
        $response_body = \wp_remote_retrieve_body( $response );
        $decoded       = json_decode( $response_body, true );

        // Handle HTTP errors.
        if ( $status_code !== 200 ) {
            trill_chat_lite_log( 'ProxyClient: HTTP error', 'error', [
                'status_code' => $status_code,
                'body'        => mb_substr( $response_body, 0, 500 ),
            ] );

            return $this->handle_http_error( $status_code, $decoded );
        }

        // Handle malformed response.
        if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
            trill_chat_lite_log( 'ProxyClient: malformed response', 'error', [
                'body_preview' => mb_substr( $response_body, 0, 200 ),
            ] );

            return [
                'success'    => false,
                'error'      => __( 'Received invalid response from AI service.', 'trill-chat-lite' ),
                'error_code' => 'MALFORMED_RESPONSE',
            ];
        }

        trill_chat_lite_log( 'ProxyClient: response received', 'info', [
            'conversation_id'         => $conversation_id,
            'response_length'         => mb_strlen( $decoded['response'] ),
            'conversations_remaining' => $decoded['meta']['conversations_remaining'] ?? null,
        ] );

        return [
            'success' => true,
            'message' => $decoded['message'] ?? [
                'role'    => 'assistant',
                'content' => '',
            ],
            'meta' => $decoded['meta'] ?? [],
        ];
    }

    /**
     * Handle HTTP error status codes.
     *
     * @param int        $status_code HTTP status code.
     * @param array|null $decoded     Decoded response body.
     * @return array Error response array.
     */
    private function handle_http_error( int $status_code, ?array $decoded ): array {
        $error_message = $decoded['error'] ?? '';

        switch ( $status_code ) {
            case 429:
                return [
                    'success'    => false,
                    'error'      => __( 'Too many requests. Please wait a moment and try again.', 'trill-chat-lite' ),
                    'error_code' => 'RATE_LIMITED',
                ];

            case 402:
                return [
                    'success'    => false,
                    'error'      => __( 'Monthly conversation limit reached. Upgrade for unlimited conversations.', 'trill-chat-lite' ),
                    'error_code' => 'LIMIT_REACHED',
                    'meta'       => [
                        'upgrade_url' => LiteConfig::getUpgradeUrl( 'limit_reached' ),
                    ],
                ];

            case 403:
                return [
                    'success'    => false,
                    'error'      => __( 'Access denied. Please check your site configuration.', 'trill-chat-lite' ),
                    'error_code' => 'FORBIDDEN',
                ];

            case 500:
            case 502:
            case 503:
                return [
                    'success'    => false,
                    'error'      => __( 'AI service is temporarily unavailable. Please try again later.', 'trill-chat-lite' ),
                    'error_code' => 'SERVICE_UNAVAILABLE',
                ];

            default:
                return [
                    'success'    => false,
                    'error'      => sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'Unexpected error (HTTP %d). Please try again.', 'trill-chat-lite' ),
                        $status_code
                    ),
                    'error_code' => 'HTTP_ERROR',
                ];
        }
    }

    /**
     * Check if the proxy service is reachable.
     *
     * @return bool True if the service responds.
     */
    public function is_available(): bool {
        $url      = LiteConfig::PROXY_BASE_URL . '/health';
        $response = \wp_remote_get( $url, [
            'timeout' => 5,
            'headers' => $this->build_headers(),
        ] );

        if ( \is_wp_error( $response ) ) {
            return false;
        }

        return \wp_remote_retrieve_response_code( $response ) === 200;
    }
}
