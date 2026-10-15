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

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	/**
	 * Stand-in for load_plugin_textdomain(): records each call so a test can assert the arguments.
	 *
	 * @param string       $domain          Text domain.
	 * @param string|false $deprecated      Unused.
	 * @param string|false $plugin_rel_path Path to the translations, relative to the plugins directory.
	 * @return bool
	 */
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		$GLOBALS['blueline_core_test_textdomains'][] = array( $domain, $deprecated, $plugin_rel_path );

		return false;
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
