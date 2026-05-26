<?php
/**
 * Content indexer — turns merchant pages, posts, and product-category
 * descriptions into FULLTEXT-indexable rows in `wp_trcl_content_index`.
 *
 * Flow (per source):
 *   1. Load WP_Post / WP_Term.
 *   2. Apply guards (blacklist, opt-in check, published status).
 *   3. Compose source text (title + content + excerpt for posts; name +
 *      description for terms).
 *   4. Strip shortcodes + HTML via ContentChunker::prepare().
 *   5. Split into chunks (~400 chars, 50-char overlap).
 *   6. Transactional DELETE-existing + INSERT-new rows.
 *
 * `save_post` and term-edit hooks call this synchronously inside the
 * same request that edits the post — chunks are visible to the next
 * chat turn immediately. The daily cron fallback (`trcl_index_content`)
 * exists to repair drift (orphan chunks, externally edited rows, etc.).
 *
 * Hard blacklist: products are NEVER indexed here. They have their own
 * search path via `TrillChatLite\Search\ProductSearch`.
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
 * Class ContentIndexer
 *
 * SOLID: Single Responsibility — only chunked content persistence.
 * SOLID: Dependency Inversion — ContentSettings injected via constructor.
 */
class ContentIndexer {

    /**
     * Table name without prefix.
     */
    private const TABLE = 'trcl_content_index';

    /**
     * Max chars stored in the `snippet` column. Matches the TEXT type's
     * practical limit but also keeps individual rows bounded so a runaway
     * page can't blow up the index.
     */
    private const SNIPPET_MAX = 4000;

    /**
     * Max chars stored in the `title` column (matches DDL varchar(255)).
     */
    private const TITLE_MAX = 250;

    /**
     * Max chars stored in the `url` column (matches DDL varchar(500)).
     */
    private const URL_MAX = 490;

    /**
     * Settings facade.
     *
     * @var ContentSettings
     */
    private ContentSettings $settings;

    /**
     * Constructor.
     *
     * @param ContentSettings|null $settings Optional injected settings
     *                                       (production code passes the
     *                                       singleton instance; tests
     *                                       can pass a mock).
     */
    public function __construct( ?ContentSettings $settings = null ) {
        $this->settings = $settings ?? new ContentSettings();
    }

    /**
     * Cron hook name (daily reindex safety net).
     */
    public const CRON_HOOK = 'trcl_index_content';

