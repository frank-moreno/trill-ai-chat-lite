<?php
/**
 * Main Plugin Class
 *
 * Implements Singleton pattern. Coordinates all plugin components.
 * Simplified for Lite tier: no engines, no tiers, no licensing.
 *
 * @package TrillChatLite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TrillChatLite\Admin\Admin;
use TrillChatLite\Admin\Settings;
use TrillChatLite\Frontend\Frontend;
use TrillChatLite\Database\DbManager;
use TrillChatLite\Lite\UpgradeNotices;
use TrillChatLite\Lite\UsageLimiter;
use TrillChatLite\AI\ProxyClient;

/**
 * Class Plugin
 *
 * Main orchestrator following Single Responsibility Principle.
 * Responsible only for plugin initialisation and component coordination.
 */
final class Plugin {

    /**
     * Plugin version.
     *
     * @var string
     */
    private string $version;

    /**
     * Loader instance for managing hooks.
     *
     * @var Loader
     */
    private Loader $loader;

    /**
     * Single instance (Singleton).
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Plugin components.
     *
     * @var array
     */
    private array $components = [];

    /**
     * Get singleton instance.
     *
     * @return Plugin
     */
    public static function get_instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $this->version = defined( 'TCL_VERSION' ) ? TCL_VERSION : '1.0.0';
        $this->loader  = new Loader();

        $this->init_components();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialising.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }

    /**
     * Initialise the plugin.
     *
     * Sets up internationalisation and runs all registered hooks.
     */
    public function init(): void {
        $this->set_locale();
        $this->loader->run();

        tcl_log( 'Trill Chat Lite initialised successfully', 'info' );
    }

    /**
     * Initialise plugin components.
     *
     * Following Interface Segregation Principle — each component has specific responsibility.
     */
    private function init_components(): void {

        // 1. Database Manager.
        $this->components['db_manager'] = new DbManager();

        // 2. Usage Limiter (Lite-specific).
        $this->components['usage_limiter'] = new UsageLimiter();

        // 3. Settings manager.
        $this->components['settings'] = new Settings();

        // 4. Admin component (always instantiated for AJAX handlers).
        $this->components['admin'] = new Admin(
            $this->loader,
            $this->version,
            $this->components['settings']
        );
        $this->components['admin']->register_hooks();

        // 5. Frontend component (only on frontend).
        if ( ! is_admin() ) {
            $this->components['frontend'] = new Frontend( $this->loader, $this->version );
            $this->components['frontend']->register_hooks();
        }

        // 6. Upgrade Notices (Lite CTA system).
        $this->components['upgrade_notices'] = new UpgradeNotices();
        $this->components['upgrade_notices']->init();

        // 7. REST API.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        tcl_log( 'All plugin components initialised', 'debug' );
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        $controller = new \TrillChatLite\AI\RestController(
            $this->components['db_manager'],
            $this->components['usage_limiter']
        );
        $controller->register_routes();
    }

    /**
     * Define the locale for internationalisation.
     */
    private function set_locale(): void {
        $i18n = new I18n();
        $this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
    }

    /**
     * Get the loader instance.
     *
     * @return Loader
     */
    public function get_loader(): Loader {
        return $this->loader;
    }

    /**
     * Get plugin version.
     *
     * @return string
     */
    public function get_version(): string {
        return $this->version;
    }

    /**
     * Get a specific component.
     *
     * @param string $name Component name.
     * @return mixed|null
     */
    public function get_component( string $name ) {
        return $this->components[ $name ] ?? null;
    }
}
