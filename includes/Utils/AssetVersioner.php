<?php
/**
 * Asset Versioner utility.
 *
 * Resolves a relative asset path to the best available file on disk
 * (minified variant when present, unminified when SCRIPT_DEBUG is on)
 * and returns a short content-hash cache-buster derived from the file
 * itself — so a browser only re-downloads assets whose bytes actually
 * changed, not every asset in the plugin on every version bump.
 *
 * @package TrillChatLite\Utils
 * @since 1.2.3
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AssetVersioner
 *
 * SOLID:
 *   - Single Responsibility: map relative asset paths → URL + version.
 *   - Open/Closed: the candidate-resolution strategy is isolated in
 *     get_candidates() and can be extended without changing callers.
 *
 * OWASP:
 *   - Does not evaluate user input. Paths come from plugin code.
 *   - Filesystem access is bounded to the plugin directory by the
 *     constructor — callers cannot escape with "../".
 */
class AssetVersioner {

    /**
     * Per-request memoisation of resolved assets.
     *
     * @var array<string, array{url: string, path: string, ver: string}>
     */
    private static array $cache = [];

    /**
     * Absolute plugin directory, with trailing slash.
     */
    private string $plugin_dir;

    /**
     * Plugin URL, with trailing slash.
     */
    private string $plugin_url;

    /**
     * Fallback version used when a candidate cannot be found or hashed.
     */
    private string $fallback_version;

    /**
     * Constructor.
     *
     * @param string $plugin_dir        Absolute plugin directory (with or without trailing slash).
     * @param string $plugin_url        Plugin URL (with or without trailing slash).
     * @param string $fallback_version  Plugin version, used if a file cannot be hashed.
     */
    public function __construct( string $plugin_dir, string $plugin_url, string $fallback_version ) {
        $this->plugin_dir       = \trailingslashit( $plugin_dir );
        $this->plugin_url       = \trailingslashit( $plugin_url );
        $this->fallback_version = $fallback_version;
    }

    /**
     * Return the URL of the best candidate for the given asset.
     *
     * @param string $relative_path Path relative to the plugin root (e.g. "assets/js/chat-widget.js").
     */
    public function url( string $relative_path ): string {
        return $this->resolve( $relative_path )['url'];
    }

    /**
     * Return a short content-hash version string for the given asset.
     */
    public function version( string $relative_path ): string {
        return $this->resolve( $relative_path )['ver'];
    }

    /**
     * Return the resolved absolute path on disk.
     */
    public function path( string $relative_path ): string {
        return $this->resolve( $relative_path )['path'];
    }

    /**
     * Return the URL with the content-hash appended as `?ver=<hash>`.
     *
     * Useful when injecting the URL into localised data that is consumed
     * by dynamic <script>/<link> injection (e.g. the lazy-load bootstrap),
     * where WP's own `?ver=` query string is not applied automatically.
     */
    public function versioned_url( string $relative_path ): string {
        $resolved = $this->resolve( $relative_path );
        $sep      = ( strpos( $resolved['url'], '?' ) === false ) ? '?' : '&';
        return $resolved['url'] . $sep . 'ver=' . rawurlencode( $resolved['ver'] );
    }

    /**
     * Resolve a relative path to a candidate on disk and compute its hash.
     *
     * @return array{url: string, path: string, ver: string}
     */
    private function resolve( string $relative_path ): array {
        // Defence in depth: reject traversal. Paths are plugin-code-supplied,
        // but we still normalise to stay inside $plugin_dir.
        $relative_path = ltrim( $relative_path, '/\\' );
        if ( strpos( $relative_path, '..' ) !== false ) {
            return $this->fallback( $relative_path );
        }

        if ( isset( self::$cache[ $relative_path ] ) ) {
            return self::$cache[ $relative_path ];
        }

        $ext = pathinfo( $relative_path, PATHINFO_EXTENSION );
        if ( '' === $ext ) {
            // No extension — return as-is with fallback version.
            return self::$cache[ $relative_path ] = $this->fallback( $relative_path );
        }

        $base = substr( $relative_path, 0, -( strlen( $ext ) + 1 ) );

        foreach ( $this->get_candidates( $base, $ext ) as $candidate ) {
            $abs = $this->plugin_dir . $candidate;
            if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
                continue;
            }

            $hash = @md5_file( $abs );
            if ( false === $hash ) {
                continue;
            }

            return self::$cache[ $relative_path ] = [
                'url'  => $this->plugin_url . $candidate,
                'path' => $abs,
                'ver'  => substr( $hash, 0, 10 ),
            ];
        }

        return self::$cache[ $relative_path ] = $this->fallback( $relative_path );
    }

    /**
     * Candidate order for the given base + extension.
     *
     * When SCRIPT_DEBUG is on, prefer the unminified file so developers
     * get readable source in devtools. Otherwise prefer the minified
     * variant.
     *
     * @return string[]
     */
    private function get_candidates( string $base, string $ext ): array {
        $min_first = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

        $minified   = $base . '.min.' . $ext;
        $unminified = $base . '.' . $ext;

        return $min_first
            ? [ $minified, $unminified ]
            : [ $unminified, $minified ];
    }

    /**
     * Last-resort fallback when no candidate is readable.
     *
     * @return array{url: string, path: string, ver: string}
     */
    private function fallback( string $relative_path ): array {
        return [
            'url'  => $this->plugin_url . $relative_path,
            'path' => $this->plugin_dir . $relative_path,
            'ver'  => $this->fallback_version,
        ];
    }
}
