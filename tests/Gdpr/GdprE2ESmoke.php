<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output and
//    direct PHP file I/O for the abort-gate — both are intentional for
//    a stand-alone harness and not appropriate WPCS subjects.
/**
 * E2E smoke for GDPR slice 1 (v2.0 Block 2).
 *
 * Verifies that:
 *   - PrivacyHooks registers an exporter + eraser via the WP filters.
 *   - ConversationManager finds conversations by both user_id AND by
 *     customer_email column.
 *   - export_for_email returns the WP Privacy API payload shape
 *     (group_id / group_label / item_id / data triples).
 *   - erase_for_email cascades through feedback → messages →
 *     conversations rows.
 *   - GdprSettings clamps retention_days to safe bounds.
 *
 * Self-cleaning: every fixture (user, conversation, messages) is
 * destroyed at the end so the test leaves no permanent rows on the
 * dev site even if the erase path is broken.
 *
 * Usage:
 *   wp eval-file tests/Gdpr/GdprE2ESmoke.php
 *
 * @package TrillChatLite\Tests\Gdpr
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

echo "GDPR slice 1 E2E smoke\n";
echo "======================\n\n";

// ---------------------------------------------------------------------
// Step A: GdprSettings clamps to safe bounds.
// ---------------------------------------------------------------------
echo "A. GdprSettings clamps retention\n";

$gdpr = new \TrillChatLite\Gdpr\GdprSettings();

\update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_RETENTION_DAYS, 1 );
trcl_assert(
    $gdpr->get_retention_days() === \TrillChatLite\Gdpr\GdprSettings::RETENTION_MIN_DAYS,
    'retention=1 clamps up to MIN_DAYS (' . \TrillChatLite\Gdpr\GdprSettings::RETENTION_MIN_DAYS . ')'
);

\update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_RETENTION_DAYS, 99999 );
trcl_assert(
    $gdpr->get_retention_days() === \TrillChatLite\Gdpr\GdprSettings::RETENTION_MAX_DAYS,
    'retention=99999 clamps down to MAX_DAYS (' . \TrillChatLite\Gdpr\GdprSettings::RETENTION_MAX_DAYS . ')'
);

\update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_RETENTION_DAYS, 365 );
trcl_assert(
    $gdpr->get_retention_days() === 365,
    'retention=365 passes through unchanged'
);

// ---------------------------------------------------------------------
// Step B: PrivacyHooks register entries in the WP filter registries.
// ---------------------------------------------------------------------
echo "\nB. WP Privacy API registration\n";

$exporters = \apply_filters( 'wp_privacy_personal_data_exporters', [] );
trcl_assert(
    isset( $exporters[ \TrillChatLite\Gdpr\PrivacyHooks::EXPORTER_ID ] ),
    'exporter is registered in wp_privacy_personal_data_exporters'
);
trcl_assert(
    isset( $exporters[ \TrillChatLite\Gdpr\PrivacyHooks::EXPORTER_ID ]['callback'] )
        && is_callable( $exporters[ \TrillChatLite\Gdpr\PrivacyHooks::EXPORTER_ID ]['callback'] ),
    'exporter callback is callable'
);

$erasers = \apply_filters( 'wp_privacy_personal_data_erasers', [] );
trcl_assert(
    isset( $erasers[ \TrillChatLite\Gdpr\PrivacyHooks::ERASER_ID ] ),
    'eraser is registered in wp_privacy_personal_data_erasers'
);
trcl_assert(
    isset( $erasers[ \TrillChatLite\Gdpr\PrivacyHooks::ERASER_ID ]['callback'] )
        && is_callable( $erasers[ \TrillChatLite\Gdpr\PrivacyHooks::ERASER_ID ]['callback'] ),
    'eraser callback is callable'
);

// ---------------------------------------------------------------------
// Step C: Fixture — create a fake user + conversation + messages.
// ---------------------------------------------------------------------
echo "\nC. Fixture setup\n";

$test_email = 'trcl-smoke-' . time() . '@example.test';
$test_user  = \wp_insert_user( [
    'user_login' => 'trcl_smoke_' . time(),
    'user_pass'  => \wp_generate_password( 24 ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
] );
if ( \is_wp_error( $test_user ) ) {
    echo "  FATAL: could not create test user — {$test_user->get_error_message()}\n";
    exit( 1 );
}
echo "  test user_id={$test_user}, email={$test_email}\n";

$db   = new \TrillChatLite\Database\DbManager();
$sess = $db->create_conversation( $test_user, [ 'customer_email' => $test_email ] );
$db->create_message( $sess, 'user', 'Test message 1 with potential PII like an email leaked.' );
$db->create_message( $sess, 'assistant', 'Test reply 1.' );
$db->create_message( $sess, 'user', 'Follow-up message 2.' );

trcl_assert(
    $sess !== '',
    'fixture conversation created',
    'session=' . $sess
);

$conv_count_before = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_conversations WHERE user_id = %d",
        $test_user
    )
);
trcl_assert(
    $conv_count_before === 1,
    'fixture: 1 conversation tied to user',
    'got ' . $conv_count_before
);

// ---------------------------------------------------------------------
// Step D: Lookup by email.
// ---------------------------------------------------------------------
echo "\nD. find_by_email lookup\n";

$mgr     = new \TrillChatLite\Gdpr\ConversationManager();
$by_mail = $mgr->find_by_email( $test_email );

trcl_assert(
    count( $by_mail ) >= 1,
    'find_by_email returns at least 1 row',
    'got ' . count( $by_mail )
);

$found_session = false;
foreach ( $by_mail as $c ) {
    if ( (string) $c->session_id === $sess ) {
        $found_session = true;
    }
}
trcl_assert(
    $found_session,
    'find_by_email returns the fixture conversation by session_id'
);

$by_user = $mgr->find_by_user_id( $test_user );
trcl_assert(
    count( $by_user ) >= 1,
    'find_by_user_id returns at least 1 row'
);

// ---------------------------------------------------------------------
// Step E: Export shape.
// ---------------------------------------------------------------------
echo "\nE. export_for_email payload shape\n";

$export = $mgr->export_for_email( $test_email );

trcl_assert(
    isset( $export['data'], $export['done'] ),
    'export return shape has data + done keys'
);
trcl_assert(
    $export['done'] === true,
    'export marked done=true'
);
trcl_assert(
    is_array( $export['data'] ) && count( $export['data'] ) >= 1,
    'export contains at least 1 item'
);

$item = $export['data'][0] ?? null;
trcl_assert(
    is_array( $item )
        && isset( $item['group_id'], $item['group_label'], $item['item_id'], $item['data'] ),
    'each item has WP Privacy API keys (group_id/group_label/item_id/data)'
);

$names = is_array( $item['data'] ?? null )
    ? array_column( $item['data'], 'name' )
    : [];
trcl_assert(
    in_array( 'Session ID', $names, true ) && in_array( 'Messages', $names, true ),
    'item.data carries Session ID + Messages keys'
);

$messages_value = '';
foreach ( $item['data'] as $field ) {
    if ( ( $field['name'] ?? '' ) === 'Messages' ) {
        $messages_value = (string) ( $field['value'] ?? '' );
    }
}
trcl_assert(
    strpos( $messages_value, 'Test message 1' ) !== false,
    'Messages field contains the actual user content'
);

// ---------------------------------------------------------------------
// Step F: Eraser callback (via WP API filter, end-to-end).
// ---------------------------------------------------------------------
echo "\nF. eraser_callback hard-delete\n";

$eraser_cb = $erasers[ \TrillChatLite\Gdpr\PrivacyHooks::ERASER_ID ]['callback'];
$result    = call_user_func( $eraser_cb, $test_email, 1 );

trcl_assert(
    isset( $result['items_removed'], $result['items_retained'], $result['messages'], $result['done'] ),
    'eraser return shape matches WP Privacy API contract'
);
trcl_assert(
    (int) $result['items_removed'] >= 1,
    'eraser removed at least 1 conversation',
    'items_removed=' . ( $result['items_removed'] ?? '?' )
);
trcl_assert(
    $result['done'] === true,
    'eraser marked done=true'
);

$conv_count_after = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_conversations WHERE user_id = %d",
        $test_user
    )
);
trcl_assert(
    $conv_count_after === 0,
    'all fixture conversations removed by eraser',
    'remaining=' . $conv_count_after
);

$msg_count_after = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_messages WHERE conversation_id NOT IN (SELECT id FROM {$wpdb->prefix}trcl_conversations)"
    )
);
trcl_assert(
    $msg_count_after === 0,
    'no orphan messages after cascade delete'
);

// ---------------------------------------------------------------------
// v2.4 PRV-02: audit logger.
// ---------------------------------------------------------------------
echo "\nF2. Audit logger (v2.4)\n";

$trcl_audit_table = $wpdb->prefix . 'trcl_gdpr_audit';
trcl_assert(
    $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $trcl_audit_table ) ) === $trcl_audit_table,
    'trcl_gdpr_audit table exists (schema 1.5.0)'
);

$trcl_audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$trcl_audit_table}" );

$trcl_logger = new \TrillChatLite\Gdpr\AuditLogger();
$trcl_logger->log( \TrillChatLite\Gdpr\AuditLogger::ACTION_SEARCH, 'smoke-test@example.com', 3 );

$trcl_audit_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$trcl_audit_table}" );
trcl_assert(
    $trcl_audit_after === $trcl_audit_before + 1,
    'audit log writes exactly one row per action'
);

$trcl_audit_row = $wpdb->get_row(
    "SELECT * FROM {$trcl_audit_table} ORDER BY id DESC LIMIT 1"
);
trcl_assert(
    $trcl_audit_row && $trcl_audit_row->target_email === 's*********@example.com',
    'audit row stores the email MASKED',
    'got: ' . ( $trcl_audit_row->target_email ?? 'null' )
);
trcl_assert(
    $trcl_audit_row && (int) $trcl_audit_row->records_affected === 3,
    'audit row records_affected persisted'
);

// Unknown action must be rejected, not written.
$trcl_logger->log( 'bogus', 'smoke-test@example.com', 1 );
trcl_assert(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$trcl_audit_table}" ) === $trcl_audit_after,
    'unknown audit action rejected'
);

$trcl_recent = $trcl_logger->get_recent( 5 );
trcl_assert(
    is_array( $trcl_recent ) && count( $trcl_recent ) >= 1 && $trcl_recent[0]->action === 'search',
    'get_recent returns newest first'
);

// Remove the smoke rows so repeated runs stay clean.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$trcl_audit_table} WHERE target_email = %s", 's*********@example.com' ) );

// ---------------------------------------------------------------------
// v2.4 PRV-03: retention service (preview must match run, dry-run
// must not delete).
// ---------------------------------------------------------------------
echo "\nF3. Retention service (v2.4)\n";

$trcl_retention = new \TrillChatLite\Gdpr\RetentionService();

$trcl_conv_total_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_conversations" );
$trcl_preview_count     = $trcl_retention->preview();

trcl_assert(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_conversations" ) === $trcl_conv_total_before,
    'preview() is a dry-run — no rows deleted'
);
trcl_assert(
    $trcl_preview_count >= 0 && $trcl_preview_count <= $trcl_conv_total_before,
    'preview() count within sane bounds'
);

$trcl_stats = $trcl_retention->get_stats();
trcl_assert(
    $trcl_stats['conversations'] === $trcl_conv_total_before,
    'get_stats() conversation total matches DB'
);
trcl_assert(
    $trcl_stats['retention_days'] >= 7 && $trcl_stats['retention_days'] <= 3650,
    'get_stats() retention respects the clamp'
);

// ---------------------------------------------------------------------
// v2.4 D11: consent key derivation.
// ---------------------------------------------------------------------
echo "\nF4. Consent key (v2.4)\n";

$trcl_gdpr_settings = new \TrillChatLite\Gdpr\GdprSettings();
$trcl_prev_url      = get_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL, '' );

update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL, 'https://example.com/privacy/' );
$trcl_key_a = $trcl_gdpr_settings->get_consent_key();

update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL, 'https://example.com/privacy-v2/' );
$trcl_key_b = $trcl_gdpr_settings->get_consent_key();

trcl_assert(
    $trcl_key_a !== '' && strpos( $trcl_key_a, 'trcl_consent_' ) === 0,
    'consent key derives with the expected prefix'
);
trcl_assert(
    $trcl_key_a !== $trcl_key_b,
    'consent key changes when the notice changes (forces re-consent)'
);

update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL, '' );
trcl_assert(
    $trcl_gdpr_settings->get_consent_key() === '',
    'no notice configured → no consent key (gate disabled)'
);

// Restore whatever the site had.
if ( $trcl_prev_url !== '' ) {
    update_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL, $trcl_prev_url );
} else {
    delete_option( \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL );
}

// ---------------------------------------------------------------------
// Cleanup fixture user.
// ---------------------------------------------------------------------
echo "\nG. Fixture teardown\n";

if ( ! function_exists( 'wp_delete_user' ) ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
}
$deleted_user = \wp_delete_user( $test_user );
trcl_assert(
    (bool) $deleted_user,
    'fixture user deleted'
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n======================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
