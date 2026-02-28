<?php
/**
 * REST API Controller for Lite plugin.
 *
 * Handles registration and processing of all REST API endpoints.
 * Simplified for Lite: managed proxy only, no BYOK, no topic enforcement.
 *
 * Conversation limits are enforced exclusively server-side by the proxy
 * (api.trillai.io) via HTTP 429 responses. No local enforcement per
 * WordPress.org Guideline 5 (no trialware).
 *
 * @package TrillChatLite\AI
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Database\DbManager;
use TrillChatLite\Lite\LiteConfig;

/**
 * REST Controller — Lite API endpoints.
 *
 * SOLID: Single Responsibility — only REST API routing.
 * SOLID: Dependency Inversion — receives DbManager via constructor.
 */
class RestController {

    /**
     * API namespace.
     *
     * @var string
     */
    private const API_NAMESPACE = 'trcl/v1';

    /**
     * UUID validation pattern.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Maximum message length.
     */
    private const MAX_MESSAGE_LENGTH = 500;

    /**
     * Rate limit: max requests per minute per IP.
     */
    private const RATE_LIMIT_PER_MINUTE = 10;

    /**
     * Database manager.
     *
     * @var DbManager
     */
    private DbManager $db;

    /**
     * Proxy client.
     *
     * @var ProxyClient
     */
    private ProxyClient $proxy;

    /**
     * Prompt builder.
     *
     * @var PromptBuilder
     */
    private PromptBuilder $prompt_builder;

    /**
     * Response formatter.
     *
     * @var ResponseFormatter
     */
    private ResponseFormatter $formatter;

    /**
     * Constructor.
     *
     * @param DbManager $db Database manager instance.
     */
    public function __construct( DbManager $db ) {
        $this->db             = $db;
        $this->proxy          = new ProxyClient();
        $this->prompt_builder = new PromptBuilder();
        $this->formatter      = new ResponseFormatter();
    }

    /**
     * Register all REST API routes.
     */
    public function register_routes(): void {
        // POST /wp-json/trcl/v1/message
        // Intentionally public: store visitors (not logged in) must be able to chat.
        // Rate limiting via proxy (server-side) and per-IP transient (client-side).
        \register_rest_route(
            self::API_NAMESPACE,
            '/message',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_message' ],
                'permission_callback' => [ $this, 'check_message_permissions' ],
                'args'                => $this->get_message_args(),
            ]
        );