    /**
     * Register WP action hooks. Called once from Plugin::init_components.
     *
     * Hooks wired:
     *   - save_post                  → on_save_post   (real-time reindex)
     *   - trashed_post, deleted_post → on_post_removed (chunk cleanup)
     *   - edited_product_cat,
     *     created_product_cat        → on_term_edited (category descriptions)
     *   - delete_product_cat         → on_term_deleted
     *   - trcl_index_content (cron)  → on_cron_reindex
     */
    public function register_hooks(): void {
        \add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
        \add_action( 'trashed_post', [ $this, 'on_post_removed' ] );
        \add_action( 'deleted_post', [ $this, 'on_post_removed' ] );

        \add_action( 'edited_product_cat', [ $this, 'on_term_edited' ] );
        \add_action( 'created_product_cat', [ $this, 'on_term_edited' ] );
        \add_action( 'delete_product_cat', [ $this, 'on_term_deleted' ] );

        \add_action( self::CRON_HOOK, [ $this, 'on_cron_reindex' ] );
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Index (or re-index) a single WordPress post.
     *
     * Idempotent: existing chunks for this post are deleted before the
     * new chunks are inserted, inside a single transaction.
     *
     * Returns `false` for skipped-by-design cases (guards, drafts, etc.)
     * as well as DB errors. The caller can distinguish via
     * `get_last_skip_reason()` if it cares; production callers usually
     * only care about the boolean.
     *
     * @param int $post_id Post ID.
     * @return bool True on successful insert. False on skip or DB error.
     */
    public function index_post( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        if ( ! $this->settings->is_enabled() ) {
            return false;
        }

        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        // Hard blacklist + merchant opt-in for post_type.
        if ( ! $this->settings->is_post_type_opted_in( $post->post_type ) ) {
            // Defensive cleanup in case this post was opted-in previously.
            $this->delete_for_post( $post_id );
            return false;
        }

        // Only published posts are indexed; everything else (draft, pending,
        // private, trash, auto-draft) gets its chunks cleaned out so the
        // index can't leak unpublished content into the chat.
        if ( $post->post_status !== 'publish' ) {
            $this->delete_for_post( $post_id );
            return false;
        }

        // Per-post selection: empty included_ids = all published of type.
        if ( ! $this->settings->is_post_id_opted_in( $post->post_type, $post_id ) ) {
            $this->delete_for_post( $post_id );
            return false;
        }

        // Compose source text.
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        $excerpt = (string) $post->post_excerpt;
        $source  = trim( $title . "\n\n" . $content . "\n\n" . $excerpt );

        $prepared = ContentChunker::prepare( $source );
        $chunks   = ContentChunker::chunk( $prepared );

        if ( empty( $chunks ) ) {
            // Page exists but yields no usable text (gallery-only, embeds-only…)
            // — still wipe any pre-existing chunks so we don't keep stale data.
            $this->delete_for_post( $post_id );
            return false;
        }

        $url = (string) \get_permalink( $post_id );

        return $this->replace_chunks(
            $post_id,
            $post->post_type,
            $title,
            $url,
            $chunks
        );
    }

    /**
     * Index (or re-index) a taxonomy term description.
     *
     * Used today for `product_cat`; the same mechanism works for any
     * other taxonomy if the merchant opts it in via settings.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug (default 'product_cat').
     * @return bool True on successful insert.
     */
    public function index_term( int $term_id, string $taxonomy = 'product_cat' ): bool {
        if ( $term_id <= 0 ) {
            return false;
        }
        if ( ! $this->settings->is_enabled() ) {
            return false;
        }
        if ( ! $this->settings->is_post_type_opted_in( $taxonomy ) ) {
            $this->delete_for_term( $term_id, $taxonomy );
            return false;
        }

        $term = \get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return false;
        }

        $name        = (string) $term->name;
        $description = (string) $term->description;

        // Skip terms with no merchant-written description — name alone
        // isn't enough signal to be useful in the chat context.
        if ( trim( $description ) === '' ) {
            $this->delete_for_term( $term_id, $taxonomy );
            return false;
        }

        $prepared = ContentChunker::prepare( $name . "\n\n" . $description );
        $chunks   = ContentChunker::chunk( $prepared );

        if ( empty( $chunks ) ) {
            $this->delete_for_term( $term_id, $taxonomy );
            return false;
        }

        $url = (string) \get_term_link( $term );
        if ( \is_wp_error( $url ) || $url === '' ) {
            $url = (string) \get_site_url();
        }

        return $this->replace_chunks(
            $term_id,
            $taxonomy,
            $name,
            $url,
            $chunks
        );
    }

