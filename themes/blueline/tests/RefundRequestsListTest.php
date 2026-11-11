<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * ACC-05: woocommerce/myaccount/my-refund-requests.php must not print the
 * previous row's product for a request whose product was deleted.
 *
 * Runs in its own process: YITH_Refund_Request and the WooCommerce helpers
 * the template calls are stood in with eval().
 */
final class RefundRequestsListTest extends TestCase {

	/**
	 * Define the YITH and WooCommerce stand-ins.
	 *
	 * @param array<int, array<string, mixed>> $requests Request id => properties.
	 * @param array<int, string>               $products Existing product id => title.
	 */
	private function arrange( array $requests, array $products ): void {
		blueline_test_reset();

		$GLOBALS['bl_test_refunds'] = array(
			'requests' => $requests,
			'products' => $products,
		);

		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- YITH/WooCommerce stand-ins, defined only in this test's own process.
			'class YITH_Refund_Request {
				public $id; public $status = "ywcars-new"; public $order_id = 0; public $whole_order = false; public $product_id = 0; public $refund_total = 0;
				public function __construct( $id ) {
					$this->id = $id;
					foreach ( $GLOBALS["bl_test_refunds"]["requests"][ $id ] as $k => $v ) { $this->$k = $v; }
				}
				public function exists() { return true; }
				public function get_view_request_url() { return "https://example.test/refund/" . $this->id; }
			}
			function wc_get_order( $id ) { return false; }
			function wc_get_product( $id ) {
				if ( ! isset( $GLOBALS["bl_test_refunds"]["products"][ $id ] ) ) { return false; }
				$title = $GLOBALS["bl_test_refunds"]["products"][ $id ];
				return new class( $title ) {
					private $t;
					public function __construct( $t ) { $this->t = $t; }
					public function get_permalink() { return "https://example.test/product/"; }
					public function get_title() { return $this->t; }
				};
			}
			function wc_price( $amount ) { return "$" . $amount; }
			function ywcars_get_request_status_by_key( $key ) { return $key; }'
		);
	}

	/**
	 * Render the template for $request_ids.
	 *
	 * @param int[] $request_ids Request IDs.
	 * @return string
	 */
	private function render( array $request_ids ): string {
		ob_start();
		include __DIR__ . '/../woocommerce/myaccount/my-refund-requests.php';
		return (string) ob_get_clean();
	}

	/**
	 * A deleted product's row says so, instead of repeating the row before it.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_deleted_product_row_does_not_reuse_previous_product(): void {
		$this->arrange(
			array(
				1 => array( 'product_id' => 501 ),
				2 => array( 'product_id' => 999 ), // Deleted.
			),
			array( 501 => 'Winter Player Registration' )
		);

		$html = $this->render( array( 1, 2 ) );

		$this->assertSame( 1, substr_count( $html, 'Winter Player Registration' ), 'only the first row names that product' );
		$this->assertStringContainsString( 'Product no longer available', $html );
	}

	/**
	 * A deleted product on the first row prints the fallback (no undefined variable).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_deleted_product_on_first_row(): void {
		$this->arrange( array( 1 => array( 'product_id' => 999 ) ), array() );

		$html = $this->render( array( 1 ) );

		$this->assertStringContainsString( 'Product no longer available', $html );
	}
}
