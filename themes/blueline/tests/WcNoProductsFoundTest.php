<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * D-11: empty shop/category/tag archives were a dead end ("No products were
 * found"); they now show the theme empty state with a way to registration.
 */
final class WcNoProductsFoundTest extends TestCase {

	/**
	 * Runs before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Leaf mark, an h2 under the archive's h1, and a link to the Register page.
	 */
	public function test_renders_the_empty_state_with_a_register_link(): void {
		ob_start();
		blueline_wc_no_products_found();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="bl-empty-state bl-empty-state--shop"', $html );
		$this->assertStringContainsString( 'bl-leaf-mark bl-empty-state__mark', $html );
		$this->assertStringContainsString( '<h2 class="bl-empty-state__title">', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/register' ) . '"', $html );
		$this->assertStringNotContainsString( 'No products were found', $html );
	}

	/**
	 * Core's notice callback is swapped for the theme's.
	 */
	public function test_hooks(): void {
		$src = (string) file_get_contents( __DIR__ . '/../inc/woocommerce.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );", $src );
		$this->assertStringContainsString( "add_action( 'woocommerce_no_products_found', 'blueline_wc_no_products_found' );", $src );
	}
}