    /**
     * Bulk re-index every opted-in post and taxonomy term.
     *
     * Used by:
     *   - Activator (initial backfill on plugin activation).
     *   - Daily cron (drift repair).
     *   - Settings UI "Reindex now" button.
     *
     * @return array{posts_indexed: int, terms_indexed: int, total_chunks: int}
     */
    public function index_all_opted_in(): array {
        $posts_indexed = 0;
        $terms_indexed = 0;

        if ( ! $this->settings->is_enabled() ) {
            return [
                'posts_indexed' => 0,
                'terms_indexed' => 0,
                'total_chunks'  => 0,
            ];
        }

        $opted_in = $this->settings->get_opted_in_post_types();

        // Separate post_types (CPT-style) from taxonomy-style entries.
        $taxonomy_types = [];
        $post_types     = [];
        foreach ( $opted_in as $type ) {
            if ( \taxonomy_exists( $type ) ) {
                $taxonomy_types[] = $type;
            } elseif ( \post_type_exists( $type ) ) {
                $post_types[] = $type;
            }
        }

        // Index posts (paginated to handle large sites without OOM).
        if ( ! empty( $post_types ) ) {
            $paged = 1;
            do {
                $query = new \WP_Query( [
                    'post_type'              => $post_types,
                    'post_status'            => 'publish',
                    'posts_per_page'         => 50,
                    'paged'                  => $paged,
                    'fields'                 => 'ids',
                    'no_found_rows'          => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ] );

                foreach ( $query->posts as $post_id ) {
                    if ( $this->index_post( (int) $post_id ) ) {
                        $posts_indexed++;
                    }
                }

                $paged++;
            } while ( $paged <= $query->max_num_pages );

            \wp_reset_postdata();
        }

        // Index taxonomy terms (no pagination needed — typically < 100).
        foreach ( $taxonomy_types as $taxonomy ) {
            $terms = \get_terms( [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ] );
            if ( \is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                if ( $this->index_term( (int) $term->term_id, $taxonomy ) ) {
                    $terms_indexed++;
                }
            }
        }

        $total_chunks = $this->count_rows();

        $this->settings->update_status( $total_chunks );

        trcl_log( 'Content index refresh complete', 'info', [
            'posts_indexed' => $posts_indexed,
            'terms_indexed' => $terms_indexed,
            'total_chunks'  => $total_chunks,
        ] );

        return [
            'posts_indexed' => $posts_indexed,
            'terms_indexed' => $terms_indexed,
            'total_chunks'  => $total_chunks,
        ];
    }

