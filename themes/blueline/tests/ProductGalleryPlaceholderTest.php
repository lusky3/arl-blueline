<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * D-17: an image-less registration renders no gallery instead of the placeholder.
 */
final class ProductGalleryPlaceholderTest extends TestCase {

	/**
	 * Reset the global product after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
	}

	/**
	 * A minimal WC_Product double exposing only the two image accessors.
	 *
	 * @param int   $image_id Featured image ID (0 for none).
	 * @param int[] $gallery  Gallery image IDs.
	 * @return object
	 */
	private function product( int $image_id, array $gallery = array() ) {
		return new class( $image_id, $gallery ) {
			/**
			 * Featured image ID.
			 *
			 * @var int
			 */
			private $image_id;

			/**
			 * Gallery image IDs.
			 *
			 * @var int[]
			 */
			private $gallery;

			/**
			 * Constructor.
			 *
			 * @param int   $image_id Featured image ID.
			 * @param int[] $gallery  Gallery image IDs.
			 */
			public function __construct( int $image_id, array $gallery ) {
				$this->image_id = $image_id;
				$this->gallery  = $gallery;
			}

			/**
			 * Featured image ID.
			 *
			 * @return int
			 */
			public function get_image_id() {
				return $this->image_id;
			}

			/**
			 * Gallery image IDs.
			 *
			 * @return int[]
			 */
			public function get_gallery_image_ids() {
				return $this->gallery;
			}
		};
	}

	/**
	 * Render the theme's gallery callback for a product.
	 *
	 * @param mixed $product Product double (or null).
	 * @return string
	 */
	private function render( $product ): string {
		$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating WooCommerce's loop global.
		ob_start();
		blueline_wc_show_product_images();
		return (string) ob_get_clean();
	}

	/**
	 * Test case: no featured and no gallery image means no gallery markup.
	 */
	public function test_imageless_product_skips_gallery(): void {
		$this->assertSame( '', $this->render( $this->product( 0 ) ) );
	}

	/**
	 * Test case: a featured image keeps WooCommerce's gallery.
	 */
	public function test_featured_image_keeps_gallery(): void {
		$this->assertStringContainsString( 'woocommerce-product-gallery', $this->render( $this->product( 42 ) ) );
	}

	/**
	 * Test case: gallery images alone also keep it.
	 */
	public function test_gallery_only_keeps_gallery(): void {
		$this->assertStringContainsString( 'woocommerce-product-gallery', $this->render( $this->product( 0, array( 7 ) ) ) );
	}

	/**
	 * Test case: no product global falls back to WooCommerce's default.
	 */
	public function test_unknown_product_keeps_gallery(): void {
		$this->assertStringContainsString( 'woocommerce-product-gallery', $this->render( null ) );
	}

	/**
	 * Test case: the theme callback replaces WooCommerce's at the same priority.
	 */
	public function test_hook_swapped_at_priority_20(): void {
		$src = (string) file_get_contents( __DIR__ . '/../inc/woocommerce.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );", $src );
		$this->assertStringContainsString( "add_action( 'woocommerce_before_single_product_summary', 'blueline_wc_show_product_images', 20 );", $src );
	}
}
