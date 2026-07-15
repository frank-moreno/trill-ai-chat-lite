<?php
/**
 * Trial bearer secret storage abstraction.
 *
 * Single, narrow interface over wp_options for the `tt_trial_*` secret
 * obtained from POST /v1/trial/register. Keeps the rest of the codebase
 * free of get_option/update_option calls for this specific value so:
 *
 *   - There's exactly ONE place that knows the option name + autoload flag.
 *   - Tests can mock this class without monkey-patching WordPress globals.
 *   - Future rotations / encryption-at-rest can land in one place.
 *
 * @package TrillChatLite\Lite
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Lite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wraps wp_option access for the trial bearer secret.
 *
 * The secret is the plaintext returned by POST /v1/trial/register, shape
 * `tt_trial_<32 chars>`. The backend stores only its SHA-256 hash; we
 * store the plaintext because the plugin needs to send it on every
 * chat request as `Authorization: Bearer ...`.
 *
 * Threat model:
 *   - WP database dump → exposes the secret. Blast radius: the attacker
 *     can consume 50 trial messages/month for THIS specific site. We
 *     accept this trade-off (anonymous trial, no user data exposed).
 *   - Plugin uninstall → uninstall.php should clear this option.
 */
class TrialSecretStore {

    /**
     * Read the stored secret.
     *
     * @return string Empty string if none stored.
     */
    public static function get_secret(): string {
        $value = \get_option( LiteConfig::OPT_TRIAL_SECRET, '' );
        return is_string( $value ) ? $value : '';
    }

    /**
     * Persist the secret. autoload=no — secrets must never load on
     * every WordPress page request.
     *
     * Returns true if write succeeded (or value was already set to
     * the same string), false otherwise.
     */
    public static function set_secret( string $secret ): bool {
        if ( $secret === '' ) {
            return false;
        }

        // \add_option supports the third autoload param ('no'); on existing
        // rows it returns false and we fall through to update_option which
        // doesn't change autoload — both branches end up autoload=no.
        $added = \add_option( LiteConfig::OPT_TRIAL_SECRET, $secret, '', 'no' );
        if ( $added ) {
            return true;
        }
        return (bool) \update_option( LiteConfig::OPT_TRIAL_SECRET, $secret );
    }

    /**
     * Drop the secret. Used by the upgrade-detection migration (clears
     * any orphan secret from a botched re-install) and by uninstall.php.
     */
    public static function clear_secret(): bool {
        return (bool) \delete_option( LiteConfig::OPT_TRIAL_SECRET );
    }

    /**
     * True iff a non-empty secret is stored.
     */
    public static function has_secret(): bool {
        return self::get_secret() !== '';
    }
}
