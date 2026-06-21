<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct $wpdb writes to plant deterministic fixtures — both are
//    intentional for a stand-alone harness and not appropriate WPCS
//    subjects.
/**
 * E2E smoke for ConversationQueryService (v2.2 CNV-02).
 *
 * Covers:
 *   - sanitise_filters(): status whitelist, rating bounds, date
 *     validation, search truncation, per_page clamp, paged-vs-page
 *     precedence.
 *   - query(): result envelope, pagination maths, ORDER BY started_at
 *     DESC, status + search filters. Fixtures are isolated from any
 *     existing data by planting a sentinel started_at on 2000-01-01 and
 *     filtering to that day.
 *   - get_transcript(): null for missing / non-positive IDs, correct
 *     shape and message list otherwise.
 *
 * Self-cleaning: every fixture row is removed at the end.
 *
 * Usage:
 *   wp eval-file tests/Conversations/ConversationQuerySmoke.php
 *
 * @package TrillChatLite\Tests\Conversations
 * @since 2.2.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must be run via wp eval-file (needs WordPress).\n" );
    exit( 1 );
}

global $wpdb, $passes, $failures;
$passes   = 0;
$failures = 0;

function trcl_assert( bool $cond, string $name, string $detail = '' ): void {
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

echo "ConversationQueryService E2E smoke (CNV-02)\n";
echo "===========================================\n\n";

$svc = new \TrillChatLite\Conversations\ConversationQueryService();

// ---------------------------------------------------------------------
// Step A: sanitise_filters — pure, no DB needed.
// ---------------------------------------------------------------------
echo "A. sanitise_filters\n";

$def = $svc->sanitise_filters( [] );
trcl_assert(
    $def['status'] === '' && $def['rating'] === 0 && $def['page'] === 1
        && $def['per_page'] === 20 && $def['date_from'] === '' && $def['date_to'] === '' && $def['search'] === '',
    'empty args yield safe defaults'
);

trcl_assert( $svc->sanitise_filters( [ 'status' => 'nope' ] )['status'] === '', 'invalid status is dropped' );
trcl_assert( $svc->sanitise_filters( [ 'status' => 'completed' ] )['status'] === 'completed', 'allowed status passes through' );

trcl_assert( $svc->sanitise_filters( [ 'rating' => 9 ] )['rating'] === 0, 'rating above 5 is dropped' );
trcl_assert( $svc->sanitise_filters( [ 'rating' => 0 ] )['rating'] === 0, 'rating 0 stays 0' );
trcl_assert( $svc->sanitise_filters( [ 'rating' => 3 ] )['rating'] === 3, 'rating 3 passes through' );

trcl_assert( $svc->sanitise_filters( [ 'date_from' => '2025-13-40' ] )['date_from'] === '', 'impossible date is rejected' );
trcl_assert( $svc->sanitise_filters( [ 'date_from' => 'garbage' ] )['date_from'] === '', 'non-date string is rejected' );
trcl_assert( $svc->sanitise_filters( [ 'date_from' => '2025-06-21' ] )['date_from'] === '2025-06-21', 'valid date passes through' );

$long = str_repeat( 'a', 250 );
trcl_assert( mb_strlen( $svc->sanitise_filters( [ 'search' => $long ] )['search'] ) === 100, 'search is truncated to 100 chars' );

trcl_assert( $svc->sanitise_filters( [ 'per_page' => 100000 ] )['per_page'] === 100, 'per_page clamps to PER_PAGE_MAX (100)' );
trcl_assert( $svc->sanitise_filters( [ 'per_page' => 0 ] )['per_page'] === 1, 'per_page floors at 1' );

trcl_assert( $svc->sanitise_filters( [ 'paged' => 3, 'page' => 'trcl-conversations' ] )['page'] === 3, 'paged wins over the page slug' );
trcl_assert( $svc->sanitise_filters( [ 'page' => '2' ] )['page'] === 2, 'numeric page is honoured' );
trcl_assert( $svc->sanitise_filters( [ 'page' => 'trcl-conversations' ] )['page'] === 1, 'non-numeric page slug falls back to 1' );

trcl_assert( is_bool( $svc->uses_fulltext() ), 'uses_fulltext returns a bool' );

// ---------------------------------------------------------------------
// Step B: Fixtures — three conversations on a sentinel date.
// ---------------------------------------------------------------------
echo "\nB. Fixture setup (sentinel date 2000-01-01)\n";

$test_email = 'trcl-q-' . time() . '@example.test';
$test_user  = \wp_insert_user( [
    'user_login' => 'trcl_q_' . time(),
    'user_pass'  => \wp_generate_password( 24 ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
] );
if ( \is_wp_error( $test_user ) ) {
    echo "  FATAL: could not create test user — {$test_user->get_error_message()}\n";
    exit( 1 );
}

$db    = new \TrillChatLite\Database\DbManager();
$token = 'trclqtoken' . time();          // unique, > min ft token length.
$conv  = $wpdb->prefix . 'trcl_conversations';
$ids   = [];

for ( $i = 0; $i < 3; $i++ ) {
    $sess = $db->create_conversation( $test_user, [ 'customer_email' => $test_email ] );
    $db->create_message( $sess, 'user', 'Query fixture message ' . $i );
    $db->create_message( $sess, 'assistant', 'Reply ' . $i );
    $id = (int) $db->get_conversation_id( $sess );
    // Plant a deterministic, ordered started_at far in the past.
    $wpdb->update(
        $conv,
        [ 'started_at' => '2000-01-01 12:00:0' . $i ],
        [ 'id' => $id ],
        [ '%s' ],
        [ '%d' ]
    );
    $ids[] = $id;
}

// Plant the search token in the FIRST conversation only.
$db_msg_sess = null;
$wpdb->query(
    $wpdb->prepare(
        "UPDATE {$wpdb->prefix}trcl_messages SET content = %s WHERE conversation_id = %d AND role = 'user'",
        'unique searchable ' . $token . ' phrase',
        $ids[0]
    )
);

$base = [ 'date_from' => '2000-01-01', 'date_to' => '2000-01-01' ];

// ---------------------------------------------------------------------
// Step C: query() envelope + pagination.
// ---------------------------------------------------------------------
echo "\nC. query() envelope + pagination\n";

$all = $svc->query( $base );
trcl_assert(
    isset( $all['rows'], $all['total'], $all['pages'], $all['page'], $all['per_page'] ),
    'result envelope has all five keys'
);
trcl_assert( (int) $all['total'] === 3, 'date window isolates exactly the 3 fixtures', 'total=' . $all['total'] );
trcl_assert( count( $all['rows'] ) === 3 && (int) $all['pages'] === 1, 'single page holds all 3 rows' );
trcl_assert( (int) $all['rows'][0]->id === $ids[2], 'rows ordered by started_at DESC (newest first)', 'first id=' . $all['rows'][0]->id );
trcl_assert( (int) $all['rows'][0]->message_count === 2, 'per-row message_count aggregates correctly' );

$p1 = $svc->query( array_merge( $base, [ 'per_page' => 2 ] ) );
trcl_assert(
    (int) $p1['total'] === 3 && (int) $p1['pages'] === 2 && (int) $p1['per_page'] === 2 && count( $p1['rows'] ) === 2,
    'per_page=2 → 2 pages, 2 rows on page 1'
);

$p2 = $svc->query( array_merge( $base, [ 'per_page' => 2, 'paged' => 2 ] ) );
trcl_assert( (int) $p2['page'] === 2 && count( $p2['rows'] ) === 1, 'page 2 holds the remaining 1 row' );

// ---------------------------------------------------------------------
// Step D: status + search filters.
// ---------------------------------------------------------------------
echo "\nD. status + search filters\n";

$wpdb->update( $conv, [ 'status' => 'completed' ], [ 'id' => $ids[1] ], [ '%s' ], [ '%d' ] );
$by_status = $svc->query( array_merge( $base, [ 'status' => 'completed' ] ) );
trcl_assert( (int) $by_status['total'] === 1, 'status filter narrows to the 1 completed conversation', 'total=' . $by_status['total'] );

$by_search = $svc->query( array_merge( $base, [ 'search' => $token ] ) );
trcl_assert( (int) $by_search['total'] === 1, 'search filter finds the 1 conversation carrying the token', 'total=' . $by_search['total'] );
trcl_assert( count( $by_search['rows'] ) === 1 && (int) $by_search['rows'][0]->id === $ids[0], 'search returns the right conversation' );

// ---------------------------------------------------------------------
// Step E: get_transcript shape.
// ---------------------------------------------------------------------
echo "\nE. get_transcript\n";

trcl_assert( $svc->get_transcript( 0 ) === null, 'get_transcript(0) is null' );
trcl_assert( $svc->get_transcript( 999999999 ) === null, 'get_transcript(missing) is null' );

$t = $svc->get_transcript( $ids[2] );
trcl_assert(
    is_array( $t ) && isset( $t['conversation'], $t['messages'] ) && (int) $t['conversation']->id === $ids[2],
    'transcript returns conversation + messages for a real id'
);
trcl_assert(
    count( $t['messages'] ) === 2
        && isset( $t['messages'][0]->role, $t['messages'][0]->content ),
    'transcript lists both messages with role + content'
);

// ---------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------
echo "\nF. Fixture teardown\n";

( new \TrillChatLite\Gdpr\ConversationManager() )->delete_by_ids( $ids );
$remaining = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$conv} WHERE id IN ( %d, %d, %d )",
        $ids[0],
        $ids[1],
        $ids[2]
    )
);
trcl_assert( $remaining === 0, 'fixture conversations removed' );

if ( ! function_exists( 'wp_delete_user' ) ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
}
trcl_assert( (bool) \wp_delete_user( $test_user ), 'fixture user deleted' );

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n===========================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
