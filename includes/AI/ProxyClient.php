<?php
/**
 * Trill Cloud backend client (api-v2.trillai.io, plugin v2.0+).
 *
 * Talks to the new `api-v2.trillai.io` backend introduced with the
 * OSS plugin 2.0 release. Replaces the legacy v1.x site-hash auth
 * with a Bearer-per-site model:
 *
 *   1. The plugin calls POST /v1/trial/register ONCE per site URL,
 *      gets back a `tt_trial_*` plaintext secret, and persists it
 *      via TrialSecretStore. The backend stores only the SHA-256
 *      hash — re-registration with the same site URL returns 409.
 *
 *   2. Every chat request is POST /v1/trial/chat with
 *      `Authorization: Bearer <secret>` and the full conversation
 *      history as `messages[]`. Server is stateless.
 *
 *   3. The X-Trill-Trial-Remaining response header conveys the
 *      remaining trial allowance (cap - used - 1) for plugin UX.
 *
 * @package TrillChatLite\AI
 * @since 1.0.0 — site-hash transport
 * @since 2.0.0 — Bearer-per-site transport
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Lite\LiteConfig;
use TrillChatLite\Lite\TrialSecretStore;

/**
 * Proxy Client — Trill Cloud backend wrapper.
 *
 * SOLID: Single Responsibility — only API transport.
 */
class ProxyClient {

    /**
     * Request timeout for chat calls (seconds). Backend timeout to its
     * upstream OpenAI is 30s; we give it some headroom.
     */
    private const CHAT_TIMEOUT = 35;

    /**
     * Request timeout for register / health (fast endpoints).
     */
    private const SHORT_TIMEOUT = 10;

    /**
     * Request timeout for rotate (2.3.0). Longer than SHORT_TIMEOUT
     * because the backend performs its own 5 s verification fetch back
     * to this site inside the request.
     */
    private const ROTATE_TIMEOUT = 15;

    // =========================================================================
    // Register
    // =========================================================================

    /**
     * Call POST /v1/trial/register to obtain a bearer secret for THIS site.
     *
     * Caller is responsible for persisting the secret via TrialSecretStore.
     * Idempotency: a 409 from the backend means the site URL is already
     * registered with a DIFFERENT secret (we cannot recover it). The
     * caller should NOT clobber an existing stored secret on 409.
     *
     * @return array{
     *     success: bool,
     *     secret?: string,
     *     site_id?: string,
     *     error?: string,
     *     error_code?: string,
     *     http_status?: int,
     * }
     */
    public function register(): array {
        $url = LiteConfig::get_trial_register_url();
        $body = [
            'siteUrl' => \get_site_url(),
        ];

        trcl_log( 'ProxyClient::register sending', 'debug', [
            'url'      => $url,
            'site_url' => $body['siteUrl'],
        ] );

        $response = \wp_remote_post( $url, [
            'headers' => [
                'Content-Type'         => 'application/json',
                'Accept'               => 'application/json',
                'User-Agent'           => $this->user_agent(),
                'X-Trill-Plugin-Ver'   => defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '0.0.0',
            ],
            'body'    => \wp_json_encode( $body ),
            'timeout' => self::SHORT_TIMEOUT,
        ] );

        if ( \is_wp_error( $response ) ) {
            trcl_log( 'ProxyClient::register network error', 'error', [
                'error' => $response->get_error_message(),
            ] );
            return [
                'success'    => false,
                'error'      => __( 'Could not reach Trill Cloud to set up the trial. We will retry automatically.', 'trill-ai-chat-lite' ),
                'error_code' => 'NETWORK_ERROR',
            ];
        }

        $status_code = \wp_remote_retrieve_response_code( $response );
        $body_raw    = \wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body_raw, true );

        // Happy path: 201 with secret.
        if ( $status_code === 201 && is_array( $decoded ) && ! empty( $decoded['secret'] ) ) {
            trcl_log( 'ProxyClient::register success', 'info', [
                'site_id' => $decoded['siteId'] ?? null,
            ] );
            return [
                'success' => true,
                'secret'  => (string) $decoded['secret'],
                'site_id' => isset( $decoded['siteId'] ) ? (string) $decoded['siteId'] : '',
            ];
        }

        // 409 already_registered → site URL collision (likely the plugin
        // re-installed and lost its secret). The backend won't give us
        // the original secret back — the caller recovers via rotate().
        if ( $status_code === 409 ) {
            trcl_log( 'ProxyClient::register duplicate', 'warning', [
                'site_url' => $body['siteUrl'],
            ] );
            return [
                'success'     => false,
                'error'       => __( 'This site is already registered. The plugin will verify site ownership and reconnect automatically.', 'trill-ai-chat-lite' ),
                'error_code'  => 'ALREADY_REGISTERED',
                'http_status' => 409,
            ];
        }

