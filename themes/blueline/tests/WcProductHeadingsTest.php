<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * D-09/D-13/D-16: heading structure on product and checkout pages.
 */
final class WcProductHeadingsTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Point the fake query at product 42.
	 */
	private function view_product(): void {
		blueline_test_set_queried_post_type( 'product' );
		$state                      = &blueline_test_state();
		$state['queried_object_id'] = 42;
		$state['posts'][42]         = array( 'title' => 'Protected: Player Registration (W2026-27)' );
	}

	/**
	 * Test case: the protected product's own password form gains its h1.
	 */
	public function test_password_form_on_its_product_gets_h1(): void {
		$this->view_product();

		$this->assertSame(
			'<h1 class="product_title entry-title">Protected: Player Registration (W2026-27)</h1><form></form>',
			blueline_wc_password_form_heading( '<form></form>', (object) array( 'ID' => 42 ) )
		);
	}

	/**
	 * Test case: other posts' forms (pages, embeds of another post) are left alone.
	 */
	public function test_password_form_elsewhere_untouched(): void {
		$this->view_product();
		$this->assertSame( '<form></form>', blueline_wc_password_form_heading( '<form></form>', (object) array( 'ID' => 7 ) ) );
		$this->assertSame( '<form></form>', blueline_wc_password_form_heading( '<form></form>' ) );

		blueline_test_set_queried_post_type( 'page' );
		$this->assertSame( '<form></form>', blueline_wc_password_form_heading( '<form></form>', (object) array( 'ID' => 42 ) ) );
	}

	/**
	 * Test case: both filters are registered.
	 */
	public function test_filters_registered(): void {
		$this->assertTrue( $this->hooked( 'the_password_form', 'blueline_wc_password_form_heading' ) );
		$this->assertTrue( $this->hooked( 'woocommerce_product_description_heading', '__return_empty_string' ) );
	}

	/**
	 * Test case: the checkout template puts an h2 between the page h1 and core's h3s.
	 */
	public function test_checkout_template_has_h2_before_customer_details(): void {
		$template = (string) file_get_contents( __DIR__ . '/../woocommerce/checkout/form-checkout.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$h2       = strpos( $template, '<h2 class="screen-reader-text">' );
		$details  = strpos( $template, 'id="customer_details"' );

		$this->assertNotFalse( $h2 );
		$this->assertLessThan( $details, $h2 );
	}

	/**
	 * Whether $callback is registered on $tag at any priority.
	 *
	 * @param string $tag      Hook name.
	 * @param string $callback Callback name.
	 * @return bool
	 */
	private function hooked( string $tag, string $callback ): bool {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $bucket ) {
			foreach ( $bucket as $entry ) {
				if ( $callback === $entry['cb'] ) {
					return true;
				}
			}
		}
		return false;
	}
}
