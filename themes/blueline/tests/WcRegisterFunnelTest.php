<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
/**
 * Unit tests.
 *
 * Two small stand-ins live beside the test case itself (a WC_Product double
 * and a payment-gateway double), the same trade-off tests/bootstrap.php makes
 * for its own WordPress stand-ins.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the FileName disable above.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * A minimal WC_Product double: only what the funnel code reads.
 */
final class BluelineFunnelProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Raw price string, as WooCommerce stores it.
	 *
	 * @var string
	 */
	private $price;

	/**
	 * Short description.
	 *
	 * @var string
	 */
	private $short;

	/**
	 * Constructor.
	 *
	 * @param int    $id    Product ID.
	 * @param string $price Raw price.
	 * @param string $short Short description.
	 */
	public function __construct( int $id, string $price, string $short = '' ) {
		$this->id    = $id;
		$this->price = $price;
		$this->short = $short;
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

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function get_short_description() {
		return $this->short;
	}

	/**
	 * Always purchasable.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		return true;
	}
}

/**
 * The registration funnel: sidebar-free product and cart pages, waitlist
 * wording, the processing-fee note, the Register page's jump link, and the
 * product image attributes.
 *
 * Every test runs in its own process because the WooCommerce conditional tags
 * and the season/offer helpers are stood in with eval()-defined functions that
 * must not leak into the shared process.
 */
final class WcRegisterFunnelTest extends TestCase {

	/**
	 * Define the WooCommerce stand-ins.
	 *
	 * @param array<string, mixed> $world Which conditional tags answer true, plus data the stand-ins return.
	 */
	private function arrange( array $world = array() ): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_funnel'] = $world + array(
			'product'  => false,
			'cart'     => false,
			'season'   => array(),
			'gateways' => array(),
			'offers'   => array(),
			'shop'     => false,
		);

		if ( function_exists( 'is_product' ) ) {
			return; // Stand-ins already defined for this process; they read the globals set above.
		}

		eval( 'function is_product() { return ! empty( $GLOBALS["bl_test_funnel"]["product"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_cart() { return ! empty( $GLOBALS["bl_test_funnel"]["cart"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_shop() { return ! empty( $GLOBALS["bl_test_funnel"]["shop"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_product_taxonomy() { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function in_the_loop() { return true; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- main-loop conditional stand-in.
		eval( 'function is_main_query() { return true; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- main-query conditional stand-in.
		eval( 'function get_the_ID() { return get_queried_object_id(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the loop's current post is the queried page in these tests.
		eval( 'function blueline_registration_season_product_ids(): array { return $GLOBALS["bl_test_funnel"]["season"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the real one needs WP_Query; season-state.php is not loaded here.
		eval( 'function blueline_homepage_registration_offers(): array { return $GLOBALS["bl_test_funnel"]["offers"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the hero's offer list; homepage-modules.php is not loaded here.
	}

	/**
	 * Product and cart pages get no sidebar; the shop archive and ordinary
	 * pages keep theirs.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_product_and_cart_pages_have_no_sidebar(): void {
		$this->arrange( array( 'product' => true ) );
		$this->assertTrue( blueline_wc_page_has_no_sidebar() );
		$this->assertFalse( blueline_wc_funnel_sidebar_filter( true, 'sidebar-1' ) );

		$GLOBALS['bl_test_funnel']['product'] = false;
		$GLOBALS['bl_test_funnel']['cart']    = true;
		$this->assertTrue( blueline_wc_page_has_no_sidebar() );
		$this->assertFalse( blueline_wc_funnel_sidebar_filter( true, 'sidebar-1' ) );

		$GLOBALS['bl_test_funnel']['cart'] = false;
		$this->assertFalse( blueline_wc_page_has_no_sidebar() );
		$this->assertTrue( blueline_wc_funnel_sidebar_filter( true, 'sidebar-1' ) );
	}

	/**
	 * Only the main sidebar is switched off, and a sidebar that was already
	 * inactive stays inactive.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_only_the_main_sidebar_is_suppressed(): void {
		$this->arrange( array( 'cart' => true ) );

		$this->assertTrue( blueline_wc_funnel_sidebar_filter( true, 'footer-1' ) );
		$this->assertFalse( blueline_wc_funnel_sidebar_filter( false, 'sidebar-1' ) );
	}

	/**
	 * The filter and the empty wrapper are wired, and sidebar.php bails out.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sidebar_wiring(): void {
		$this->assertArrayHasKey( 'is_active_sidebar', $GLOBALS['bl_test_hooks'] );

		$sidebar = (string) file_get_contents( __DIR__ . '/../sidebar.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$this->assertStringContainsString( 'blueline_wc_page_has_no_sidebar()', $sidebar );
	}

	/**
	 * The empty container the shop wrapper opens is skipped too.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_no_empty_sidebar_container_on_a_product_page(): void {
		$this->arrange( array( 'product' => true ) );

		ob_start();
		blueline_wc_sidebar_wrapper_start();
		blueline_wc_sidebar_wrapper_end();

		$this->assertSame( '', (string) ob_get_clean() );

		$GLOBALS['bl_test_funnel']['product'] = false;

		ob_start();
		blueline_wc_sidebar_wrapper_start();
		blueline_wc_sidebar_wrapper_end();

		$this->assertSame( '<div class="bl-container bl-container--wc-sidebar"></div>', (string) ob_get_clean() );
	}

	/**
	 * A $0 product in the season's registration category is a waitlist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_waitlist_detection(): void {
		$this->arrange( array( 'season' => array( 10, 11 ) ) );

		$this->assertTrue( blueline_wc_is_waitlist_product( new BluelineFunnelProduct( 10, '0' ) ) );
		$this->assertTrue( blueline_wc_is_waitlist_product( new BluelineFunnelProduct( 10, '0.00' ) ) );
		$this->assertFalse( blueline_wc_is_waitlist_product( new BluelineFunnelProduct( 11, '575' ) ) );
		$this->assertFalse( blueline_wc_is_waitlist_product( new BluelineFunnelProduct( 99, '0' ) ), 'a free product outside the season is not a waitlist' );
		$this->assertFalse( blueline_wc_is_waitlist_product( new BluelineFunnelProduct( 10, '' ) ), 'no price at all is not a waitlist' );
		$this->assertFalse( blueline_wc_is_waitlist_product( null ) );
	}

	/**
	 * The button reads "Join the waitlist" for a waitlist and is left alone otherwise.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_waitlist_button_and_price_wording(): void {
		$this->arrange( array( 'season' => array( 10, 11 ) ) );

		$waitlist = new BluelineFunnelProduct( 10, '0' );
		$paid     = new BluelineFunnelProduct( 11, '575' );

		$this->assertSame( 'Join the waitlist', blueline_wc_waitlist_add_to_cart_text( 'Register Now', $waitlist ) );
		$this->assertSame( 'Register Now', blueline_wc_waitlist_add_to_cart_text( 'Register Now', $paid ) );

		$this->assertSame( '<span class="bl-price-free">Free</span>', blueline_wc_waitlist_price_html( '<span>$0.00</span>', $waitlist ) );
		$this->assertSame( '<span>$575.00</span>', blueline_wc_waitlist_price_html( '<span>$575.00</span>', $paid ) );
	}

	/**
	 * Both the product page and the loop use the wording, at a priority that
	 * beats a snippet registered at the default.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_waitlist_filters_are_registered_late(): void {
		foreach ( array( 'woocommerce_product_single_add_to_cart_text', 'woocommerce_product_add_to_cart_text' ) as $tag ) {
			$buckets = $GLOBALS['bl_test_hooks'][ $tag ] ?? array();
			$this->assertNotEmpty( $buckets, $tag );
			$this->assertSame( 'blueline_wc_waitlist_add_to_cart_text', $buckets[ array_key_last( $buckets ) ][0]['cb'], $tag );
			$this->assertGreaterThan( 10, array_key_last( $buckets ), $tag );
		}

		$this->assertArrayHasKey( 'woocommerce_get_price_html', $GLOBALS['bl_test_hooks'] );
	}

	/**
	 * The fee wording quotes the percentage and the fixed amount, and names a
	 * fee-free method only when that gateway is enabled.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fee_message_only_claims_a_fee_free_method_that_is_enabled(): void {
		$this->arrange();

		$with_transfer             = array( 'gateways' => array( 'betpg' => 'yes' ) );
		$GLOBALS['bl_test_funnel'] = $with_transfer + $GLOBALS['bl_test_funnel'];
		eval( 'function WC() { return new class { public function payment_gateways() { return new class { public function payment_gateways() { $out = array(); foreach ( $GLOBALS["bl_test_funnel"]["gateways"] as $id => $enabled ) { $out[ $id ] = (object) array( "enabled" => $enabled ); } return $out; } }; } }; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce() stand-in exposing only the gateway list.

		$message = blueline_wc_card_fee_message();
		$this->assertStringContainsString( '3.5%', $message );
		$this->assertStringContainsString( '$0.30', $message );
		$this->assertStringContainsString( 'Interac e-Transfer has no fee', $message );
		$this->assertStringNotContainsString( 'free', strtolower( $message ) );

		$GLOBALS['bl_test_funnel']['gateways'] = array( 'betpg' => 'no' );
		$this->assertStringNotContainsString( 'e-Transfer', blueline_wc_card_fee_message(), 'a disabled gateway is never advertised as fee-free' );

		$GLOBALS['bl_test_funnel']['gateways'] = array();
		$this->assertStringNotContainsString( 'e-Transfer', blueline_wc_card_fee_message() );
	}

	/**
	 * The terms are filterable and an empty result switches the note off.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fee_terms_filter_can_change_or_remove_the_note(): void {
		$this->arrange();

		add_filter(
			'blueline_wc_card_fee_terms',
			static function ( array $terms ): array {
				$terms['percent'] = 2.9;
				$terms['fixed']   = 0.0;
				return $terms;
			}
		);

		$message = blueline_wc_card_fee_message();
		$this->assertStringContainsString( '2.9%', $message );
		$this->assertStringNotContainsString( '$0', $message );

		add_filter(
			'blueline_wc_card_fee_terms',
			static function (): array {
				return array();
			},
			20
		);
		$this->assertSame( '', blueline_wc_card_fee_message() );
	}

	/**
	 * Paid products carry the note; a waitlist does not.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fee_note_renders_for_paid_products_only(): void {
		$this->arrange( array( 'season' => array( 10, 11 ) ) );

		$GLOBALS['product'] = new BluelineFunnelProduct( 11, '575' );
		ob_start();
		blueline_wc_render_product_fee_note();
		$paid = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="bl-price-fee"', $paid );
		$this->assertStringContainsString( '3.5%', $paid );

		$GLOBALS['product'] = new BluelineFunnelProduct( 10, '0' );
		ob_start();
		blueline_wc_render_product_fee_note();
		$this->assertSame( '', (string) ob_get_clean() );

		unset( $GLOBALS['product'] );
	}

	/**
	 * It sits straight after the price on the product page and in the loop.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fee_note_hooks_follow_the_price(): void {
		foreach ( array( 'woocommerce_single_product_summary', 'woocommerce_after_shop_loop_item_title' ) as $tag ) {
			$this->assertArrayHasKey( 11, $GLOBALS['bl_test_hooks'][ $tag ], $tag );
			$this->assertSame( 'blueline_wc_render_product_fee_note', $GLOBALS['bl_test_hooks'][ $tag ][11][0]['cb'], $tag );
		}
	}

	/**
	 * The Register page gets a jump link ahead of its content, with the same
	 * price line the hero shows, built from the hero's own offer list.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_page_gets_a_jump_link_and_the_hero_price_line(): void {
		$this->arrange(
			array(
				'offers' => array(
					array(
						'role_label'  => 'Player',
						'price_label' => '$575.00',
					),
					array(
						'role_label'  => 'Goalie',
						'price_label' => '$170.00',
					),
				),
			)
		);
		blueline_test_register_post( 11113, 'publish', 'https://example.test/register' );
		blueline_test_set_queried_post_type( 'page' );
		$state                                 = &blueline_test_state();
		$state['queried_object_id']            = 11113;
		$state['posts'][11113]['post_content'] = '<p>Intro</p>[product_page sku="116522-P"]';

		$html = blueline_wc_register_jump_filter( '<p>Intro</p>' );

		$this->assertStringStartsWith( '<div class="bl-register-jump">', $html );
		$this->assertStringContainsString( 'href="#bl-choose-registration"', $html );
		$this->assertStringContainsString( 'Choose your registration', $html );
		$this->assertStringContainsString( 'Player $575.00 · Goalie $170.00', $html );
		$this->assertStringEndsWith( '<p>Intro</p>', $html );
	}

	/**
	 * No offers, no price line; the jump link still shows.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_jump_without_offers_has_no_price_line(): void {
		$this->arrange();
		blueline_test_register_post( 11113, 'publish', 'https://example.test/register' );
		blueline_test_set_queried_post_type( 'page' );
		$state                                 = &blueline_test_state();
		$state['queried_object_id']            = 11113;
		$state['posts'][11113]['post_content'] = '[product_page sku="116522-P"]';

		$html = blueline_wc_register_jump_filter( 'body' );

		$this->assertStringContainsString( 'Choose your registration', $html );
		$this->assertStringNotContainsString( 'bl-register-jump__prices', $html );
	}

	/**
	 * Pages with no embedded product, and pages that are not singular pages, are untouched.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_register_jump_only_on_pages_that_embed_products(): void {
		$this->arrange();
		blueline_test_register_post( 5, 'publish', 'https://example.test/about' );
		blueline_test_set_queried_post_type( 'page' );
		$state                             = &blueline_test_state();
		$state['queried_object_id']        = 5;
		$state['posts'][5]['post_content'] = 'Nothing to buy here';

		$this->assertSame( 'Nothing to buy here', blueline_wc_register_jump_filter( 'Nothing to buy here' ) );
	}

	/**
	 * The anchor the jump link targets is printed once, before the first card.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_anchor_target_is_printed_once_and_off_the_product_page(): void {
		$this->arrange();

		ob_start();
		blueline_wc_register_anchor_target();
		blueline_wc_register_anchor_target();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'id="bl-choose-registration"' ) );

		$this->arrange( array( 'product' => true ) );
		ob_start();
		blueline_wc_register_anchor_target();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The product page's "What's included" block: the owner's short
	 * description when there is one, the league's own one-payment line when
	 * there isn't, nothing for a waitlist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_whats_included_block(): void {
		$this->arrange(
			array(
				'product' => true,
				'season'  => array( 10, 11 ),
			)
		);

		$GLOBALS['product'] = new BluelineFunnelProduct( 11, '575', "Season runs Sept 25 to Mar 28.\n21 games." );
		ob_start();
		blueline_wc_render_included_block();
		$described = (string) ob_get_clean();

		$this->assertStringContainsString( 'What’s included', $described );
		$this->assertStringContainsString( 'Season runs Sept 25 to Mar 28.', $described );
		$this->assertStringContainsString( 'woocommerce-product-details__short-description', $described );

		$GLOBALS['product'] = new BluelineFunnelProduct( 11, '575' );
		ob_start();
		blueline_wc_render_included_block();
		$fallback = (string) ob_get_clean();

		$this->assertStringContainsString( 'jersey, socks, officials and ice time', $fallback );

		$GLOBALS['product'] = new BluelineFunnelProduct( 10, '0' );
		ob_start();
		blueline_wc_render_included_block();
		$this->assertSame( '', (string) ob_get_clean(), 'a waitlist with no short description prints nothing' );

		unset( $GLOBALS['product'] );
	}

	/**
	 * The /register embed keeps core's plain excerpt: no heading there.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_whats_included_is_for_the_product_page_only(): void {
		$this->arrange( array( 'product' => false ) );

		$GLOBALS['product'] = new BluelineFunnelProduct( 11, '575', 'Some text' );
		ob_start();
		blueline_wc_render_included_block();
		$this->assertSame( '', (string) ob_get_clean() );

		unset( $GLOBALS['product'] );
	}

	/**
	 * A real size for the box each image fills, so the browser stops guessing.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_product_image_sizes_match_the_css_box(): void {
		$this->arrange();

		$loop   = blueline_wc_product_image_sizes( 'woocommerce_thumbnail' );
		$single = blueline_wc_product_image_sizes( 'woocommerce_single' );

		$this->assertStringContainsString( 'calc(100vw', $loop );
		$this->assertStringNotContainsString( '200px', $loop );
		$this->assertStringContainsString( 'calc(100vw', $single );
		$this->assertStringNotContainsString( '200px', $single );
	}

	/**
	 * The main image on a product page is the page's LCP candidate.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_main_gallery_image_is_high_priority_on_the_product_page_only(): void {
		$this->arrange( array( 'product' => true ) );

		$main = blueline_wc_gallery_image_params( array( 'class' => 'wp-post-image' ), 5, 'woocommerce_single', true );
		$this->assertSame( 'high', $main['fetchpriority'] );
		$this->assertSame( 'eager', $main['loading'] );
		$this->assertSame( blueline_wc_product_image_sizes( 'woocommerce_single' ), $main['sizes'] );

		$thumb = blueline_wc_gallery_image_params( array(), 6, 'woocommerce_single', false );
		$this->assertArrayNotHasKey( 'fetchpriority', $thumb, 'only the first image is prioritised' );
		$this->assertSame( blueline_wc_product_image_sizes( 'woocommerce_single' ), $thumb['sizes'] );

		$GLOBALS['bl_test_funnel']['product'] = false;
		$this->assertSame( array( 'class' => 'x' ), blueline_wc_gallery_image_params( array( 'class' => 'x' ), 5, 'woocommerce_single', true ), 'off the product page the params are untouched' );
	}

	/**
	 * Only the first product image in an archive loop is prioritised.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_first_loop_image_is_prioritised_once(): void {
		$this->arrange( array( 'shop' => true ) );

		$first  = blueline_wc_loop_image_attributes();
		$second = blueline_wc_loop_image_attributes();

		$this->assertSame( 'high', $first['fetchpriority'] );
		$this->assertSame( 'eager', $first['loading'] );
		$this->assertArrayNotHasKey( 'fetchpriority', $second );
		$this->assertSame( blueline_wc_product_image_sizes( 'woocommerce_thumbnail' ), $second['sizes'] );

		$this->arrange(
			array(
				'product' => true,
				'shop'    => false,
			)
		);
		$related = blueline_wc_loop_image_attributes( true );
		$this->assertArrayNotHasKey( 'fetchpriority', $related, 'related products on a product page are below the fold' );
	}

	/**
	 * Newly generated product images are big enough for the box they fill.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_product_image_sizes_are_raised_for_new_uploads(): void {
		$this->arrange();

		$single = blueline_wc_image_size_single(
			array(
				'width'  => 200,
				'height' => '',
				'crop'   => 0,
			)
		);
		$this->assertSame( 640, $single['width'] );

		$thumb = blueline_wc_image_size_thumbnail(
			array(
				'width'  => 200,
				'height' => '',
				'crop'   => 0,
			)
		);
		$this->assertSame( 320, $thumb['width'] );

		$this->assertSame(
			800,
			blueline_wc_image_size_single(
				array(
					'width'  => 800,
					'height' => '',
					'crop'   => 0,
				)
			)['width'],
			'a larger owner-chosen size is kept'
		);
	}
}
