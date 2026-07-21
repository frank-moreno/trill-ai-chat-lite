<?php
/**
 * Site-ownership verification for secret rotation (2.3.0, V1 flow).
 *
 * When the backend answers /v1/trial/register with 409 (site already
 * registered — typically after a delete + reinstall), the plugin proves
 * it controls this site so the backend will rotate the secret:
 *
 *   1. issue_token() puts a random 64-hex token in a 5-minute transient.
 *   2. The plugin calls POST /v1/trial/rotate { siteUrl, verifyToken }.
 *   3. The BACKEND fetches GET /wp-json/trcl/v1/verify on this site and
 *      compares what the site serves against the request body. The
 *      request body is not the proof — the fetch is: an attacker can
 *      send any token but cannot make this WordPress serve it.
 *   4. consume_token() deletes the transient — single use, and outside
 *      a rotation window the endpoint 404s (zero standing surface).
 *
 * The route is public and unauthenticated BY DESIGN: it is the proof
 * mechanism (same trust model as Let's Encrypt HTTP-01). It is
 * read-only, serves no personal data, and only exists functionally
 * during the 5-minute window of a rotation in progress.
 *
 * @package TrillChatLite\Lite
 * @since 2.3.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SiteVerification
 *
 * SOLID: Single Responsibility — only the verify-token lifecycle and
 * its REST endpoint. Rotation orchestration lives in TrialRegistration;
 * transport lives in ProxyClient.
 */
class SiteVerification {

    /**
     * Transient key holding the pending verify token.
     */
    private const TOKEN_TRANSIENT = 'trcl_rotate_verify_token';

    /**
     * Token time-to-live (seconds). A rotation round-trip takes seconds;
     * five minutes leaves room for a slow backend fetch without leaving
     * a long-lived token lying around.
     */
    private const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * REST namespace — matches the chat routes (trcl/v1).
     */
    private const API_NAMESPACE = 'trcl/v1';

    /**
     * Register the REST route. Called from Plugin on rest_api_init.
     */
    public function register_routes(): void {
        \register_rest_route(
            self::API_NAMESPACE,
            '/verify',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_verify' ],
                // Public by design — see class docblock. Read-only, no
                // personal data, 404 outside a rotation window.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * GET /wp-json/trcl/v1/verify handler.
     *
     * @return \WP_REST_Response
     */
    public function handle_verify(): \WP_REST_Response {
        $token = self::get_pending_token();

        if ( $token === '' ) {
            return new \WP_REST_Response( [ 'error' => 'no_pending_verification' ], 404 );
        }

        return new \WP_REST_Response( [ 'token' => $token ], 200 );
    }

    /**
     * Generate and stage a fresh verify token for one rotation attempt.
     *
     * @return string The 64-hex token, or '' if it could not be staged
     *                (transient write failed — caller aborts rotation).
     */
    public static function issue_token(): string {
        try {
            $token = bin2hex( random_bytes( 32 ) );
        } catch ( \Exception $e ) {
            trcl_log( 'SiteVerification: random_bytes failed', 'error', [
                'error' => $e->getMessage(),
            ] );
            return '';
        }

        if ( ! \set_transient( self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL ) ) {
            trcl_log( 'SiteVerification: could not stage verify token', 'error' );
            return '';
        }

        return $token;
    }

    /**
     * Read the pending token, if any.
     *
     * @return string Empty string when no rotation is in progress.
     */
    public static function get_pending_token(): string {
        $value = \get_transient( self::TOKEN_TRANSIENT );
        return is_string( $value ) ? $value : '';
    }

    /**
     * Invalidate the pending token (single use). Called after the
     * rotation response arrives, on success AND on failure.
     */
    public static function consume_token(): void {
        \delete_transient( self::TOKEN_TRANSIENT );
    }
}
