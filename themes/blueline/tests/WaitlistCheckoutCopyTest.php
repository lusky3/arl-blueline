<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
/**
 * Unit tests.
 *
 * Small WooCommerce doubles live beside the test case itself, the same
 * trade-off tests/bootstrap.php makes for its own WordPress stand-ins.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the FileName disable above.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * A minimal WC_Product double: an ID and a raw price.
 */
final class BluelineWaitlistCopyProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Raw price string.
	 *
	 * @var string
	 */
	private $price;

	/**
	 * Constructor.
	 *
	 * @param int    $id    Product ID.
	 * @param string $price Raw price.
	 */
	public function __construct( int $id, string $price ) {
		$this->id    = $id;
		$this->price = $price;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Raw price.
	 *
	 * @return string
	 */
	public function get_price() {
		return $this->price;
	}
}

/**
 * A minimal order line item: the product it holds (as it is today) and the
 * subtotal saved on the order when it was placed.
 */
final class BluelineWaitlistCopyItem {

	/**
	 * The product.
	 *
	 * @var BluelineWaitlistCopyProduct
	 */
	private $product;

	/**
	 * Saved line subtotal.
	 *
	 * @var string
	 */
	private $subtotal;

	/**
	 * Constructor.
	 *
	 * @param BluelineWaitlistCopyProduct $product  The product.
	 * @param string                      $subtotal Saved line subtotal.
	 */
	public function __construct( BluelineWaitlistCopyProduct $product, string $subtotal ) {
		$this->product  = $product;
		$this->subtotal = $subtotal;
	}

	/**
	 * The product.
	 *
	 * @return BluelineWaitlistCopyProduct
	 */
	public function get_product() {
		return $this->product;
	}

	/**
	 * Saved line subtotal.
	 *
	 * @return string
	 */
	public function get_subtotal() {
		return $this->subtotal;
	}
}

/**
 * A minimal order double: its line items.
 */
final class BluelineWaitlistCopyOrder {

	/**
	 * Line items.
	 *
	 * @var BluelineWaitlistCopyItem[]
	 */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param BluelineWaitlistCopyItem[] $items Line items.
	 */
	public function __construct( array $items ) {
		$this->items = $items;
	}

	/**
	 * Line items.
	 *
	 * @return BluelineWaitlistCopyItem[]
	 */
	public function get_items() {
		return $this->items;
	}
}

/**
 * ACC-04: a cart or order holding only $0 waitlist products must not be told
 * that "this one payment covers the whole season" or that the registration
 * was received.
 *
 * Every test runs in its own process: WC() and the season helper are stood in
 * with eval() and must not leak into the shared process.
 */
final class WaitlistCheckoutCopyTest extends TestCase {

