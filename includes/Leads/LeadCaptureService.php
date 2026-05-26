<?php
/**
 * Read/write service over wp_trcl_leads.
 *
 * Captures and manages opt-in leads collected proactively by Robin
 * during chat conversations. Backed by Block 4's `trcl_leads` table.
 *
 * GDPR posture:
 *   - The consent text shown to the visitor at capture time is
 *     snapshotted into the row (opt_in_consent column) so a future
 *     audit can prove what the visitor agreed to.
 *   - erase_by_email() is called from Gdpr\ConversationManager so the
 *     plugin's WP Privacy Tools integration nukes leads alongside
 *     conversations on a right-to-erasure request.
 *
 * @package TrillChatLite\Leads
 * @since 2.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Leads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LeadCaptureService
 *
 * SOLID: Single Responsibility — persistence + queries on trcl_leads.
 */
class LeadCaptureService {

    /**
     * Allowed intent_type values. Anything outside this set is coerced
     * to 'generic' at insert so the column stays clean for filtering.
     */
    public const INTENT_OUT_OF_STOCK = 'out_of_stock';
    public const INTENT_PRICE_DROP   = 'price_drop';
    public const INTENT_GENERIC      = 'generic';

    /**
     * Allowed status values.
     */
    public const STATUS_NEW       = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_ERASED    = 'erased';

    /**
     * Table without prefix.
     */
    private const TABLE = 'trcl_leads';

    /**
     * @var \wpdb
     */
    private \wpdb $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Capture a new lead. Idempotent on (email, session_id) — if the
     * same visitor opts in twice for the same intent in the same chat
     * session we only keep one row and update its status/metadata.
     *
     * @param string $email          Raw email (sanitised before insert).
     * @param string $intent_type    out_of_stock | price_drop | generic.
     * @param array  $context        Optional context:
     *                               - session_id   string  Chat session UUID.
     *                               - product_id   int     Bound product (0 if none).
     *                               - opt_in_consent string Snapshot of the
     *                                                       text shown to visitor.
     *                               - metadata     array|string  Extra JSON.
     * @return int Inserted (or existing) row id, 0 on failure.
     */
    public function capture( string $email, string $intent_type, array $context = [] ): int {
        $email = \sanitize_email( $email );
        if ( $email === '' ) {
            trcl_log( 'Lead capture: invalid email', 'warning' );
            return 0;
        }

        $intent_type = in_array( $intent_type, [
            self::INTENT_OUT_OF_STOCK,
            self::INTENT_PRICE_DROP,
            self::INTENT_GENERIC,
        ], true ) ? $intent_type : self::INTENT_GENERIC;

        $table       = $this->wpdb->prefix . self::TABLE;
        $session_id  = isset( $context['session_id'] ) && $context['session_id'] !== ''
            ? (string) $context['session_id']
            : null;
        $product_id  = isset( $context['product_id'] ) ? (int) $context['product_id'] : 0;
        $consent     = isset( $context['opt_in_consent'] ) ? (string) $context['opt_in_consent'] : '';
        $metadata    = $this->encode_metadata( $context['metadata'] ?? null );

        // Idempotency: same email + same session + same intent → reuse row.
        $existing_id = $this->find_duplicate( $email, $session_id, $intent_type );
        if ( $existing_id > 0 ) {
            return $existing_id;
        }

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $ok = $this->wpdb->insert(
                $table,
                [
                    'session_id'     => $session_id,
                    'email'          => $email,
                    'intent_type'    => $intent_type,
                    'product_id'     => $product_id,
                    'status'         => self::STATUS_NEW,
                    'opt_in_consent' => $consent,
                    'metadata'       => $metadata,
                ],
                [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
            );

            if ( $ok === false ) {
                trcl_log( 'Lead capture insert failed', 'error', [
                    'error' => $this->wpdb->last_error,
                ] );
                return 0;
            }

            $id = (int) $this->wpdb->insert_id;

            trcl_log( 'Lead captured', 'info', [
                'lead_id'      => $id,
                'intent_type'  => $intent_type,
                'product_id'   => $product_id,
                'email_masked' => self::mask_email( $email ),
            ] );

            return $id;
        } catch ( \Throwable $e ) {
            trcl_log( 'Lead capture threw', 'error', [
                'error' => $e->getMessage(),
            ] );
            return 0;
        }
    }

