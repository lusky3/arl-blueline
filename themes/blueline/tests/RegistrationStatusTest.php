<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
/**
 * Unit tests.
 *
 * A WC_Order_Item double lives beside the test case itself, the same
 * trade-off tests/bootstrap.php makes for its own WordPress stand-ins.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the FileName disable above.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * A minimal order line item: only what the registration reader reads.
 */
final class BluelineRegistrationItem {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Line subtotal (before coupons), as WooCommerce stores it.
	 *
	 * @var string
	 */
	private $subtotal;

	/**
	 * Constructor.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $subtotal   Line subtotal.
	 */
	public function __construct( int $product_id, string $subtotal ) {
		$this->product_id = $product_id;
		$this->subtotal   = $subtotal;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_product_id() {
		return $this->product_id;
	}

	/**
	 * Line subtotal.
	 *
	 * @return string
	 */
	public function get_subtotal() {
		return $this->subtotal;
	}
}

/**
 * ACC-02: "My registration" must not report the newest season order of any
 * status. A $0 waitlist order is "On the waitlist", never "Paid"; a paid
 * registration outranks a newer unpaid one; abandoned (pending), failed,
 * cancelled and refunded orders are not a registration at all.
 *
 * Every test runs in its own process: WC_Order and wc_get_orders() are stood
 * in with eval() and must not leak into the shared process (other suites
 * define their own always-empty wc_get_orders()).
 */
final class RegistrationStatusTest extends TestCase {

	/**
	 * Season product IDs: 501 is the $525 player registration, 502 the $0 waitlist.
	 */
	private const PAID_PRODUCT     = 501;
	private const WAITLIST_PRODUCT = 502;

	/**
	 * Define the WooCommerce stand-ins and a one-season product_cat tree.
	 *
	 * @param array<int, array{id:int, status:string, paid:bool, items:array}> $orders Newest first.
	 */
	private function arrange( array $orders ): void {
		blueline_test_reset();
		blueline_test_reset_state();

		// Registration term 91 (the documented fallback) with one season child.
		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 300, 'product_cat', 'Winter 2026-27', 91 );

		$GLOBALS['bl_test_get_posts_model'] = static function ( $args ) {
			if ( 'product' !== ( $args['post_type'] ?? '' ) || empty( $args['tax_query'] ) ) {
				return null;
			}

			return array( self::PAID_PRODUCT, self::WAITLIST_PRODUCT );
		};

		$GLOBALS['bl_test_orders']      = $orders;
		$GLOBALS['bl_test_orders_args'] = array();

