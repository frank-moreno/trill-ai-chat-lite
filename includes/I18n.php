<?php
/**
 * Internationalisation functionality
 *
 * @package GspltdChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite;

/**
 * Loads and defines the internationalisation files for this plugin.
 */
class I18n {

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain(): void {
        \load_plugin_textdomain(
            'gspltd-chat-lite',
            false,
            dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
        );
    }
}
