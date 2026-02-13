<?php
/**
 * Logger utility.
 *
 * Provides structured logging for debugging and error tracking.
 * Writes to WordPress debug.log when WP_DEBUG_LOG is enabled.
 *
 * @package TrillChatLite\Utils
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Logger
 *
 * SOLID: Single Responsibility — only logging.
 */
class Logger {

    /**
     * Log levels.
     */
    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    /**
     * Logger context (class name / module).
     *
     * @var string
     */
    private string $context;

    /**
     * Constructor.
     *
     * @param string $context Logger context identifier.
     */
    public function __construct( string $context = 'General' ) {
        $this->context = $context;
    }

    /**
     * Log a debug message.
     *
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    public function debug( string $message, array $data = [] ): void {
        $this->log( 'debug', $message, $data );
    }

    /**
     * Log an info message.
     *
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    public function info( string $message, array $data = [] ): void {
        $this->log( 'info', $message, $data );
    }

    /**
     * Log a warning message.
     *
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    public function warning( string $message, array $data = [] ): void {
        $this->log( 'warning', $message, $data );
    }

    /**
     * Log an error message.
     *
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    public function error( string $message, array $data = [] ): void {
        $this->log( 'error', $message, $data );
    }

    /**
     * Write a log entry.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    private function log( string $level, string $message, array $data = [] ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return;
        }

        $min_level = defined( 'TCL_LOG_LEVEL' ) ? TCL_LOG_LEVEL : 'info';

        if ( ( self::LEVELS[ $level ] ?? 0 ) < ( self::LEVELS[ $min_level ] ?? 0 ) ) {
            return;
        }

        $timestamp = \current_time( 'Y-m-d H:i:s' );
        $upper     = strtoupper( $level );

        $entry = sprintf(
            '[%s] TCL.%s [%s] %s',
            $timestamp,
            $upper,
            $this->context,
            $message
        );

        if ( ! empty( $data ) ) {
            $entry .= ' | ' . \wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
        error_log( $entry );
    }
}
