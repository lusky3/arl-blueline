<?php
/**
 * My Account endpoints.
 *
 * Slugs marked "LIVE URL" were created by yith-woocommerce-customize-myaccount-page, which
 * Blueline replaced. They must not change, or bookmarked and emailed links break.
 *
 * Routing lives here so it survives a theme switch; the endpoint content callbacks
 * (`woocommerce_account_{$slug}_endpoint`) belong to the theme. League tabs only show in the
 * menu when such a renderer exists and `blueline_core_account_endpoint_enabled` allows them
 * (see blueline_account_endpoint_is_active()).
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The My Account endpoint map: slug => label/group/order. League content sorts before billing.
 *
 * 'subscriptions' (YITH "Installments") and 'downloads' are deliberately absent: no
 * subscriptions plugin is active, and downloads were already disabled in YITH.
 *
 * @return array<string, array{label: string, group: string, order: int}>
 */
function blueline_account_endpoints(): array {
	return array(
		'my-team'         => array(
			'label' => __( 'My Team', 'blueline-core' ),
			'group' => 'league',
			'order' => 10,
		),
		'my-schedule'     => array(
			'label' => __( 'My Schedule', 'blueline-core' ),
			'group' => 'league',
			'order' => 20,
		),
		'player-profile'  => array(
			'label' => __( 'Player Profile', 'blueline-core' ),
			'group' => 'league',
			'order' => 25,
		),
		'preferences'     => array(
			'label' => __( 'Preferences', 'blueline-core' ),
			'group' => 'preferences',
			'order' => 30,
		),
		'registrations'   => array( // LIVE URL.
			'label' => __( 'My Registrations', 'blueline-core' ),
			'group' => 'billing',
			'order' => 50,
		),
		'store-credit'    => array( // LIVE URL.
			'label' => __( 'Credits', 'blueline-core' ),
			'group' => 'billing',
			'order' => 60,
		),
		'refund-requests' => array( // LIVE URL.
			'label' => __( 'Refund requests', 'blueline-core' ),
			'group' => 'billing',
			'order' => 70,
		),
		'payment-methods' => array(
			'label' => __( 'Payment Methods', 'blueline-core' ),
			'group' => 'billing',
			'order' => 80,
		),
		'edit-address'    => array(
			'label' => __( 'Addresses', 'blueline-core' ),
			'group' => 'billing',
			'order' => 90,
		),
		'edit-account'    => array(
			'label' => __( 'Account Details', 'blueline-core' ),
			'group' => 'account',
			'order' => 100,
		),
	);
}

/**
 * League endpoints: shown only when the theme renders them and allows them.
 *
 * @return string[]
 */
function blueline_account_league_endpoint_slugs(): array {
	return array( 'my-team', 'my-schedule', 'player-profile', 'preferences' );
}

/**
 * Whether an endpoint from blueline_account_endpoints() belongs in the menu.
 *
 * Billing and account slugs always do. A league slug needs a content callback on
 * `woocommerce_account_{$slug}_endpoint` and the `blueline_core_account_endpoint_enabled`
 * filter's approval, so a theme without those renderers never gets tabs that render nothing.
 *
 * @param string $slug A key from blueline_account_endpoints().
 * @return bool
 */
function blueline_account_endpoint_is_active( string $slug ): bool {
	if ( ! in_array( $slug, blueline_account_league_endpoint_slugs(), true ) ) {
		return true;
	}

	if ( ! has_action( "woocommerce_account_{$slug}_endpoint" ) ) {
		return false;
	}

	/**
	 * Filters whether a league My Account endpoint appears in the menu.
	 *
	 * @param bool   $enabled Default true.
	 * @param string $slug    Endpoint slug, e.g. 'my-team'.
	 */
	return (bool) apply_filters( 'blueline_core_account_endpoint_enabled', true, $slug );
}

/**
 * Legacy URL slug => current slug, for the 301s in blueline_redirect_legacy_account_endpoints().
 *
 * These keys are LITERAL OLD URL SEGMENTS that may still be bookmarked or sitting in an old
 * email (`/account/orders/`). They are NOT WooCommerce query-var names; see
 * blueline_account_query_var_map() for that separate concern.
 *
 * @return array<string, string>
 */
