<?php
/**
 * Stand-ins for what woocommerce/myaccount/navigation.php reads, so the template itself can be rendered.
 *
 * Defines two functions the Blueline Core plugin provides (the theme tests never load the plugin), so it is
 * loaded ONLY inside #[RunInSeparateProcess] tests: AccountSectionsTest asserts those functions are absent.
 *
 * @package blueline
 */

/**
 * The endpoint the stubbed "current page" is on; set by the test before rendering.
 *
 * @var string
 */
$GLOBALS['bl_test_account_nav_current'] = 'dashboard';

if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
	/**
	 * A My Account menu as WooCommerce and the plugin leave it: league tabs, then billing tabs.
	 *
	 * @return array<string, string>
	 */
	function wc_get_account_menu_items() {
		return array(
			'dashboard'       => 'Dashboard',
			'my-team'         => 'My Team',
			'orders'          => 'Orders',
			'edit-address'    => 'Addresses',
			'refund-requests' => 'Refund requests',
			'customer-logout' => 'Log out',
		);
	}
}

if ( ! function_exists( 'wc_get_account_menu_item_classes' ) ) {
	/**
	 * WooCommerce's list-item classes for one endpoint, `is-active` on the current page.
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	function wc_get_account_menu_item_classes( $endpoint ) {
		$classes = 'woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--' . $endpoint;

		return $GLOBALS['bl_test_account_nav_current'] === $endpoint ? $classes . ' is-active' : $classes;
	}
}

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * My Account endpoint URL on a fixed test host.
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://example.test/account/' . $endpoint . '/';
	}
}

if ( ! function_exists( 'blueline_account_endpoints' ) ) {
	/**
	 * The plugin's slug => config map; only the group matters to the nav.
	 *
	 * @return array<string, array{group: string}>
	 */
	function blueline_account_endpoints() {
		return array(
			'my-team'         => array( 'group' => 'league' ),
			'orders'          => array( 'group' => 'billing' ),
			'edit-address'    => array( 'group' => 'billing' ),
			'refund-requests' => array( 'group' => 'billing' ),
		);
	}
}

if ( ! function_exists( 'blueline_account_slug_query_var' ) ) {
	/**
	 * The plugin maps a slug to its query var; the same string is enough here.
	 *
	 * @param string $slug Endpoint slug.
	 * @return string
	 */
	function blueline_account_slug_query_var( $slug ) {
		return $slug;
	}
}
