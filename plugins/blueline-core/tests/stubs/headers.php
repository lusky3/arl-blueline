<?php
/**
 * Stubs for the headers module's Content-Security-Policy and its report endpoint.
 *
 * Request conditionals read $GLOBALS['bl_core_test_conditionals'][ <function name> ]; registered REST
 * routes are recorded in $GLOBALS['bl_core_test_rest_routes'][ <namespace> ][ <route> ].
 *
 * @package blueline-core
 */

foreach ( array( 'is_feed', 'is_robots', 'is_favicon', 'is_trackback', 'is_embed', 'is_cart', 'is_checkout' ) as $blueline_core_test_conditional ) {
	if ( ! function_exists( $blueline_core_test_conditional ) ) {
		// One generated stand-in per conditional tag, all driven by the same global map.
		eval( 'function ' . $blueline_core_test_conditional . '() { return ! empty( $GLOBALS["bl_core_test_conditionals"]["' . $blueline_core_test_conditional . '"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- conditional tag stand-ins generated from a fixed list.
	}
}
unset( $blueline_core_test_conditional );

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Stand-in for wp_doing_cron(): $GLOBALS['bl_core_test_doing_cron'] (same global as the access stubs).
	 *
	 * @return bool
	 */
	function wp_doing_cron() {
		return ! empty( $GLOBALS['bl_core_test_doing_cron'] );
	}
}

if ( ! function_exists( 'wp_is_serving_rest_request' ) ) {
	/**
	 * Stand-in for wp_is_serving_rest_request(): $GLOBALS['bl_core_test_rest_request'] (same global as the access stubs).
	 *
	 * @return bool
	 */
	function wp_is_serving_rest_request() {
		return ! empty( $GLOBALS['bl_core_test_rest_request'] );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stand-in for register_rest_route(): records the route's arguments.
	 *
	 * @param string $route_namespace Namespace, e.g. blueline/v1.
	 * @param string $route           Route, e.g. /csp-report.
	 * @param array  $args            Route arguments.
	 * @return bool
	 */
	function register_rest_route( $route_namespace, $route, $args = array() ) {
		$GLOBALS['bl_core_test_rest_routes'][ $route_namespace ][ $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stand-in for rest_url(): pretty-permalink form under home_url().
	 *
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return home_url( '/wp-json/' . ltrim( (string) $path, '/' ) );
	}
}
