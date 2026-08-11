<?php
/**
 * My Account endpoints.
 *
 * Slugs marked "LIVE URL" were created by yith-woocommerce-customize-myaccount-page,
 * which this theme replaces. They must not change or existing bookmarked and emailed
 * links break.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The ARL's My Account endpoint map: slug => label/group/order.
 *
 * League content sorts before billing content -- the ARL is a league site
 * first, a store second. Two endpoints are deliberately absent:
 * - 'subscriptions' (YITH label "Installments"): no subscriptions plugin is
 *   active, so the endpoint cannot render.
 * - 'downloads': already disabled in YITH.
 *
 * @return array<string, array{label: string, group: string, order: int}>
 */
function blueline_account_endpoints(): array {
	return array(
		'my-team'         => array(
			'label' => __( 'My Team', 'blueline' ),
			'group' => 'league',
			'order' => 10,
		),
		'my-schedule'     => array(
			'label' => __( 'My Schedule', 'blueline' ),
			'group' => 'league',
			'order' => 20,
		),
		'registrations'   => array( // LIVE URL.
			'label' => __( 'My Registrations', 'blueline' ),
			'group' => 'billing',
			'order' => 50,
		),
		'store-credit'    => array( // LIVE URL.
			'label' => __( 'Credits', 'blueline' ),
			'group' => 'billing',
			'order' => 60,
		),
		'refund-requests' => array( // LIVE URL.
			'label' => __( 'Refund requests', 'blueline' ),
			'group' => 'billing',
			'order' => 70,
		),
		'payment-methods' => array(
			'label' => __( 'Payment Methods', 'blueline' ),
			'group' => 'billing',
			'order' => 80,
		),
		'edit-address'    => array(
			'label' => __( 'Addresses', 'blueline' ),
			'group' => 'billing',
			'order' => 90,
		),
		'edit-account'    => array(
			'label' => __( 'Account Details', 'blueline' ),
			'group' => 'billing',
			'order' => 100,
		),
	);
}

/**
 * WooCommerce-default endpoint slug => ARL slug.
 *
 * Both keys are WooCommerce's internal query-var name AND, prior to YITH
 * renaming them, its default URL slug -- so a request to the literal legacy
 * slug (e.g. /account/orders/) is what template_redirect below watches for.
 *
 * @return array<string, string>
 */
function blueline_account_legacy_redirect_map(): array {
	return array(
		'orders' => 'registrations',
		'credit' => 'store-credit',
	);
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

add_action( 'init', 'blueline_register_account_rewrite_endpoints' );
/**
 * Register rewrite endpoints that WooCommerce does not already own.
 *
 * 'my-team' and 'my-schedule' are new theme tabs, not WooCommerce endpoints,
 * so WordPress needs to be told about them directly. The legacy WooCommerce
 * default slugs ('orders', 'credit') are ALSO registered here -- separately
 * from WooCommerce's own (remapped) registration below -- purely so a request
 * to the old slug resolves to the account page instead of 404ing, giving
 * template_redirect() a chance to 301 it to the ARL slug.
 *
 * Each legacy slug is deliberately registered under its OWN query var
 * (blueline_legacy_query_var()) rather than its literal name. WC_Query's own
 * remap (see blueline_remap_account_query_vars()) sets $wp->query_vars['orders']
 * whenever the *new* ARL slug 'registrations' matches, so checking for a bare
 * 'orders' key in template_redirect() would also fire -- wrongly -- on every
 * correct request and 301 it to itself. The dedicated var name only ever gets
 * set by a literal hit on the old slug.
 */
function blueline_register_account_rewrite_endpoints() {
	add_rewrite_endpoint( 'my-team', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'my-schedule', EP_ROOT | EP_PAGES );

	foreach ( array_keys( blueline_account_legacy_redirect_map() ) as $legacy_slug ) {
		add_rewrite_endpoint( $legacy_slug, EP_ROOT | EP_PAGES, blueline_legacy_query_var( $legacy_slug ) );
	}
}

/**
 * The dedicated (collision-free) query var a legacy slug's rewrite endpoint
 * is registered under. See blueline_register_account_rewrite_endpoints().
 *
 * @param string $legacy_slug A key from blueline_account_legacy_redirect_map().
 * @return string
 */
function blueline_legacy_query_var( string $legacy_slug ): string {
	return 'blueline_legacy_' . str_replace( '-', '_', $legacy_slug );
}

add_filter( 'woocommerce_get_query_vars', 'blueline_remap_account_query_vars', 20 );
/**
 * Rename the URL slug WooCommerce registers for its 'orders' and 'credit'
 * query vars, without touching the query-var keys themselves.
 *
 * This is the crux of the slug-preservation requirement: WC_Query keeps
 * mapping the ARL slug back onto its own internal query var (see
 * WC_Query::init_query_vars()/parse_request()), so WooCommerce's own
 * handlers -- order history, store credit -- still fire. Only the URL
 * segment changes.
 *
 * @param array<string, string> $vars Query var => URL slug.
 * @return array<string, string>
 */
function blueline_remap_account_query_vars( array $vars ): array {
	foreach ( blueline_account_legacy_redirect_map() as $query_var => $arl_slug ) {
		if ( isset( $vars[ $query_var ] ) ) {
			$vars[ $query_var ] = $arl_slug;
		}
	}
	return $vars;
}

add_filter( 'woocommerce_account_menu_items', 'blueline_account_menu_items' );
/**
 * Reorder and relabel the My Account nav to the league-first, grouped list
 * from blueline_account_endpoints(), dropping endpoints the ARL map omits
 * (dead 'subscriptions'/'downloads' tabs) while preserving 'dashboard' and
 * 'customer-logout'.
 *
 * @param array<string, string> $items WooCommerce's default menu items.
 * @return array<string, string>
 */
function blueline_account_menu_items( array $items ): array {
	$endpoints = blueline_account_endpoints();
	uasort( $endpoints, static fn( $a, $b ) => $a['order'] <=> $b['order'] );

	// Endpoints whose slug differs from WooCommerce's internal query-var key
	// (registrations/store-credit) must be added to the nav under that key,
	// or wc_get_account_endpoint_url() can't resolve their URL.
	$slug_to_query_var = array_flip( blueline_account_legacy_redirect_map() );

	$ordered = array();
	if ( isset( $items['dashboard'] ) ) {
		$ordered['dashboard'] = $items['dashboard'];
	}

	foreach ( $endpoints as $slug => $config ) {
		$query_var             = $slug_to_query_var[ $slug ] ?? $slug;
		$ordered[ $query_var ] = $config['label'];
	}

	if ( isset( $items['customer-logout'] ) ) {
		$ordered['customer-logout'] = $items['customer-logout'];
	}

	return $ordered;
}

add_action( 'template_redirect', 'blueline_redirect_legacy_account_endpoints' );
/**
 * 301 a request to a WooCommerce-default account slug to its ARL slug.
 *
 * These slugs are still registered (see blueline_register_account_rewrite_endpoints())
 * so they resolve to the account page instead of 404ing; this is what turns
 * that resolved request into a permanent redirect instead of showing
 * WooCommerce's own (differently-slugged) content at two URLs.
 */
function blueline_redirect_legacy_account_endpoints() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return;
	}

	global $wp;

	foreach ( blueline_account_legacy_redirect_map() as $legacy_slug => $arl_slug ) {
		if ( array_key_exists( blueline_legacy_query_var( $legacy_slug ), $wp->query_vars ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( $arl_slug ), 301 );
			exit;
		}
	}
}

add_action( 'after_switch_theme', 'flush_rewrite_rules' );
