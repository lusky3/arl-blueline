<?php
/**
 * Stubs for the account-endpoints module tests.
 *
 * @package blueline-core
 */

if ( ! defined( 'EP_ROOT' ) ) {
	define( 'EP_ROOT', 64 );
}

if ( ! defined( 'EP_PAGES' ) ) {
	define( 'EP_PAGES', 4096 );
}

if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
	/**
	 * Stand-in for add_rewrite_endpoint(): records each call in $GLOBALS['blueline_core_test_rewrite_endpoints'].
	 *
	 * @param string      $name      Endpoint name.
	 * @param int         $places    Endpoint mask.
	 * @param string|bool $query_var Query var name, or true for $name.
	 * @return void
	 */
	function add_rewrite_endpoint( $name, $places, $query_var = true ) {
		$GLOBALS['blueline_core_test_rewrite_endpoints'][] = array(
			'name'      => $name,
			'places'    => $places,
			'query_var' => true === $query_var ? $name : $query_var,
		);
	}
}
