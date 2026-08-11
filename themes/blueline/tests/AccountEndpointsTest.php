<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/endpoints.php';

final class AccountEndpointsTest extends TestCase {

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

	public function test_woocommerce_default_slugs_redirect_to_arl_slugs(): void {
		$map = blueline_account_legacy_redirect_map();
		$this->assertSame( 'registrations', $map['orders'] );
		$this->assertSame( 'store-credit', $map['credit'] );
	}

	public function test_dead_endpoints_are_absent(): void {
		$e = blueline_account_endpoints();
		// No subscriptions plugin is active; the Installments endpoint cannot render.
		$this->assertArrayNotHasKey( 'subscriptions', $e );
		// Downloads was already disabled in YITH.
		$this->assertArrayNotHasKey( 'downloads', $e );
	}

	public function test_league_modules_sort_before_billing(): void {
		$e = blueline_account_endpoints();
		$league  = array_filter( $e, fn( $v ) => 'league' === $v['group'] );
		$billing = array_filter( $e, fn( $v ) => 'billing' === $v['group'] );
		$this->assertNotEmpty( $league, 'the account must lead with league content' );
		$this->assertLessThan(
			min( array_column( $billing, 'order' ) ),
			max( array_column( $league, 'order' ) ),
			'every league endpoint must sort before every billing endpoint'
		);
	}

	public function test_every_endpoint_has_a_label(): void {
		foreach ( blueline_account_endpoints() as $slug => $cfg ) {
			$this->assertNotEmpty( $cfg['label'], "endpoint $slug has no label" );
			$this->assertContains( $cfg['group'], array( 'league', 'billing' ) );
		}
	}

	public function test_legacy_slug_is_free_to_register_when_remap_is_intact(): void {
		// 'orders' remapped to 'registrations' means WooCommerce's resolved
		// vars no longer contain the literal slug 'orders' anywhere -- safe.
		$this->assertTrue(
			blueline_legacy_slug_is_free_to_register( 'orders', array( 'orders' => 'registrations' ) )
		);
	}

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

	public function test_legacy_slug_is_not_free_when_another_key_resolves_to_it(): void {
		// The collision is about the literal SLUG, not which query-var key
		// produces it -- a different plugin's key could end up pointing at the
		// same literal name.
		$this->assertFalse(
			blueline_legacy_slug_is_free_to_register( 'orders', array( 'some-other-plugin-key' => 'orders' ) )
		);
	}

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

	public function test_legacy_orders_request_matches_with_no_sub_value(): void {
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_orders' => '' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'registrations', '' ), $match );
	}

	public function test_legacy_orders_pagination_sub_value_is_preserved(): void {
		// /account/orders/2/ must redirect to /account/registrations/2/, not lose
		// the page number by redirecting to the bare /account/registrations/.
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_orders' => '2' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'registrations', '2' ), $match );
	}

	public function test_legacy_credit_request_matches_its_own_arl_slug(): void {
		$match = blueline_match_legacy_account_request(
			array( 'blueline_legacy_credit' => '' ),
			blueline_account_legacy_redirect_map()
		);
		$this->assertSame( array( 'store-credit', '' ), $match );
	}

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

	public function test_no_legacy_slug_present_does_not_match(): void {
		$match = blueline_match_legacy_account_request( array(), blueline_account_legacy_redirect_map() );
		$this->assertNull( $match );
	}
}
