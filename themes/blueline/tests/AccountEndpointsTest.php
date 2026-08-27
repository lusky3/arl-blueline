<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/endpoints.php';

/**
 * Unit tests.
 */
final class AccountEndpointsTest extends TestCase {

	/**
	 * Test case.
	 */
	public function test_arl_slugs_are_preserved_exactly(): void {
		$e = blueline_account_endpoints();
		// These slugs are live URLs today, created by yith-woocommerce-customize-myaccount-page.
		$this->assertArrayHasKey( 'registrations', $e );
		$this->assertArrayHasKey( 'store-credit', $e );
		$this->assertArrayHasKey( 'refund-requests', $e );
		$this->assertArrayHasKey( 'payment-methods', $e );
		$this->assertArrayHasKey( 'edit-address', $e );
		$this->assertArrayHasKey( 'edit-account', $e );
	}

	/**
	 * Test case.
	 */
	public function test_woocommerce_default_slugs_redirect_to_arl_slugs(): void {
		$map = blueline_account_legacy_redirect_map();
		$this->assertSame( 'registrations', $map['orders'] );
		$this->assertSame( 'store-credit', $map['credit'] );
	}

	/**
	 * Test case.
	 */
	public function test_query_var_map_is_not_the_legacy_url_map(): void {
		// These two maps answer different questions and coincide only for
		// 'orders'. Conflating them -- flipping the LEGACY map to get a
		// slug => query-var lookup -- yielded 'store-credit' => 'credit', and
		// 'credit' is a query var nothing on this install registers.
		$query_vars = blueline_account_query_var_map();

		$this->assertSame( 'registrations', $query_vars['orders'] );
		$this->assertArrayNotHasKey(
			'credit',
			$query_vars,
			"'credit' is a legacy URL slug, never a query var: WC()->query->get_query_vars() carries 'store-credit' => 'store-credit' and no 'credit' key at all"
		);

		// The legacy URL map keeps BOTH -- /account/credit/ is still a real
		// old URL that must 301 somewhere.
		$this->assertSame( 'store-credit', blueline_account_legacy_redirect_map()['credit'] );
	}

	/**
	 * Test case.
	 */
	public function test_store_credit_resolves_to_its_own_query_var(): void {
		// The exact regression: navigation.php feeds this value to
		// wc_get_account_endpoint_url() and wc_get_account_menu_item_classes().
		// Returning 'credit' made the nav link to /account/credit/ (a 301) on
		// every render and left the real page unable to mark itself active.
		$this->assertSame( 'store-credit', blueline_account_slug_query_var( 'store-credit' ) );
	}

	/**
	 * Test case.
	 */
	public function test_registrations_resolves_to_woocommerces_orders_query_var(): void {
		$this->assertSame( 'orders', blueline_account_slug_query_var( 'registrations' ) );
	}

	/**
	 * Test case.
	 */
	public function test_every_endpoint_slug_resolves_to_a_real_query_var(): void {
		// A slug with no translation is its own query var. What must never
		// happen is a slug resolving to a key that exists only in the legacy
		// URL map -- those are dead URLs, not endpoints.
		$legacy_only = array_diff(
			array_keys( blueline_account_legacy_redirect_map() ),
			array_keys( blueline_account_query_var_map() )
		);

		foreach ( array_keys( blueline_account_endpoints() ) as $slug ) {
			$this->assertNotContains(
				blueline_account_slug_query_var( $slug ),
				$legacy_only,
				"endpoint '$slug' resolved to a legacy-only URL slug instead of a query var"
			);
		}
	}

	/**
	 * Test case.
	 */
	public function test_dead_endpoints_are_absent(): void {
		$e = blueline_account_endpoints();
		// No subscriptions plugin is active; the Installments endpoint cannot render.
		$this->assertArrayNotHasKey( 'subscriptions', $e );
		// Downloads was already disabled in YITH.
		$this->assertArrayNotHasKey( 'downloads', $e );
	}

	/**
	 * Test case.
	 */
	public function test_league_modules_sort_before_billing(): void {
		$e       = blueline_account_endpoints();
		$league  = array_filter( $e, fn( $v ) => 'league' === $v['group'] );
		$billing = array_filter( $e, fn( $v ) => 'billing' === $v['group'] );
		$this->assertNotEmpty( $league, 'the account must lead with league content' );
		$this->assertLessThan(
			min( array_column( $billing, 'order' ) ),
			max( array_column( $league, 'order' ) ),
			'every league endpoint must sort before every billing endpoint'
		);
	}

	/**
	 * Test case.
	 */
	public function test_every_endpoint_has_a_label(): void {
		foreach ( blueline_account_endpoints() as $slug => $cfg ) {
			$this->assertNotEmpty( $cfg['label'], "endpoint $slug has no label" );
			$this->assertContains( $cfg['group'], array( 'league', 'billing', 'account', 'preferences' ) );
		}
	}

	/**
	 * P1 sub-project (account shell rebuild): edit-account moved out of the
	 * Billing group so it renders as a top-level nav pill, not inside the
	 * Billing disclosure.
	 */
	public function test_edit_account_is_not_in_the_billing_group(): void {
		$e = blueline_account_endpoints();

		$this->assertSame( 'account', $e['edit-account']['group'] );
	}

	/**
	 * Sub-project 2 (Preferences page): a new top-level endpoint, grouped
	 * separately from league/billing/account since it's neither team content
	 * nor account administration -- it's site-experience settings.
	 */
	public function test_preferences_endpoint_exists_with_its_own_group(): void {
		$e = blueline_account_endpoints();

		$this->assertArrayHasKey( 'preferences', $e );
		$this->assertSame( 'preferences', $e['preferences']['group'] );
		$this->assertSame( 'Preferences', $e['preferences']['label'] );
	}