        // 400 invalid_site_url etc.
        if ( $status_code === 400 ) {
            $reason = is_array( $decoded ) ? ( $decoded['reason'] ?? '' ) : '';
            trcl_log( 'ProxyClient::register bad request', 'error', [
                'reason' => $reason,
            ] );
            return [
                'success'     => false,
                'error'       => __( 'The site URL was rejected by the backend. Make sure it is publicly reachable (https://).', 'trill-ai-chat-lite' ),
                'error_code'  => 'INVALID_SITE_URL',
                'http_status' => 400,
            ];
        }

        // Anything else.
        trcl_log( 'ProxyClient::register unexpected response', 'error', [
            'status_code' => $status_code,
            'body'        => mb_substr( (string) $body_raw, 0, 300 ),
        ] );
        return [
            'success'     => false,
            'error'       => __( 'Unexpected response from Trill Cloud while setting up the trial.', 'trill-ai-chat-lite' ),
            'error_code'  => 'UNEXPECTED',
            'http_status' => (int) $status_code,
        ];
    }

    // =========================================================================
    // Rotate (2.3.0 — reinstall recovery)
    // =========================================================================

    /**
     * Call POST /v1/trial/rotate to replace this site's secret after a
     * 409 from register (typically a delete + reinstall).
     *
     * The caller (TrialRegistration) must have staged $verify_token via
     * SiteVerification::issue_token() FIRST — the backend fetches
     * GET /wp-json/trcl/v1/verify on this site during the request and
     * only rotates if the served token matches.
     *
     * @since 2.3.0
     *
     * @param string $verify_token The staged 64-hex verify token.
     * @return array{
     *     success: bool,
     *     secret?: string,
     *     site_id?: string,
     *     error?: string,
     *     error_code?: string,
     *     http_status?: int,
     * }
     */
    public function rotate( string $verify_token ): array {
        $url  = LiteConfig::get_trial_rotate_url();
        $body = [
            'siteUrl'     => \get_site_url(),
            'verifyToken' => $verify_token,
        ];

        trcl_log( 'ProxyClient::rotate sending', 'debug', [
            'url'      => $url,
            'site_url' => $body['siteUrl'],
        ] );

        $response = \wp_remote_post( $url, [
            'headers' => [
                'Content-Type'       => 'application/json',
                'Accept'             => 'application/json',
                'User-Agent'         => $this->user_agent(),
                'X-Trill-Plugin-Ver' => defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '0.0.0',
            ],
            'body'    => \wp_json_encode( $body ),
            'timeout' => self::ROTATE_TIMEOUT,
        ] );

        if ( \is_wp_error( $response ) ) {
            trcl_log( 'ProxyClient::rotate network error', 'error', [
                'error' => $response->get_error_message(),
            ] );
            return [
                'success'    => false,
                'error'      => __( 'Could not reach Trill Cloud to reconnect this site.', 'trill-ai-chat-lite' ),
                'error_code' => 'NETWORK_ERROR',
            ];
        }

        $status_code = \wp_remote_retrieve_response_code( $response );
        $body_raw    = \wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body_raw, true );

        // Happy path: 201 with the replacement secret.
        if ( $status_code === 201 && is_array( $decoded ) && ! empty( $decoded['secret'] ) ) {
            trcl_log( 'ProxyClient::rotate success', 'info', [
                'site_id' => $decoded['siteId'] ?? null,
            ] );
            return [
                'success' => true,
                'secret'  => (string) $decoded['secret'],
                'site_id' => isset( $decoded['siteId'] ) ? (string) $decoded['siteId'] : '',
            ];
        }

        // 404 → no registration to rotate; caller falls back to register.
        if ( $status_code === 404 ) {
            trcl_log( 'ProxyClient::rotate site not registered', 'warning' );
            return [
                'success'     => false,
                'error'       => __( 'This site is not registered with Trill Cloud yet.', 'trill-ai-chat-lite' ),
                'error_code'  => 'SITE_NOT_REGISTERED',
                'http_status' => 404,
            ];
        }

        // 409 → the backend could not verify ownership (site unreachable,
        // REST API blocked, token mismatch...). Reason is logged for
        // support; the user-facing copy lives in the admin notice.
        if ( $status_code === 409 ) {
            $reason = is_array( $decoded ) ? (string) ( $decoded['reason'] ?? '' ) : '';
            trcl_log( 'ProxyClient::rotate verification failed', 'warning', [
                'reason' => $reason,
            ] );
            return [
                'success'     => false,
                'error'       => __( 'Trill Cloud could not verify ownership of this site.', 'trill-ai-chat-lite' ),
                'error_code'  => 'VERIFICATION_FAILED',
                'http_status' => 409,
            ];
        }

        // 429 → rotation budget exhausted for today.
        if ( $status_code === 429 ) {
            trcl_log( 'ProxyClient::rotate rate limited', 'warning' );
            return [
                'success'     => false,
                'error'       => __( 'Too many reconnection attempts today. Please try again tomorrow.', 'trill-ai-chat-lite' ),
                'error_code'  => 'RATE_LIMITED',
                'http_status' => 429,
            ];
        }

        // Anything else (400 validation, 5xx...).
        trcl_log( 'ProxyClient::rotate unexpected response', 'error', [
            'status_code' => $status_code,
            'body'        => mb_substr( (string) $body_raw, 0, 300 ),
        ] );
        return [
            'success'     => false,
            'error'       => __( 'Unexpected response from Trill Cloud while reconnecting this site.', 'trill-ai-chat-lite' ),
            'error_code'  => 'UNEXPECTED',
            'http_status' => (int) $status_code,
        ];
    }

    // =========================================================================
    // Chat
    // =========================================================================

    /**
     * Send a chat completion request.
     *
     * @param array $messages Array of {role, content} turns. The caller
     *                        (RestController) builds this from the
     *                        pre-built system prompt + persisted history.
     * @param string $session_id UUID of the conversation (for backend logs).
     * @return array{
     *     success: bool,
     *     reply?: string,
     *     provider?: string,
     *     trial_remaining?: int,
     *     trial_cap?: int,
     *     error?: string,
     *     error_code?: string,
     *     http_status?: int,
     * }
     */
    public function send_message( array $messages, string $session_id = '' ): array {
        $secret = TrialSecretStore::get_secret();
        if ( $secret === '' ) {
            trcl_log( 'ProxyClient::send_message missing secret', 'error' );
            return [
                'success'    => false,
                'error'      => __( 'The plugin has not finished setting up the trial. Please try again in a moment.', 'trill-ai-chat-lite' ),
                'error_code' => 'NOT_REGISTERED',
            ];
        }

        $url  = LiteConfig::get_trial_chat_url();
        $body = [
            'messages' => $messages,
        ];
        if ( $session_id !== '' ) {
            $body['sessionId'] = $session_id;
        }

        trcl_log( 'ProxyClient::send_message sending', 'debug', [
            'url'           => $url,
            'message_count' => count( $messages ),
            'session_id'    => $session_id,
        ] );

        $response = \wp_remote_post( $url, [
            'headers' => [
                'Content-Type'        => 'application/json',
                'Accept'              => 'application/json',
                'Authorization'       => 'Bearer ' . $secret,
                'User-Agent'          => $this->user_agent(),
                'X-Trill-Plugin-Ver'  => defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '0.0.0',
            ],
            'body'    => \wp_json_encode( $body ),
            'timeout' => self::CHAT_TIMEOUT,
        ] );

        if ( \is_wp_error( $response ) ) {
            trcl_log( 'ProxyClient::send_message network error', 'error', [
                'error' => $response->get_error_message(),
            ] );
            return [
                'success'    => false,
                'error'      => __( 'Unable to reach the AI service. Please try again later.', 'trill-ai-chat-lite' ),
                'error_code' => 'NETWORK_ERROR',
            ];
        }

        $status_code = (int) \wp_remote_retrieve_response_code( $response );
        $body_raw    = \wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body_raw, true );

        // Read the remaining trial allowance — backend exposes it on every
        // 200, useful for the dashboard widget + chat UI countdown. Since
        // 2.3.1 the plan-aware cap travels alongside it (X-Trill-Trial-Cap)
        // so the dashboard can show the real allowance per plan.
        $headers   = \wp_remote_retrieve_headers( $response );
        $remaining = null;
        $cap       = null;
        if ( $headers ) {
            // wp_remote_retrieve_headers returns a Requests_Utility_CaseInsensitiveDictionary
            // (or array, depending on WP version); both support array access.
            $raw = $headers['x-trill-trial-remaining'] ?? null;
            if ( $raw !== null && $raw !== '' && is_numeric( $raw ) ) {
                $remaining = (int) $raw;
            }
            $raw_cap = $headers['x-trill-trial-cap'] ?? null;
            if ( $raw_cap !== null && $raw_cap !== '' && is_numeric( $raw_cap ) ) {
                $cap = (int) $raw_cap;
            }
        }

        if ( $status_code === 200 && is_array( $decoded ) && isset( $decoded['reply'] ) ) {
            return [
                'success'         => true,
                'reply'           => (string) $decoded['reply'],
                'provider'        => (string) ( $decoded['provider'] ?? 'openai' ),
                'trial_remaining' => $remaining,
                'trial_cap'       => $cap,
            ];
        }

        // Non-200: map to internal error_code via the helper.
        return $this->handle_http_error( $status_code, is_array( $decoded ) ? $decoded : null );
    }

    // =========================================================================
    // Health
    // =========================================================================

    /**
     * Quick check that the backend is reachable. Used by the dashboard
     * widget to surface a "service down" message without burning a chat.
     */
    public function is_available(): bool {
        $url      = LiteConfig::get_health_url();
        $response = \wp_remote_get( $url, [
            'timeout' => self::SHORT_TIMEOUT,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => $this->user_agent(),
            ],
        ] );

        if ( \is_wp_error( $response ) ) {
            return false;
        }
        return (int) \wp_remote_retrieve_response_code( $response ) === 200;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Build a User-Agent string identifying this plugin to the backend.
     * Lets backend ops correlate traffic with plugin version.
     */
    private function user_agent(): string {
        $version = defined( 'TRCL_VERSION' ) ? TRCL_VERSION : '0.0.0';
        return 'trill-ai-chat-lite/' . $version . ' (+' . LiteConfig::SUPPORT_URL . ')';
    }

    /**
     * Map an upstream non-200 to our internal error shape.
     *
     * Backend → plugin error code mapping:
     *   401 unauthorized          → AUTH_INVALID (secret revoked / cleared)
     *   429 trial_exhausted       → TRIAL_EXHAUSTED (cap hit, distinct from rate limit)
     *   429 rate_limit_exceeded   → RATE_LIMITED (try again shortly)
     *   400 content_policy_*      → CONTENT_REFUSED (rephrase prompt)
     *   400 invalid_request       → BAD_REQUEST (plugin bug)
     *   502 provider_error        → SERVICE_UNAVAILABLE
     *   503 upstream_overloaded   → SERVICE_UNAVAILABLE (try again later)
     *   504 upstream_timeout      → SERVICE_TIMEOUT
     *   anything else             → HTTP_ERROR
     */
    private function handle_http_error( int $status_code, ?array $decoded ): array {
        $backend_error = is_array( $decoded ) ? ( $decoded['error'] ?? '' ) : '';

        switch ( $status_code ) {
            case 401:
                trcl_log( 'ProxyClient: auth invalid', 'warning' );
                return [
                    'success'     => false,
                    'error'       => __( 'Trial credentials are no longer valid. Please reactivate the plugin.', 'trill-ai-chat-lite' ),
                    'error_code'  => 'AUTH_INVALID',
                    'http_status' => 401,
                ];

            case 429:
                if ( $backend_error === 'trial_exhausted' ) {
                    return [
                        'success'        => false,
                        'error'          => __( 'Monthly trial limit reached. Upgrade to keep chatting.', 'trill-ai-chat-lite' ),
                        'error_code'     => 'TRIAL_EXHAUSTED',
                        'http_status'    => 429,
                        'upgrade_url'    => is_array( $decoded ) ? ( $decoded['upgrade_url'] ?? LiteConfig::PRICING_URL ) : LiteConfig::PRICING_URL,
                        'reset_at'       => is_array( $decoded ) ? ( $decoded['reset_at'] ?? '' ) : '',
                    ];
                }
                return [
                    'success'              => false,
                    'error'                => __( 'Too many requests. Please wait a moment and try again.', 'trill-ai-chat-lite' ),
                    'error_code'           => 'RATE_LIMITED',
                    'http_status'          => 429,
                    'retry_after_seconds'  => is_array( $decoded ) ? (int) ( $decoded['retry_after_seconds'] ?? 0 ) : 0,
                ];

            case 400:
                if ( $backend_error === 'content_policy_violation' ) {
                    return [
                        'success'     => false,
                        'error'       => __( 'Your message was refused on content-policy grounds. Please rephrase.', 'trill-ai-chat-lite' ),
                        'error_code'  => 'CONTENT_REFUSED',
                        'http_status' => 400,
                    ];
                }
                return [
                    'success'     => false,
                    'error'       => __( 'The request was rejected by the AI service.', 'trill-ai-chat-lite' ),
                    'error_code'  => 'BAD_REQUEST',
                    'http_status' => 400,
                ];

            case 502:
            case 503:
                return [
                    'success'     => false,
                    'error'       => __( 'AI service is temporarily unavailable. Please try again shortly.', 'trill-ai-chat-lite' ),
                    'error_code'  => 'SERVICE_UNAVAILABLE',
                    'http_status' => $status_code,
                ];

            case 504:
                return [
                    'success'     => false,
                    'error'       => __( 'The AI service took too long to respond. Please try again.', 'trill-ai-chat-lite' ),
                    'error_code'  => 'SERVICE_TIMEOUT',
                    'http_status' => 504,
                ];

            default:
                return [
                    'success'     => false,
                    'error'       => sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'Unexpected error (HTTP %d). Please try again.', 'trill-ai-chat-lite' ),
                        $status_code
                    ),
                    'error_code'  => 'HTTP_ERROR',
                    'http_status' => $status_code,
                ];
        }
    }
}
