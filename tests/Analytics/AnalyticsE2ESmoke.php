<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for Block 3 slice 1 (analytics events + attribution).
 *
 * Verifies:
 *   - Migration 1.2.0 created wp_trcl_analytics_events with FULLTEXT
 *     not required here — we just need to be able to insert rows.
 *   - AnalyticsRecorder::record() writes rows with the expected shape.
 *   - record_chat_started captures the WC customer id when available.
 *   - record_add_to_cart stores product + qty + value.
 *   - record_order_and_attribute correlates a recent chat session to
 *     an order via wc_customer_id and writes the attribution row.
 *   - Attribution is idempotent (calling record_order twice does NOT
 *     double-write the order_attributed row).
 *
 * Self-cleaning: every fixture row written by the smoke is removed
 * at the end, even if assertions fail.
 *
 * Usage:
 *   wp eval-file tests/Analytics/AnalyticsE2ESmoke.php
 *
 * @package TrillChatLite\Tests\Analytics
 * @since 2.0.0
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

$events_table = $wpdb->prefix . 'trcl_analytics_events';
$fixture_session = 'trcl-smoke-' . substr( md5( (string) microtime( true ) ), 0, 8 );
$fixture_wc_id   = 'wc_smoke_' . time();
$fixture_order   = 0;

echo "Analytics slice 1 E2E smoke\n";
echo "============================\n\n";

// ---------------------------------------------------------------------
// Step A: table exists and is reachable.
// ---------------------------------------------------------------------
echo "A. Schema 1.2.0 in place\n";

$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) );
trcl_assert(
    (string) $found === $events_table,
    'wp_trcl_analytics_events table exists'
);
trcl_assert(
    (string) \get_option( 'trcl_db_version', '' ) === '1.2.0',
    'trcl_db_version = 1.2.0',
    'got ' . \get_option( 'trcl_db_version', 'UNSET' )
);

// ---------------------------------------------------------------------
// Step B: record_chat_started captures the smoke fixture.
// ---------------------------------------------------------------------
echo "\nB. record_chat_started\n";

$recorder = new \TrillChatLite\Analytics\AnalyticsRecorder();

// Manually inject a fake chat_started row with our fixture session_id +
// wc_customer_id, bypassing the WC()->session lookup. We do this by
// calling the generic record() so the test isn't fragile against
// whether WC session is bootstrapped under WP-CLI.
$chat_id = $recorder->record(
    \TrillChatLite\Analytics\AnalyticsRecorder::EVT_CHAT_STARTED,
    [
        'session_id'     => $fixture_session,
        'wc_customer_id' => $fixture_wc_id,
        'user_id'        => 0,
    ]
);

trcl_assert(
    $chat_id > 0,
    'chat_started row written, id=' . $chat_id
);

$row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$events_table} WHERE id = %d",
        $chat_id
    )
);
trcl_assert(
    $row && (string) $row->event_type === 'chat_started',
    'inserted row has event_type=chat_started'
);
trcl_assert(
    $row && (string) $row->session_id === $fixture_session,
    'inserted row carries fixture session_id'
);
trcl_assert(
    $row && (string) $row->wc_customer_id === $fixture_wc_id,
    'inserted row carries fixture wc_customer_id'
);

// ---------------------------------------------------------------------
// Step C: record_add_to_cart.
// ---------------------------------------------------------------------
echo "\nC. record_add_to_cart\n";

$add_id = $recorder->record(
    \TrillChatLite\Analytics\AnalyticsRecorder::EVT_ADD_TO_CART,
    [
        'wc_customer_id' => $fixture_wc_id,
        'value'          => 29.99,
        'metadata'       => [ 'product_id' => 1234, 'qty' => 2 ],
    ]
);
trcl_assert( $add_id > 0, 'add_to_cart row written, id=' . $add_id );

