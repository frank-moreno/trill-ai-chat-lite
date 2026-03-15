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
            $is_product_msg  = $this->is_product_query( $message );
            $product_results = $is_product_msg ? $this->search_products( $message ) : [];

            trcl_log( 'Product search result', 'debug', [
                'message'        => $message,
                'is_product_msg' => $is_product_msg,
                'products_found' => count( $product_results ),
                'product_names'  => array_column( $product_results, 'name' ),
            ] );

            // 6. Build context for proxy.
            $this->prompt_builder->with_store_context( $this->build_store_context() );

            if ( ! empty( $product_results ) ) {
                $this->prompt_builder->with_product_context( $product_results );
            } elseif ( $is_product_msg ) {
                // Search was performed but no products found — inform the AI.
                $this->prompt_builder->with_empty_search_result();
            }

            // Get conversation history.
            $history = $this->db->get_messages( $session_id, 10 );
            $this->prompt_builder->with_history( $history );

            $proxy_context = $this->prompt_builder->build_context();

            trcl_log( 'Proxy context built', 'debug', [
                'has_system_prompt' => ! empty( $proxy_context['system_prompt'] ),
                'has_products'      => ! empty( $proxy_context['products'] ),
                'system_prompt_len' => mb_strlen( $proxy_context['system_prompt'] ?? '' ),
            ] );

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
     * Uses WooCommerce native search as primary strategy, then falls back
     * to taxonomy-based search (categories/tags) if no results are found.
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
            $variants     = $this->get_search_variants( $search_query );
            $products     = [];

            // Primary: WooCommerce native full-text search with plural variants.
            foreach ( $variants as $variant ) {
                $products = \wc_get_products( [
                    'status' => 'publish',
                    'limit'  => 5,
                    's'      => $variant,
                ] );
                if ( ! empty( $products ) ) {
                    break;
                }
            }

            // Fallback: taxonomy search (categories + tags) when native returns empty.
            if ( empty( $products ) ) {
                foreach ( $variants as $variant ) {
                    $products = $this->search_by_taxonomy( $variant );
                    if ( ! empty( $products ) ) {
                        break;
                    }
                }
            }

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
     * Fallback: search products by matching category or tag names.
     *
     * WooCommerce native 's' parameter only searches post_title and
     * post_content. This method catches products that are tagged or
     * categorised with the search term but whose title doesn't contain it
     * (e.g. a "V-Neck Tee" in the "T-Shirts" category).
     *
     * @param string $query Cleaned search query.
     * @param int    $limit Maximum results.
     * @return \WC_Product[] Matching products or empty array.
     */
    private function search_by_taxonomy( string $query, int $limit = 5 ): array {
        $words    = array_filter( explode( ' ', $query ) );
        $products = [];

        // Search product categories.
        $cat_terms = \get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'search'     => $query,
        ] );

        if ( ! \is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
            $cat_slugs = \wp_list_pluck( $cat_terms, 'slug' );
            $products  = \wc_get_products( [
                'status'   => 'publish',
                'limit'    => $limit,
                'category' => $cat_slugs,
            ] );
        }

        // If still empty, try product tags.
        if ( empty( $products ) ) {
            $tag_terms = \get_terms( [
                'taxonomy'   => 'product_tag',
                'hide_empty' => true,
                'search'     => $query,
            ] );

            if ( ! \is_wp_error( $tag_terms ) && ! empty( $tag_terms ) ) {
                $tag_slugs = \wp_list_pluck( $tag_terms, 'slug' );
                $products  = \wc_get_products( [
                    'status' => 'publish',
                    'limit'  => $limit,
                    'tag'    => $tag_slugs,
                ] );
            }
        }

        // Last resort: try each word individually (with plural variants).
        if ( empty( $products ) && count( $words ) > 1 ) {
            foreach ( $words as $word ) {
                if ( mb_strlen( $word ) < 3 ) {
                    continue;
                }
                $word_variants = $this->get_search_variants( $word );
                foreach ( $word_variants as $wv ) {
                    $products = \wc_get_products( [
                        'status' => 'publish',
                        'limit'  => $limit,
                        's'      => $wv,
                    ] );
                    if ( ! empty( $products ) ) {
                        break 2;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Check if message is asking about products.
     *
     * Uses inverted logic: assumes any message COULD be product-related
     * unless it clearly matches a non-product pattern (greetings, thanks,
     * support requests, etc.). This is safer for an e-commerce chatbot
     * where false negatives (missing a product query) are more costly
     * than false positives (searching when unnecessary).
     *
     * @param string $message User message.
     * @return bool True if the message may be product-related.
     */
    private function is_product_query( string $message ): bool {
        $message_lower = strtolower( trim( $message ) );

        // Very short messages that are clearly not product queries.
        $non_product_exact = [
            'hi', 'hello', 'hey', 'hiya', 'yo',
            'thanks', 'thank you', 'cheers', 'ta',
            'bye', 'goodbye', 'see you', 'ciao',
            'yes', 'no', 'ok', 'okay', 'sure', 'nope', 'yep',
            'help', 'support', 'help me',
        ];

        if ( in_array( $message_lower, $non_product_exact, true ) ) {
            return false;
        }

        // Patterns that indicate non-product queries.
        $non_product_patterns = [
            '/^(hi|hello|hey|good\s+(morning|afternoon|evening))\b/i',
            '/^(thanks?|thank\s+you|cheers)\b/i',
            '/^(bye|goodbye|see\s+you|take\s+care)\b/i',
            '/\b(opening\s+hours?|business\s+hours?|when\s+(are\s+you|do\s+you)\s+open)\b/i',
            '/\b(contact|email|phone|call|speak\s+to|talk\s+to)\s+(a\s+)?(human|person|agent|someone|support|staff)\b/i',
            '/\b(return\s+policy|refund\s+policy|shipping\s+policy|privacy\s+policy|terms\s+and\s+conditions)\b/i',
            '/\b(track|tracking)\s+(my\s+)?(order|parcel|package|delivery)\b/i',
            '/\b(who\s+are\s+you|what\s+are\s+you|what\s+can\s+you\s+do)\b/i',
            '/\b(how\s+(long|much)\s+(does|do|is)\s+(delivery|shipping|postage))\b/i',
            '/\b(where\s+is\s+my\s+order|order\s+status|my\s+order)\b/i',
            '/\b(payment\s+method|pay\s+with|accept\s+(paypal|visa|mastercard|card))\b/i',
            '/\b(cancel|change|amend)\s+(my\s+)?(order|subscription)\b/i',
        ];

        foreach ( $non_product_patterns as $pattern ) {
            if ( preg_match( $pattern, $message_lower ) ) {
                return false;
            }
        }

        // Everything else: assume it could be product-related.
        return true;
    }

    /**
     * Extract search query from user message.
     *
     * Strips conversational preamble and filler words, leaving only
     * the terms likely to match WooCommerce product titles/descriptions.
     *
     * @param string $message User message.
     * @return string Cleaned search query.
     */
    private function extract_search_query( string $message ): string {
        $query = strtolower( $message );

        // Remove conversational preambles (order matters: longest first).
        $remove_phrases = [
            // "I'm looking for / I am looking for" family.
            "i'm looking for", 'i am looking for',
            // "Do you have / sell" family.
            'do you have any', 'do you have',
            'do you sell any', 'do you sell',
            'do you stock any', 'do you stock',
            'do you carry any', 'do you carry',
            // "Can / Could" family.
            'can i buy', 'can i get', 'can i see',
            'can you show me', 'can you recommend',
            'could you show me', 'could you recommend',
            // "Where / What" family.
            'where can i find', 'where are the', 'where are your',
            "what's the price of", 'what is the price of',
            "what's available in", 'what is available in',
            'what about', 'what kind of', 'what types of',
            'what sort of',
            // "Show / List" family.
            'show me your', 'show me some', 'show me all', 'show me',
            'list me your', 'list me all', 'list me', 'list your', 'list all',
            // "Have you got" family (British English).
            'have you got any', 'have you got',
            'got any',
            // "I want / need / would like" family.
            'i want to buy', 'i want to see', 'i want some', 'i want',
            'i need to buy', 'i need some', 'i need',
            'i would like to see', 'i would like to buy',
            'i would like some', 'i would like',
            "i'd like to see", "i'd like to buy",
            "i'd like some", "i'd like",
            // "Tell me / Know about" family.
            'tell me about your', 'tell me about',
            'tell me more about', 'know about your',
            // "Looking / Search" family.
            'looking for some', 'looking for',
            'search for', 'find me some', 'find me',
            // "How much" family.
            'how much is', 'how much are', 'how much do',
            'how much does', 'how much for',
            // "Are / Is there" family.
            'are there any', 'is there any', 'is there a',
            // "Recommend" family.
            'any recommendations for', 'any good',
            'recommend me some', 'recommend me',
            'what do you recommend for', 'what do you recommend',
            "what's popular in",
            // "Please" family.
            'please show me', 'please show', 'please find',
            'please list',
        ];

        foreach ( $remove_phrases as $phrase ) {
            $query = str_ireplace( $phrase, '', $query );
        }

        // Remove location/context suffixes that pollute the search term.
        $remove_suffixes = [
            'in your store', 'in the store', 'in your shop', 'in the shop',
            'in this store', 'in this shop', 'on your website', 'on the website',
            'on your site', 'on the site', 'on this site',
            'in your catalogue', 'in the catalogue', 'in your catalog', 'in the catalog',
            'in your collection', 'in the collection',
            'in stock', 'available', 'for sale',
            'that you sell', 'that you have', 'that you offer',
            'you carry', 'you stock', 'you offer',
            'right now', 'at the moment', 'currently', 'today',
            'for me', 'for us',
        ];

        foreach ( $remove_suffixes as $suffix ) {
            $query = str_ireplace( $suffix, '', $query );
        }

        // Remove filler words, punctuation, and articles.
        $query = preg_replace( '/\b(a|an|the|some|any|please|just|maybe|all|your|my|this|that|those|these)\b/', '', $query );
        $query = str_replace( [ '?', '!', '.', ',', ';', ':' ], '', $query );
        $query = trim( preg_replace( '/\s+/', ' ', $query ) );

        // If the cleaned query is empty or too short (< 2 chars), fall back to
        // the longest word(s) from the original message as a last resort.
        if ( mb_strlen( $query ) < 2 ) {
            $fallback_words = array_filter(
                explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s\-]/', '', $message ) ) ),
                function ( $w ) {
                    return mb_strlen( $w ) >= 3;
                }
            );
            if ( ! empty( $fallback_words ) ) {
                // Sort by length descending — longest words are most likely product terms.
                usort( $fallback_words, function ( $a, $b ) {
                    return mb_strlen( $b ) - mb_strlen( $a );
                } );
                $query = implode( ' ', array_slice( $fallback_words, 0, 3 ) );
            }
        }

        return $query ?: strtolower( $message );
    }

    /**
     * Normalise a search term for WooCommerce: try the original and
     * de-pluralised / stemmed variants.
     *
     * WooCommerce native search uses MySQL LIKE %term% which is literal,
     * so "t-shirts" won't match "T-Shirt". This helper returns multiple
     * forms so the caller can try each until one matches.
     *
     * Covers common English plural rules:
     *  - ies → y   (accessories → accessory, hoodies handled by -s rule too)
     *  - ves → f   (scarves → scarf)
     *  - ses/xes/zes/ches/shes → remove trailing "es"
     *  - generic -es  (dresses → dress)
     *  - generic -s   (t-shirts → t-shirt)
     *
     * For multi-word queries each word is also de-pluralised individually
     * and the result added as an extra variant.
     *
     * @param string $term Single or multi-word search term.
     * @return string[] Array of term variants to try (original first).
     */
    private function get_search_variants( string $term ): array {
        $variants = [ $term ];

        // De-pluralise the whole term.
        $singular = $this->depluralize( $term );
        if ( $singular !== $term ) {
            $variants[] = $singular;
        }

        // For multi-word terms, de-pluralise each word individually.
        if ( strpos( $term, ' ' ) !== false ) {
            $words   = explode( ' ', $term );
            $stemmed = array_map( [ $this, 'depluralize' ], $words );
            $joined  = implode( ' ', $stemmed );
            if ( $joined !== $term ) {
                $variants[] = $joined;
            }
        }

        return array_unique( $variants );
    }

    /**
     * Attempt to de-pluralise a single English word.
     *
     * @param string $word Single word.
     * @return string Singular form (best effort) or original.
     */
    private function depluralize( string $word ): string {
        $len = mb_strlen( $word );

        // Too short to safely stem.
        if ( $len < 4 ) {
            return $word;
        }

        // -ies → -y  (accessories → accessory, categories → category).
        if ( preg_match( '/[^aeiou]ies$/i', $word ) ) {
            return preg_replace( '/ies$/i', 'y', $word );
        }

        // -ves → -f  (scarves → scarf, knives → knife).
        if ( preg_match( '/ves$/i', $word ) && $len > 4 ) {
            return preg_replace( '/ves$/i', 'f', $word );
        }

        // -ses, -xes, -zes, -ches, -shes → remove "es"
        // (dresses→dress, boxes→box, watches→watch, brushes→brush).
        if ( preg_match( '/(ss|x|z|ch|sh)es$/i', $word ) ) {
            return preg_replace( '/es$/i', '', $word );
        }

        // Generic -es when word is long enough (shoes stays shoes→shoe OK).
        if ( preg_match( '/[^s]es$/i', $word ) && $len > 4 ) {
            return preg_replace( '/es$/i', '', $word );
        }

        // Generic -s (t-shirts→t-shirt, bags→bag).
        if ( preg_match( '/[^s]s$/i', $word ) && $len > 3 ) {
            return preg_replace( '/s$/i', '', $word );
        }

        return $word;
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
            // Decode HTML entities (e.g. &pound; → £) before sending to the proxy.
            $context['currency_symbol'] = html_entity_decode(
                \get_woocommerce_currency_symbol(),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

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
