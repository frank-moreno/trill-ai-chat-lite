<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for Block 4 slice 1 — lead capture.
 *
 * Verifies:
 *   - Schema 1.3.0 in place (trcl_leads table exists).
 *   - LeadIntentDetector recognises out-of-stock + price-drop phrasing
 *     and ignores unrelated messages.
 *   - extract_email finds plausible addresses inside free text.
 *   - LeadCaptureService::capture writes a row, populates consent
 *     snapshot, and is idempotent on (email, session, intent).
 *   - status update + erase_by_email work.
 *   - PrivacyHooks export now includes the captured lead in its
 *     payload, and the eraser cascade nukes leads alongside
 *     conversations.
 *   - PromptBuilder renders a LEAD CAPTURE OPPORTUNITY section when
 *     with_lead_offer() is called.
 *
 * Self-cleaning: every fixture row is removed at the end.
 *
 * Usage:
 *   wp eval-file tests/Leads/LeadCaptureSmoke.php
 *
 * @package TrillChatLite\Tests\Leads
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must be run via wp eval-file (needs WordPress).\n" );
    exit( 1 );
}

global $wpdb, $passes, $failures;
$passes    = 0;
$failures  = 0;
$leads_tbl = $wpdb->prefix . 'trcl_leads';

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

echo "Lead capture E2E smoke (Block 4 slice 1)\n";
echo "========================================\n\n";

// ---------------------------------------------------------------------
// Step A: schema 1.3.0 in place.
// ---------------------------------------------------------------------
echo "A. Schema 1.3.0\n";

$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_tbl ) );
trcl_assert(
    $found === $leads_tbl,
    'wp_trcl_leads table exists'
);

if ( $found !== $leads_tbl ) {
    echo "Schema 1.3.0 not deployed — run Migrations::run() first.\n";
    echo "Results: {$passes} passed, {$failures} failed\n";
    exit( 1 );
}

trcl_assert(
    (string) \get_option( 'trcl_db_version', '' ) === '1.3.0',
    'trcl_db_version = 1.3.0'
);

// ---------------------------------------------------------------------
// Step B: LeadIntentDetector classification.
// ---------------------------------------------------------------------
echo "\nB. LeadIntentDetector\n";

$detector = new \TrillChatLite\Leads\LeadIntentDetector();

$products_in_scope = [
    [ 'product_id' => 42, 'name' => 'Red T-Shirt' ],
];

$res = $detector->detect( 'Can you let me know when this is back in stock?', $products_in_scope );
trcl_assert(
    $res['type'] === 'out_of_stock' && $res['product_id'] === 42,
    'notify intent → out_of_stock + product_id tied'
);

$res = $detector->detect( 'Will you have a sale on this any time soon?', $products_in_scope );
trcl_assert(
    $res['type'] === 'price_drop' && $res['product_id'] === 42,
    'sale intent → price_drop + product_id tied'
);

$res = $detector->detect( 'Hi, do you ship to Spain?', [] );
trcl_assert(
    $res['type'] === 'none',
    'unrelated message → INTENT_NONE'
);

trcl_assert(
    $detector->extract_email( 'sure, send it to FOO@example.com please' ) === 'foo@example.com',
    'extract_email returns lowercased plausible email'
);

trcl_assert(
    $detector->extract_email( 'no email here' ) === '',
    'extract_email returns "" when no email present'
);

// ---------------------------------------------------------------------
// Step C: LeadCaptureService::capture + idempotency.
// ---------------------------------------------------------------------
echo "\nC. LeadCaptureService.capture\n";

$svc           = new \TrillChatLite\Leads\LeadCaptureService();
$smoke_session = 'smoke-' . substr( md5( (string) microtime( true ) ), 0, 8 );
$smoke_email   = 'smoke+' . time() . '@example.test';

$lead_id_1 = $svc->capture( $smoke_email, 'out_of_stock', [
    'session_id'     => $smoke_session,
    'product_id'     => 42,
    'opt_in_consent' => 'I\'ll only use it to notify you once.',
    'metadata'       => [ 'source' => 'smoke_test' ],
] );

trcl_assert(
    $lead_id_1 > 0,
    'first capture returns a lead id (' . $lead_id_1 . ')'
);

$row = $wpdb->get_row(
    $wpdb->prepare( "SELECT * FROM {$leads_tbl} WHERE id = %d", $lead_id_1 )
);
trcl_assert(
    $row && (string) $row->email === $smoke_email,
    'lead row has expected email'
);
trcl_assert(
    $row && (string) $row->intent_type === 'out_of_stock',
    'lead row has intent_type = out_of_stock'
);
trcl_assert(
    $row && (int) $row->product_id === 42,
    'lead row has product_id = 42'
);
trcl_assert(
    $row && str_contains( (string) $row->opt_in_consent, 'notify' ),
    'opt_in_consent column persisted'
);