	/**
	 * Test case.
	 */
	public function test_legacy_slug_is_free_to_register_when_remap_is_intact(): void {
		// 'orders' remapped to 'registrations' means WooCommerce's resolved
		// vars no longer contain the literal slug 'orders' anywhere -- safe.
		$this->assertTrue(
			blueline_legacy_slug_is_free_to_register( 'orders', array( 'orders' => 'registrations' ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_legacy_slug_is_not_free_when_a_later_filter_reverted_the_remap(): void {
		// Simulates a hypothetical future plugin filtering woocommerce_get_query_vars
		// at a higher priority and reverting WooCommerce's 'orders' slug back to its
		// own default -- the exact collision blueline_register_account_rewrite_endpoints()
		// must detect before registering a competing rewrite endpoint for the same
		// literal name.
		$this->assertFalse(
			blueline_legacy_slug_is_free_to_register( 'orders', array( 'orders' => 'orders' ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_legacy_slug_is_not_free_when_another_key_resolves_to_it(): void {
		// The collision is about the literal SLUG, not which query-var key
		// produces it -- a different plugin's key could end up pointing at the
		// same literal name.
		$this->assertFalse(
			blueline_legacy_slug_is_free_to_register( 'orders', array( 'some-other-plugin-key' => 'orders' ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_legacy_slug_is_free_when_it_never_appears_in_resolved_vars(): void {
		// 'credit' is the real-world case this guards against a false
		// positive on: the actual WooCommerce Store Credit plugin installed
		// on this site registers its own endpoint as 'store-credit' =>
		// 'store-credit', so 'credit' never appears in WooCommerce's
		// resolved vars at all -- that must read as "free", not "collision",
		// or the legacy /account/credit redirect would wrongly be skipped.
		$this->assertTrue(
			blueline_legacy_slug_is_free_to_register( 'credit', array( 'store-credit' => 'store-credit' ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_legacy_orders_request_matches_with_no_sub_value(): void {
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_orders' => '' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'registrations', '' ), $match );
	}

	/**
	 * Test case.
	 */
	public function test_legacy_orders_pagination_sub_value_is_preserved(): void {
		// /account/orders/2/ must redirect to /account/registrations/2/, not lose
		// the page number by redirecting to the bare /account/registrations/.
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_orders' => '2' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'registrations', '2' ), $match );
	}

	/**
	 * Test case.
	 */
	public function test_legacy_credit_request_matches_its_own_arl_slug(): void {
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_credit' => '' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'store-credit', '' ), $match );
	}

	/**
	 * Test case.
	 */
	public function test_a_request_for_the_arl_slug_itself_does_not_match_as_legacy(): void {
		// This is the exact bug the redirect-loop fix closed: WooCommerce's own
		// query-var remap sets $wp->query_vars['orders'] on every *correct* request
		// to /account/registrations/ too, via its internal orders => registrations
		// mapping. The matcher must key off the dedicated blueline_legacy_* var,
		// never the bare WooCommerce query-var name, or a correct request would
		// 301 to itself.
		$match = blueline_match_legacy_account_request(
			array(
				'registrations' => '',
				'orders'        => '',
			),
			blueline_account_legacy_redirect_map()
		);
		$this->assertNull( $match );
	}

	/**
	 * Test case.
	 */
	public function test_no_legacy_slug_present_does_not_match(): void {
		$match = blueline_match_legacy_account_request( array(), blueline_account_legacy_redirect_map() );
		$this->assertNull( $match );
	}

	/**
	 * P4 finding 7: the browser tab's <title> is driven exclusively by
	 * `woocommerce_endpoint_{endpoint}_title`, keyed by WooCommerce's
	 * query-var name -- NOT this theme's own ARL slug. 'registrations' must
	 * therefore surface under 'orders', matching
	 * blueline_account_slug_query_var()'s own translation, or the filter
	 * registered against this array's keys would silently never fire.
	 */
	public function test_endpoint_titles_are_keyed_by_query_var_not_arl_slug(): void {
		$titles = blueline_account_endpoint_titles();

		$this->assertArrayHasKey( 'orders', $titles );
		$this->assertArrayNotHasKey( 'registrations', $titles );
		$this->assertSame( 'My Registrations', $titles['orders'] );
	}

	/**
	 * Test case.
	 */
	public function test_endpoint_titles_cover_every_arl_endpoint(): void {
		$titles    = blueline_account_endpoint_titles();
		$endpoints = blueline_account_endpoints();

		$this->assertCount( count( $endpoints ), $titles );

		foreach ( $endpoints as $slug => $config ) {
			$query_var = blueline_account_slug_query_var( $slug );
			$this->assertArrayHasKey( $query_var, $titles );
			$this->assertSame( $config['label'], $titles[ $query_var ] );
		}
	}

	/**
	 * Test case.
	 */
	public function test_endpoint_titles_match_this_themes_own_copy_not_woocommerces_defaults(): void {
		// Regression pin for the live mismatch: WooCommerce's own hard-coded
		// defaults are "Addresses" and "Account details" (lowercase d) --
		// this theme's copy is "Addresses" (same, coincidentally) and
		// "Account Details" (capital D). Both must resolve to THIS theme's
		// copy, not silently fall back to WooCommerce's.
		$titles = blueline_account_endpoint_titles();

		$this->assertSame( 'Addresses', $titles['edit-address'] );
		$this->assertSame( 'Account Details', $titles['edit-account'] );
	}
}
