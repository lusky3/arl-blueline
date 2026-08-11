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
}
