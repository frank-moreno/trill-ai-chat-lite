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
    private const SETTINGS_GROUP = 'tcl_settings';

    /**
     * Register settings with WordPress Settings API.
     */
    public function register_settings(): void {
        // Chat enabled toggle.
        \register_setting(
            self::SETTINGS_GROUP,
            'tcl_chat_enabled',
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
            'tcl_widget_position',
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_position' ],
                'default'           => 'bottom-right',
            ]
        );

        // Widget primary colour.
        \register_setting(
            self::SETTINGS_GROUP,
            'tcl_widget_color',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_hex_color',
                'default'           => '#10B981',
            ]
        );

        // Welcome message.
        \register_setting(
            self::SETTINGS_GROUP,
            'tcl_welcome_message',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => __( "Hi there! I'm Robin, your AI shopping assistant. How can I help you today?", 'trill-chat-lite' ),
            ]
        );

        trill_chat_lite_log( 'Settings registered with WordPress', 'debug' );
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
            'chat_enabled'     => \get_option( 'tcl_chat_enabled', '1' ),
            'widget_position'  => \get_option( 'tcl_widget_position', 'bottom-right' ),
            'widget_color'     => \get_option( 'tcl_widget_color', '#10B981' ),
            'welcome_message'  => \get_option( 'tcl_welcome_message', '' ),
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