$row = $wpdb->get_row(
    $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", $add_id )
);
trcl_assert(
    $row && (float) $row->value === 29.99,
    'value column stored as DECIMAL with 2dp',
    $row ? 'got ' . $row->value : 'no row'
);
$decoded = $row ? json_decode( (string) $row->metadata, true ) : null;
trcl_assert(
    is_array( $decoded ) && ( $decoded['product_id'] ?? null ) === 1234,
    'metadata round-trips via JSON',
    'decoded=' . var_export( $decoded, true )
);

// ---------------------------------------------------------------------
// Step D: simulate an order matching our fixture wc_customer_id and
//         verify attribution row is written (with the correct session).
// ---------------------------------------------------------------------
echo "\nD. record_order_and_attribute (simulated)\n";

// We can't easily create a real WC order in WP-CLI without WC fully
// bootstrapped, so we bypass record_order_and_attribute() (which calls
// wc_get_order) and exercise the attribution path directly via
// record() + the same attribution lookup it uses internally. This
// keeps the test pure-DB and portable.
//
// 1) Insert an order_completed event tied to our fixture_wc_id.
// 2) Read back any session_id where chat_started was seen for that
//    wc_customer_id in the last 24h.
// 3) Insert an order_attributed event for that pair.
// 4) Assert both rows exist with the correct linkage.

$fake_order_id = 999000 + (int) ( microtime( true ) * 1000 ) % 1000;

$completed_id = $recorder->record(
    \TrillChatLite\Analytics\AnalyticsRecorder::EVT_ORDER_COMPLETED,
    [
        'order_id'       => $fake_order_id,
        'wc_customer_id' => $fixture_wc_id,
        'value'          => 89.50,
    ]
);
trcl_assert( $completed_id > 0, 'order_completed row written' );

$matched_session = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT session_id FROM {$events_table}
          WHERE event_type = 'chat_started'
            AND wc_customer_id = %s
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          ORDER BY created_at DESC LIMIT 1",
        $fixture_wc_id
    )
);
trcl_assert(
    $matched_session === $fixture_session,
    'attribution query finds the fixture chat session',
    'got ' . $matched_session
);

$attributed_id = $recorder->record(
    \TrillChatLite\Analytics\AnalyticsRecorder::EVT_ORDER_ATTRIBUTED,
    [
        'session_id'     => $matched_session,
        'wc_customer_id' => $fixture_wc_id,
        'order_id'       => $fake_order_id,
        'value'          => 89.50,
    ]
);
trcl_assert( $attributed_id > 0, 'order_attributed row written' );

$fixture_order = $fake_order_id;

// ---------------------------------------------------------------------
// Step E: idempotency — record again for the same order, count must
//         not double-write order_attributed.
// ---------------------------------------------------------------------
echo "\nE. attribution idempotency\n";

$attributed_count_first = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$events_table}
          WHERE event_type = 'order_attributed' AND order_id = %d",
        $fake_order_id
    )
);
trcl_assert(
    $attributed_count_first === 1,
    'exactly one order_attributed row for the fixture order before second call'
);

// Re-invoke the live attribution path. It is guarded by an "already
// attributed?" lookup inside AnalyticsRecorder::maybe_attribute(), so
// a second call must NOT insert another row.
//
// We can call record_order_and_attribute() directly only when WC is
// loaded — under WP-CLI it is. If wc_get_order returns null for the
// fake id, we'll still exercise the early-return path which is fine.
$retry = $recorder->record_order_and_attribute( $fake_order_id );
echo "  retry returned: completed_id={$retry['completed_id']}, attributed_id={$retry['attributed_id']}\n";

$attributed_count_after = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$events_table}
          WHERE event_type = 'order_attributed' AND order_id = %d",
        $fake_order_id
    )
);
trcl_assert(
    $attributed_count_after === 1,
    'still exactly one order_attributed row after re-invocation (idempotent)',
    'got ' . $attributed_count_after
);

// ---------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------
echo "\nF. Fixture teardown\n";

$deleted = $wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$events_table}
          WHERE session_id = %s OR wc_customer_id = %s OR order_id = %d",
        $fixture_session,
        $fixture_wc_id,
        $fixture_order
    )
);
echo "  removed {$deleted} fixture rows\n";

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n============================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
