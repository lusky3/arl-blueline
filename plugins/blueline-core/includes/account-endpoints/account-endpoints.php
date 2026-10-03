<?php
/**
 * My Account endpoints. Moved from themes/blueline/inc/account/endpoints.php.
 *
 * Slugs marked "LIVE URL" were created by yith-woocommerce-customize-myaccount-page,
 * which the Blueline theme replaced. They must not change or existing bookmarked and
 * emailed links break.
 *
 * Routing lives here so it survives a theme switch; the endpoint content callbacks
 * (`woocommerce_account_{$slug}_endpoint`) stay in the theme. League tabs only show in
 * the menu when such a renderer exists and `blueline_core_account_endpoint_enabled`
 * allows them (see blueline_account_endpoint_is_active()).
 *
 * @package blueline-core
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
 * `woocommerce_account_{$slug}_endpoint` (the theme's renderer) and the
 * `blueline_core_account_endpoint_enabled` filter's approval (the theme passes its
 * section toggles), so another theme never gets tabs that would render nothing.
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
 * Legacy URL slug => ARL slug, for the 301s in
 * blueline_redirect_legacy_account_endpoints().
 *
 * These keys are LITERAL OLD URL SEGMENTS that may still be bookmarked or
 * sitting in an old email -- `/account/orders/`, `/account/credit/`. They are
 * NOT WooCommerce query-var names, and must never be used as such: see
 * blueline_account_query_var_map() below for that separate (and much smaller)
 * concern, and why conflating the two silently broke the store-credit tab.
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
 * WooCommerce query-var KEY => ARL URL slug -- the (only) endpoints whose
 * internal query-var name differs from the URL slug this theme gives them.
 *
 * Deliberately its own map, NOT a re-reading of
 * blueline_account_legacy_redirect_map(). The two answer different questions
 * and only coincide for 'orders':
 *
 *   - legacy map:    "which dead URL segments must 301 somewhere?"
 *                    -> 'orders', 'credit'
 *   - query-var map: "which query-var key does WooCommerce actually own for
 *                    this ARL slug?"  -> 'orders' only.
 *
 * Flipping the legacy map to answer the second question yielded
 * `'store-credit' => 'credit'`, and 'credit' is not a query var anything on
 * this install registers -- verified live 2026-08-11 against
 * `WC()->query->get_query_vars()`, which contains `'store-credit' =>
 * 'store-credit'` (the WooCommerce Store Credit plugin's own registration) and
 * no 'credit' key at all. That mistake made
 * `wc_get_account_endpoint_url( 'credit' )` emit `/account/credit/` -- a
 * legacy URL that 301s -- as the nav's own link on every single render, left
 * `wc_get_account_menu_item_classes( 'credit' )` unable to mark the real page
 * active, and dropped the plugin's genuine 'store-credit' menu item when
 * blueline_account_menu_items() rebuilt the list. 'store-credit' is therefore
 * absent here on purpose: its query var and its URL slug are the same string,
 * so it needs no translation.
 *
 * @return array<string, string>
 */
function blueline_account_query_var_map(): array {
	return array(
		'orders' => 'registrations',
	);
}

/**
 * ARL URL slug => the WooCommerce query-var key that owns it, for the slugs
 * where those differ (blueline_account_query_var_map()); any other slug is its
 * own query var. This is the lookup every "give me this endpoint's URL / nav
 * key" caller wants, in one place, so no caller has to remember which
 * direction to flip which map.
 *
 * @param string $slug A key from blueline_account_endpoints().
 * @return string The query-var key to hand wc_get_account_endpoint_url() etc.
 */
function blueline_account_slug_query_var( string $slug ): string {
	$slug_to_query_var = array_flip( blueline_account_query_var_map() );

	return $slug_to_query_var[ $slug ] ?? $slug;
}

