<?php
/**
 * Loader Class
 *
 * Registers all actions, filters, and shortcodes for the plugin.
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
 * Register all actions and filters for the plugin.
 */
class Loader {

    /**
     * Actions registered with WordPress.
     *
     * @var array
     */
    protected array $actions = [];

    /**
     * Filters registered with WordPress.
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * Shortcodes registered with WordPress.
     *
     * @var array
     */
    protected array $shortcodes = [];

    /**
     * Add a new action to the collection.
     *
     * @param string $hook          Hook name.
     * @param object $component     Component instance.
     * @param string $callback      Callback method.
     * @param int    $priority      Priority.
     * @param int    $accepted_args Accepted arguments count.
     */
    public function add_action( string $hook, $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Add a new filter to the collection.
     *
     * @param string $hook          Hook name.
     * @param object $component     Component instance.
     * @param string $callback      Callback method.
     * @param int    $priority      Priority.
     * @param int    $accepted_args Accepted arguments count.
     */
    public function add_filter( string $hook, $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Add a new shortcode to the collection.
     *
     * @param string $tag       Shortcode tag.
     * @param object $component Component instance.
     * @param string $callback  Callback method.
     */
    public function add_shortcode( string $tag, $component, string $callback ): void {
        $this->shortcodes = $this->add( $this->shortcodes, $tag, $component, $callback, 0, 0 );
    }

    /**
     * Add hook to the collection.
     *
     * @param array  $hooks        Existing hooks array.
     * @param string $hook         Hook name.
     * @param object $component    Component instance.
     * @param string $callback     Callback method.
     * @param int    $priority     Priority.
     * @param int    $accepted_args Accepted arguments count.
     * @return array Updated hooks array.
     */
    private function add( array $hooks, string $hook, $component, string $callback, int $priority, int $accepted_args ): array {
        $hooks[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];

        return $hooks;
    }

    /**
     * Register all hooks with WordPress.
     */
    public function run(): void {
        foreach ( $this->filters as $hook ) {
            \add_filter(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ( $this->actions as $hook ) {
            \add_action(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ( $this->shortcodes as $shortcode ) {
            \add_shortcode(
                $shortcode['hook'],
                [ $shortcode['component'], $shortcode['callback'] ]
            );
        }

        trcl_log(
            'Loader registered ' . count( $this->actions ) . ' actions, ' .
            count( $this->filters ) . ' filters, and ' .
            count( $this->shortcodes ) . ' shortcodes',
            'debug'
        );
    }
}
