<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

// Stand-ins for the two WooCommerce helpers the template calls; the template's own markup is what is under test.
require_once __DIR__ . '/checkout-terms-stubs.php';

/**
 * The checkout terms checkbox must not share an id with anything the Terms page prints.
 */
final class CheckoutTermsMarkupTest extends TestCase {

	/**
	 * Render the theme's terms.php.
	 *
	 * @return string
	 */
	private function render_terms(): string {
		ob_start();
		include __DIR__ . '/../woocommerce/checkout/terms.php';

		return (string) ob_get_clean();
	}

	/**
	 * The Terms page's own content starts with `<h2 id="terms">`, so the required checkbox must use another id.
	 */
	public function test_checkbox_does_not_reuse_the_terms_heading_id(): void {
		$html = $this->render_terms();

		$this->assertStringContainsString( 'name="terms"', $html );
		$this->assertStringNotContainsString( 'id="terms"', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="terms"[^>]*id="bl-terms-checkbox"/', $html );
	}

	/**
	 * Still one real required checkbox, still named for assistive tech by the label that wraps it.
	 */
	public function test_checkbox_stays_required_and_inside_its_label(): void {
		$html = $this->render_terms();

		$this->assertMatchesRegularExpression( '/<label[^>]*>\s*<input[^>]*name="terms"[^>]*required[^>]*aria-required="true"/', $html );
	}
}