		if ( ! class_exists( 'WC_Order' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce class stand-in, defined only in this test's own process.
				'class WC_Order {
					public $data;
					public function __construct( array $data ) { $this->data = $data; }
					public function get_id() { return $this->data["id"]; }
					public function get_status() { return $this->data["status"]; }
					public function is_paid() { return $this->data["paid"]; }
					public function get_items() { return $this->data["items"]; }
					public function get_view_order_url() { return "https://example.test/my-account/view-order/" . $this->data["id"] . "/"; }
				}'
			);
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			/*
			 * Honours `status` (with or without the wc- prefix) and paging, so
			 * a reader that forgets to filter by status sees every order, as
			 * it would against the real default status set.
			 */
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce function stand-in, defined only in this test's own process.
				'function wc_get_orders( $args ) {
					$GLOBALS["bl_test_orders_args"][] = $args;
					$rows = $GLOBALS["bl_test_orders"];
					if ( isset( $args["status"] ) ) {
						$want = array_map( static function ( $s ) { return preg_replace( "/^wc-/", "", $s ); }, (array) $args["status"] );
						$rows = array_values( array_filter( $rows, static function ( $r ) use ( $want ) { return in_array( $r["status"], $want, true ); } ) );
					}
					$limit = (int) ( $args["limit"] ?? 10 );
					$page  = max( 1, (int) ( $args["paged"] ?? 1 ) );
					$rows  = array_slice( $rows, ( $page - 1 ) * $limit, $limit );
					return array_map( static function ( $r ) { return new WC_Order( $r ); }, $rows );
				}
				function wc_get_order( $id ) {
					foreach ( $GLOBALS["bl_test_orders"] as $r ) { if ( $r["id"] === (int) $id ) { return new WC_Order( $r ); } }
					return false;
				}'
			);
		}
	}

	/**
	 * One order row for arrange().
	 *
	 * @param int    $id     Order ID.
	 * @param string $status Status, without the wc- prefix.
	 * @param bool   $paid   What WC_Order::is_paid() answers.
	 * @param array  $items  Line items.
	 * @return array{id:int, status:string, paid:bool, items:array}
	 */
	private static function order( int $id, string $status, bool $paid, array $items ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'paid'   => $paid,
			'items'  => $items,
		);
	}

	/**
	 * A line for the $525 registration product.
	 *
	 * @return BluelineRegistrationItem
	 */
	private static function registration_item(): BluelineRegistrationItem {
		return new BluelineRegistrationItem( self::PAID_PRODUCT, '525' );
	}

	/**
	 * A line for the $0 waitlist product.
	 *
	 * @return BluelineRegistrationItem
	 */
	private static function waitlist_item(): BluelineRegistrationItem {
		return new BluelineRegistrationItem( self::WAITLIST_PRODUCT, '0' );
	}

	/**
	 * A completed $0 waitlist order is "on the waitlist", not "Paid".
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_waitlist_only_member_is_on_the_waitlist_not_paid(): void {
		$this->arrange( array( self::order( 11, 'completed', true, array( self::waitlist_item() ) ) ) );

		$status = blueline_get_user_registration_status( 7 );

		$this->assertIsArray( $status );
		$this->assertSame( 11, $status['order_id'] );
		$this->assertTrue( $status['waitlist'] );
		$this->assertFalse( $status['paid'] );
		$this->assertSame( 'Winter 2026-27', $status['season'] );
	}

	/**
	 * A paid registration outranks a newer on-hold one.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_paid_registration_outranks_a_newer_on_hold_order(): void {
		$this->arrange(
			array(
				self::order( 22, 'on-hold', false, array( self::registration_item() ) ),
				self::order( 21, 'completed', true, array( self::registration_item() ) ),
			)
		);

		$status = blueline_get_user_registration_status( 7 );

		$this->assertSame( 21, $status['order_id'] );
		$this->assertTrue( $status['paid'] );
		$this->assertFalse( $status['waitlist'] );
	}

	/**
	 * Pending, failed, cancelled and refunded orders are not a registration.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_abandoned_and_reversed_orders_are_ignored(): void {
		$this->arrange(
			array(
				self::order( 35, 'pending', false, array( self::registration_item() ) ),
				self::order( 34, 'failed', false, array( self::registration_item() ) ),
				self::order( 33, 'cancelled', false, array( self::registration_item() ) ),
				self::order( 32, 'refunded', false, array( self::registration_item() ) ),
			)
		);

		$this->assertNull( blueline_get_user_registration_status( 7 ) );

		$statuses = (array) ( $GLOBALS['bl_test_orders_args'][0]['status'] ?? array() );
		sort( $statuses );
		$this->assertSame( array( 'completed', 'on-hold', 'partial-payment', 'processing' ), $statuses );
	}

	/**
	 * An on-hold registration (awaiting e-Transfer) is still "Unpaid", and
	 * outranks an older waitlist entry.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_hold_registration_is_unpaid_and_outranks_a_waitlist_entry(): void {
		$this->arrange(
			array(
				self::order( 42, 'completed', true, array( self::waitlist_item() ) ),
				self::order( 41, 'on-hold', false, array( self::registration_item() ) ),
			)
		);

		$status = blueline_get_user_registration_status( 7 );

		$this->assertSame( 41, $status['order_id'] );
		$this->assertFalse( $status['paid'] );
		$this->assertFalse( $status['waitlist'] );
	}

	/**
	 * A paid registration found on a later page still wins over a newer
	 * waitlist entry on page one; the normal case is unchanged.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_paid_registration_on_a_later_page_wins(): void {
		$orders = array( self::order( 100, 'completed', true, array( self::waitlist_item() ) ) );

		for ( $i = 99; $i > 75; $i-- ) {
			$orders[] = self::order( $i, 'completed', true, array( new BluelineRegistrationItem( 999, '20' ) ) ); // Not a season product.
		}

		$orders[] = self::order( 50, 'processing', true, array( self::registration_item() ) );

		$this->arrange( $orders );

		$status = blueline_get_user_registration_status( 7 );

		$this->assertSame( 50, $status['order_id'] );
		$this->assertTrue( $status['paid'] );
	}

	/**
	 * An ordinary paid member (one completed $525 order) reads exactly as before.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ordinary_paid_member_is_unchanged(): void {
		$this->arrange( array( self::order( 61, 'completed', true, array( self::registration_item() ) ) ) );

		$status = blueline_get_user_registration_status( 7 );

		$this->assertSame( 61, $status['order_id'] );
		$this->assertTrue( $status['paid'] );
		$this->assertFalse( $status['waitlist'] );
		$this->assertSame( 'completed', $status['status'] );
	}

	/**
	 * The card prints "On the waitlist" for a waitlist entry.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_card_says_on_the_waitlist(): void {
		$this->arrange( array( self::order( 11, 'completed', true, array( self::waitlist_item() ) ) ) );

		ob_start();
		blueline_account_render_registration( 7 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bl-account-registration__status--waitlist', $html );
		$this->assertStringContainsString( 'On the waitlist', $html );
		$this->assertStringNotContainsString( 'Paid', $html );
	}
}
