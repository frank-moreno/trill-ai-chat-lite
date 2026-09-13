<?php
/**
 * Trial registration orchestrator.
 *
 * Wraps the "call /v1/trial/register and persist the secret" flow with a
 * two-flag retry policy:
 *
 *   - OPT_TRIAL_REGISTER_RETRY      → set on transient failure (network,
 *                                     5xx). The admin_init hook retries
 *                                     on the next admin page load.
 *   - OPT_TRIAL_REGISTER_PERMA_FAIL → set on permanent failure (400, or
 *                                     409 whose rotation also failed).
 *                                     The admin_init hook does NOT retry
 *                                     — it would just keep hitting the
 *                                     same error and waste backend cycles.
 *
 * Since 2.3.0 a 409 already_registered first triggers a secret rotation
 * (prove site control via SiteVerification, get a replacement secret) —
 * the reinstall trap is only terminal when rotation fails too.
 *
 * Both flags are cleared on success. The Activator clears both BEFORE
 * a fresh register attempt, so deactivate+activate is the recovery path
 * for a stuck site.
 *
 * Called from three sites:
 *   1. Activator::activate           — best-effort on plugin activation.
 *   2. Plugin::admin_init_retry_hook — every admin page load if either
 *                                      (a) the retry flag is set, OR
 *                                      (b) there's no secret AND no
 *                                          perma-fail flag (auto-heal
 *                                          path for a manually deleted
 *                                          secret).
 *   3. RestController::handle_message — lazy fallback before serving
 *                                       a chat, if (1) and (2) haven't
 *                                       had a chance to run yet.
 *
 * @package TrillChatLite\Lite
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\AI\ProxyClient;

/**
 * Static orchestrator — keeps the activate/admin/lazy paths DRY.
 *
 * SOLID: Single Responsibility — only the registration lifecycle.
 */
class TrialRegistration {

    /**
     * Ensure THIS site has a trial bearer secret.
     *
     * Outcomes (and the resulting flag state):
     *
     *   - Secret already stored
     *       → returns true; clears both retry and perma-fail flags.
     *   - HTTP register succeeds, secret persisted
     *       → returns true; clears both flags.
     *   - HTTP register returns 409 already_registered
     *       → attempts a secret rotation (2.3.0). Success → returns true,
     *         clears both flags. Failure → returns false; sets perma-fail;
     *         clears retry.
     *   - HTTP register returns 400 invalid_site_url
     *       → returns false; sets perma-fail; clears retry.
     *   - HTTP register fails (network / 5xx)
     *       → returns false; sets retry; clears perma-fail.
     *
     * @return bool True iff a usable secret is now available locally.
     */
    public static function ensure_registered(): bool {
        if ( TrialSecretStore::has_secret() ) {
            self::clear_retry_flag();
            self::clear_perma_fail_flag();
            return true;
        }

        trcl_log( 'TrialRegistration: registering', 'info' );

        $client = new ProxyClient();
        $result = $client->register();

        if ( ! empty( $result['success'] ) && ! empty( $result['secret'] ) ) {
            $saved = TrialSecretStore::set_secret( $result['secret'] );
            if ( $saved ) {
                trcl_log( 'TrialRegistration: secret stored', 'info', [
                    'site_id' => $result['site_id'] ?? '',
                ] );
                self::clear_retry_flag();
                self::clear_perma_fail_flag();
                return true;
            }
            // Saving failed — likely an options-backend issue. Try again.
            trcl_log( 'TrialRegistration: set_secret failed', 'error' );
            self::set_retry_flag();
            self::clear_perma_fail_flag();
            return false;
        }

        $error_code = $result['error_code'] ?? 'UNKNOWN';

        // 409 already_registered → typically a delete + reinstall that
        // lost the secret. Since 2.3.0 this is recoverable: prove control
        // of the site and rotate the secret (plan and usage survive).
        // One attempt per activation cycle — if it fails we perma-fail
        // exactly like before, and deactivate+activate retries.
        if ( $error_code === 'ALREADY_REGISTERED' ) {
            // Rotation CANNOT succeed inside the activation request:
            // WordPress adds the plugin to active_plugins AFTER the
            // activation hook runs, so the backend's verification fetch
            // of /wp-json/trcl/v1/verify hits a site where the route
            // does not exist yet (404). Defer via the retry flag — the
            // redirect straight after activation fires admin_init with
            // the plugin fully active, and rotation runs there.
            if ( self::is_activation_request() ) {
                trcl_log( 'TrialRegistration: deferring rotation until after activation', 'info' );
                self::set_retry_flag();
                self::clear_perma_fail_flag();
                return false;
            }
            if ( self::attempt_rotation() ) {
                return true;
            }
            trcl_log( 'TrialRegistration: rotation failed, permanent failure', 'warning' );
            self::clear_retry_flag();
            self::set_perma_fail_flag();
            return false;
        }

        // Permanent failures: stop retrying until a deactivate+activate
        // explicitly clears the perma-fail flag.
        if ( $error_code === 'INVALID_SITE_URL' ) {
            trcl_log( 'TrialRegistration: permanent failure', 'warning', [
                'error_code' => $error_code,
            ] );
            self::clear_retry_flag();
            self::set_perma_fail_flag();
            return false;
        }

        // Transient — retry next admin page load.
        trcl_log( 'TrialRegistration: transient failure, will retry', 'warning', [
            'error_code'  => $error_code,
            'http_status' => $result['http_status'] ?? null,
        ] );
        self::set_retry_flag();
        self::clear_perma_fail_flag();
        return false;
    }

