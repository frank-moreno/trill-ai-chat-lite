<?php
/**
 * Data-subject exporter (v2.4 PRV-01, decision D1: JSON + CSV).
 *
 * Streams the complete GDPR file ("expediente") for one email over
 * the HTTP response, in two portable shapes:
 *
 *   - stream_json(): pretty-printed JSON with export metadata —
 *     the real portability format (nested structures preserved).
 *   - stream_csv(): the same file flattened to one row per field
 *     (group / item / field / value), for merchants who live in
 *     spreadsheets. Every cell passes through CsvCellSanitizer.
 *
 * The expediente itself comes from ConversationManager::
 * export_for_email() — the exact payload the WP Privacy API exporter
 * serves, so "what we show", "what we export here" and "what WP
 * exports natively" can never drift apart.
 *
 * Callers own capability + nonce checks, audit logging, and exit.
 *
 * @package TrillChatLite\Gdpr
 * @since 2.4.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Conversations\CsvCellSanitizer;

/**
 * Class SubjectExporter
 *
 * SOLID: Single Responsibility — serialisation + streaming of one
 * subject's expediente. All querying delegates to ConversationManager.
 */
class SubjectExporter {

    /**
     * Conversation manager (query + payload source).
     *
     * @var ConversationManager
     */
    private ConversationManager $manager;

    /**
     * Constructor.
     *
     * @param ConversationManager|null $manager Injectable for tests.
     */
    public function __construct( ?ConversationManager $manager = null ) {
        $this->manager = $manager ?? new ConversationManager();
    }

    /**
     * Number of items (conversations + leads) in the subject's file.
     * Used by callers for the audit trail's records_affected.
     *
     * @param string $email
     * @return int
     */
    public function count_items( string $email ): int {
        return count( $this->manager->export_for_email( $email )['data'] );
    }

    /**
     * Stream the expediente as pretty-printed JSON.
     *
     * @param string $email Data subject's email (already sanitised by caller).
     */
    public function stream_json( string $email ): void {
        $payload = [
            'generated_at'  => \gmdate( 'c' ),
            'site_url'      => \home_url(),
            'subject_email' => $email,
            'items'         => $this->manager->export_for_email( $email )['data'],
        ];

        \nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="trill-gdpr-data-' . \gmdate( 'Y-m-d' ) . '.json"' );

        echo \wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Stream the expediente flattened as CSV.
     *
     * One row per exported field: group | item | field | value.
     * Multi-line values (the concatenated Messages transcript) stay
     * multi-line inside their quoted cell — spreadsheets handle that.
     *
     * @param string $email Data subject's email (already sanitised by caller).
     */
    public function stream_csv( string $email ): void {
        $items = $this->manager->export_for_email( $email )['data'];

        \nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="trill-gdpr-data-' . \gmdate( 'Y-m-d' ) . '.csv"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream, not file IO.
        $fh = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'group', 'item', 'field', 'value' ] );

        foreach ( $items as $item ) {
            foreach ( $item['data'] as $field ) {
                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
                fputcsv( $fh, [
                    CsvCellSanitizer::sanitize( (string) $item['group_id'] ),
                    CsvCellSanitizer::sanitize( (string) $item['item_id'] ),
                    CsvCellSanitizer::sanitize( (string) $field['name'] ),
                    CsvCellSanitizer::sanitize( (string) $field['value'] ),
                ] );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing HTTP response stream opened above.
        fclose( $fh );
    }
}