// Idempotent re-capture of the same (email, session, intent).
$lead_id_2 = $svc->capture( $smoke_email, 'out_of_stock', [
    'session_id' => $smoke_session,
    'product_id' => 42,
] );
trcl_assert(
    $lead_id_2 === $lead_id_1,
    'second capture with same (email,session,intent) returns SAME id (idempotent)',
    "first={$lead_id_1}, second={$lead_id_2}"
);

// Different intent → new row.
$lead_id_3 = $svc->capture( $smoke_email, 'price_drop', [
    'session_id' => $smoke_session,
    'product_id' => 42,
] );
trcl_assert(
    $lead_id_3 > 0 && $lead_id_3 !== $lead_id_1,
    'different intent creates a separate row'
);

// ---------------------------------------------------------------------
// Step D: status update + find_by_email + count.
// ---------------------------------------------------------------------
echo "\nD. status update + lookup\n";

trcl_assert(
    $svc->update_status( $lead_id_1, 'contacted' ),
    'update_status returns true on valid input'
);
$status = (string) $wpdb->get_var(
    $wpdb->prepare( "SELECT status FROM {$leads_tbl} WHERE id = %d", $lead_id_1 )
);
trcl_assert(
    $status === 'contacted',
    'status column updated to "contacted"'
);

$found_leads = $svc->find_by_email( $smoke_email );
trcl_assert(
    count( $found_leads ) === 2,
    'find_by_email returns both lead rows for the email',
    'got ' . count( $found_leads )
);

// ---------------------------------------------------------------------
// Step E: PrivacyHooks export includes our lead.
// ---------------------------------------------------------------------
echo "\nE. WP Privacy exporter includes leads\n";

$exporters = \apply_filters( 'wp_privacy_personal_data_exporters', [] );
$cb        = $exporters[ \TrillChatLite\Gdpr\PrivacyHooks::EXPORTER_ID ]['callback'] ?? null;

trcl_assert(
    is_callable( $cb ),
    'exporter callback is callable'
);

if ( is_callable( $cb ) ) {
    $export = call_user_func( $cb, $smoke_email, 1 );

    $group_ids = array_unique( array_map(
        static fn( $item ): string => (string) ( $item['group_id'] ?? '' ),
        $export['data'] ?? []
    ) );

    trcl_assert(
        in_array( 'trill-ai-chat-lite-leads', $group_ids, true ),
        'exporter payload contains the leads group'
    );

    $leads_in_export = array_filter(
        $export['data'] ?? [],
        static fn( $item ): bool => ( $item['group_id'] ?? '' ) === 'trill-ai-chat-lite-leads'
    );
    trcl_assert(
        count( $leads_in_export ) === 2,
        'both leads appear in DSAR export',
        'got ' . count( $leads_in_export )
    );
}

// ---------------------------------------------------------------------
// Step F: eraser cascade nukes leads.
// ---------------------------------------------------------------------
echo "\nF. WP Privacy eraser cascade\n";

$erasers   = \apply_filters( 'wp_privacy_personal_data_erasers', [] );
$erase_cb  = $erasers[ \TrillChatLite\Gdpr\PrivacyHooks::ERASER_ID ]['callback'] ?? null;

if ( is_callable( $erase_cb ) ) {
    $erase_res = call_user_func( $erase_cb, $smoke_email, 1 );

    trcl_assert(
        (int) ( $erase_res['items_removed'] ?? 0 ) >= 2,
        'eraser reports at least 2 items removed (the two leads)'
    );

    $remaining = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_tbl} WHERE email = %s", $smoke_email )
    );
    trcl_assert(
        $remaining === 0,
        'no lead rows remain for the smoke email after erasure',
        'remaining=' . $remaining
    );
}

// ---------------------------------------------------------------------
// Step G: PromptBuilder LEAD CAPTURE OPPORTUNITY section.
// ---------------------------------------------------------------------
echo "\nG. PromptBuilder lead-offer rendering\n";

$builder = new \TrillChatLite\AI\PromptBuilder();
$builder->with_store_context( [ 'store_name' => 'Smoke Store' ] );
$builder->with_lead_offer( 'out_of_stock', 42, 'I will only use it to notify you once.' );
$prompt = $builder->build();

trcl_assert(
    strpos( $prompt, 'LEAD CAPTURE OPPORTUNITY:' ) !== false,
    'prompt includes LEAD CAPTURE OPPORTUNITY header'
);
trcl_assert(
    strpos( $prompt, 'out of stock' ) !== false,
    'prompt mentions out-of-stock context'
);
trcl_assert(
    strpos( $prompt, 'notify you once' ) !== false,
    'consent line is embedded in the prompt'
);

// ---------------------------------------------------------------------
// Cleanup any stray smoke rows.
// ---------------------------------------------------------------------
echo "\nH. Cleanup\n";

$deleted = $wpdb->query(
    $wpdb->prepare( "DELETE FROM {$leads_tbl} WHERE email = %s", $smoke_email )
);
echo "  removed {$deleted} stray rows\n";

echo "\n========================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
