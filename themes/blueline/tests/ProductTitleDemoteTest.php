<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * A11Y-09: the /register [product_page] embed's title drops from h1 to h2.
 */
final class ProductTitleDemoteTest extends TestCase {

	/**
	 * Test case: core's title markup keeps its classes, only the tag changes.
	 */
	public function test_h1_becomes_h2(): void {
		$this->assertSame(
			'<h2 class="product_title entry-title">Player Waitlist</h2>',
			blueline_wc_demote_product_title( '<h1 class="product_title entry-title">Player Waitlist</h1>' )
		);
	}

	/**
	 * Test case: anything else is left alone.
	 */
	public function test_other_markup_untouched(): void {
		$html = '<h10>x</h10><p class="h1">y</p>';

		$this->assertSame( $html, blueline_wc_demote_product_title( $html ) );
	}
}