/**
 * Query-var endpoint => this theme's own blueline_account_endpoints() label,
 * for filtering `woocommerce_endpoint_{endpoint}_title` (P4 finding 7).
 *
 * WooCommerce's WC_Query::get_endpoint_title() hard-codes English defaults
 * ("Orders", "Addresses", "Account details") for this hook's un-filtered
 * value, and that SAME method drives both the document `<title>`
 * (wc_page_endpoint_document_title_parts()) and the on-page `<h1>`
 * (wc_page_endpoint_title(), hooked on `the_title`) -- confirmed live
 * against WooCommerce core's own wc-page-functions.php. Nothing in this
 * theme filtered that hook at all before now, so the browser tab showed
 * WooCommerce's hard-coded defaults instead of this theme's own copy.
 *
 * This does NOT make the on-page `<h1>` agree for 'orders'/'edit-address'/
 * 'edit-account': a separate legacy Code Snippet (`wp_snippets` id 9, "My
 * Account Tab Modifications") hooks `the_title` directly, independently of
 * this filter, and its callback runs after WooCommerce's own -- see the P4
 * review for the full mechanism and why that snippet is a cutover/config
 * item, not something this theme should fight programmatically. This filter
 * still fully controls the document title for every endpoint (the snippet
 * never touches `document_title_parts`), and fully controls both title AND
 * h1 for every endpoint the snippet does not touch (my-team, my-schedule,
 * store-credit, refund-requests, payment-methods).
 *
 * Pure: no WordPress calls, so it's unit-testable without extra bootstrap
 * stubs. Kept above the WooCommerce guard below with this file's other pure
 * helpers.
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
 * The dedicated (collision-free) query var a legacy slug's rewrite endpoint
 * is registered under. See blueline_register_account_rewrite_endpoints().
 *
 * Pure string formatting -- kept above the WooCommerce guard below (with the
 * other pure helpers) so it, and everything built on it, stays unit testable
 * without WooCommerce loaded.
 *
 * @param string $legacy_slug A key from blueline_account_legacy_redirect_map().
 * @return string
 */
function blueline_legacy_query_var( string $legacy_slug ): string {
	return 'blueline_legacy_' . str_replace( '-', '_', $legacy_slug );
}

/**
 * Whether $legacy_slug is free to register as our own dedicated legacy-catch
 * rewrite endpoint (see blueline_register_account_rewrite_endpoints()),
 * given WooCommerce's fully-resolved query-var => slug map.
 *
 * It is NOT free when $legacy_slug appears as a VALUE anywhere in
 * $resolved_vars: that means WooCommerce (or some other plugin) is about to
 * call add_rewrite_endpoint() under that exact literal name itself, via
 * WC_Query::add_endpoints(), and a second registration for the same $name
 * under our own dedicated query var would be an order-dependent collision.
 *
 * This deliberately checks VALUES, not whether a specific query-var KEY
 * still equals its expected ARL slug. The two internal WooCommerce-default
 * slugs this theme knows about behave differently on this site: 'orders' is
 * a genuine native WooCommerce query var (key and un-remapped default value
 * both literally 'orders'), so a reverted blueline_remap_account_query_vars()
 * would put 'orders' back into $resolved_vars' values and must be caught.
 * 'credit', by contrast, is not a query-var key WooCommerce (or the actual
 * WooCommerce Store Credit plugin installed here, which registers its own
 * endpoint as 'store-credit' => 'store-credit') ever owns at all -- checking
 * for key-level equality against it always reads as "broken" and would wrongly
 * block a legacy /account/credit catch that was never actually at risk of
 * colliding with anything. Checking membership in the resolved VALUES avoids
 * that false positive while still catching the real collision this guards
 * against.
 *
 * Pure: no WordPress or WooCommerce calls, so it is unit testable without
 * faking WC_Query.
 *
 * @param string                $legacy_slug   Literal legacy URL slug (e.g. 'orders').
 * @param array<string, string> $resolved_vars A fully-filtered query-var => slug map.
 * @return bool
 */
function blueline_legacy_slug_is_free_to_register( string $legacy_slug, array $resolved_vars ): bool {
	return ! in_array( $legacy_slug, $resolved_vars, true );
}

/**
 * Pure decision logic behind blueline_redirect_legacy_account_endpoints():
 * given the current request's query vars, decide whether this request hit a
 * legacy WooCommerce-default account slug and, if so, which ARL slug it
 * should redirect to and what trailing sub-value must be preserved (e.g.
 * WooCommerce's /orders/2/ pagination convention -- the brief's Step 5
 * redirects with no value, which would silently reset pagination to page 1;
 * this theme instead forwards it, an intentional, authorised deviation from
 * that literal line).
 *
 * No WordPress or WooCommerce calls, so the matching/value-extraction logic
 * is unit testable without faking $wp or wc_get_endpoint_url().
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
 * 'my-team' and 'my-schedule' are new theme tabs, not WooCommerce endpoints,
 * so WordPress needs to be told about them directly. They are registered even
 * when no renderer exists (only the menu is gated, by
 * blueline_account_endpoint_is_active()): stored rewrite rules outlive theme
 * switches and toggle changes, and WooCommerce shows the dashboard for an
 * endpoint nothing renders. The legacy WooCommerce
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
 *
 * Before registering each one, blueline_account_legacy_slug_is_safe() confirms
 * the literal legacy slug isn't ALSO the slug WooCommerce (or some other
 * plugin) ends up registering after the full `woocommerce_get_query_vars`
 * filter chain has run -- not just our own priority-20 filter. A
 * later-priority plugin filter could revert or repoint
 * blueline_remap_account_query_vars()'s mapping, in which case WooCommerce
 * would register its OWN rewrite endpoint under the literal legacy slug (e.g.
 * 'orders') again. Registering a second, competing endpoint for that same
 * literal name under our dedicated query var would then be order-dependent
 * and could silently 404 /account/registrations or reopen the redirect loop
 * this file exists to prevent -- so if that collision is detected, we skip
 * our endpoint and log it instead of risking it.
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
 * Whether $legacy_slug is safe to register per
 * blueline_legacy_slug_is_free_to_register(), checked against WooCommerce's
 * actual, fully-resolved query vars -- after every `woocommerce_get_query_vars`
 * filter callback has run, not just blueline_remap_account_query_vars().
 * Queries WC_Query directly (rather than re-running our own filter logic) so
 * a later, higher-priority filter that reverts or repoints the mapping is
 * actually detected.
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
 * Surface a would-be rewrite-endpoint collision loudly instead of failing
 * silently. A 404 (or a reopened redirect loop) on a live account URL is
 * exactly what this file exists to prevent, so this is deliberately noisy
 * rather than swallowed.
 *
 * @param string $legacy_slug Literal legacy URL slug that was skipped.
 * @param string $arl_slug    The ARL slug it would have redirected to.
 */
