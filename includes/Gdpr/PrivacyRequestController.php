<?php
/**
 * Public "Request My Data" endpoint (v2.4 PRV-01).
 *
 * POST /wp-json/trcl/v1/privacy-request  { "email": "..." }
 *
 * Creates a NATIVE WordPress personal-data export request for the
 * visitor (wp_create_user_request + wp_send_user_request), so the
 * flow lands in Tools → Export Personal Data with WP core's own
 * email confirmation — deliberately no plugin-owned request table
 * (decision recorded in the 2.4.0 functional analysis).
 *
 * Abuse posture (public, unauthenticated endpoint):
 *   - Per-IP rate limit: 3 requests/hour via transient (the IP is
 *     used only as a transient hash, mirroring the chat endpoint's
 *     pattern — never persisted).
 *   - Anti-enumeration: the response is the same generic success
 *     whether or not the email has data, is a WP user, or already
 *     has a pending request. Only a syntactically invalid email
 *     returns an error.
 *
 * @package TrillChatLite\Gdpr
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PrivacyRequestController
 *
 * SOLID: Single Responsibility — only the public privacy-request
 * route. All request lifecycle logic belongs to WP core.
 */
class PrivacyRequestController {

    /**
     * Max requests per IP per hour. Deliberately tight: a legitimate
     * visitor needs exactly one.
     */
    private const MAX_PER_HOUR = 3;

    /**
     * Register the REST route.
     */
    public function register_routes(): void {
        \register_rest_route( 'trcl/v1', '/privacy-request', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => [ $this, 'check_rate_limit' ],
            'args'                => [
                'email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ] );
    }

    /**
     * Permission callback: per-IP rate limit.
     *
     * Mirrors RestController::enforce_rate_limit — the IP is hashed
     * into a transient key and never stored as data.
     *
     * @return true|\WP_Error
     */
    public function check_rate_limit() {
        $ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key     = 'trcl_privreq_' . md5( $ip );
        $current = (int) \get_transient( $key );

        if ( $current >= self::MAX_PER_HOUR ) {
            return new \WP_Error(
                'rest_rate_limited',
                __( 'Too many requests. Please try again later.', 'trill-ai-chat-lite' ),
                [ 'status' => 429 ]
            );
        }

        \set_transient( $key, $current + 1, HOUR_IN_SECONDS );
        return true;
    }

    /**
     * Create + send the native export request.
     *
     * @param \WP_REST_Request $request Incoming request.
     * @return \WP_REST_Response
     */
    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $email = \sanitize_email( (string) $request->get_param( 'email' ) );

        if ( $email === '' || ! \is_email( $email ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'Please enter a valid email address.', 'trill-ai-chat-lite' ),
            ], 400 );
        }

        // Generic response used for every outcome past validation —
        // never confirms whether the email is known to this site.
        $generic = new \WP_REST_Response( [
            'success' => true,
            'message' => __( 'If data exists for that address, a confirmation email is on its way.', 'trill-ai-chat-lite' ),
        ], 200 );

        $request_id = \wp_create_user_request( $email, 'export_personal_data' );

        if ( \is_wp_error( $request_id ) ) {
            // duplicate_request (already pending) et al — log masked,
            // reply generic (anti-enumeration).
            trcl_log( 'Privacy request not created', 'info', [
                'email_masked' => ConversationManager::mask_email( $email ),
                'error_code'   => $request_id->get_error_code(),
            ] );
            return $generic;
        }

        $sent = \wp_send_user_request( $request_id );

        if ( \is_wp_error( $sent ) ) {
            trcl_log( 'Privacy request confirmation email failed', 'warning', [
                'email_masked' => ConversationManager::mask_email( $email ),
                'error_code'   => $sent->get_error_code(),
            ] );
        }

        return $generic;
    }
}