    /**
     * True iff we are inside a plugin-activation request.
     *
     * The generic `activate_plugin` action fires just before any
     * plugin's activation hook runs, and only in that request — a
     * cheap, reliable marker. Regular admin_init loads and the lazy
     * chat fallback never see it fired.
     *
     * @since 2.3.0
     */
    private static function is_activation_request(): bool {
        return \did_action( 'activate_plugin' ) > 0;
    }

    /**
     * Attempt a secret rotation after a 409 already_registered (2.3.0).
     *
     * Flow (V1 challenge): stage a verify token on this site → call
     * POST /v1/trial/rotate → the backend fetches our /verify endpoint
     * and, if the served token matches, returns a replacement secret.
     * The token is consumed after the response, success or not.
     *
     * @since 2.3.0
     *
     * @return bool True iff a rotated secret was obtained and stored.
     */
    private static function attempt_rotation(): bool {
        $token = SiteVerification::issue_token();
        if ( $token === '' ) {
            return false;
        }

        trcl_log( 'TrialRegistration: attempting secret rotation', 'info' );

        $client = new ProxyClient();
        $result = $client->rotate( $token );

        // Single use — never leave the token standing after the attempt.
        SiteVerification::consume_token();

        if ( ! empty( $result['success'] ) && ! empty( $result['secret'] ) ) {
            if ( TrialSecretStore::set_secret( $result['secret'] ) ) {
                trcl_log( 'TrialRegistration: rotated secret stored', 'info', [
                    'site_id' => $result['site_id'] ?? '',
                ] );
                self::clear_retry_flag();
                self::clear_perma_fail_flag();
                return true;
            }
            trcl_log( 'TrialRegistration: set_secret failed after rotation', 'error' );
            return false;
        }

        trcl_log( 'TrialRegistration: rotation rejected', 'warning', [
            'error_code'  => $result['error_code'] ?? 'UNKNOWN',
            'http_status' => $result['http_status'] ?? null,
        ] );
        return false;
    }

    /**
     * True iff the admin_init hook should try to register on this request.
     *
     * Triggers in two scenarios:
     *   - A previous attempt left an explicit retry flag, OR
     *   - There's no secret AND we haven't hit a permanent failure
     *     (auto-heal path for a manually-cleared secret).
     *
     * This keeps the hook cheap on the happy path (has_secret=true → no work)
     * AND on the permanent-failure path (perma-fail set → skipped).
     */
    public static function should_attempt_on_admin_init(): bool {
        if ( self::has_pending_retry() ) {
            return true;
        }
        if ( TrialSecretStore::has_secret() ) {
            return false;
        }
        if ( self::has_perma_fail() ) {
            return false;
        }
        return true;
    }

    /**
     * Whether a transient retry is pending.
     */
    public static function has_pending_retry(): bool {
        return \get_option( LiteConfig::OPT_TRIAL_REGISTER_RETRY, '' ) === '1';
    }

    /**
     * Whether the plugin has hit a permanent registration failure.
     */
    public static function has_perma_fail(): bool {
        return \get_option( LiteConfig::OPT_TRIAL_REGISTER_PERMA_FAIL, '' ) === '1';
    }

    /**
     * Wipe both retry and perma-fail flags. Called by the Activator
     * before a fresh register attempt so deactivate+activate is the
     * canonical "reset and try again from scratch" recovery path.
     */
    public static function reset_flags(): void {
        self::clear_retry_flag();
        self::clear_perma_fail_flag();
    }

    private static function set_retry_flag(): void {
        \update_option( LiteConfig::OPT_TRIAL_REGISTER_RETRY, '1', false );
    }

    private static function clear_retry_flag(): void {
        \delete_option( LiteConfig::OPT_TRIAL_REGISTER_RETRY );
    }

    private static function set_perma_fail_flag(): void {
        \update_option( LiteConfig::OPT_TRIAL_REGISTER_PERMA_FAIL, '1', false );
    }

    private static function clear_perma_fail_flag(): void {
        \delete_option( LiteConfig::OPT_TRIAL_REGISTER_PERMA_FAIL );
    }
}
