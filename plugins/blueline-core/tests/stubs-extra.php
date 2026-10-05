<?php
/**
 * Plugin-only WordPress stubs, loaded after the theme's shared stubs.
 *
 * Shared plugin-only stubs live here. A module's own stubs go in tests/stubs/<slug>.php
 * (loaded by tests/bootstrap.php after this file), so parallel module branches never edit
 * the same file. Never add plugin-only stubs to the theme bootstrap. Wrap every stub in
 * `if ( ! function_exists( ... ) )` so the first definition wins and duplicates cannot fatal.
 *
 * @package blueline-core
 */

if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	// Core always defines it; an empty directory, so the register-fix mu-plugin is never "present".
	define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/blueline-core-tests-no-mu-plugins' );
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	/**
	 * Stand-in for register_activation_hook(): records the callback per plugin file.
	 *
	 * @param string   $file     Plugin main file.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function register_activation_hook( $file, $callback ) {
		$GLOBALS['blueline_core_test_activation_hooks'][ $file ] = $callback;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	/**
	 * Stand-in for register_deactivation_hook(): records the callback per plugin file.
	 *
	 * @param string   $file     Plugin main file.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['blueline_core_test_deactivation_hooks'][ $file ] = $callback;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Stand-in for has_filter() over the shared hook store.
	 *
	 * @param string        $tag      Hook name.
	 * @param callable|bool $callback Callback to look for, or false for "any".
	 * @return bool|int Priority when a callback is given, else whether any callback exists.
	 */
	function has_filter( $tag, $callback = false ) {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $priority => $bucket ) {
			foreach ( $bucket as $hook ) {
				if ( false === $callback ) {
					return true;
				}
				if ( $hook['cb'] === $callback ) {
					return $priority;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	/**
	 * Stand-in for __return_true().
	 *
	 * @return true
	 */
	function __return_true() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore -- WP core name.
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	/**
	 * Stand-in for __return_false().
	 *
	 * @return false
	 */
	function __return_false() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore -- WP core name.
		return false;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Stand-in for has_action(); actions and filters share one store.
	 *
	 * @param string        $tag      Hook name.
	 * @param callable|bool $callback Callback to look for, or false for "any".
	 * @return bool|int
	 */
	function has_action( $tag, $callback = false ) {
		return has_filter( $tag, $callback );
	}
}
