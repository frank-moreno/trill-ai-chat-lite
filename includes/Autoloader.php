<?php
/**
 * PSR-4 Autoloader for the TrillChatLite namespace.
 *
 * Replaces Composer's vendor/autoload.php so the plugin works
 * out-of-the-box without running `composer install`.
 *
 * @package TrillChatLite
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( function ( string $class ): void {

	$prefix   = 'TrillChatLite\\';
	$base_dir = __DIR__ . '/';

	// Bail if the class does not belong to our namespace.
	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	// Build the file path: TrillChatLite\Admin\Settings → includes/Admin/Settings.php
	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );
