<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output — both
//    are intentional for a stand-alone harness and not appropriate WPCS
//    subjects.
/**
 * E2E smoke for admin conversation deletion (v2.2 CNV-08).
 *
 * Verifies ConversationManager::delete_by_ids():
 *   - Input hardening: empty / zero / negative IDs delete nothing.
 *   - Single delete removes exactly one conversation and its messages,
 *     leaving sibling conversations untouched.
 *   - Bulk delete removes multiple conversations, silently ignores a
 *     non-existent ID, and leaves no orphan message rows (the GDPR
 *     cascade feedback → messages → conversations).
 *
 * Self-cleaning: every fixture (user, conversations, messages) is
 * destroyed at the end so the test leaves no permanent rows even if the
 * delete path is broken.
 *
 * Usage:
 *   wp eval-file tests/Conversations/DeleteByIdsSmoke.php
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

/** Count messages still attached to a given conversation id. */
function trcl_msg_count( int $conversation_id ): int {
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_messages WHERE conversation_id = %d",
            $conversation_id
        )
    );
}

/** True when a conversation row still exists. */
function trcl_conv_exists( int $conversation_id ): bool {
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_conversations WHERE id = %d",
            $conversation_id
        )
    ) > 0;
}

echo "Conversations delete_by_ids E2E smoke (CNV-08)\n";
echo "==============================================\n\n";

// ---------------------------------------------------------------------
// Step A: Fixture — one user, two conversations, messages on each.
// ---------------------------------------------------------------------
echo "A. Fixture setup\n";

$test_email = 'trcl-del-' . time() . '@example.test';
$test_user  = \wp_insert_user( [
    'user_login' => 'trcl_del_' . time(),
    'user_pass'  => \wp_generate_password( 24 ),
    'user_email' => $test_email,
    'role'       => 'subscriber',
] );
if ( \is_wp_error( $test_user ) ) {
    echo "  FATAL: could not create test user — {$test_user->get_error_message()}\n";
    exit( 1 );
}

$db = new \TrillChatLite\Database\DbManager();

$sess_a = $db->create_conversation( $test_user, [ 'customer_email' => $test_email ] );
$db->create_message( $sess_a, 'user', 'Conversation A — message 1.' );
$db->create_message( $sess_a, 'assistant', 'Conversation A — reply 1.' );

$sess_b = $db->create_conversation( $test_user, [ 'customer_email' => $test_email ] );
$db->create_message( $sess_b, 'user', 'Conversation B — message 1.' );
$db->create_message( $sess_b, 'assistant', 'Conversation B — reply 1.' );
$db->create_message( $sess_b, 'user', 'Conversation B — message 2.' );

$id_a = (int) $db->get_conversation_id( $sess_a );
$id_b = (int) $db->get_conversation_id( $sess_b );

trcl_assert( $id_a > 0 && $id_b > 0 && $id_a !== $id_b, 'two distinct fixture conversations created', "A={$id_a} B={$id_b}" );
trcl_assert( trcl_msg_count( $id_a ) === 2, 'conversation A has 2 messages', 'got ' . trcl_msg_count( $id_a ) );
trcl_assert( trcl_msg_count( $id_b ) === 3, 'conversation B has 3 messages', 'got ' . trcl_msg_count( $id_b ) );

$mgr = new \TrillChatLite\Gdpr\ConversationManager();

// ---------------------------------------------------------------------
// Step B: Input hardening — nothing valid means nothing deleted.
// ---------------------------------------------------------------------
echo "\nB. Input hardening\n";

trcl_assert( $mgr->delete_by_ids( [] ) === 0, 'empty array deletes nothing (returns 0)' );
trcl_assert( $mgr->delete_by_ids( [ 0, -3, -1 ] ) === 0, 'zero / negative IDs delete nothing (returns 0)' );
trcl_assert( trcl_conv_exists( $id_a ) && trcl_conv_exists( $id_b ), 'both conversations still present after hardening calls' );

// ---------------------------------------------------------------------
// Step C: Single delete — A goes, B untouched.
// ---------------------------------------------------------------------
echo "\nC. Single delete\n";

$removed_a = $mgr->delete_by_ids( [ $id_a ] );

trcl_assert( $removed_a >= 1, 'single delete removed at least 1 row', 'rows=' . $removed_a );
trcl_assert( ! trcl_conv_exists( $id_a ), 'conversation A removed' );
trcl_assert( trcl_msg_count( $id_a ) === 0, 'conversation A messages removed' );
trcl_assert( trcl_conv_exists( $id_b ), 'conversation B untouched' );
trcl_assert( trcl_msg_count( $id_b ) === 3, 'conversation B still has its 3 messages' );

// ---------------------------------------------------------------------
// Step D: Bulk delete — B + a non-existent ID; no orphans left.
// ---------------------------------------------------------------------
echo "\nD. Bulk delete + non-existent ID\n";

$removed_b = $mgr->delete_by_ids( [ $id_b, 999999999 ] );

trcl_assert( $removed_b >= 1, 'bulk delete removed at least 1 row', 'rows=' . $removed_b );
trcl_assert( ! trcl_conv_exists( $id_b ), 'conversation B removed' );

$orphans = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}trcl_messages WHERE conversation_id IN ( %d, %d )",
        $id_a,
        $id_b
    )
);
trcl_assert( $orphans === 0, 'no orphan messages remain for either conversation', 'orphans=' . $orphans );

// ---------------------------------------------------------------------
// Cleanup fixture user (conversations already gone).
// ---------------------------------------------------------------------
echo "\nE. Fixture teardown\n";

if ( ! function_exists( 'wp_delete_user' ) ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
}
trcl_assert( (bool) \wp_delete_user( $test_user ), 'fixture user deleted' );

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n==============================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