    /**
     * Paginated lookup for the admin Leads page.
     *
     * @param array $filters Optional: status, intent_type, search (email substring).
     * @param int   $page    1-indexed.
     * @param int   $per_page
     * @return array{rows: array, total: int}
     */
    public function list_paginated( array $filters = [], int $page = 1, int $per_page = 20 ): array {
        $table   = $this->wpdb->prefix . self::TABLE;
        $page    = max( 1, $page );
        $offset  = ( $page - 1 ) * $per_page;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['intent_type'] ) ) {
            $where[]  = 'intent_type = %s';
            $params[] = (string) $filters['intent_type'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $where[]  = 'email LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like( (string) $filters['search'] ) . '%';
        }

        $where_sql = implode( ' AND ', $where );

        // Count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $total = (int) $this->wpdb->get_var(
            empty( $params )
                ? "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
                : $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params )
        );

        // Rows. We append LIMIT/OFFSET as integers, never user-supplied.
        $sql_rows = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY captured_at DESC LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, [ $per_page, $offset ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql_rows, ...$rows_params )
        );

        return [
            'rows'  => is_array( $rows ) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Update status of a lead (mark as contacted, etc).
     *
     * @param int    $lead_id
     * @param string $status One of STATUS_*.
     * @return bool
     */
    public function update_status( int $lead_id, string $status ): bool {
        if ( $lead_id <= 0 ) {
            return false;
        }
        if ( ! in_array( $status, [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_ERASED,
        ], true ) ) {
            return false;
        }

        $table = $this->wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $this->wpdb->update(
            $table,
            [ 'status' => $status ],
            [ 'id' => $lead_id ],
            [ '%s' ],
            [ '%d' ]
        );
        return $ok !== false;
    }

    /**
     * Hard-delete every lead row matching the email.
     *
     * Called from Gdpr\ConversationManager::erase_for_email so the
     * WP Privacy Tools flow stays consistent across all PII surfaces.
     *
     * @param string $email
     * @return int Rows deleted.
     */
    public function erase_by_email( string $email ): int {
        $email = \sanitize_email( $email );
        if ( $email === '' ) {
            return 0;
        }

        $table = $this->wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$table} WHERE email = %s",
                $email
            )
        );

        return (int) max( 0, $deleted );
    }

    /**
     * Lookup leads by email (for DSAR export).
     *
     * @param string $email
     * @return array
     */
    public function find_by_email( string $email ): array {
        $email = \sanitize_email( $email );
        if ( $email === '' ) {
            return [];
        }

        $table = $this->wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s ORDER BY captured_at ASC",
                $email
            )
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Total leads captured in the period (for dashboard widget if we
     * surface it later — currently used only by tests).
     *
     * @param int $days Lookback window.
     * @return int
     */
    public function count_recent( int $days = 30 ): int {
        $table  = $this->wpdb->prefix . self::TABLE;
        $cutoff = \gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE captured_at >= %s",
                $cutoff
            )
        );
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Find an existing lead row for the same (email, session_id, intent)
     * tuple. Used for idempotent capture.
     *
     * @return int Existing row id, or 0 if none.
     */
    private function find_duplicate( string $email, ?string $session_id, string $intent_type ): int {
        $table = $this->wpdb->prefix . self::TABLE;

        if ( $session_id === null ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$table}
                      WHERE email = %s AND intent_type = %s AND session_id IS NULL
                      LIMIT 1",
                    $email,
                    $intent_type
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$table}
                      WHERE email = %s AND intent_type = %s AND session_id = %s
                      LIMIT 1",
                    $email,
                    $intent_type,
                    $session_id
                )
            );
        }

        return $id ? (int) $id : 0;
    }

    /**
     * @param array|string|null $metadata
     * @return string|null
     */
    private function encode_metadata( $metadata ): ?string {
        if ( $metadata === null ) {
            return null;
        }
        if ( is_string( $metadata ) ) {
            return $metadata;
        }
        $json = \wp_json_encode( $metadata );
        return is_string( $json ) ? $json : null;
    }

    /**
     * Mask email for log output (same shape as ConversationManager).
     */
    private static function mask_email( string $email ): string {
        $at = strpos( $email, '@' );
        if ( $at === false || $at < 1 ) {
            return '***';
        }
        return $email[0] . str_repeat( '*', max( 1, $at - 1 ) ) . substr( $email, $at );
    }
}
