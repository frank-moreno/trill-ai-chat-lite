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
use TrillChatLite\Lite\TrialRegistration;
use TrillChatLite\Search\ProductSearch;

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
     * Product search service.
     *
     * @var ProductSearch
     */
    private ProductSearch $search;

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
        $this->search         = new ProductSearch();
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
            $is_product_msg  = $this->search->is_product_query( $message );
            $product_results = $is_product_msg ? $this->search->search( $message ) : [];

            trcl_log( 'Product search result', 'debug', [
                'message'        => $message,
                'is_product_msg' => $is_product_msg,
                'products_found' => count( $product_results ),
                'product_names'  => array_column( $product_results, 'name' ),
            ] );

            // 6. Build system prompt with store + product context.
            $store_context = $this->build_store_context();
            $this->prompt_builder->with_store_context( $store_context );
            $this->prompt_builder->with_guardrails_context( $store_context );

            if ( ! empty( $product_results ) ) {
                $this->prompt_builder->with_product_context( $product_results );
            } elseif ( $is_product_msg ) {
                $this->prompt_builder->with_empty_search_result();
            }

            $system_prompt = $this->prompt_builder->build();

            // 7. Lazy trial registration fallback. The Activator and the
            //    admin_init retry hook should already have run, but if a
            //    front-end visitor arrives before either does (rare edge
            //    case on a fresh activate), try one more time here.
            TrialRegistration::ensure_registered();

            // 8. Build the messages[] array for the stateless backend.
            //    - First: system prompt with persona, guardrails, products.
            //    - Then: last N turns of history (already includes the user
            //      message we just stored on step 4).
            $history = $this->db->get_conversation_history(
                $session_id,
                LiteConfig::MAX_HISTORY_MESSAGES
            );

            $messages = array_merge(
                [ [ 'role' => 'system', 'content' => $system_prompt ] ],
                $history
            );

            trcl_log( 'Built messages payload', 'debug', [
                'session_id'          => $session_id,
                'message_count'       => count( $messages ),
                'system_prompt_bytes' => mb_strlen( $system_prompt ),
                'history_turns'       => count( $history ),
                'products_found'      => count( $product_results ),
            ] );

            // 9. Send to Trill Cloud backend.
            $ai_response = $this->proxy->send_message( $messages, $session_id );

            if ( ! $ai_response['success'] ) {
                trcl_log( 'Proxy request failed', 'error', [
                    'error_code' => $ai_response['error_code'] ?? 'UNKNOWN',
                    'session_id' => $session_id,
                ] );

                $error_code = $ai_response['error_code'] ?? 'AI_ERROR';

                // Trial monthly cap reached → 429 with upgrade_url for the
                // widget to render a "Get more conversations" CTA.
                if ( $error_code === 'TRIAL_EXHAUSTED' ) {
                    return new \WP_REST_Response( [
                        'success'     => false,
                        'error'       => $ai_response['error'] ?? __( 'Monthly trial limit reached.', 'trill-ai-chat-lite' ),
                        'error_code'  => 'TRIAL_EXHAUSTED',
                        'upgrade_url' => $ai_response['upgrade_url'] ?? LiteConfig::PRICING_URL,
                        'reset_at'    => $ai_response['reset_at'] ?? '',
                    ], 429 );
                }

                if ( $error_code === 'RATE_LIMITED' ) {
                    return new \WP_REST_Response( [
                        'success'             => false,
                        'error'               => $ai_response['error'] ?? __( 'Too many requests.', 'trill-ai-chat-lite' ),
                        'error_code'          => 'RATE_LIMITED',
                        'retry_after_seconds' => $ai_response['retry_after_seconds'] ?? 0,
                    ], 429 );
                }

                // AUTH_INVALID indicates a corrupted local secret. Clear it
                // so the next admin page load re-registers cleanly. The
                // current request still fails — UX trade-off accepted.
                if ( $error_code === 'AUTH_INVALID' ) {
                    \TrillChatLite\Lite\TrialSecretStore::clear_secret();
                }

                $http_status = isset( $ai_response['http_status'] )
                    ? (int) $ai_response['http_status']
                    : 502;
                return $this->formatter->format_error(
                    $ai_response['error'] ?? __( 'AI service temporarily unavailable.', 'trill-ai-chat-lite' ),
                    $error_code,
                    $http_status >= 400 ? $http_status : 502
                );
            }

            // 10. Store AI response.
            $ai_content    = $ai_response['reply'];
            $ai_message_id = $this->db->create_message( $session_id, 'assistant', $ai_content );
            if ( ! $ai_message_id ) {
                trcl_log( 'Failed to store AI message (response still returned)', 'warning', [
                    'session_id' => $session_id,
                ] );
                $ai_message_id = 0;
            }

            // 11. Stash the X-Trill-Trial-Remaining value for the dashboard
            //     widget. Don't fail the request if the option write fails.
            if ( isset( $ai_response['trial_remaining'] ) ) {
                \update_option(
                    LiteConfig::OPT_TRIAL_REMAINING,
                    (int) $ai_response['trial_remaining'],
                    false
                );
            }

            // 12. Format and return.
            $processing_time = microtime( true ) - $start_time;

            trcl_log( 'Message processed successfully', 'info', [
                'session_id'      => $session_id,
                'processing_time' => round( $processing_time, 3 ),
                'is_new'          => $is_new_conversation,
                'products_found'  => count( $product_results ),
                'trial_remaining' => $ai_response['trial_remaining'] ?? null,
            ] );

            $response_data = $this->formatter->format(
                $session_id,
                $ai_content,
                $ai_message_id,
                $processing_time,
                $product_results
            );

            if ( isset( $ai_response['trial_remaining'] ) ) {
                $response_data['meta'] = [
                    'trial_remaining' => (int) $ai_response['trial_remaining'],
                ];
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