function blueline_account_legacy_redirect_map(): array {
	return array(
		'orders' => 'registrations',
		'credit' => 'store-credit',
	);
}

/**
 * WooCommerce query-var KEY => URL slug, for the only endpoint whose query-var name differs
 * from its URL slug ('orders' -> 'registrations').
 *
 * Deliberately not a flip of blueline_account_legacy_redirect_map(): that would yield
 * `'store-credit' => 'credit'`, and 'credit' is not a query var anything registers (the Store
 * Credit plugin registers 'store-credit' => 'store-credit'). The nav would then link to the
 * legacy `/account/credit/` URL on every render and fail to mark the real page active.
 * 'store-credit' needs no entry because its query var and slug are the same string.
 *
 * @return array<string, string>
 */
function blueline_account_query_var_map(): array {
	return array(
		'orders' => 'registrations',
	);
}

/**
 * URL slug => the WooCommerce query-var key that owns it (blueline_account_query_var_map());
 * any other slug is its own query var. The one lookup every "this endpoint's URL / nav key"
 * caller should use.
 *
 * @param string $slug A key from blueline_account_endpoints().
 * @return string The query-var key to hand wc_get_account_endpoint_url() etc.
 */
function blueline_account_slug_query_var( string $slug ): string {
	$slug_to_query_var = array_flip( blueline_account_query_var_map() );

	return $slug_to_query_var[ $slug ] ?? $slug;
}

/**
 * Query-var endpoint => label, for filtering `woocommerce_endpoint_{endpoint}_title`.
 *
 * WooCommerce hard-codes English defaults ("Orders", "Addresses", "Account details") for that
 * hook, and the same value drives both the document `<title>` and the on-page `<h1>`.
 * Filtering it gives every endpoint our own label in the tab title. The `<h1>` of 'orders',
 * 'edit-address' and 'edit-account' can still be overridden by a separate Code Snippet that
 * hooks `the_title` directly (a site-configuration matter, not fought here).
 *
 * Pure: no WordPress calls, so it stays unit testable and sits above the WooCommerce guard.
 *
 * @return array<string, string> query-var => label.
 */
function blueline_account_endpoint_titles(): array {
	$titles = array();
	foreach ( blueline_account_endpoints() as $slug => $config ) {
		$titles[ blueline_account_slug_query_var( $slug ) ] = $config['label'];
	}
	return $titles;
}

/**
 * The dedicated (collision-free) query var a legacy slug's rewrite endpoint is registered
 * under. See blueline_register_account_rewrite_endpoints().
 *
 * @param string $legacy_slug A key from blueline_account_legacy_redirect_map().
 * @return string
 */
function blueline_legacy_query_var( string $legacy_slug ): string {
	return 'blueline_legacy_' . str_replace( '-', '_', $legacy_slug );
}

/**
 * Whether $legacy_slug is free to register as our own dedicated legacy-catch rewrite endpoint,
 * given WooCommerce's fully-resolved query-var => slug map.
 *
 * It is not free when the slug appears as a VALUE in $resolved_vars: WooCommerce (or another
 * plugin) is then about to call add_rewrite_endpoint() under that literal name itself, and a
 * second registration would collide. Checks values, not keys: 'orders' is a native WooCommerce
 * query var, so a reverted blueline_remap_account_query_vars() puts 'orders' back among the
 * values and must be caught, while 'credit' is no query-var key at all and would wrongly read
 * as broken under a key comparison.
 *
 * @param string                $legacy_slug   Literal legacy URL slug (e.g. 'orders').
 * @param array<string, string> $resolved_vars A fully-filtered query-var => slug map.
 * @return bool
 */
function blueline_legacy_slug_is_free_to_register( string $legacy_slug, array $resolved_vars ): bool {
	return ! in_array( $legacy_slug, $resolved_vars, true );
}

