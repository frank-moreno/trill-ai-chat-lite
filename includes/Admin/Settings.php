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
     * Appearance colour options and their defaults (v2.1).
     *
     * Single source of truth shared by register_settings(), the
     * Appearance tab view and the frontend style injector. Defaults
     * mirror the widget's current hardcoded palette so existing
     * installs see zero visual change on upgrade.
     *
     * @since 2.1.0
     * @var array<string, string> option_name => default hex.
     */
    public const COLOR_DEFAULTS = [
        'trcl_widget_color'      => '#10B981', // Primary (header, launcher, send button).
        'trcl_widget_color_hover' => '#059669', // Primary hover (links, hover states).
        'trcl_user_bubble_color' => '#10B981', // User message bubble.
        'trcl_ai_bubble_color'   => '#F3F4F6', // Assistant message bubble.
        'trcl_header_text_color' => '#FFFFFF', // Header text.
        'trcl_body_text_color'   => '#1F2937', // Body / assistant bubble text.
        'trcl_widget_bg_color'   => '#FFFFFF', // Chat window background.
    ];

    /**
     * Valid widget positions (v2.1 expands from 2 to 4 corners).
     *
     * @since 2.1.0
     * @var string[]
     */
    public const POSITIONS = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];

    /**
     * Widget dimension ranges and defaults (px).
     *
     * Defaults match the previously hardcoded CSS values (370x520,
     * radius 12) — not the Pro defaults — so upgrades are visually
     * silent until the merchant opts into a change.
     *
     * @since 2.1.0
     */
    public const WIDTH_MIN      = 280;
    public const WIDTH_MAX      = 600;
    public const WIDTH_DEFAULT  = 370;
    public const HEIGHT_MIN     = 350;
    public const HEIGHT_MAX     = 800;
    public const HEIGHT_DEFAULT = 520;
    public const RADIUS_MIN     = 0;
    public const RADIUS_MAX     = 50;
    public const RADIUS_DEFAULT = 12;

    /**
     * Valid launcher styles (v2.1 APP-08).
     *
     * 'brand'  — floating Trill SVG logo (the pre-2.1 look, default so
     *            upgrades stay visually identical).
     * 'bubble' — classic round bubble with a chat icon, tinted with the
     *            primary colour.
     *
     * @since 2.1.0
     * @var string[]
     */
    public const LAUNCHER_STYLES = [ 'brand', 'bubble' ];

    /**
     * Curated font choices for the widget (key => CSS stack).
     *
     * A whitelist instead of a free-text field: font-family is injected
     * into a <style> block, so arbitrary input would be a CSS injection
     * vector. The empty key means "keep the widget's default system
     * stack". No webfonts — zero extra HTTP requests, GDPR-friendly.
     *
     * @since 2.1.0
     * @var array<string, string>
     */
    public const FONT_CHOICES = [
        ''        => '', // Default — widget's built-in system stack.
        'serif'   => 'Georgia, "Times New Roman", serif',
        'helvetica' => '"Helvetica Neue", Helvetica, Arial, sans-serif',
        'mono'    => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    ];

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

        // Widget colours (v2.1 — full palette; trcl_widget_color is the
        // legacy primary key kept for backwards compatibility).
        foreach ( self::COLOR_DEFAULTS as $color_option => $color_default ) {
            \register_setting(
                self::SETTINGS_GROUP,
                $color_option,
                [
                    'type'              => 'string',
                    'sanitize_callback' => function ( $value ) use ( $color_default ) {
                        $clean = \sanitize_hex_color( (string) $value );
                        return ( is_string( $clean ) && '' !== $clean ) ? $clean : $color_default;
                    },
                    'default'           => $color_default,
                ]
            );
        }

        // Widget dimensions (v2.1) — clamped ints, px units applied at render.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_width',
            [
                'type'              => 'integer',
                'sanitize_callback' => fn( $value ): int => $this->clamp_int( $value, self::WIDTH_MIN, self::WIDTH_MAX, self::WIDTH_DEFAULT ),
                'default'           => self::WIDTH_DEFAULT,
            ]
        );

        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_height',
            [
                'type'              => 'integer',
                'sanitize_callback' => fn( $value ): int => $this->clamp_int( $value, self::HEIGHT_MIN, self::HEIGHT_MAX, self::HEIGHT_DEFAULT ),
                'default'           => self::HEIGHT_DEFAULT,
            ]
        );

        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_border_radius',
            [
                'type'              => 'integer',
                'sanitize_callback' => fn( $value ): int => $this->clamp_int( $value, self::RADIUS_MIN, self::RADIUS_MAX, self::RADIUS_DEFAULT ),
                'default'           => self::RADIUS_DEFAULT,
            ]
        );

        // Assistant name (v2.1) — empty means "use the default (Robin)".
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_assistant_name',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ): string {
                    return mb_substr( \sanitize_text_field( (string) $value ), 0, 40 );
                },
                'default'           => '',
            ]
        );

        // Launcher style (v2.1 APP-08) — whitelisted key.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_launcher_style',
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_launcher_style' ],
                'default'           => 'brand',
            ]
        );

        // Custom avatar (v2.1) — media library attachment ID; 0 means
        // "use the bundled default avatar".
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_custom_avatar_id',
            [
                'type'              => 'integer',
                'sanitize_callback' => [ $this, 'sanitize_avatar_id' ],
                'default'           => 0,
            ]
        );

        // Widget font (v2.1) — whitelisted key into FONT_CHOICES.
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_widget_font',
            [
                'type'              => 'string',
                'sanitize_callback' => function ( $value ): string {
                    $key = \sanitize_key( (string) $value );
                    return array_key_exists( $key, self::FONT_CHOICES ) ? $key : '';
                },
                'default'           => '',
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
        // Default is empty: the frontend builds the localised fallback greeting
        // dynamically so it follows the configured assistant name (v2.1).
        \register_setting(
            self::SETTINGS_GROUP,
            'trcl_welcome_message',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default'           => '',
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
     * v2.1 expands the whitelist from 2 to 4 corners.
     *
     * @param string $position Position value.
     * @return string Sanitized position.
     */
    public function sanitize_position( string $position ): string {
        $position = sanitize_text_field( $position );

        return in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right';
    }

    /**
     * Clamp a raw value to an int within [min, max], falling back to a
     * default when the input is not numeric at all.
     *
     * @since 2.1.0
     *
     * @param mixed $value   Raw input.
     * @param int   $min     Lower bound.
     * @param int   $max     Upper bound.
     * @param int   $default Fallback when input is non-numeric.
     * @return int Clamped value.
     */
    public function clamp_int( $value, int $min, int $max, int $default ): int {
        if ( ! is_numeric( $value ) ) {
            return $default;
        }
        return max( $min, min( $max, (int) $value ) );
    }

    /**
     * Return the assistant's display name (custom or default "Robin").
     *
     * @since 2.1.0
     *
     * @return string Non-empty display name.
     */
    public function get_assistant_name(): string {
        // Re-sanitise on read (defence-in-depth): the option may have been
        // written by WP-CLI / imports that bypass the Settings API sanitizer,
        // and the value ends up in widget DOM built via string concatenation.
        $custom = mb_substr(
            \sanitize_text_field( (string) \get_option( 'trcl_assistant_name', '' ) ),
            0,
            40
        );

        return ( '' !== $custom ) ? $custom : __( 'Robin', 'trill-ai-chat-lite' );
    }

    /**
     * Sanitize the launcher style against the whitelist.
     *
     * @since 2.1.0
     *
     * @param mixed $value Raw input.
     * @return string 'brand' or 'bubble'.
     */
    public function sanitize_launcher_style( $value ): string {
        $key = \sanitize_key( (string) $value );

        return in_array( $key, self::LAUNCHER_STYLES, true ) ? $key : 'brand';
    }

    /**
     * Sanitize the custom avatar attachment ID.
     *
     * Accepts only IDs that resolve to an actual image attachment in the
     * media library — anything else collapses to 0 (default avatar).
     *
     * @since 2.1.0
     *
     * @param mixed $value Raw input.
     * @return int Valid image attachment ID, or 0.
     */
    public function sanitize_avatar_id( $value ): int {
        $id = \absint( $value );

        if ( $id > 0 && ! \wp_attachment_is_image( $id ) ) {
            return 0;
        }

        return $id;
    }

    /**
     * Return the custom avatar URL, or '' when the bundled default
     * should be used.
     *
     * Re-validates the attachment on read: if the image was deleted from
     * the media library since it was selected, we fall back to default
     * instead of emitting a broken <img>.
     *
     * @since 2.1.0
     *
     * @return string Escaped URL, or '' for default.
     */
    public function get_avatar_url(): string {
        $id = \absint( \get_option( 'trcl_custom_avatar_id', 0 ) );

        if ( $id <= 0 || ! \wp_attachment_is_image( $id ) ) {
            return '';
        }

        $url = \wp_get_attachment_image_url( $id, 'thumbnail' );

        return is_string( $url ) ? \esc_url( $url ) : '';
    }

    /**
     * Return the CSS font stack for the configured widget font.
     *
     * Empty string means "keep the widget's built-in system stack"
     * (i.e. emit no font-family override at all).
     *
     * @since 2.1.0
     *
     * @return string CSS font-family value, or '' for default.
     */
    public function get_font_stack(): string {
        $key = (string) \get_option( 'trcl_widget_font', '' );

        return self::FONT_CHOICES[ $key ] ?? '';
    }

    /**
     * Return the full, sanitised appearance configuration.
     *
     * Values are re-validated on read (defence-in-depth: options can be
     * written by other code paths, WP-CLI, or imports that bypass the
     * Settings API sanitizers).
     *
     * @since 2.1.0
     *
     * @return array{
     *   colors: array<string, string>,
     *   position: string,
     *   width: int,
     *   height: int,
     *   radius: int,
     *   font_stack: string,
     *   assistant_name: string
     * }
     */
    public function get_appearance_config(): array {
        $colors = [];
        foreach ( self::COLOR_DEFAULTS as $option => $default ) {
            $stored = \sanitize_hex_color( (string) \get_option( $option, $default ) );
            $colors[ $option ] = ( is_string( $stored ) && '' !== $stored ) ? $stored : $default;
        }

        return [
            'colors'         => $colors,
            'position'       => $this->sanitize_position( (string) \get_option( 'trcl_widget_position', 'bottom-right' ) ),
            'width'          => $this->clamp_int( \get_option( 'trcl_widget_width', self::WIDTH_DEFAULT ), self::WIDTH_MIN, self::WIDTH_MAX, self::WIDTH_DEFAULT ),
            'height'         => $this->clamp_int( \get_option( 'trcl_widget_height', self::HEIGHT_DEFAULT ), self::HEIGHT_MIN, self::HEIGHT_MAX, self::HEIGHT_DEFAULT ),
            'radius'         => $this->clamp_int( \get_option( 'trcl_widget_border_radius', self::RADIUS_DEFAULT ), self::RADIUS_MIN, self::RADIUS_MAX, self::RADIUS_DEFAULT ),
            'font_stack'     => $this->get_font_stack(),
            'assistant_name' => $this->get_assistant_name(),
            'launcher_style' => $this->sanitize_launcher_style( (string) \get_option( 'trcl_launcher_style', 'brand' ) ),
        ];
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
