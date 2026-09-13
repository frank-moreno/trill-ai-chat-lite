<?php
// phpcs:disable
// ^^ Dev-only smoke test. Excluded from the wp.org SVN package via
//    .distignore. Uses plain `echo` for human-readable CLI output —
//    intentional for a stand-alone harness, not a WPCS subject.
/**
 * Smoke for the secret-rotation building blocks (2.3.0 §2).
 *
 * Verifies:
 *   - SiteVerification token lifecycle: issue → pending → consume.
 *   - GET /wp-json/trcl/v1/verify serves the token during a window
 *     and 404s outside it (via rest_do_request — no HTTP needed).
 *   - LiteConfig rotate URL wiring.
 *   - ProxyClient::rotate() error mapping for a malformed local call
 *     is NOT exercised here (needs the live backend) — the E2E for
 *     that is the reinstall scenario on the testserver.
 *
 * Usage:
 *   wp eval-file tests/Lite/RotationSmoke.php
 *
 * @package TrillChatLite\Tests\Lite
 * @since 2.3.0
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

use TrillChatLite\Lite\SiteVerification;
use TrillChatLite\Lite\LiteConfig;

echo "Secret rotation building blocks smoke\n";
echo "=====================================\n\n";

// Clean slate.
SiteVerification::consume_token();

// ---------------------------------------------------------------------
// Step A: token lifecycle.
// ---------------------------------------------------------------------
echo "A. SiteVerification token lifecycle\n";

trcl_assert(
    SiteVerification::get_pending_token() === '',
    'no pending token before issue'
);

$token = SiteVerification::issue_token();

trcl_assert(
    preg_match( '/^[0-9a-f]{64}$/', $token ) === 1,
    'issued token is 64 lowercase hex chars',
    'got: "' . $token . '"'
);
trcl_assert(
    SiteVerification::get_pending_token() === $token,
    'pending token readable after issue'
);

// Re-issue replaces (one rotation in flight at a time).
$token2 = SiteVerification::issue_token();
trcl_assert(
    $token2 !== $token && SiteVerification::get_pending_token() === $token2,
    're-issue replaces the pending token'
);

SiteVerification::consume_token();
trcl_assert(
    SiteVerification::get_pending_token() === '',
    'consume clears the pending token'
);

// ---------------------------------------------------------------------
// Step B: REST endpoint via rest_do_request.
// ---------------------------------------------------------------------
echo "\nB. GET /wp-json/trcl/v1/verify\n";

// Outside a rotation window → 404.
$response = rest_do_request( new WP_REST_Request( 'GET', '/trcl/v1/verify' ) );
trcl_assert(
    $response->get_status() === 404,
    'verify endpoint 404s when no rotation is in progress',
    'got status ' . $response->get_status()
);

// During a window → 200 with the token.
$token3   = SiteVerification::issue_token();
$response = rest_do_request( new WP_REST_Request( 'GET', '/trcl/v1/verify' ) );
$data     = $response->get_data();

trcl_assert(
    $response->get_status() === 200,
    'verify endpoint 200s during a rotation window',
    'got status ' . $response->get_status()
);
trcl_assert(
    is_array( $data ) && ( $data['token'] ?? '' ) === $token3,
    'verify endpoint serves the staged token'
);

// Consumed → 404 again (single use).
SiteVerification::consume_token();
$response = rest_do_request( new WP_REST_Request( 'GET', '/trcl/v1/verify' ) );
trcl_assert(
    $response->get_status() === 404,
    'verify endpoint 404s again after consume'
);

// ---------------------------------------------------------------------
// Step C: config wiring.
// ---------------------------------------------------------------------
echo "\nC. LiteConfig rotate URL\n";

trcl_assert(
    str_ends_with( LiteConfig::get_trial_rotate_url(), '/v1/trial/rotate' ),
    'rotate URL ends with /v1/trial/rotate',
    'got: ' . LiteConfig::get_trial_rotate_url()
);
trcl_assert(
    str_starts_with(
        LiteConfig::get_trial_rotate_url(),
        LiteConfig::get_proxy_base_url()
    ),
    'rotate URL uses the configured proxy base'
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n=====================================\n";
echo "Results: {$passes} passed, {$failures} failed\n";

if ( $failures > 0 ) {
    exit( 1 );
}
