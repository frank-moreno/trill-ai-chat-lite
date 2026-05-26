<?php
/**
 * Content indexing settings — thin wrapper over the `trcl_content_settings`
 * option. Read-only for now; the Settings UI tab (slice 4) writes through it.
 *
 * Schema of the underlying option:
 *
 *   [
 *     'enabled'      => '1' | '0',
 *     'post_types'   => [ 'page' => '1', 'post' => '0', 'product_cat' => '1' ],
 *     'included_ids' => [ 'page' => [12, 34, 56], 'post' => [] ],
 *     'auto_reindex' => '1' | '0',
 *     'last_indexed' => 'YYYY-MM-DD HH:MM:SS' | '',
 *     'total_chunks' => int,
 *   ]
 *
 * Defaults (see Page Indexing Block 1 design memo):
 *   - enabled        ON
 *   - pages          ON  (no IDs filter → "all published pages")
 *   - posts          OFF
 *   - product_cat    ON
 *   - auto_reindex   ON
 *
 * `included_ids[$type]` semantics: empty array means "include every
 * published item of that post_type". The Settings UI will populate
 * specific IDs once the merchant narrows the selection.
 *
 * @package TrillChatLite\Content
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Content;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ContentSettings
 *
 * SOLID: Single Responsibility — only settings access for the indexer.
 */
class ContentSettings {

    /**
     * wp_options key storing the indexing configuration.
     */
    public const OPTION_KEY = 'trcl_content_settings';

    /**
     * Default configuration applied when the option is unset
     * or partially populated. `array_replace_recursive` merges
     * stored values on top of these.
     *
     * @var array
     */
    private const DEFAULTS = [
        'enabled'      => '1',
        'post_types'   => [
            'page'        => '1',
            'post'        => '0',
            'product_cat' => '1',
        ],
        'included_ids' => [
            'page' => [],
            'post' => [],
        ],
        'auto_reindex' => '1',
        'last_indexed' => '',
        'total_chunks' => 0,
    ];

    /**
     * Hard-coded blacklist of post types that must never be indexed,
     * regardless of merchant configuration. Products live in their own
     * ProductSearch path; chunking their long descriptions would only
     * pollute the prompt with redundant data.
     *
     * @var string[]
     */
    private const NEVER_INDEX = [
        'product',
        'product_variation',
        'shop_order',
        'shop_coupon',
        'shop_subscription',
        'attachment',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'revision',
    ];

    /**
     * Read the merged settings array.
     *
     * @return array Effective settings (defaults merged with stored option).
     */
    public function get(): array {
        $stored = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $merged = array_replace_recursive( self::DEFAULTS, $stored );

        // Defensive: array_replace_recursive can leave non-string values
        // in scalar slots if a previous install wrote garbage. Force the
        // expected types so callers don't have to guard.
        $merged['enabled']      = (string) ( $merged['enabled'] ?? '1' );
        $merged['auto_reindex'] = (string) ( $merged['auto_reindex'] ?? '1' );
        $merged['total_chunks'] = (int) ( $merged['total_chunks'] ?? 0 );
        $merged['last_indexed'] = (string) ( $merged['last_indexed'] ?? '' );

        return $merged;
    }

    /**
     * Is content indexing globally enabled?
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return $this->get()['enabled'] === '1';
    }

    /**
     * Should the indexer re-index automatically on save_post / cron?
     *
     * @return bool
     */
    public function is_auto_reindex_enabled(): bool {
        return $this->get()['auto_reindex'] === '1';
    }

    /**
     * Is a given post_type opted in for indexing?
     *
     * Combines the hard-coded NEVER_INDEX blacklist with the merchant's
     * per-type toggle. The blacklist always wins.
     *
     * @param string $post_type Post type slug ('page', 'post', 'product_cat', etc.)
     * @return bool
     */
    public function is_post_type_opted_in( string $post_type ): bool {
        if ( in_array( $post_type, self::NEVER_INDEX, true ) ) {
            return false;
        }
        $types = $this->get()['post_types'];
        return isset( $types[ $post_type ] ) && (string) $types[ $post_type ] === '1';
    }

    /**
     * Is a specific post/term included in the merchant's selection?
     *
     * Returns `true` when the included_ids list for that type is empty
     * (default "all published") OR when the ID is explicitly listed.
     *
     * @param string $post_type Post type slug.
     * @param int    $id        Post ID (or term ID for taxonomies).
     * @return bool
     */
    public function is_post_id_opted_in( string $post_type, int $id ): bool {
        $ids = $this->get()['included_ids'][ $post_type ] ?? [];
        if ( empty( $ids ) ) {
            return true;
        }
        return in_array( $id, array_map( 'intval', $ids ), true );
    }

    /**
     * Return the list of opted-in post_types (after applying the
     * hard-coded blacklist).
     *
     * @return string[]
     */
    public function get_opted_in_post_types(): array {
        $types = $this->get()['post_types'];
        $out   = [];
        foreach ( $types as $type => $flag ) {
            if ( (string) $flag === '1' && ! in_array( $type, self::NEVER_INDEX, true ) ) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Persist the index status (last_indexed timestamp + total_chunks).
     *
     * Used by ContentIndexer after a successful bulk reindex so the
     * Settings UI and dashboard widget can show recency without an
     * extra DB count.
     *
     * @param int    $total_chunks Number of rows now in the index.
     * @param string $timestamp    `YYYY-MM-DD HH:MM:SS` or '' to use now.
     */
    public function update_status( int $total_chunks, string $timestamp = '' ): void {
        $current               = $this->get();
        $current['total_chunks'] = max( 0, $total_chunks );
        $current['last_indexed'] = $timestamp !== '' ? $timestamp : \current_time( 'mysql' );
        \update_option( self::OPTION_KEY, $current, false );
    }
}
