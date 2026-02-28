<?php
/**
 * Encryption utility.
 *
 * Provides simple encryption/decryption for sensitive data
 * using WordPress salts and OpenSSL.
 *
 * @package TrillChatLite\Utils
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Encryptor
 *
 * SOLID: Single Responsibility — only encryption operations.
 */
class Encryptor {

    /**
     * Cipher method.
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Get the encryption key from WordPress salts.
     *
     * @return string Encryption key.
     */
    private static function get_key(): string {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'trcl-default-encryption-key';
        return hash( 'sha256', $salt, true );
    }

    /**
     * Encrypt a value.
     *
     * @param string $value Value to encrypt.
     * @return string|false Encrypted value (base64 encoded) or false on failure.
     */
    public static function encrypt( string $value ) {
        if ( ! extension_loaded( 'openssl' ) ) {
            trcl_log( 'OpenSSL extension not loaded, returning raw value', 'warning' );
            return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER );
        $iv        = openssl_random_pseudo_bytes( $iv_length );
        $encrypted = openssl_encrypt( $value, self::CIPHER, self::get_key(), 0, $iv );

        if ( false === $encrypted ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt a value.
     *
     * @param string $encrypted Encrypted value (base64 encoded).
     * @return string|false Decrypted value or false on failure.
     */
    public static function decrypt( string $encrypted ) {
        if ( ! extension_loaded( 'openssl' ) ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            return base64_decode( $encrypted, true );
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $data = base64_decode( $encrypted, true );

        if ( false === $data ) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER );

        if ( strlen( $data ) < $iv_length ) {
            return false;
        }

        $iv            = substr( $data, 0, $iv_length );
        $encrypted_data = substr( $data, $iv_length );

        return openssl_decrypt( $encrypted_data, self::CIPHER, self::get_key(), 0, $iv );
    }
}