	/**
	 * Define the WooCommerce stand-ins. Season products: 10 ($0 waitlist), 11 ($525).
	 *
	 * @param BluelineWaitlistCopyProduct[] $cart Products in the cart.
	 */
	private function arrange( array $cart ): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_waitlist_copy'] = array(
			'season' => array( 10, 11 ),
			'cart'   => array_map(
				static function ( $product ) {
					return array( 'data' => $product );
				},
				$cart
			),
		);

		if ( function_exists( 'WC' ) ) {
			return;
		}

		eval( 'function blueline_registration_season_product_ids(): array { return $GLOBALS["bl_test_waitlist_copy"]["season"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the real one needs WP_Query; season-state.php is not loaded here.
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce stand-in, defined only in this test's own process.
			'function WC() {
				return (object) array(
					"cart" => new class() {
						public function get_cart() { return $GLOBALS["bl_test_waitlist_copy"]["cart"]; }
					},
				);
			}'
		);
	}

	/**
	 * The reassurance note's output.
	 *
	 * @return string
	 */
	private function reassurance(): string {
		ob_start();
		blueline_checkout_reassurance();
		return (string) ob_get_clean();
	}

	/**
	 * A waitlist-only cart gets waitlist copy, not the season-payment copy.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_waitlist_only_cart_gets_waitlist_reassurance(): void {
		$this->arrange( array( new BluelineWaitlistCopyProduct( 10, '0' ) ) );

		$html = $this->reassurance();

		$this->assertStringContainsString( 'waitlist', $html );
		$this->assertStringNotContainsString( 'covers the whole season', $html );
		$this->assertStringNotContainsString( 'roster show up', $html );
	}

	/**
	 * A paid registration (alone or beside a waitlist) keeps the season copy.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_paid_cart_keeps_season_reassurance(): void {
		$this->arrange( array( new BluelineWaitlistCopyProduct( 11, '525' ) ) );
		$this->assertStringContainsString( 'covers the whole season', $this->reassurance() );

		$GLOBALS['bl_test_waitlist_copy']['cart'][] = array( 'data' => new BluelineWaitlistCopyProduct( 10, '0' ) );
		$this->assertStringContainsString( 'covers the whole season', $this->reassurance() );
	}

	/**
	 * An empty cart is not a waitlist cart.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_empty_cart_is_not_waitlist_only(): void {
		$this->arrange( array() );

		$this->assertFalse( blueline_wc_cart_is_waitlist_only() );
		$this->assertStringContainsString( 'covers the whole season', $this->reassurance() );
	}

	/**
	 * The thank-you line says waitlist for a waitlist-only order.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_thankyou_text_for_waitlist_order(): void {
		$this->arrange( array() );

		$received = 'Thank you. Your registration has been received.';
		$waitlist = new BluelineWaitlistCopyOrder( array( new BluelineWaitlistCopyItem( new BluelineWaitlistCopyProduct( 10, '0' ), '0' ) ) );
		$paid     = new BluelineWaitlistCopyOrder( array( new BluelineWaitlistCopyItem( new BluelineWaitlistCopyProduct( 11, '525' ), '525' ) ) );

		$this->assertStringContainsString( 'waitlist', blueline_wc_waitlist_thankyou_text( $received, $waitlist ) );
		$this->assertSame( $received, blueline_wc_waitlist_thankyou_text( $received, $paid ) );
		$this->assertSame( $received, blueline_wc_waitlist_thankyou_text( $received, false ), 'no order: unchanged' );
		$this->assertSame( $received, blueline_wc_waitlist_thankyou_text( $received, new BluelineWaitlistCopyOrder( array() ) ), 'no lines: unchanged' );
	}

	/**
	 * A saved order keeps its meaning when the product's price changes after
	 * purchase: the thank-you line reads the order's own saved lines, not
	 * today's product price or today's season.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_thankyou_text_ignores_price_changes_after_purchase(): void {
		$this->arrange( array() );

		$received = 'Thank you. Your registration has been received.';

		// Paid $525 when placed; the product has since been repriced to $0 (now a waitlist).
		$was_paid = new BluelineWaitlistCopyOrder( array( new BluelineWaitlistCopyItem( new BluelineWaitlistCopyProduct( 10, '0' ), '525' ) ) );
		$this->assertSame( $received, blueline_wc_waitlist_thankyou_text( $received, $was_paid ) );

		// Joined at $0; the product now costs $575.
		$was_waitlist = new BluelineWaitlistCopyOrder( array( new BluelineWaitlistCopyItem( new BluelineWaitlistCopyProduct( 11, '575' ), '0' ) ) );
		$this->assertStringContainsString( 'waitlist', blueline_wc_waitlist_thankyou_text( $received, $was_waitlist ) );

		// Joined at $0 in a past season; that product is no longer in the current season.
		$past_season = new BluelineWaitlistCopyOrder( array( new BluelineWaitlistCopyItem( new BluelineWaitlistCopyProduct( 77, '0' ), '0' ) ) );
		$this->assertStringContainsString( 'waitlist', blueline_wc_waitlist_thankyou_text( $received, $past_season ) );
	}
}
