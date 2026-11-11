<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

// Stand-ins for the two WooCommerce helpers the template calls; the template's own markup is what is under test.
require_once __DIR__ . '/checkout-terms-stubs.php';
require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * The checkout terms checkbox keeps WooCommerce's `id="terms"` (checkout.js
 * saves and restores the tick by that id across every update_checkout), and
 * nothing else in the terms area may share it (A11Y-16): the Terms page's own
 * content opens with `<h2 id="terms">`.
 */
final class CheckoutTermsMarkupTest extends TestCase {

	/**
	 * Reset hooks between tests.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Render the theme's terms.php, with the Terms page content WooCommerce
	 * prints on `woocommerce_checkout_terms_and_conditions` stood in.
	 *
	 * @return string
	 */
	private function render_terms(): string {
		add_action(
			'woocommerce_checkout_terms_and_conditions',
			static function () {
				echo '<div class="woocommerce-terms-and-conditions"><h2 class="wp-block-heading" id="terms">WAVE ARL TERMS</h2><p><a href="#terms">Back to top</a></p></div>';
			},
			30
		);

		ob_start();
		include __DIR__ . '/../woocommerce/checkout/terms.php';

		return (string) ob_get_clean();
	}

	/**
	 * ACC-01: the checkbox keeps core's id, which WooCommerce's checkout.js
	 * reads (`$( '#terms' ).prop( 'checked' )`) to restore the tick after the
	 * order review refreshes.
	 */
	public function test_checkbox_keeps_woocommerce_terms_id(): void {
		$html = $this->render_terms();

		$this->assertMatchesRegularExpression( '/<input[^>]*name="terms"[^>]*id="terms"/', $html );
	}

	/**
	 * A11Y-16: exactly one element carries id="terms" -- the checkbox -- so
	 * its label can never resolve to the Terms page's heading.
	 */
	public function test_terms_heading_no_longer_shares_the_checkbox_id(): void {
		$html = $this->render_terms();

		$this->assertSame( 1, preg_match_all( '/\bid="terms"/', $html ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading" id="bl-terms-heading">', $html );
		$this->assertStringContainsString( 'href="#bl-terms-heading"', $html, 'in-page links to the heading follow it' );
	}

	/**
	 * Still one real required checkbox, still named for assistive tech by the label that wraps it.
	 */
	public function test_checkbox_stays_required_and_inside_its_label(): void {
		$html = $this->render_terms();

		$this->assertMatchesRegularExpression( '/<label[^>]*>\s*<input[^>]*name="terms"[^>]*required[^>]*aria-required="true"/', $html );
	}
}
