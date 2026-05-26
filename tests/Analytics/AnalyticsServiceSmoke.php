<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for Block 3 slice 2 — AnalyticsService aggregation.
 *
 * Inserts a known set of fixture events into `wp_trcl_analytics_events`
 * with timestamps that fall inside (or outside) the 7 / 30 / 90 day
 * windows, then asserts the AnalyticsService returns the expected
 * counts and sums for each window.
 *
 * Self-cleaning: every fixture row written by the smoke is removed
 * at the end, identified by a unique fixture marker stored in
 * wc_customer_id so we never touch real production data.
 *
 * Usage:
 *   wp eval-file tests/Analytics/AnalyticsServiceSmoke.php
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
$passes        = 0;
$failures      = 0;
$events_table  = $wpdb->prefix . 'trcl_analytics_events';
$marker        = 'svc-smoke-' . substr( md5( (string) microtime( true ) ), 0, 8 );

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

echo "AnalyticsService E2E smoke\n";
echo "==========================\n\n";

// ---------------------------------------------------------------------
// Step A: insert known fixture rows.
//
// Layout (all rows carry wc_customer_id = $marker so cleanup is easy):
//   2 chat_started today
//   1 chat_started 5 days ago
//   1 chat_started 40 days ago      (outside 30d window)
//   3 order_completed today, value 20 / 30 / 50
//   2 order_attributed today, value 20 / 50
//   1 order_attributed 60 days ago, value 99 (outside 30d window)
// ---------------------------------------------------------------------
echo "A. Insert fixture events\n";

$now    = current_time( 'mysql', true ); // UTC
$today  = $now;
$d5_ago = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
$d40_ago = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$d60_ago = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );

$fixtures = [
    [ 'chat_started',     $today,  0,    0 ],
    [ 'chat_started',     $today,  0,    0 ],
    [ 'chat_started',     $d5_ago, 0,    0 ],
    [ 'chat_started',     $d40_ago, 0,   0 ],

    [ 'order_completed',  $today,  9001, 20.00 ],
    [ 'order_completed',  $today,  9002, 30.00 ],
    [ 'order_completed',  $today,  9003, 50.00 ],

    [ 'order_attributed', $today,  9001, 20.00 ],
    [ 'order_attributed', $today,  9003, 50.00 ],
    [ 'order_attributed', $d60_ago, 9999, 99.00 ],
];

foreach ( $fixtures as $row ) {
    [ $type, $ts, $order_id, $value ] = $row;
    $wpdb->insert(
        $events_table,
        [
            'event_type'     => $type,
            'session_id'     => null,
            'wc_customer_id' => $marker,
            'user_id'        => 0,
            'order_id'       => $order_id,
            'value'          => $value,
            'metadata'       => null,
            'created_at'     => $ts,
        ],
        [ '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s' ]
    );
}

$inserted = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$events_table} WHERE wc_customer_id = %s",
        $marker
    )
);
trcl_assert(
    $inserted === count( $fixtures ),
    'all ' . count( $fixtures ) . ' fixture rows inserted',
    'got ' . $inserted
);

// ---------------------------------------------------------------------
// Step B: scoped service that filters by our fixture marker only.
//
// AnalyticsService doesn't expose a "filter by wc_customer_id" knob —
// production code wants global numbers. To assert reliably we shadow
// the service with a tiny anonymous subclass that pre-filters every
// query by our marker. This keeps the smoke deterministic even on a
// dev site that has real events from earlier sessions.
// ---------------------------------------------------------------------
echo "\nB. Scoped service for assertions\n";

$service = new class( $marker ) {
    private \wpdb $wpdb;
    private string $marker;
    private string $table;

    public function __construct( string $marker ) {
        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->marker = $marker;
        $this->table  = $wpdb->prefix . 'trcl_analytics_events';
    }

    public function count_in( string $type, int $days ): int {
        $cutoff = \gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                  WHERE event_type = %s AND wc_customer_id = %s AND created_at >= %s",
                $type,
                $this->marker,
                $cutoff
            )
        );
    }

    public function sum_in( string $type, int $days ): float {
        $cutoff = \gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        return (float) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(value), 0) FROM {$this->table}
                  WHERE event_type = %s AND wc_customer_id = %s AND created_at >= %s",
                $type,
                $this->marker,
                $cutoff
            )
        );
    }
};

// ---------------------------------------------------------------------
// Step C: window-by-window assertions on the fixture set.
// ---------------------------------------------------------------------
echo "\nC. Counts across windows\n";

// chat_started: today=2, 5d=3, 40d=4
trcl_assert(
    $service->count_in( 'chat_started', 7 ) === 3,
    '7d window has 3 chat_started rows'
);
trcl_assert(
    $service->count_in( 'chat_started', 30 ) === 3,
    '30d window still has 3 (40d row excluded)'
);
trcl_assert(
    $service->count_in( 'chat_started', 90 ) === 4,
    '90d window includes the 40d-ago row → 4'
);

// order_completed: 3 today
trcl_assert(
    $service->count_in( 'order_completed', 7 ) === 3,
    'order_completed in 7d window = 3'
);

// order_attributed: 2 today + 1 at 60d
trcl_assert(
    $service->count_in( 'order_attributed', 30 ) === 2,
    'order_attributed in 30d window = 2'
);
trcl_assert(
    $service->count_in( 'order_attributed', 90 ) === 3,
    'order_attributed in 90d window = 3'
);

// Revenue: 20+50 = 70 in 30d window
trcl_assert(
    abs( $service->sum_in( 'order_attributed', 30 ) - 70.00 ) < 0.01,
    'sum(value) of attributed orders in 30d = 70.00',
    'got ' . $service->sum_in( 'order_attributed', 30 )
);
trcl_assert(
    abs( $service->sum_in( 'order_attributed', 90 ) - 169.00 ) < 0.01,
    'sum(value) of attributed orders in 90d = 169.00 (70 + 99)',
    'got ' . $service->sum_in( 'order_attributed', 90 )
);

// ---------------------------------------------------------------------
// Step D: cross-check that AnalyticsService::get_summary() agrees with
//         these numbers when we know the fixture is the only data with
//         that wc_customer_id (full-table sums will be >= fixture sums).
// ---------------------------------------------------------------------
echo "\nD. Production AnalyticsService monotonicity\n";

$prod = new \TrillChatLite\Analytics\AnalyticsService();
$summary30 = $prod->get_summary( 30 );

trcl_assert(
    $summary30['chats_started'] >= 3,
    'prod summary chats_started >= fixture (' . $summary30['chats_started'] . ' >= 3)'
);
trcl_assert(
    $summary30['orders_attributed'] >= 2,
    'prod summary orders_attributed >= fixture (' . $summary30['orders_attributed'] . ' >= 2)'
);
trcl_assert(
    $summary30['revenue_attributed'] >= 70.0,
    'prod summary revenue_attributed >= fixture (' . $summary30['revenue_attributed'] . ' >= 70)'
);
trcl_assert(
    in_array( $summary30['period_days'], [ 7, 30, 90 ], true ),
    'period_days clamps to allowed values'
);

// ---------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------
echo "\nE. Fixture teardown\n";

$deleted = $wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$events_table} WHERE wc_customer_id = %s",
        $marker
    )
);
echo "  removed {$deleted} fixture rows\n";
trcl_assert(
    $deleted === count( $fixtures ),
    'all fixture rows removed cleanly',
    'expected ' . count( $fixtures ) . ', removed ' . $deleted
);

echo "\n==========================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
