<?php
/**
 * Transcript Exporter (v2.2 CNV-04).
 *
 * Streams conversation data as CSV over the HTTP response. Two shapes:
 *
 *   - stream_conversations_csv(): one row per conversation, honouring
 *     the same filters as the admin list screen. Paged internally so
 *     large tables never load into memory at once.
 *   - stream_transcript_csv(): one row per message for a single
 *     conversation (the per-row "CSV" action).
 *
 * Deliberately CSV-only: the Pro plugin's "PDF" export is print-HTML,
 * judged not worth the OSS maintenance surface (decision 6 Jun 2026).
 *
 * @package TrillChatLite\Conversations
 * @since 2.2.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TranscriptExporter.
 *
 * SOLID: Single Responsibility — CSV serialisation + streaming only.
 * All querying is delegated to ConversationQueryService.
 */
class TranscriptExporter {

    /**
     * Page size used when walking the full result set for the global
     * export. Matches the query service's hard cap.
     */
    private const EXPORT_PAGE_SIZE = 100;

    /**
     * Query service.
     *
     * @var ConversationQueryService
     */
    private ConversationQueryService $service;

    /**
     * Constructor.
     *
     * @param ConversationQueryService|null $service Injectable for tests.
     */
    public function __construct( ?ConversationQueryService $service = null ) {
        $this->service = $service ?? new ConversationQueryService();
    }

    /**
     * Stream a CSV of all conversations matching the given (raw) filters.
     *
     * Caller is responsible for capability + nonce checks and for
     * calling exit afterwards. Raw filters are sanitised by the query
     * service — passing $_GET through is safe.
     *
     * @param array $raw_filters Raw filter args (date_from, date_to, status, rating, search).
     */
    public function stream_conversations_csv( array $raw_filters ): void {
        $filename = 'trill-conversations-' . \gmdate( 'Y-m-d' ) . '.csv';
        $fh       = $this->open_stream( $filename );

        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [
            'id',
            'session_id',
            'started_at',
            'ended_at',
            'status',
            'customer',
            'messages',
            'avg_rating',
            'converted',
            'order_id',
            'revenue',
        ] );

        $page = 1;

        do {
            $result = $this->service->query( array_merge( $raw_filters, [
                'page'     => $page,
                'per_page' => self::EXPORT_PAGE_SIZE,
            ] ) );

            foreach ( $result['rows'] as $row ) {
                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
                fputcsv( $fh, [
                    (int) $row->id,
                    CsvCellSanitizer::sanitize( $row->session_id ),
                    CsvCellSanitizer::sanitize( $row->started_at ),
                    CsvCellSanitizer::sanitize( $row->ended_at ),
                    CsvCellSanitizer::sanitize( $row->status ),
                    CsvCellSanitizer::sanitize( $this->customer_label( $row ) ),
                    (int) $row->message_count,
                    null !== $row->avg_rating ? round( (float) $row->avg_rating, 1 ) : '',
                    ( (int) $row->order_id > 0 ) ? 'yes' : 'no',
                    (int) $row->order_id > 0 ? (int) $row->order_id : '',
                    number_format( (float) $row->revenue, 2, '.', '' ),
                ] );
            }

            $page++;
        } while ( $page <= $result['pages'] && ! empty( $result['rows'] ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
        fclose( $fh );
    }

    /**
     * Stream one conversation's transcript as CSV (one row per message).
     *
     * @param int $conversation_id Conversation row ID.
     * @return bool False when the conversation does not exist (nothing streamed).
     */
    public function stream_transcript_csv( int $conversation_id ): bool {
        $transcript = $this->service->get_transcript( $conversation_id );

        if ( null === $transcript ) {
            return false;
        }

        $c        = $transcript['conversation'];
        $filename = 'trill-conversation-' . (int) $c->id . '-' . \gmdate( 'Y-m-d' ) . '.csv';
        $fh       = $this->open_stream( $filename );

        // Conversation meta block first — spreadsheet-friendly context.
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'session_id', CsvCellSanitizer::sanitize( $c->session_id ) ] );
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'customer', CsvCellSanitizer::sanitize( $this->customer_label( $c ) ) ] );
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'status', CsvCellSanitizer::sanitize( $c->status ) ] );
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'started_at', CsvCellSanitizer::sanitize( $c->started_at ) ] );
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'ended_at', CsvCellSanitizer::sanitize( (string) $c->ended_at ) ] );
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [] );

        // Message header + rows.
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
        fputcsv( $fh, [ 'timestamp', 'role', 'content', 'rating' ] );

        foreach ( $transcript['messages'] as $m ) {
            // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- HTTP response stream.
            fputcsv( $fh, [
                CsvCellSanitizer::sanitize( $m->created_at ),
                CsvCellSanitizer::sanitize( $m->role ),
                CsvCellSanitizer::sanitize( $m->content ),
                isset( $m->feedback_rating ) && null !== $m->feedback_rating ? (int) $m->feedback_rating : '',
            ] );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
        fclose( $fh );

        return true;
    }

    /**
     * Send CSV headers and open the output stream.
     *
     * WP_Filesystem is for on-disk files; here we write to the HTTP
     * response (php://output), where fputcsv-on-stream is the correct,
     * memory-safe tool (same precedent as the Leads export).
     *
     * @param string $filename Download filename.
     * @return resource
     */
    private function open_stream( string $filename ) {
        \nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream, not file IO.
        return fopen( 'php://output', 'w' );
    }

    /**
     * Customer label for CSV rows (no user lookup — keep the export
     * fast; user_id + email columns give the merchant what they need).
     *
     * @param object $row Row with user_id + customer_email.
     * @return string
     */
    private function customer_label( object $row ): string {
        $email = isset( $row->customer_email ) ? (string) $row->customer_email : '';

        if ( '' !== $email ) {
            return $email;
        }

        $user_id = isset( $row->user_id ) ? (int) $row->user_id : 0;

        return $user_id > 0 ? 'user:' . $user_id : 'guest';
    }
}
