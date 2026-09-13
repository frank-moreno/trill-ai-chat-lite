<?php
/**
 * Encryption helper for secrets stored in wp_options.
 *
 * @package TrillChatLite\Utils
 * @since 1.0.0
 * @since 2.5.0 AES-256-GCM (authenticated), no plaintext fallback.
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Encryptor
 *
 * AES-256-GCM with a key derived from AUTH_KEY. The IV and the
 * authentication tag travel with the ciphertext, so a tampered or
 * truncated value fails to decrypt instead of yielding garbage.
 *
 * Callers must check is_available() and keep their own plaintext path
 * (with a warning) when it returns false — this class never pretends
 * to encrypt.
 */
class Encryptor {

    /**
     * Cipher. GCM = authenticated encryption; 12-byte IV, 16-byte tag.
     */
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH  = 12;
    private const TAG_LENGTH = 16;

    /**
     * Whether encryption can be used on this site.
     *
     * Requires the OpenSSL extension with GCM support and a real AUTH_KEY
     * (the wp-config.php default placeholder is not a key).
     *
     * @since 2.5.0
     */
    public static function is_available(): bool {
        if ( ! extension_loaded( 'openssl' ) || ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
            return false;
        }
        return defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && AUTH_KEY !== '' && AUTH_KEY !== 'put your unique phrase here';
    }

    /**
     * Derive the 32-byte key from AUTH_KEY.
     */
    private static function get_key(): string {
        return hash( 'sha256', (string) AUTH_KEY, true );
    }

    /**
     * Encrypt a string.
     *
     * @param string $value Plaintext.
     * @return string|false base64( iv . tag . ciphertext ), or false when
     *                      encryption is unavailable or fails.
     */
    public static function encrypt( string $value ) {
        if ( ! self::is_available() ) {
            return false;
        }

        try {
            $iv = random_bytes( self::IV_LENGTH );
        } catch ( \Exception $e ) {
            return false;
        }

        $tag        = '';
        $ciphertext = openssl_encrypt( $value, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );
        if ( false === $ciphertext || strlen( $tag ) !== self::TAG_LENGTH ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage in wp_options, not obfuscation.
        return base64_encode( $iv . $tag . $ciphertext );
    }

    /**
     * Decrypt a value produced by encrypt().
     *
     * @param string $encrypted base64 payload.
     * @return string|false Plaintext, or false when unavailable, malformed,
     *                      tampered, or encrypted under a different AUTH_KEY.
     */
    public static function decrypt( string $encrypted ) {
        if ( ! self::is_available() ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
        $data = base64_decode( $encrypted, true );
        if ( false === $data || strlen( $data ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
            return false;
        }

        $iv         = substr( $data, 0, self::IV_LENGTH );
        $tag        = substr( $data, self::IV_LENGTH, self::TAG_LENGTH );
        $ciphertext = substr( $data, self::IV_LENGTH + self::TAG_LENGTH );

        $plain = openssl_decrypt( $ciphertext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

        return false === $plain ? false : $plain;
    }
}