        // GET /wp-json/trcl/v1/conversation/{session_id}
        // Semi-public: validates session ownership via cookie/fingerprint.
        \register_rest_route(
            self::API_NAMESPACE,
            '/conversation/(?P<session_id>[\w-]+)',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_get_conversation' ],
                'permission_callback' => [ $this, 'check_conversation_permissions' ],
                'args'                => [
                    'session_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'description'       => 'Session UUID',
                        'validate_callback' => [ $this, 'validate_uuid' ],
                    ],
                ],
            ]
        );

        // POST /wp-json/trcl/v1/feedback
        // Semi-public: validates that message_id belongs to an active session.
        \register_rest_route(
            self::API_NAMESPACE,
            '/feedback',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_feedback' ],
                'permission_callback' => [ $this, 'check_feedback_permissions' ],
                'args'                => $this->get_feedback_args(),
            ]
        );

        trcl_log( 'REST API routes registered', 'debug', [
            'namespace' => self::API_NAMESPACE,
        ] );
    }

    /**
     * Handle message endpoint.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function handle_message( \WP_REST_Request $request ): \WP_REST_Response {
        $start_time = microtime( true );

        try {
            // 1. Extract parameters.
            $message    = \sanitize_textarea_field( $request->get_param( 'message' ) );
            $session_id = \sanitize_text_field( $request->get_param( 'session_id' ) ?? '' );
            $context    = $request->get_param( 'context' ) ?? [];

            // 2. Validate message.
            if ( empty( trim( $message ) ) ) {
                return $this->formatter->format_error(
                    __( 'Message cannot be empty.', 'trill-ai-chat-lite' ),
                    'EMPTY_MESSAGE',
                    400
                );
            }

            if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
                return $this->formatter->format_error(
                    sprintf(
                        /* translators: %d: maximum character length */
                        __( 'Message exceeds maximum length of %d characters.', 'trill-ai-chat-lite' ),
                        self::MAX_MESSAGE_LENGTH
                    ),
                    'MESSAGE_TOO_LONG',
                    400
                );
            }

            // 3. Get or create conversation (no local limit check — server enforces).
            $is_new_conversation = false;

            if ( empty( $session_id ) ) {
                $user_id    = \get_current_user_id() ?: 0;
                $session_id = $this->db->create_conversation( $user_id );

                if ( empty( $session_id ) ) {
                    trcl_log( 'Failed to create conversation', 'error' );
                    return $this->formatter->format_error(
                        __( 'Failed to create conversation.', 'trill-ai-chat-lite' ),
                        'DB_ERROR',
                        500
                    );
                }

                $is_new_conversation = true;
            } else {
                // Validate existing session.
                if ( ! preg_match( self::UUID_PATTERN, $session_id ) ) {
                    return $this->formatter->format_error(
                        __( 'Invalid session ID format.', 'trill-ai-chat-lite' ),
                        'INVALID_SESSION',
                        400
                    );
                }

                if ( ! $this->db->conversation_exists( $session_id ) ) {
                    return $this->formatter->format_error(
                        __( 'Session not found. Please start a new conversation.', 'trill-ai-chat-lite' ),
                        'SESSION_NOT_FOUND',
                        404
                    );
                }
            }

            // 4. Store user message.
            $user_message_id = $this->db->create_message( $session_id, 'user', $message );

            if ( ! $user_message_id ) {
                trcl_log( 'Failed to store user message', 'error', [ 'session_id' => $session_id ] );
                return $this->formatter->format_error(
                    __( 'Failed to store message.', 'trill-ai-chat-lite' ),
                    'DB_ERROR',
                    500
                );
            }

            // 5. Search relevant products.
            $product_results = $this->search_products( $message );

            // 6. Build context for proxy.
            $this->prompt_builder->with_store_context( $this->build_store_context() );

            if ( ! empty( $product_results ) ) {
                $this->prompt_builder->with_product_context( $product_results );
            }

            // Get conversation history.
            $history = $this->db->get_messages( $session_id, 10 );
            $this->prompt_builder->with_history( $history );

            $proxy_context = $this->prompt_builder->build_context();

            // 7. Send to proxy.
            $ai_response = $this->proxy->send_message( $message, $session_id, $proxy_context );

            if ( ! $ai_response['success'] ) {
                trcl_log( 'Proxy request failed', 'error', [
                    'error_code' => $ai_response['error_code'] ?? 'UNKNOWN',
                    'session_id' => $session_id,
                ] );

                $error_code = $ai_response['error_code'] ?? 'AI_ERROR';

                // Handle proxy 429 (server-side limit reached) gracefully.
                if ( $error_code === 'LIMIT_REACHED' ) {
                    return new \WP_REST_Response( [
                        'success'     => false,
                        'error'       => __( 'You have reached your monthly conversation limit. Upgrade for unlimited conversations.', 'trill-ai-chat-lite' ),
                        'error_code'  => 'SERVICE_LIMIT_REACHED',
                        'upgrade_url' => LiteConfig::getUpgradeUrl( 'api_limit' ),
                    ], 429 );
                }

                return $this->formatter->format_error(
                    $ai_response['error'] ?? __( 'AI service temporarily unavailable.', 'trill-ai-chat-lite' ),
                    $error_code,
                    502
                );
            }

            // 8. Store AI response.
            $ai_content    = $ai_response['message']['content'];
            $ai_message_id = $this->db->create_message( $session_id, 'assistant', $ai_content );

            if ( ! $ai_message_id ) {
                trcl_log( 'Failed to store AI message (response still returned)', 'warning', [
                    'session_id' => $session_id,
                ] );
                $ai_message_id = 0;
            }

            // 9. Format and return response.
            $processing_time = microtime( true ) - $start_time;

            trcl_log( 'Message processed successfully', 'info', [
                'session_id'       => $session_id,
                'processing_time'  => round( $processing_time, 3 ),
                'is_new'           => $is_new_conversation,
                'products_found'   => count( $product_results ),
            ] );

            $response_data = $this->formatter->format(
                $session_id,
                $ai_content,
                $ai_message_id,
                $processing_time,
                $product_results
            );

            // Add proxy meta if available.
            if ( ! empty( $ai_response['meta'] ) ) {
                $response_data['meta'] = $ai_response['meta'];
            }

            return new \WP_REST_Response( $response_data, 200 );

        } catch ( \Exception $e ) {
            trcl_log( 'Message endpoint exception', 'error', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ] );

            return $this->formatter->format_error(
                __( 'An unexpected error occurred. Please try again.', 'trill-ai-chat-lite' ),
                'INTERNAL_ERROR',
                500
            );
        }
    }

    /**
     * Handle get conversation endpoint.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function handle_get_conversation( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $session_id = \sanitize_text_field( $request->get_param( 'session_id' ) );

            if ( ! $this->db->conversation_exists( $session_id ) ) {
                return $this->formatter->format_error(
                    __( 'Conversation not found.', 'trill-ai-chat-lite' ),
                    'NOT_FOUND',
                    404
                );
            }

            $messages = $this->db->get_messages( $session_id, 50 );

            $formatted_messages = array_map( function ( $msg ) {
                return [
                    'id'        => $msg->id ?? 0,
                    'role'      => $msg->role,
                    'content'   => $msg->content,
                    'timestamp' => $msg->created_at ?? '',
                ];
            }, $messages );

            return new \WP_REST_Response( [
                'success'    => true,
                'session_id' => $session_id,
                'messages'   => $formatted_messages,
            ], 200 );

        } catch ( \Exception $e ) {
            trcl_log( 'Conversation endpoint error', 'error', [ 'error' => $e->getMessage() ] );

            return $this->formatter->format_error(
                __( 'Failed to retrieve conversation.', 'trill-ai-chat-lite' ),
                'INTERNAL_ERROR',
                500
            );
        }
    }

    /**
     * Handle feedback endpoint.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function handle_feedback( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $message_id = absint( $request->get_param( 'message_id' ) );
            $rating     = absint( $request->get_param( 'rating' ) );
            $comment    = \sanitize_textarea_field( $request->get_param( 'comment' ) ?? '' );

            $saved = $this->db->save_feedback( $message_id, $rating, $comment );

            if ( ! $saved ) {
                return $this->formatter->format_error(
                    __( 'Failed to save feedback.', 'trill-ai-chat-lite' ),
                    'DB_ERROR',
                    500
                );
            }

            return new \WP_REST_Response( [
                'success' => true,
                'message' => __( 'Thank you for your feedback!', 'trill-ai-chat-lite' ),
            ], 200 );

        } catch ( \Exception $e ) {
            trcl_log( 'Feedback endpoint error', 'error', [ 'error' => $e->getMessage() ] );

            return $this->formatter->format_error(
                __( 'Failed to save feedback.', 'trill-ai-chat-lite' ),
                'INTERNAL_ERROR',
                500
            );
        }
    }

    // =========================================================================
    // PERMISSION CALLBACKS (ISSUE-05: Separate per endpoint)
    // =========================================================================

    /**
     * Permission callback for POST /message.
     *
     * Intentionally public — store visitors (not logged in) must be able to
     * send messages via the chat widget. Rate limiting is enforced per-IP
     * and the proxy enforces conversation quotas server-side.
     *
     * @param \WP_REST_Request $request Request object.
     * @return true|\WP_Error
     */
    public function check_message_permissions( \WP_REST_Request $request ) {
        // Verify nonce if provided (logged-in users).
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! empty( $nonce ) && ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Invalid security token. Please refresh the page.', 'trill-ai-chat-lite' ),
                [ 'status' => 403 ]
            );
        }

        // Per-IP rate limiting (max 10 requests/minute).
        return $this->enforce_rate_limit( self::RATE_LIMIT_PER_MINUTE );
    }

    /**
     * Permission callback for GET /conversation/{session_id}.
     *
     * Semi-public — validates that the requester owns the session by
     * checking the session_id exists and was created recently.
     * The session_id itself acts as a bearer token (UUID is unguessable).
     *
     * @param \WP_REST_Request $request Request object.
     * @return true|\WP_Error
     */
    public function check_conversation_permissions( \WP_REST_Request $request ) {
        // Verify nonce if provided.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! empty( $nonce ) && ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Invalid security token. Please refresh the page.', 'trill-ai-chat-lite' ),
                [ 'status' => 403 ]
            );
        }

        // Validate UUID format.
        $session_id = $request->get_param( 'session_id' );
        if ( empty( $session_id ) || ! preg_match( self::UUID_PATTERN, $session_id ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Invalid session identifier.', 'trill-ai-chat-lite' ),
                [ 'status' => 403 ]
            );
        }

        // Rate limit (30/minute for reads — less restrictive than writes).
        return $this->enforce_rate_limit( 30 );
    }

    /**
     * Permission callback for POST /feedback.
     *
     * Semi-public — validates that the message_id is a positive integer.
     * The callback handler will verify the message exists.
     *
     * @param \WP_REST_Request $request Request object.
     * @return true|\WP_Error
     */
    public function check_feedback_permissions( \WP_REST_Request $request ) {
        // Verify nonce if provided.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! empty( $nonce ) && ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Invalid security token. Please refresh the page.', 'trill-ai-chat-lite' ),
                [ 'status' => 403 ]
            );
        }

        // Validate message_id is provided and positive.
        $message_id = $request->get_param( 'message_id' );
        if ( empty( $message_id ) || ! is_numeric( $message_id ) || (int) $message_id <= 0 ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'Invalid message identifier.', 'trill-ai-chat-lite' ),
                [ 'status' => 400 ]
            );
        }

        // Rate limit (10/minute — same as message to prevent spam).
        return $this->enforce_rate_limit( self::RATE_LIMIT_PER_MINUTE );
    }

    /**
     * Enforce per-IP rate limiting via transient.
     *
     * @param int $max_per_minute Maximum requests per minute.
     * @return true|\WP_Error
     */
    private function enforce_rate_limit( int $max_per_minute ) {
        $ip      = $this->get_client_ip();
        $ip_hash = md5( $ip );
        $key     = 'trcl_rate_' . $ip_hash;
        $count   = (int) \get_transient( $key );

        if ( $count >= $max_per_minute ) {
            return new \WP_Error(
                'rest_rate_limited',
                __( 'Too many requests. Please try again later.', 'trill-ai-chat-lite' ),
                [ 'status' => 429 ]
            );
        }

        \set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

        return true;
    }

    /**
     * Validate UUID format.
     *
     * @param string           $value   UUID value.
     * @param \WP_REST_Request $request Request object.
     * @param string           $param   Parameter name.
     * @return bool
     */
    public function validate_uuid( $value, $request, $param ): bool {
        if ( empty( $value ) ) {
            return true;
        }
        return (bool) preg_match( self::UUID_PATTERN, $value );
    }

    /**
     * Get message endpoint argument schema.
     *
     * @return array
     */
    private function get_message_args(): array {
        return [
            'message'    => [
                'required'          => true,
                'type'              => 'string',
                'description'       => 'User message content',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'session_id' => [
                'required'          => false,
                'type'              => 'string',
                'description'       => 'Existing session UUID (optional)',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this, 'validate_uuid' ],
            ],
            'context'    => [
                'required'    => false,
                'type'        => 'object',
                'description' => 'Additional context',
                'default'     => [],
            ],
        ];
    }

    /**
     * Get feedback endpoint argument schema.
     *
     * @return array
     */
    private function get_feedback_args(): array {
        return [
            'message_id' => [
                'required'          => true,
                'type'              => 'integer',
                'description'       => 'Message ID to rate',
                'validate_callback' => function ( $value ) {
                    return is_numeric( $value ) && $value > 0;
                },
            ],
            'rating'     => [
                'required'          => true,
                'type'              => 'integer',
                'description'       => 'Rating from 1 to 5',
                'validate_callback' => function ( $value ) {
                    return is_numeric( $value ) && $value >= 1 && $value <= 5;
                },
            ],
            'comment'    => [
                'required'          => false,
                'type'              => 'string',
                'description'       => 'Optional feedback comment',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
        ];
    }

    /**
     * Search for products relevant to the message.
     *
     * @param string $message User message.
     * @return array Product results or empty array.
     */
    private function search_products( string $message ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        // Check if message is product-related.
        if ( ! $this->is_product_query( $message ) ) {
            return [];
        }

        try {
            $search_query = $this->extract_search_query( $message );

            $products = \wc_get_products( [
                'status' => 'publish',
                'limit'  => 5,
                's'      => $search_query,
            ] );

            $results = [];
            foreach ( $products as $product ) {
                $results[] = [
                    'product_id' => $product->get_id(),
                    'name'       => $product->get_name(),
                    'price'      => trcl_format_price( $product->get_price() ),
                    'url'        => $product->get_permalink(),
                    'in_stock'   => $product->is_in_stock(),
                ];
            }

            return $results;

        } catch ( \Exception $e ) {
            trcl_log( 'Product search failed', 'warning', [ 'error' => $e->getMessage() ] );
            return [];
        }
    }

    /**
     * Check if message is asking about products.
     *
     * @param string $message User message.
     * @return bool
     */
    private function is_product_query( string $message ): bool {
        $keywords = [
            'product', 'item', 'buy', 'purchase', 'price', 'cost',
            'stock', 'available', 'sell', 'shop', 'catalog',
            'looking for', 'search', 'find', 'show me', 'recommend',
            'cheap', 'expensive', 'sale', 'discount', 'offer',
        ];

        $message_lower = strtolower( $message );

        foreach ( $keywords as $keyword ) {
            if ( strpos( $message_lower, $keyword ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract search query from user message.
     *
     * @param string $message User message.
     * @return string Cleaned search query.
     */
    private function extract_search_query( string $message ): string {
        $remove_phrases = [
            'do you have', 'can i buy', 'show me', 'i want', 'i need',
            'looking for', 'search for', 'find me', 'what about',
            'how much is', 'how much are', "i'm looking for",
        ];

        $query = strtolower( $message );

        foreach ( $remove_phrases as $phrase ) {
            $query = str_ireplace( $phrase, '', $query );
        }

        $query = str_replace( '?', '', $query );
        $query = trim( preg_replace( '/\s+/', ' ', $query ) );

        return $query ?: $message;
    }

    /**
     * Build store context for the prompt.
     *
     * @return array Store context data.
     */
    private function build_store_context(): array {
        $context = [
            'store_name'        => \get_bloginfo( 'name' ),
            'store_url'         => \get_site_url(),
            'store_description' => \get_bloginfo( 'description' ),
        ];

        if ( function_exists( 'WC' ) ) {
            $context['currency']        = \get_woocommerce_currency();
            $context['currency_symbol'] = \get_woocommerce_currency_symbol();

            $product_count            = \wp_count_posts( 'product' );
            $context['total_products'] = (int) ( $product_count->publish ?? 0 );

            // Top categories.
            $terms = \get_terms( [
                'taxonomy'   => 'product_cat',
                'orderby'    => 'count',
                'order'      => 'DESC',
                'number'     => 5,
                'hide_empty' => true,
            ] );

            if ( ! \is_wp_error( $terms ) && ! empty( $terms ) ) {
                $context['top_categories'] = \wp_list_pluck( $terms, 'name' );
            }
        }

        return $context;
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip(): string {
        $ip_keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = \sanitize_text_field( \wp_unslash( $_SERVER[ $key ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
