<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Captures the streamed CSV with output buffering; the
//    exporter's header() calls are no-ops under the CLI SAPI.
/**
 * E2E smoke for TranscriptExporter (v2.2 CNV-04).
 *
 * Covers:
 *   - stream_conversations_csv(): correct 11-column header, one data row
 *     per matching conversation, derived columns (messages count,
 *     converted=no, revenue=0.00) when no order/feedback exists.
 *   - stream_transcript_csv(): meta block + per-message rows, content
 *     round-trips; returns false for a missing / non-positive ID with no
 *     output streamed.
 *
 * Fixtures are isolated from existing data via a sentinel started_at on
 * 2000-01-01 and a date-window filter. Self-cleaning.
 *
 * Usage:
 *   wp eval-file tests/Conversations/ExporterSmoke.php
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

/** Parse a CSV blob into an array of row arrays (dropping a trailing blank). */
function trcl_parse_csv( string $blob ): array {
    $rows  = [];
    $lines = preg_split( "/\r\n|\n|\r/", $blob );
    foreach ( $lines as $line ) {
        if ( $line === '' ) {
            continue;
        }
        $rows[] = str_getcsv( $line );
    }
    return $rows;
}

echo "TranscriptExporter E2E smoke (CNV-04)\n";
echo "=====================================\n\n";

// ---------------------------------------------------------------------
// Step A: Fixture — one conversation, two messages, sentinel date.
// ---------------------------------------------------------------------
echo "A. Fixture setup\n";

$test_email = 'trcl-x-' . time() . '@example.test';
$test_user  = \wp_insert_user( [
    'user_login' => 'trcl_x_' . time(),
    'user_pass'  => \wp_generate_password( 24 ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
] );
if ( \is_wp_error( $test_user ) ) {
    echo "  FATAL: could not create test user — {$test_user->get_error_message()}\n";
    exit( 1 );
}

$db   = new \TrillChatLite\Database\DbManager();
$conv = $wpdb->prefix . 'trcl_conversations';

$sess = $db->create_conversation( $test_user, [ 'customer_email' => $test_email ] );
$db->create_message( $sess, 'user', 'Export fixture — hello there.' );
// A hostile assistant/user message that begins with a spreadsheet
// formula trigger — must be neutralised on export (CNV-06).
$formula_payload = '=HYPERLINK("http://evil.example","click")';
$db->create_message( $sess, 'assistant', $formula_payload );
$id = (int) $db->get_conversation_id( $sess );

$wpdb->update( $conv, [ 'started_at' => '2000-01-01 12:00:00' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

trcl_assert( $id > 0, 'fixture conversation created', 'id=' . $id );

$base = [ 'date_from' => '2000-01-01', 'date_to' => '2000-01-01' ];

// ---------------------------------------------------------------------
// Step B: Global conversations CSV.
// ---------------------------------------------------------------------
echo "\nB. stream_conversations_csv\n";

$exporter = new \TrillChatLite\Conversations\TranscriptExporter();

ob_start();
$exporter->stream_conversations_csv( $base );
$csv  = (string) ob_get_clean();
$rows = trcl_parse_csv( $csv );

$expected_header = [
    'id', 'session_id', 'started_at', 'ended_at', 'status', 'customer',
    'messages', 'avg_rating', 'converted', 'order_id', 'revenue',
];
trcl_assert( isset( $rows[0] ) && $rows[0] === $expected_header, 'header row matches the 11-column contract' );
trcl_assert( count( $rows ) === 2, 'exactly 1 data row for the isolated fixture', 'rows incl. header=' . count( $rows ) );

$data = $rows[1] ?? [];
trcl_assert( isset( $data[0] ) && (int) $data[0] === $id, 'data row carries the conversation id' );
trcl_assert( isset( $data[5] ) && $data[5] === $test_email, 'customer column resolves to the stored email' );
trcl_assert( isset( $data[6] ) && (int) $data[6] === 2, 'messages column counts both messages' );
trcl_assert( isset( $data[8] ) && $data[8] === 'no', 'converted column is "no" with no attributed order' );
trcl_assert( isset( $data[10] ) && $data[10] === '0.00', 'revenue column is formatted 0.00' );

// ---------------------------------------------------------------------
// Step C: Per-conversation transcript CSV.
// ---------------------------------------------------------------------
echo "\nC. stream_transcript_csv\n";

ob_start();
$ok = $exporter->stream_transcript_csv( $id );
$tcsv = (string) ob_get_clean();
$trows = trcl_parse_csv( $tcsv );

trcl_assert( $ok === true, 'returns true for a real conversation' );

$flat = array_map( static fn( $r ) => $r[0] ?? '', $trows );
trcl_assert( in_array( 'session_id', $flat, true ), 'meta block includes session_id row' );
trcl_assert( in_array( 'timestamp', $flat, true ), 'message header row (timestamp/role/content/rating) present' );

$has_user_content = false;
foreach ( $trows as $r ) {
    if ( isset( $r[2] ) && strpos( (string) $r[2], 'Export fixture — hello there.' ) !== false ) {
        $has_user_content = true;
    }
}
trcl_assert( $has_user_content, 'a message row round-trips the original content' );

$message_rows = 0;
foreach ( $trows as $r ) {
    if ( isset( $r[1] ) && in_array( $r[1], [ 'user', 'assistant' ], true ) && isset( $r[3] ) ) {
        $message_rows++;
    }
}
trcl_assert( $message_rows === 2, 'one CSV row per message (2 total)', 'got ' . $message_rows );

// CNV-06: the formula payload must be neutralised (leading quote), and
// the raw "=..." form must NOT appear as a cell value anywhere.
$neutralised = false;
$raw_formula = false;
foreach ( $trows as $r ) {
    foreach ( $r as $cell ) {
        if ( $cell === "'" . $formula_payload ) {
            $neutralised = true;
        }
        if ( $cell === $formula_payload ) {
            $raw_formula = true;
        }
    }
}
trcl_assert( $neutralised, 'formula payload is prefixed with a quote (CSV injection neutralised)' );
trcl_assert( ! $raw_formula, 'no cell exposes the raw =formula form' );

// ---------------------------------------------------------------------
// Step D: Missing / invalid IDs stream nothing.
// ---------------------------------------------------------------------
echo "\nD. missing / invalid IDs\n";

ob_start();
$missing = $exporter->stream_transcript_csv( 999999999 );
$missing_out = (string) ob_get_clean();
trcl_assert( $missing === false && $missing_out === '', 'missing id returns false and streams nothing' );

ob_start();
$zero = $exporter->stream_transcript_csv( 0 );
$zero_out = (string) ob_get_clean();
trcl_assert( $zero === false && $zero_out === '', 'id 0 returns false and streams nothing' );

// ---------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------
echo "\nE. Fixture teardown\n";

( new \TrillChatLite\Gdpr\ConversationManager() )->delete_by_ids( [ $id ] );
trcl_assert(
    (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE id = %d", $id ) ) === 0,
    'fixture conversation removed'
);

if ( ! function_exists( 'wp_delete_user' ) ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
}
trcl_assert( (bool) \wp_delete_user( $test_user ), 'fixture user deleted' );

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n=====================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