function blueline_log_legacy_slug_collision( string $legacy_slug, string $arl_slug ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated, deliberate: this must never fail silently.
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
 * blueline_account_query_var_map() ('orders'), without touching the query-var
 * keys themselves.
 *
 * This is the crux of the slug-preservation requirement: WC_Query keeps
 * mapping the ARL slug back onto its own internal query var (see
 * WC_Query::init_query_vars()/parse_request()), so WooCommerce's own
 * order-history handler still fires. Only the URL segment changes. Store
 * credit needs no entry here -- its plugin already registers
 * 'store-credit' => 'store-credit', the slug this theme wants.
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
 * Register the `woocommerce_endpoint_{endpoint}_title` filter for every
 * endpoint in blueline_account_endpoint_titles(). One filter per endpoint
 * (the hook name itself carries the endpoint), each a small closure over
 * that one endpoint's own label rather than a single generic callback
 * inspecting current_filter() -- keeps each registration self-contained and
 * trivially correct regardless of call order.
 *
 * Hooked to `init` rather than called directly at file scope: this file is
 * `require_once`'d from functions.php while WordPress is still loading the
 * theme (functions.php runs before `after_setup_theme` even fires), so a
 * bare call here ran blueline_account_endpoint_titles() -> ... -> __() for
 * the `blueline` text domain before ANY action had fired at all -- confirmed
 * live via a `doing_it_wrong_run` backtrace against staging (see the Task 10
 * report): every frame led back to this file's own top-level
 * `blueline_register_account_endpoint_title_filters();` call, not (as
 * initially suspected) inc/setup.php's register_nav_menus()/register_sidebar()
 * calls, which run ON `after_setup_theme`/`widgets_init` and so already
 * satisfy _load_textdomain_just_in_time()'s "not before after_setup_theme"
 * check. That file-scope call is what produced WP 6.7's
 * "Translation loading for the `blueline` domain was triggered too early"
 * notice on every request, including a plain `wp user create`. The filters
 * this registers only need to exist before WooCommerce actually renders an
 * endpoint title -- always well after `init` -- so deferring the whole
 * registration one tick, exactly like the sibling
 * blueline_register_account_rewrite_endpoints() a few lines up, costs
 * nothing and fixes the timing outright.
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
 * Reorder and relabel the My Account nav to the league-first, grouped list
 * from blueline_account_endpoints(), dropping endpoints the ARL map omits
 * (dead 'subscriptions'/'downloads' tabs) while preserving 'dashboard' and
 * 'customer-logout'.
 *
 * Also drops league endpoints that blueline_account_endpoint_is_active()
 * rejects: no theme renderer, or the theme's section toggle (passed through
 * `blueline_core_account_endpoint_enabled`) is off.
 *
 * Every key written into $ordered must be a real query-var key, since
 * navigation.php feeds them straight to wc_get_account_endpoint_url() and
 * wc_get_account_menu_item_classes(); blueline_account_slug_query_var() is the
 * one place that translation lives.
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
	if ( ! function_exists( 'wc_get_endpoint_url' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}

	global $wp;

	$match = blueline_match_legacy_account_request( $wp->query_vars, blueline_account_legacy_redirect_map() );
	if ( null === $match ) {
		return;
	}

	list( $arl_slug, $value ) = $match;

	// wc_get_account_endpoint_url() always passes an empty value, so it can't
	// be reused here -- this mirrors its own non-dashboard/non-logout branch
	// (wc_get_endpoint_url() against the account page permalink) but forwards
	// $value through instead of discarding it.
	wp_safe_redirect(
		wc_get_endpoint_url( $arl_slug, $value, wc_get_page_permalink( 'myaccount' ) ),
		301
	);
	exit;
}
