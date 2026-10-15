<?php
/**
 * Stand-ins for what blueline_account_heading_follows_nav() reads about the
 * current request: the loop, the main query, the My Account page ID and
 * WooCommerce's current endpoint. A test sets $GLOBALS['bl_test_account_heading'].
 *
 * Defines WC(), so it is loaded ONLY inside #[RunInSeparateProcess] tests.
 *
 * @package blueline
 */

$GLOBALS['bl_test_account_heading'] = array(
	'in_loop'    => false,
	'main_query' => false,
	'page_id'    => 0,
	'endpoint'   => '',
);

if ( ! function_exists( 'is_account_page' ) ) {
	/**
	 * Stand-in for WooCommerce's is_account_page().
	 *
	 * @return bool
	 */
	function is_account_page() {
		return ! empty( $GLOBALS['bl_test_is_account_page'] );
	}
}

if ( ! function_exists( 'in_the_loop' ) ) {
	/**
	 * Stand-in for in_the_loop().
	 *
	 * @return bool
	 */
	function in_the_loop() {
		return (bool) $GLOBALS['bl_test_account_heading']['in_loop'];
	}
}

if ( ! function_exists( 'is_main_query' ) ) {
	/**
	 * Stand-in for is_main_query().
	 *
	 * @return bool
	 */
	function is_main_query() {
		return (bool) $GLOBALS['bl_test_account_heading']['main_query'];
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	/**
	 * Stand-in for wc_get_page_id(): only 'myaccount' is known.
	 *
	 * @param string $page Page name.
	 * @return int
	 */
	function wc_get_page_id( $page ) {
		return 'myaccount' === $page ? (int) $GLOBALS['bl_test_account_heading']['page_id'] : -1;
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Stand-in for WC(): just the query object and its current endpoint.
	 *
	 * @return object
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors WooCommerce's own function name.
		return (object) array(
			'query' => new class() {
				/**
				 * The endpoint the test says the request is on.
				 *
				 * @return string
				 */
				public function get_current_endpoint() {
					return (string) $GLOBALS['bl_test_account_heading']['endpoint'];
				}
			},
		);
	}
}