/**
 * Pure decision logic behind blueline_redirect_legacy_account_endpoints(): whether this request
 * hit a legacy account slug and, if so, which current slug it redirects to and which trailing
 * sub-value to forward (so /orders/2/ pagination survives).
 *
 * @param array<string, mixed>  $query_vars $wp->query_vars for the current request.
 * @param array<string, string> $legacy_map blueline_account_legacy_redirect_map().
 * @return array{0: string, 1: string}|null [ $arl_slug, $value ], or null if no legacy slug matched.
 */
function blueline_match_legacy_account_request( array $query_vars, array $legacy_map ): ?array {
	foreach ( $legacy_map as $legacy_slug => $arl_slug ) {
		$dedicated_var = blueline_legacy_query_var( $legacy_slug );
		if ( array_key_exists( $dedicated_var, $query_vars ) ) {
			return array( $arl_slug, (string) $query_vars[ $dedicated_var ] );
		}
	}

	return null;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

add_action( 'init', 'blueline_register_account_rewrite_endpoints' );
/**
 * Register rewrite endpoints that WooCommerce does not already own.
 *
 * The league tabs are registered even when no renderer exists (only the menu is gated, by
 * blueline_account_endpoint_is_active()): stored rewrite rules outlive theme switches and
 * toggle changes, and WooCommerce shows the dashboard for an endpoint nothing renders.
 *
 * The legacy slugs ('orders', 'credit') are also registered, so a request to the old slug
 * resolves to the account page and template_redirect can 301 it. Each goes under its OWN
 * query var (blueline_legacy_query_var()), not its literal name: WC_Query's remap sets
 * $wp->query_vars['orders'] on every correct 'registrations' request, so a bare 'orders' key
 * would 301 that request to itself.
 *
 * Before registering one, blueline_account_legacy_slug_is_safe() checks that WooCommerce (or
 * another plugin) is not registering the same literal slug after the whole
 * `woocommerce_get_query_vars` chain ran, e.g. because a later filter reverted our remap. A
 * second competing endpoint would be order-dependent and could 404 /account/registrations or
 * reopen the redirect loop, so on a collision we skip ours and log it.
 */
function blueline_register_account_rewrite_endpoints() {
	add_rewrite_endpoint( 'my-team', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'my-schedule', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'player-profile', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'preferences', EP_ROOT | EP_PAGES );

	foreach ( blueline_account_legacy_redirect_map() as $legacy_slug => $arl_slug ) {
		if ( ! blueline_account_legacy_slug_is_safe( $legacy_slug ) ) {
			blueline_log_legacy_slug_collision( $legacy_slug, $arl_slug );
			continue;
		}

		add_rewrite_endpoint( $legacy_slug, EP_ROOT | EP_PAGES, blueline_legacy_query_var( $legacy_slug ) );
	}
}

/**
 * Whether $legacy_slug is safe to register per blueline_legacy_slug_is_free_to_register(),
 * checked against WC_Query's actual query vars after every `woocommerce_get_query_vars`
 * filter has run, so a later filter that reverts or repoints our mapping is detected.
 *
 * @param string $legacy_slug Literal legacy URL slug (e.g. 'orders').
 * @return bool
 */
function blueline_account_legacy_slug_is_safe( string $legacy_slug ): bool {
	if ( ! function_exists( 'WC' ) || ! WC()->query ) {
		return false;
	}

	return blueline_legacy_slug_is_free_to_register( $legacy_slug, WC()->query->get_query_vars() );
}

/**
 * Log a would-be rewrite-endpoint collision (WP_DEBUG only) instead of failing silently.
 *
 * @param string $legacy_slug Literal legacy URL slug that was skipped.
 * @param string $arl_slug    The slug it would have redirected to.
 */
function blueline_log_legacy_slug_collision( string $legacy_slug, string $arl_slug ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated; a skipped legacy redirect must be visible.
	error_log(
		sprintf(
			'[blueline] Skipping the legacy-redirect endpoint for "/account/%1$s" -> "/account/%2$s": WooCommerce (or another plugin) is also registering a rewrite endpoint under the literal slug "%1$s", and a second one would collide. Falling back to WooCommerce default behaviour for that slug.',
			$legacy_slug,
			$arl_slug
		)
	);
}

add_filter( 'woocommerce_get_query_vars', 'blueline_remap_account_query_vars', 20 );
/**
 * Rename the URL slug WooCommerce registers for the query vars in
 * blueline_account_query_var_map() ('orders'), without touching the query-var keys.
 *
 * WC_Query maps the new slug back onto its own query var, so WooCommerce's order-history
 * handler still fires; only the URL segment changes. Store credit needs no entry: its plugin
 * already registers the slug we want.
 *
 * @param array<string, string> $vars Query var => URL slug.
 * @return array<string, string>
 */
function blueline_remap_account_query_vars( array $vars ): array {
	foreach ( blueline_account_query_var_map() as $query_var => $arl_slug ) {
		if ( isset( $vars[ $query_var ] ) ) {
			$vars[ $query_var ] = $arl_slug;
		}
	}
	return $vars;
}

add_action( 'init', 'blueline_register_account_endpoint_title_filters' );
/**
 * Register the `woocommerce_endpoint_{endpoint}_title` filter for every endpoint in
 * blueline_account_endpoint_titles().
 *
 * Hooked on `init`, not run at file scope: __() before `after_setup_theme` triggers WP 6.7's
 * "translation loading triggered too early" notice, and the filters only need to exist before
 * an endpoint title renders, which is always after `init`.
 */
function blueline_register_account_endpoint_title_filters(): void {
	foreach ( blueline_account_endpoint_titles() as $query_var => $label ) {
		add_filter(
			"woocommerce_endpoint_{$query_var}_title",
			static function () use ( $label ) {
				return $label;
			}
		);
	}
}

add_filter( 'woocommerce_account_menu_items', 'blueline_account_menu_items' );
/**
 * Reorder and relabel the My Account nav to the league-first, grouped list from
 * blueline_account_endpoints(), dropping endpoints that map omits (the dead 'subscriptions' and
 * 'downloads' tabs) and league endpoints blueline_account_endpoint_is_active() rejects, while
 * preserving 'dashboard' and 'customer-logout'.
 *
 * Every key written into $ordered must be a real query-var key, because the theme's nav feeds
 * them straight to wc_get_account_endpoint_url() and wc_get_account_menu_item_classes();
 * blueline_account_slug_query_var() is the one place that translation lives.
 *
 * @param array<string, string> $items WooCommerce's default menu items.
 * @return array<string, string>
 */
function blueline_account_menu_items( array $items ): array {
	$endpoints = blueline_account_endpoints();
	uasort( $endpoints, static fn( $a, $b ) => $a['order'] <=> $b['order'] );

	$ordered = array();
	if ( isset( $items['dashboard'] ) ) {
		$ordered['dashboard'] = $items['dashboard'];
	}

	foreach ( $endpoints as $slug => $config ) {
		if ( ! blueline_account_endpoint_is_active( $slug ) ) {
			continue;
		}

		$ordered[ blueline_account_slug_query_var( $slug ) ] = $config['label'];
	}

	if ( isset( $items['customer-logout'] ) ) {
		$ordered['customer-logout'] = $items['customer-logout'];
	}

	return $ordered;
}

add_action( 'template_redirect', 'blueline_redirect_legacy_account_endpoints' );
/**
 * 301 a request to a legacy WooCommerce-default account slug to its current slug.
 *
 * Those slugs stay registered (see blueline_register_account_rewrite_endpoints()) so they
 * resolve to the account page instead of 404ing; this turns that resolved request into a
 * permanent redirect rather than serving the same content at two URLs.
 */
function blueline_redirect_legacy_account_endpoints() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	if ( ! function_exists( 'wc_get_endpoint_url' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}

	global $wp;

	$match = blueline_match_legacy_account_request( $wp->query_vars, blueline_account_legacy_redirect_map() );
	if ( null === $match ) {
		return;
	}

	list( $arl_slug, $value ) = $match;

	// wc_get_account_endpoint_url() always passes an empty value, so it cannot be reused: this
	// mirrors its endpoint branch but forwards $value.
	wp_safe_redirect(
		wc_get_endpoint_url( $arl_slug, $value, wc_get_page_permalink( 'myaccount' ) ),
		301
	);
	exit;
}
