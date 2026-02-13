<?php
/**
 * Internationalisation functionality
 *
 * Since WordPress 4.6, translations for plugins hosted on WordPress.org
 * are loaded automatically. No manual call to load_plugin_textdomain()
 * is needed.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Internationalisation placeholder.
 *
 * Kept for backward compatibility. WordPress.org handles translations
 * automatically since WordPress 4.6.
 */
class I18n {

    /**
     * No-op. WordPress loads translations automatically for .org-hosted plugins.
     */
    public function load_plugin_textdomain(): void {
        // Intentionally empty — WordPress handles this automatically since 4.6.
    }
}
