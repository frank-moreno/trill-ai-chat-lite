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

use TrillChatLite\Utils\Encryptor;

/**
 * Wraps wp_option access for the trial bearer secret.
 *
 * The secret is the plaintext returned by POST /v1/trial/register, shape
 * `tt_trial_<32 chars>`. The backend stores only its SHA-256 hash; we
 * store the plaintext because the plugin needs to send it on every
 * chat request as `Authorization: Bearer ...`.
 *
 * Storage (2.5.0): encrypted at rest with Utils\Encryptor (AES-256-GCM,
 * key derived from AUTH_KEY) and stored as `enc:v1:<payload>`. A value
 * that still starts with `tt_trial_` is a plaintext secret written by
 * 2.4.x and earlier; it is re-stored encrypted on first read. When
 * encryption is unavailable (no OpenSSL/GCM, placeholder AUTH_KEY) the
 * secret is kept in plaintext and a warning is logged — never a fake
 * "encoding".
 *
 * Threat model:
 *   - WP database dump alone → no longer exposes the secret; AUTH_KEY
 *     (wp-config.php) is needed as well. Blast radius if both leak:
 *     the attacker can consume this site's Trill Cloud allowance.
 *   - AUTH_KEY rotated by the host → decrypt fails → get_secret() returns
 *     '' → the existing AUTH_INVALID / re-register flow recovers.
 *   - Plugin uninstall → uninstall.php clears this option.
 */
class TrialSecretStore {

    /**
     * Prefix marking an encrypted value. Versioned so a future cipher
     * change can coexist with stored values.
     */
    private const ENC_PREFIX = 'enc:v1:';

    /**
     * Shape of a plaintext secret as issued by the backend.
     */
    private const PLAIN_PREFIX = 'tt_trial_';

    /**
     * Read the stored secret.
     *
     * @return string Empty string if none stored.
     */
    public static function get_secret(): string {
        $value = \get_option( LiteConfig::OPT_TRIAL_SECRET, '' );
        if ( ! is_string( $value ) || $value === '' ) {
            return '';
        }

        if ( str_starts_with( $value, self::ENC_PREFIX ) ) {
            $plain = Encryptor::decrypt( substr( $value, strlen( self::ENC_PREFIX ) ) );
            if ( ! is_string( $plain ) || $plain === '' ) {
                // Different AUTH_KEY or corrupted row: behave as "no
                // secret" so the registration flow re-registers/rotates.
                trcl_log( 'TrialSecretStore: stored secret could not be decrypted', 'warning' );
                return '';
            }
            return $plain;
        }

        // Legacy plaintext (<= 2.4.x): migrate in place when possible.
        if ( str_starts_with( $value, self::PLAIN_PREFIX ) && Encryptor::is_available() ) {
            self::write( $value );
        }

        return $value;
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
        return self::write( $secret );
    }

    /**
     * Encrypt (when possible) and persist. autoload=no — secrets must
     * never load on every WordPress page request.
     *
     * @since 2.5.0
     *
     * @param string $secret Plaintext secret.
     * @return bool True if the row now holds the secret.
     */
    private static function write( string $secret ): bool {
        $stored = $secret;

        if ( Encryptor::is_available() ) {
            $payload = Encryptor::encrypt( $secret );
            if ( is_string( $payload ) ) {
                $stored = self::ENC_PREFIX . $payload;
            } else {
                trcl_log( 'TrialSecretStore: encryption failed, storing plaintext', 'warning' );
            }
        } else {
            trcl_log( 'TrialSecretStore: encryption unavailable (OpenSSL/GCM or AUTH_KEY), storing plaintext', 'warning' );
        }

        // \add_option supports the third autoload param ('no'); on existing
        // rows it returns false and we fall through to update_option which
        // doesn't change autoload — both branches end up autoload=no.
        if ( \add_option( LiteConfig::OPT_TRIAL_SECRET, $stored, '', 'no' ) ) {
            return true;
        }
        if ( \update_option( LiteConfig::OPT_TRIAL_SECRET, $stored ) ) {
            return true;
        }
        // update_option() returns false when the value is unchanged; that
        // still means the row holds what we asked for.
        return \get_option( LiteConfig::OPT_TRIAL_SECRET, '' ) === $stored;
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
