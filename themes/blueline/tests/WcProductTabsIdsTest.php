<?php
/**
 * The product tabs template override (woocommerce/single-product/tabs/tabs.php):
 * ids are unique per product where a page embeds several products
 * (/register's two [product_page] shortcodes), so each tab's aria-controls and
 * each panel's aria-labelledby resolve to that product's own elements.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * Unit tests.
 */
final class WcProductTabsIdsTest extends TestCase {

	/**
	 * Reset the fake WordPress state and the tabs filter.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_test_reset_hooks();
		add_filter(
			'woocommerce_product_tabs',
			static function () {
				return array(
					'description' => array(
						'title'    => 'Description',
						'callback' => static function () {
							echo 'Spots are filled.';
						},
					),
				);
			}
		);
	}

	/**
	 * Clear the tabs filter.
	 */
	protected function tearDown(): void {
		blueline_test_reset_hooks();
		unset( $GLOBALS['product'] );
	}

	/**
	 * Render the tabs for product $id.
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	private function render( int $id ): string {
		$GLOBALS['product'] = new class( $id ) {
			/**
			 * Product id.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Product id.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * WC_Product::get_id().
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};

		ob_start();
		include __DIR__ . '/../woocommerce/single-product/tabs/tabs.php';

		return (string) ob_get_clean();
	}

	/**
	 * Embedded on another page, every id carries the product id, and the
	 * tab, its link target and its panel all agree.
	 */
	public function test_an_embedded_product_has_its_own_tab_ids(): void {
		$state                      = &blueline_test_state();
		$state['queried_object_id'] = 9;

		$html = $this->render( 116522 );

		$this->assertStringContainsString( 'id="tab-title-description-116522"', $html );
		$this->assertStringContainsString( 'href="#tab-description-116522"', $html );
		$this->assertStringContainsString( 'aria-controls="tab-description-116522"', $html );
		$this->assertStringContainsString( 'id="tab-description-116522" role="tabpanel" aria-labelledby="tab-title-description-116522"', $html );
		$this->assertStringContainsString( 'Spots are filled.', $html );
		$this->assertStringNotContainsString( '"tab-description"', $html );
	}

	/**
	 * Two embedded products never share an id.
	 */
	public function test_two_embedded_products_share_no_id(): void {
		$state                      = &blueline_test_state();
		$state['queried_object_id'] = 9;

		preg_match_all( '/\bid="([^"]+)"/', $this->render( 1 ) . $this->render( 2 ), $ids );

		$this->assertSame( array_unique( $ids[1] ), $ids[1] );
	}

	/**
	 * On the product's own page the stock ids stay, so WooCommerce's own
	 * #tab-reviews links and review anchors keep working.
	 */
	public function test_the_products_own_page_keeps_the_stock_ids(): void {
		$state                      = &blueline_test_state();
		$state['queried_object_id'] = 42;

		$html = $this->render( 42 );

		$this->assertStringContainsString( 'id="tab-title-description"', $html );
		$this->assertStringContainsString( 'href="#tab-description"', $html );
		$this->assertStringContainsString( 'id="tab-description" role="tabpanel" aria-labelledby="tab-title-description"', $html );
	}
}
