<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output —
//    intentional for a stand-alone harness, not a WPCS subject.
/**
 * Smoke for the encrypted trial-secret store (2.5.0 M3).
 *
 * Verifies:
 *   - Encryptor availability on this host (OpenSSL GCM + real AUTH_KEY).
 *   - set_secret() stores an `enc:v1:` payload, never the plaintext.
 *   - get_secret() round-trips; has_secret() agrees.
 *   - A legacy plaintext `tt_trial_*` row (<= 2.4.x) is migrated on read.
 *   - A tampered payload reads as "no secret" (never garbage).
 *   - clear_secret() empties the store.
 *
 * The real secret of this site is saved before the run and restored
 * afterwards, so the smoke is safe on a registered site.
 *
 * Usage:
 *   wp eval-file tests/Lite/SecretStoreSmoke.php
 *
 * @package TrillChatLite\Tests\Lite
 * @since 2.5.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must be run via wp eval-file (needs WordPress).\n" );
    exit( 1 );
}

global $passes, $failures;
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

use TrillChatLite\Lite\LiteConfig;
use TrillChatLite\Lite\TrialSecretStore;
use TrillChatLite\Utils\Encryptor;

echo "Encrypted secret store smoke\n";
echo "============================\n\n";

// Preserve the live row (raw, whatever its format) and restore at the end.
$trcl_saved_row = get_option( LiteConfig::OPT_TRIAL_SECRET, null );

$trcl_restore = static function () use ( $trcl_saved_row ): void {
    delete_option( LiteConfig::OPT_TRIAL_SECRET );
    if ( is_string( $trcl_saved_row ) && $trcl_saved_row !== '' ) {
        add_option( LiteConfig::OPT_TRIAL_SECRET, $trcl_saved_row, '', 'no' );
    }
};

// ---------------------------------------------------------------------
// Step A: availability.
// ---------------------------------------------------------------------
echo "Step A — availability\n";
trcl_assert( Encryptor::is_available(), 'Encryptor available (OpenSSL GCM + AUTH_KEY)', 'Without it the store keeps plaintext and logs a warning — check AUTH_KEY in wp-config.php.' );

// ---------------------------------------------------------------------
// Step B: write → stored encrypted → round-trip.
// ---------------------------------------------------------------------
echo "\nStep B — write and read\n";
$trcl_secret = 'tt_trial_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 );
delete_option( LiteConfig::OPT_TRIAL_SECRET );

trcl_assert( TrialSecretStore::set_secret( $trcl_secret ), 'set_secret() returns true' );
$trcl_raw = (string) get_option( LiteConfig::OPT_TRIAL_SECRET, '' );
trcl_assert( str_starts_with( $trcl_raw, 'enc:v1:' ), 'row is stored as enc:v1:<payload>', 'raw=' . substr( $trcl_raw, 0, 16 ) );
trcl_assert( strpos( $trcl_raw, $trcl_secret ) === false, 'plaintext never appears in the row' );
trcl_assert( TrialSecretStore::get_secret() === $trcl_secret, 'get_secret() round-trips' );
trcl_assert( TrialSecretStore::has_secret(), 'has_secret() true' );
trcl_assert( TrialSecretStore::set_secret( $trcl_secret ), 're-set same secret (new IV) still returns true' );
trcl_assert( TrialSecretStore::get_secret() === $trcl_secret, 'round-trips after re-set' );

// ---------------------------------------------------------------------
// Step C: legacy plaintext migration.
// ---------------------------------------------------------------------
echo "\nStep C — legacy plaintext row\n";
$trcl_legacy = 'tt_trial_legacy00000000000000000000000000';
update_option( LiteConfig::OPT_TRIAL_SECRET, $trcl_legacy, false );
trcl_assert( TrialSecretStore::get_secret() === $trcl_legacy, 'legacy row readable' );
trcl_assert( str_starts_with( (string) get_option( LiteConfig::OPT_TRIAL_SECRET, '' ), 'enc:v1:' ), 'legacy row migrated to enc:v1 on read' );
trcl_assert( TrialSecretStore::get_secret() === $trcl_legacy, 'still readable after migration' );

// ---------------------------------------------------------------------
// Step D: tampered payload.
// ---------------------------------------------------------------------
echo "\nStep D — tampered payload\n";
$trcl_row   = (string) get_option( LiteConfig::OPT_TRIAL_SECRET, '' );
$trcl_bytes = base64_decode( substr( $trcl_row, 7 ), true );
$trcl_bytes[ strlen( $trcl_bytes ) - 1 ] = chr( ord( $trcl_bytes[ strlen( $trcl_bytes ) - 1 ] ) ^ 0x01 );
update_option( LiteConfig::OPT_TRIAL_SECRET, 'enc:v1:' . base64_encode( $trcl_bytes ), false );
trcl_assert( TrialSecretStore::get_secret() === '', 'tampered row reads as empty' );
trcl_assert( ! TrialSecretStore::has_secret(), 'has_secret() false on tampered row' );

// ---------------------------------------------------------------------
// Step E: clear.
// ---------------------------------------------------------------------
echo "\nStep E — clear\n";
trcl_assert( TrialSecretStore::clear_secret(), 'clear_secret() returns true' );
trcl_assert( TrialSecretStore::get_secret() === '', 'empty after clear' );

$trcl_restore();
trcl_assert( get_option( LiteConfig::OPT_TRIAL_SECRET, '' ) === ( $trcl_saved_row ?? '' ), 'original row restored' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures === 0 ? 0 : 1 );