    /**
     * Delete every chunk associated with a WordPress post.
     *
     * Filtered to non-taxonomy post_types only — if a term shares an ID
     * with a post (legitimately, in different ID spaces), the term's
     * chunks are not affected.
     *
     * @param int $post_id Post ID.
     * @return bool True if 1+ row was affected, false otherwise.
     */
    public function delete_for_post( int $post_id ): bool {
        global $wpdb;

        if ( $post_id <= 0 ) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE post_id = %d AND post_type NOT IN ('product_cat')",
                $post_id
            )
        );

        return $affected !== false && $affected > 0;
    }

    /**
     * Delete every chunk associated with a taxonomy term.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @return bool True if 1+ row was affected.
     */
    public function delete_for_term( int $term_id, string $taxonomy = 'product_cat' ): bool {
        global $wpdb;

        if ( $term_id <= 0 || $taxonomy === '' ) {
            return false;
        }

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $wpdb->delete(
            $table,
            [
                'post_id'   => $term_id,
                'post_type' => $taxonomy,
            ],
            [ '%d', '%s' ]
        );

        return $affected !== false && $affected > 0;
    }

    /**
     * Wipe the entire content index. Used by the Settings "Reindex now"
     * button (clear-then-rebuild) and by uninstall.
     *
     * @return int Number of rows deleted.
     */
    public function truncate_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query( "DELETE FROM {$table}" );
        return (int) max( 0, $deleted );
    }

    /**
     * Return a status snapshot for dashboard / settings widgets.
     *
     * @return array{indexed_sources: int, total_chunks: int, last_indexed: string}
     */
    public function get_status(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sources = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id, post_type) FROM {$table}"
        );

        return [
            'indexed_sources' => $sources,
            'total_chunks'    => $this->count_rows(),
            'last_indexed'    => (string) $this->settings->get()['last_indexed'],
        ];
    }

    // =========================================================================
    // HOOK HANDLERS (wired from Plugin::register_hooks)
    // =========================================================================

    /**
     * `save_post` handler.
     *
     * Skips revisions, autosaves, and any post_type WP didn't expose.
     * Delegates to `index_post()` for the actual work; that method
     * handles drafts → delete chunks, opt-in checks, etc.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update vs new post.
     */
    public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        unset( $update ); // not needed — index_post is idempotent.

        if ( \wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( \wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $this->settings->is_auto_reindex_enabled() ) {
            return;
        }

        $this->index_post( $post_id );
    }

    /**
     * `trashed_post` / `deleted_post` handler.
     *
     * @param int $post_id Post ID.
     */
    public function on_post_removed( int $post_id ): void {
        $this->delete_for_post( $post_id );
    }

    /**
     * `edited_<taxonomy>` / `created_<taxonomy>` handler.
     *
     * @param int $term_id Term ID.
     */
    public function on_term_edited( int $term_id ): void {
        if ( ! $this->settings->is_auto_reindex_enabled() ) {
            return;
        }
        $this->index_term( $term_id, 'product_cat' );
    }

    /**
     * `delete_<taxonomy>` handler.
     *
     * @param int $term_id Term ID.
     */
    public function on_term_deleted( int $term_id ): void {
        $this->delete_for_term( $term_id, 'product_cat' );
    }

    /**
     * Cron hook callback for `trcl_index_content` (daily safety net).
     */
    public function on_cron_reindex(): void {
        trcl_log( 'Cron: starting content index refresh', 'info' );
        $result = $this->index_all_opted_in();
        trcl_log( 'Cron: content index complete', 'info', $result );
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Atomic replace: delete existing chunks for the source then insert
     * the new ones, all inside a single transaction. Rolls back on any
     * insert error so the index never lands in a half-written state.
     *
     * @param int      $source_id  Post ID or term ID.
     * @param string   $post_type  Post type or taxonomy slug.
     * @param string   $title      Source title.
     * @param string   $url        Source permalink.
     * @param string[] $chunks     Chunks from ContentChunker::chunk().
     * @return bool
     */
    private function replace_chunks(
        int $source_id,
        string $post_type,
        string $title,
        string $url,
        array $chunks
    ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $title_safe = $this->truncate_utf8( $title, self::TITLE_MAX );
        $url_safe   = $this->truncate_utf8( $url, self::URL_MAX );
        $now        = \current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( 'START TRANSACTION' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete(
            $table,
            [
                'post_id'   => $source_id,
                'post_type' => $post_type,
            ],
            [ '%d', '%s' ]
        );

        if ( $deleted === false ) {
            $wpdb->query( 'ROLLBACK' );
            trcl_log( 'Content indexer: DELETE failed', 'error', [
                'source_id' => $source_id,
                'post_type' => $post_type,
                'error'     => $wpdb->last_error,
            ] );
            return false;
        }

        foreach ( $chunks as $i => $snippet ) {
            $snippet_safe = $this->truncate_utf8( $snippet, self::SNIPPET_MAX );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted = $wpdb->insert(
                $table,
                [
                    'post_id'      => $source_id,
                    'post_type'    => $post_type,
                    'chunk_index'  => $i,
                    'title'        => $title_safe,
                    'snippet'      => $snippet_safe,
                    'url'          => $url_safe,
                    'last_indexed' => $now,
                ],
                [ '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
            );

            if ( $inserted === false ) {
                $wpdb->query( 'ROLLBACK' );
                trcl_log( 'Content indexer: INSERT failed', 'error', [
                    'source_id'   => $source_id,
                    'post_type'   => $post_type,
                    'chunk_index' => $i,
                    'error'       => $wpdb->last_error,
                ] );
                return false;
            }
        }

        $wpdb->query( 'COMMIT' );

        return true;
    }

    /**
     * Count rows in the index table.
     *
     * @return int
     */
    private function count_rows(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Truncate a string to a max number of CHARACTERS (not bytes), so
     * we never split a multi-byte UTF-8 codepoint when storing it in
     * a varchar column.
     *
     * @param string $text Input.
     * @param int    $max  Max characters.
     * @return string
     */
    private function truncate_utf8( string $text, int $max ): string {
        if ( $max <= 0 ) {
            return '';
        }
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        return mb_substr( $text, 0, $max );
    }
}
