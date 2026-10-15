<?php
/**
 * Checkout's "Click here to login" toggle (woocommerce/checkout/form-login.php)
 * is a disclosure: a button role and an aria-expanded that matches whether
 * the login form starts open. assets/src/js/checkout-login-toggle.js adds
 * aria-controls and keeps the state in step after that.
 *
 * Runs in separate processes: the WooCommerce functions the template calls are
 * defined per test.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests.
 */
final class CheckoutLoginToggleTest extends TestCase {

	/**
	 * Stand in for WooCommerce and render the template for a guest.
	 *
	 * @return string
	 */
	private function render(): string {
		blueline_test_reset_state();
		$GLOBALS['bl_test_options']['woocommerce_enable_checkout_login_reminder'] = 'yes';

		eval( 'class WC_Checkout { public static function instance() { return new self(); } public function is_registration_enabled() { return false; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce class stand-in.
		eval( 'function wc_print_notice( $message, $type ) { echo "<div class=\"woocommerce-info\">" . $message . "</div>"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce function stand-in.
		eval( 'function woocommerce_login_form( $args ) { $GLOBALS["bl_test_login_form_args"] = $args; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce function stand-in.
		eval( 'function wc_get_checkout_url() { return "https://example.test/checkout/"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce function stand-in.

		ob_start();
		include __DIR__ . '/../woocommerce/checkout/form-login.php';

		return (string) ob_get_clean();
	}

	/**
	 * Collapsed by default, announced as a button.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_toggle_is_a_collapsed_disclosure(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<a href="#" class="showlogin" role="button" aria-expanded="false">', $html );
		$this->assertTrue( $GLOBALS['bl_test_login_form_args']['hidden'] );
	}

	/**
	 * After a failed login the form is shown again, and the toggle says so.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_toggle_starts_expanded_after_a_login_attempt(): void {
		$_POST['login'] = '1';

		$html = $this->render();

		unset( $_POST['login'] );

		$this->assertStringContainsString( 'aria-expanded="true"', $html );
		$this->assertFalse( $GLOBALS['bl_test_login_form_args']['hidden'] );
	}
}
