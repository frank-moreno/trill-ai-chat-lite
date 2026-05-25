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
 *   - OPT_TRIAL_REGISTER_PERMA_FAIL → set on permanent failure (409, 400).
 *                                     The admin_init hook does NOT retry
 *                                     — it would just keep hitting the
 *                                     same error and waste backend cycles.
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
     *       → returns false; sets perma-fail; clears retry.
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

        // Permanent failures: stop retrying until a deactivate+activate
        // explicitly clears the perma-fail flag.
        if ( in_array( $error_code, [ 'ALREADY_REGISTERED', 'INVALID_SITE_URL' ], true ) ) {
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
