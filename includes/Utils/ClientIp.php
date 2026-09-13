<?php
/**
 * Client IP resolution for rate limiting.
 *
 * @package TrillChatLite\Utils
 * @since 2.4.2
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ClientIp
 *
 * Single place for "which address do we rate-limit on". Shared by the
 * chat REST routes and the public privacy-request route so both apply
 * the same trust rules.
 */
class ClientIp {

    /**
     * Cloudflare edge ranges (https://www.cloudflare.com/ips/, fetched
     * 2026-09-13). CF-Connecting-IP is only trusted when REMOTE_ADDR is
     * inside one of these, so the header cannot be forged by a client
     * that is not actually behind Cloudflare.
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Get client IP address.
     *
     * Reads REMOTE_ADDR. X-Forwarded-For / Client-IP are written by the
     * client, so trusting them lets anyone reset the per-IP rate limit
     * with a fresh header per request (2.4.2). The one proxy handled out
     * of the box is Cloudflare: CF-Connecting-IP is honoured only when
     * the connection itself comes from a Cloudflare range. Any other
     * reverse proxy or CDN resolves the real address through the
     * `trcl_client_ip` filter.
     *
     * @return string Validated IP, or '0.0.0.0' when none is available.
     */
    public static function get(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_cloudflare_ip( $ip ) ) {
            $cf = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
                $ip = $cf;
            }
        }

        /**
         * Filter the client IP used for REST rate limiting.
         *
         * Only trust proxy headers here when REMOTE_ADDR is a proxy you
         * control (e.g. read CF-Connecting-IP behind Cloudflare).
         *
         * @since 2.4.2
         *
         * @param string $ip REMOTE_ADDR as received.
         */
        $ip = (string) \apply_filters( 'trcl_client_ip', $ip );

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    /**
     * Whether an address belongs to a Cloudflare edge range.
     *
     * @since 2.4.2
     *
     * @param string $ip Candidate address (already validated or empty).
     * @return bool
     */
    private static function is_cloudflare_ip( string $ip ): bool {
        $packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input handled below.
        if ( false === $packed ) {
            return false;
        }
        $bits = strlen( $packed ) * 8;

        foreach ( self::CLOUDFLARE_RANGES as $range ) {
            [ $subnet, $prefix ] = explode( '/', $range, 2 );
            $subnet_packed       = inet_pton( $subnet );
            $prefix              = (int) $prefix;

            if ( false === $subnet_packed || strlen( $subnet_packed ) !== strlen( $packed ) || $prefix > $bits ) {
                continue;
            }

            $full_bytes = intdiv( $prefix, 8 );
            $rest_bits  = $prefix % 8;

            if ( $full_bytes > 0 && substr( $packed, 0, $full_bytes ) !== substr( $subnet_packed, 0, $full_bytes ) ) {
                continue;
            }
            if ( $rest_bits > 0 ) {
                $mask = ( 0xFF << ( 8 - $rest_bits ) ) & 0xFF;
                if ( ( ord( $packed[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_packed[ $full_bytes ] ) & $mask ) ) {
                    continue;
                }
            }
            return true;
        }

        return false;
    }
}
