<?php
/**
 * Unit tests for blueline_redirect_legacy_account_endpoints() (the template_redirect 301).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/account-endpoints/account-endpoints.php';

/**
 * Exercises the redirect itself, not just the pure matcher: the account-page
 * guard, the target URL and the sub-value forwarding.
 *
 * Runs in separate processes because it must define wc_get_page_permalink() and
 * wc_get_endpoint_url(); once defined, other suites' `function_exists( 'wc_get_page_permalink' )`
 * branches would flip for the rest of the run.
 */
#[RunTestsInSeparateProcesses]
final class AccountLegacyRedirectTest extends TestCase {

	/**
	 * Define the two WooCommerce URL helpers (visible to this test's process only).
	 */
	protected function setUp(): void {
		// phpcs:disable Universal.Files.SeparateFunctionsFromOO, Generic.Functions.FunctionCallArgumentSpacing -- process-local stubs declared inside the test on purpose.
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity.
			eval( 'function wc_get_page_permalink( $page ) { return "https://example.test/account/"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-local stub, see class docblock.
		}
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			eval( 'function wc_get_endpoint_url( $endpoint, $value = "", $permalink = "" ) { $url = rtrim( $permalink, "/" ) . "/" . $endpoint . "/"; return "" !== $value ? $url . $value . "/" : $url; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-local stub, see class docblock.
		}
		// phpcs:enable

		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_core_test_is_account_page'] = true;
		$GLOBALS['wp']                           = (object) array( 'query_vars' => array() );
	}

	/**
	 * Remove the request globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_core_test_is_account_page'], $GLOBALS['wp'] );
	}

	/**
	 * Run the redirect and return the target, or null when it did not redirect.
	 *
	 * @return string|null
	 */
	private function redirect_target(): ?string {
		try {
			blueline_redirect_legacy_account_endpoints();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		return null;
	}

	/**
	 * The legacy /orders/ slug 301s to /registrations/ with no value.
	 */
	public function test_legacy_orders_redirects_to_registrations(): void {
		$GLOBALS['wp']->query_vars = array( blueline_legacy_query_var( 'orders' ) => '' );

		$this->assertSame( 'https://example.test/account/registrations/', $this->redirect_target() );
	}

	/**
	 * WooCommerce's /orders/2/ pagination value is forwarded into the redirect URL.
	 */
	public function test_pagination_value_is_forwarded_into_the_redirect_url(): void {
		$GLOBALS['wp']->query_vars = array( blueline_legacy_query_var( 'orders' ) => '2' );

		$this->assertSame( 'https://example.test/account/registrations/2/', $this->redirect_target() );
	}

	/**
	 * The legacy /credit/ slug 301s to /store-credit/.
	 */
	public function test_legacy_credit_redirects_to_store_credit(): void {
		$GLOBALS['wp']->query_vars = array( blueline_legacy_query_var( 'credit' ) => '' );

		$this->assertSame( 'https://example.test/account/store-credit/', $this->redirect_target() );
	}

	/**
	 * A request for the ARL slug itself (or anything non-legacy) is not redirected.
	 */
	public function test_current_arl_slug_is_not_redirected(): void {
		$GLOBALS['wp']->query_vars = array( 'orders' => '' );

		$this->assertNull( $this->redirect_target() );
	}

	/**
	 * Off the account page nothing is redirected, even with a legacy query var present.
	 */
	public function test_other_pages_are_not_redirected(): void {
		$GLOBALS['bl_core_test_is_account_page'] = false;
		$GLOBALS['wp']->query_vars               = array( blueline_legacy_query_var( 'orders' ) => '' );

		$this->assertNull( $this->redirect_target() );
	}
}
