<?php
/**
 * GDPR audit logger (v2.4 PRV-02).
 *
 * Append-only accountability trail for the GDPR Tools admin page:
 * every search / view / export / erase writes exactly one row to
 * trcl_gdpr_audit, recording WHO did WHAT to WHICH (masked) email
 * and HOW MANY records were touched.
 *
 * Privacy posture (deliberate):
 *   - Emails are masked BEFORE they reach the table — the clear
 *     address never touches disk here (OWASP A02).
 *   - No IP addresses, of the admin or anyone else. Lite never
 *     stores IPs.
 *   - Rows are exempt from the retention cleanup (decision D3) —
 *     this table IS the compliance record.
 *
 * @package TrillChatLite\Gdpr
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AuditLogger
 *
 * SOLID: Single Responsibility — only writing and reading GDPR audit
 * entries. Callers (GdprToolsPage, ConversationManager wrappers)
 * depend on this abstraction, never on $wpdb directly.
 */
class AuditLogger {

    /**
     * Logical action names — the only values ever written to the
     * `action` column. varchar(20), so keep new ones short.
     */
    public const ACTION_SEARCH = 'search';
    public const ACTION_VIEW   = 'view';
    public const ACTION_EXPORT = 'export';
    public const ACTION_ERASE  = 'erase';

    /**
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Fully-qualified audit table name.
     *
     * @var string
     */
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'trcl_gdpr_audit';
    }

    /**
     * Record one GDPR action.
     *
     * The email is masked here, unconditionally — callers pass the
     * clear address and must NOT pre-mask (double-masking would
     * corrupt the domain part).
     *
     * @param string $action  One of the ACTION_* constants.
     * @param string $email   Clear email of the data subject.
     * @param int    $records Number of records the action touched
     *                        (0 for a search with no results).
     */
    public function log( string $action, string $email, int $records ): void {
        if ( ! in_array(
            $action,
            [ self::ACTION_SEARCH, self::ACTION_VIEW, self::ACTION_EXPORT, self::ACTION_ERASE ],
            true
        ) ) {
            trcl_log( 'AuditLogger: unknown action rejected', 'warning', [
                'action' => $action,
            ] );
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom audit table; $wpdb->insert handles preparation.
        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'action'           => $action,
                'target_email'     => ConversationManager::mask_email( $email ),
                'performed_by'     => \get_current_user_id(),
                'records_affected' => max( 0, $records ),
                'created_at'       => \current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%d', '%d', '%s' ]
        );

        if ( false === $inserted ) {
            // Non-fatal by design: a failed audit write must not block
            // the underlying GDPR action, but it must leave a trace.
            trcl_log( 'AuditLogger: insert failed', 'error', [
                'action'   => $action,
                'db_error' => $this->wpdb->last_error,
            ] );
        }
    }

    /**
     * Latest audit entries, newest first, for the GDPR Tools page.
     *
     * @param int $limit Maximum rows to return (clamped 1–100).
     * @return array<int, object> Rows with action, target_email,
     *                            performed_by, records_affected,
     *                            created_at.
     */
    public function get_recent( int $limit = 20 ): array {
        $limit = max( 1, min( 100, $limit ) );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from $wpdb->prefix concat (trusted); LIMIT bound via prepare. Block-scoped: phpcs:ignore only covers one line and this statement spans several.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT action, target_email, performed_by, records_affected, created_at
                   FROM {$this->table}
                  ORDER BY created_at DESC, id DESC
                  LIMIT %d",
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return is_array( $rows ) ? $rows : [];
    }
}
