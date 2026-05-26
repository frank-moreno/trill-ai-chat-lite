<?php
/**
 * Settings Controller
 *
 * Handles settings registration and validation.
 * Simplified for Lite: no BYOK, no fallback, no budget.
 *
 * @package TrillChatLite\Admin
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Settings.
 *
 * SOLID: Single Responsibility — only settings management.
 */
class Settings {

    /**
     * Settings group name.
     *
     * @var string
     */
    private const SETTINGS_GROUP = 'trcl_settings';

    /**
     * Register settings with WordPress Settings API.
     */
    public function register_settings(): void {
        // Chat enabled toggle.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_chat_enabled',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return $value === '1' ? '1' : '0';
                },
                'default'           => '1',
            ]
        );

        // Widget position.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_position',
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_position' ],
                'default'           => 'bottom-right',
            ]
        );

        // Widget primary colour.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_color',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_hex_color',
                'default'           => '#10B981',
            ]
        );

        // Show "Powered by Trill AI" badge (opt-in, OFF by default).
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_show_powered_by',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return $value === '1' ? '1' : '0';
                },
                'default'           => '0',
            ]
        );

        // Welcome message (textarea — sanitize_textarea_field preserves line breaks).
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_welcome_message',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default'           => __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-ai-chat-lite' ),
            ]
        );

        // Skip widget on WooCommerce checkout (opt-in, OFF by default).
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_skip_checkout',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return $value === '1' ? '1' : '0';
                },
                'default'           => '0',
            ]
        );

        // Skip widget on WooCommerce My Account pages (opt-in, OFF by default).
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_skip_account',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return $value === '1' ? '1' : '0';
                },
                'default'           => '0',
            ]
        );

        // Initial quick replies — suggested starter prompts shown when the chat opens.
        //
        // Stored as newline-separated text. Each non-empty line is one chip; an
        // optional "|" splits a display Label from the underlying Value sent on
        // click (falls back to Label when Value is omitted). Capped at 3 chips.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_initial_quick_replies',
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_initial_quick_replies' ],
                'default'           => $this->get_default_quick_replies_raw(),
            ]
        );

        // Content indexing settings (v2.0 Block 1).
        // Nested array — the sanitizer enforces structure + types.
        \register_setting(
            self::SETTINGS_GROUP,
            \TrillChatLite\Content\ContentSettings::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_content_settings' ],
                'default'           => [],
            ]
        );

        // GDPR settings (v2.0 Block 2).
        \register_setting(
            self::SETTINGS_GROUP,
            \TrillChatLite\Gdpr\GdprSettings::OPT_RETENTION_DAYS,
            [
                'type'              => 'integer',
                'sanitize_callback' => [ $this, 'sanitize_retention_days' ],
                'default'           => \TrillChatLite\Gdpr\GdprSettings::RETENTION_DEFAULT_DAYS,
            ]
        );

        \register_setting(
            self::SETTINGS_GROUP,
            \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_URL,
            [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            ]
        );

        \register_setting(
            self::SETTINGS_GROUP,
            \TrillChatLite\Gdpr\GdprSettings::OPT_PRIVACY_NOTICE_TEXT,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        trcl_log( 'Settings registered with WordPress', 'debug' );
    }

    /**
     * Sanitize the retention_days setting.
     *
     * Clamps the merchant's input to [RETENTION_MIN_DAYS, RETENTION_MAX_DAYS]
     * so typos can't disable the cleanup cron or set a 1-day window that
     * deletes legitimate active conversations.
     *
     * @since 2.0.0
     *
     * @param mixed $value Raw input.
     * @return int Clamped integer.
     */
    public function sanitize_retention_days( $value ): int {
        $n = (int) $value;
        if ( $n < \TrillChatLite\Gdpr\GdprSettings::RETENTION_MIN_DAYS ) {
            return \TrillChatLite\Gdpr\GdprSettings::RETENTION_MIN_DAYS;
        }
        if ( $n > \TrillChatLite\Gdpr\GdprSettings::RETENTION_MAX_DAYS ) {
            return \TrillChatLite\Gdpr\GdprSettings::RETENTION_MAX_DAYS;
        }
        return $n;
    }

    /**
     * Sanitize the trcl_content_settings nested-array payload coming
     * from the Content tab form.
     *
     * The form POSTs a structure like:
     *
     *   trcl_content_settings[enabled]                = '1'
     *   trcl_content_settings[auto_reindex]           = '1'
     *   trcl_content_settings[post_types][page]       = '1'
     *   trcl_content_settings[post_types][post]       = '1'
     *   trcl_content_settings[post_types][product_cat]= '1'
     *   trcl_content_settings[included_ids][page][]   = 12
     *   trcl_content_settings[included_ids][page][]   = 34
     *
     * Unchecked checkboxes are absent. Defaults: enabled OFF when not
     * checked, post_types OFF when not checked. Included IDs are
     * intval'd and deduped; empty array means "all published of that
     * post type". Unknown post_type keys are silently dropped to keep
     * the schema clean.
     *
     * Read-only meta (last_indexed, total_chunks) is preserved from
     * the existing option value — we never let the form overwrite
     * the indexer's runtime state.
     *
     * @since 2.0.0
     *
     * @param mixed $value Raw value from $_POST (expected: array, can be string).
     * @return array Sanitised, structured array.
     */
    public function sanitize_content_settings( $value ): array {
        if ( ! is_array( $value ) ) {
            $value = [];
        }

        // Preserve the indexer-managed status fields from the existing
        // stored option — the form never submits these.
        $existing     = \get_option( \TrillChatLite\Content\ContentSettings::OPTION_KEY, [] );
        $last_indexed = isset( $existing['last_indexed'] ) ? (string) $existing['last_indexed'] : '';
        $total_chunks = isset( $existing['total_chunks'] ) ? (int) $existing['total_chunks'] : 0;

        // Allowed post_type keys for the form. We deliberately ignore
        // unexpected keys (no surprises in the option payload).
        $allowed_types = [ 'page', 'post', 'product_cat' ];

        $clean = [
            'enabled'      => ( isset( $value['enabled'] ) && (string) $value['enabled'] === '1' ) ? '1' : '0',
            'auto_reindex' => ( isset( $value['auto_reindex'] ) && (string) $value['auto_reindex'] === '1' ) ? '1' : '0',
            'post_types'   => [],
            'included_ids' => [],
            'last_indexed' => $last_indexed,
            'total_chunks' => $total_chunks,
        ];

        foreach ( $allowed_types as $type ) {
            $checked = isset( $value['post_types'][ $type ] ) && (string) $value['post_types'][ $type ] === '1';
            $clean['post_types'][ $type ] = $checked ? '1' : '0';
        }

        foreach ( $allowed_types as $type ) {
            $ids = $value['included_ids'][ $type ] ?? [];
            if ( ! is_array( $ids ) ) {
                $ids = [];
            }
            $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
            $clean['included_ids'][ $type ] = $ids;
        }

        return $clean;
    }

    /**
     * Return the default initial quick replies as a raw textarea string.
     *
     * Kept in a helper so both register_setting() (for the default) and the
     * view layer (for initial render) share a single source of truth.
     *
     * @return string Newline-separated "Label|Value" lines.
     */
    public function get_default_quick_replies_raw(): string {
        return implode( "\n", [
            __( "What's on sale?", 'trill-ai-chat-lite' ),
            __( 'Help me choose a product', 'trill-ai-chat-lite' ),
            __( 'Do you ship to my country?', 'trill-ai-chat-lite' ),
        ] );
    }

    /**
     * Sanitize the initial quick replies textarea.
     *
     * SOLID: Single Responsibility — only parses + normalises this field.
     * OWASP:
     *   - Strip HTML tags and control bytes with sanitize_text_field() per line.
     *   - Hard length caps on label (80) and value (200) to keep the localised
     *     payload small and prevent stuffing.
     *   - Hard count cap (3) so the UI never breaks the chat footer layout.
     *
     * @param string $value Raw textarea content.
     * @return string Newline-separated, sanitised lines (≤ 3).
     */
    public function sanitize_initial_quick_replies( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $lines = preg_split( "/\r\n|\r|\n/", $value ) ?: [];
        $clean = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            // Split Label|Value, tolerating whitespace around the pipe.
            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            $label = isset( $parts[0] ) ? \sanitize_text_field( $parts[0] ) : '';
            $val   = isset( $parts[1] ) ? \sanitize_text_field( $parts[1] ) : '';

            if ( '' === $label ) {
                continue;
            }

            $label = mb_substr( $label, 0, 80 );
            $val   = ( '' !== $val ) ? mb_substr( $val, 0, 200 ) : '';

            $clean[] = ( '' !== $val ) ? $label . '|' . $val : $label;

            if ( count( $clean ) >= 3 ) {
                break;
            }
        }

        return implode( "\n", $clean );
    }

    /**
     * Parse the stored raw value into a structured array for localisation.
     *
     * Each entry is `[ 'label' => string, 'value' => string ]` where `value`
     * falls back to `label` when no pipe was provided.
     *
     * @return array<int, array{label:string, value:string}>
     */
    public function get_initial_quick_replies(): array {
        $raw = \get_option( 'trcl_initial_quick_replies', $this->get_default_quick_replies_raw() );

        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return [];
        }

        $lines = preg_split( "/\r\n|\r|\n/", $raw ) ?: [];
        $out   = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            $label = $parts[0] ?? '';
            $val   = $parts[1] ?? '';

            if ( '' === $label ) {
                continue;
            }

            $out[] = [
                'label' => $label,
                'value' => ( '' !== $val ) ? $val : $label,
            ];

            if ( count( $out ) >= 3 ) {
                break;
            }
        }

        return $out;
    }

    /**
     * Sanitize widget position.
     *
     * @param string $position Position value.
     * @return string Sanitized position.
     */
    public function sanitize_position( string $position ): string {
        $valid = [ 'bottom-right', 'bottom-left' ];

        $position = sanitize_text_field( $position );

        return in_array( $position, $valid, true ) ? $position : 'bottom-right';
    }

    /**
     * Get current configuration.
     *
     * @return array Current configuration.
     */
    public function get_current_config(): array {
        return [
            'chat_enabled'      => \get_option( 'trcl_chat_enabled', '1' ),
            'widget_position'   => \get_option( 'trcl_widget_position', 'bottom-right' ),
            'widget_color'      => \get_option( 'trcl_widget_color', '#10B981' ),
            'welcome_message'   => \get_option( 'trcl_welcome_message', '' ),
            'show_powered_by'   => \get_option( 'trcl_show_powered_by', '0' ),
            'skip_checkout'     => \get_option( 'trcl_skip_checkout', '0' ),
            'skip_account'      => \get_option( 'trcl_skip_account', '0' ),
        ];
    }

    /**
     * Get settings group name.
     *
     * @return string
     */
    public function get_settings_group(): string {
        return self::SETTINGS_GROUP;
    }
}
