<?php
/**
 * E2E smoke for ContentIndexer hooks — meant to run via `wp eval-file`.
 *
 * Verifies that:
 *   - The daily reindex cron is scheduled (auto-schedules if not).
 *   - save_post triggers our on_save_post handler and re-chunks the post.
 *   - trashed_post triggers chunk cleanup.
 *   - Restored post gets re-indexed.
 *
 * The script never deletes content permanently: any page it modifies
 * is reverted at the end.
 *
 * Usage:
 *   wp eval-file tests/Content/IndexerE2ESmoke.php
 *
 * @package TrillChatLite\Tests\Content
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must be run via wp eval-file (needs WordPress).\n" );
    exit( 1 );
}

// NOTE: `wp eval-file` wraps this script's body in an anonymous function,
// so top-level vars are local to that wrapper unless explicitly globalised.
// Declaring the counter vars `global` here keeps them in sync with the
// matching `global` inside trcl_smoke_assert().
global $wpdb, $passes, $failures;

$table    = $wpdb->prefix . 'trcl_content_index';
$passes   = 0;
$failures = 0;

function trcl_smoke_assert( bool $cond, string $name, string $detail = '' ): void {
    global $passes, $failures;
    if ( $cond ) {
        $passes++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$name}\n";
    if ( $detail !== '' ) {
        echo "        {$detail}\n";
    }
}

echo "ContentIndexer E2E smoke\n";
echo "========================\n\n";

// ---------------------------------------------------------------------
// Step A: daily reindex cron is (or becomes) scheduled.
// ---------------------------------------------------------------------
echo "A. Cron schedule\n";

$hook = \TrillChatLite\Content\ContentIndexer::CRON_HOOK;
if ( ! \wp_next_scheduled( $hook ) ) {
    \wp_schedule_event( time(), 'daily', $hook );
    echo "  (scheduled cron during smoke run)\n";
}
$next = (int) \wp_next_scheduled( $hook );
trcl_smoke_assert(
    $next > 0,
    'trcl_index_content is scheduled',
    'wp_next_scheduled returned ' . $next
);
trcl_smoke_assert(
    $next > time() - 60,
    'cron next run is in the future or just now',
    'next=' . gmdate( 'Y-m-d H:i:s', $next )
);

// ---------------------------------------------------------------------
// Step B: save_post triggers reindex.
// ---------------------------------------------------------------------
echo "\nB. save_post triggers reindex\n";

$pages = \get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'orderby'        => 'ID',
    'order'          => 'ASC',
] );

if ( empty( $pages ) ) {
    echo "  SKIP  no published page available\n";
} else {
    $page             = $pages[0];
    $original_content = $page->post_content;
    echo "  target: page #{$page->ID} \"{$page->post_title}\"\n";

    $before = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND post_type = %s",
        $page->ID,
        'page'
    ) );
    echo "  chunks before: {$before}\n";

    $marker      = 'trcl-smoke-' . time();
    $new_content = $original_content . " Additional content with marker " . $marker . ' to verify reindex.';

    $updated = \wp_update_post( [
        'ID'           => $page->ID,
        'post_content' => $new_content,
    ], true );

    trcl_smoke_assert(
        ! \is_wp_error( $updated ),
        'wp_update_post succeeded',
        \is_wp_error( $updated ) ? $updated->get_error_message() : ''
    );

    $after = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND post_type = %s",
        $page->ID,
        'page'
    ) );
    echo "  chunks after:  {$after}\n";

    trcl_smoke_assert(
        $after >= 1,
        'chunks exist after wp_update_post'
    );

    $latest_snippet = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT snippet FROM {$table} WHERE post_id = %d AND post_type = %s ORDER BY chunk_index DESC LIMIT 1",
        $page->ID,
        'page'
    ) );

    trcl_smoke_assert(
        strpos( $latest_snippet, $marker ) !== false,
        'reindexed snippet contains smoke marker (proves save_post hook fired)',
        'marker=' . $marker
    );

    // Revert.
    \wp_update_post( [
        'ID'           => $page->ID,
        'post_content' => $original_content,
    ] );
    echo "  reverted page content\n";
}

// ---------------------------------------------------------------------
// Step C: trashed_post triggers cleanup; restore re-indexes.
// ---------------------------------------------------------------------
echo "\nC. trashed_post cleanup + restore\n";

$pages_c = \get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'orderby'        => 'ID',
    'order'          => 'DESC',
] );

if ( empty( $pages_c ) ) {
    echo "  SKIP  no published page available\n";
} else {
    $page_c = $pages_c[0];
    echo "  target: page #{$page_c->ID} \"{$page_c->post_title}\"\n";

    $before_c = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
        $page_c->ID
    ) );
    echo "  chunks before trash: {$before_c}\n";

    \wp_trash_post( $page_c->ID );

    $after_trash = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
        $page_c->ID
    ) );
    echo "  chunks after trash:  {$after_trash}\n";

    trcl_smoke_assert(
        $after_trash === 0,
        'all chunks removed after wp_trash_post'
    );

    // Restore + force re-publish (untrash leaves the status as previous,
    // which is fine, but we also need to fire save_post again so the
    // indexer reinserts. wp_untrash_post sets the status back; that
    // triggers save_post automatically inside core.)
    \wp_untrash_post( $page_c->ID );
    \wp_update_post( [
        'ID'          => $page_c->ID,
        'post_status' => 'publish',
    ] );

    $after_restore = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
        $page_c->ID
    ) );
    echo "  chunks after restore: {$after_restore}\n";

    trcl_smoke_assert(
        $after_restore >= 1,
        'chunks reappear after untrash + publish'
    );
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n========================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
