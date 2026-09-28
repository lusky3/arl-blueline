<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * This file carries two object structures -- BluelineFakeProduct (a stub)
 * alongside the RegistrationPricingTest case itself -- the same trade-off
 * tests/bootstrap.php makes for its own WordPress stand-ins (Walker_Nav_Menu,
 * WP_Error): a one-off stub of an external library's class has nowhere more
 * useful to live than beside the one test file that needs it. Both
 * file-organisation sniffs are disabled (not ignored) for exactly that
 * reason, per bootstrap.php's identical precedent.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the FileName disable above.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * A minimal stand-in for WC_Product -- only the three methods this suite's
 * code under test ever calls (get_id(), get_name(), get_price()). No real
 * WooCommerce class is loadable in this stub environment (see
 * tests/bootstrap.php and HeroEffectiveStateTest.php's own note on why), so
 * every function tested here must be reachable with nothing more than this.
 */
final class BluelineFakeProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Product title.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Raw price string, WooCommerce's own representation.
	 *
	 * @var string
	 */
	private $price;

	/**
	 * Constructor.
	 *
	 * @param int    $id    Product ID.
	 * @param string $name  Product title.
	 * @param string $price Raw price string, WooCommerce's own representation.
	 */
	public function __construct( int $id, string $name, string $price ) {
		$this->id    = $id;
		$this->name  = $name;
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
	 * Product title.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
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
 * P0 finding 1: the homepage hero used to read a single product ID off
 * Season State ("the first purchasable product in the season's category,
 * by post ID") and print its price on the primary Register button. On this
 * site that resolved to a $145 Goalie Registration outranking a $550 Player
 * Registration, so the button read "Register — $145.00" while the visitor
 * it was aimed at actually owed $550 at checkout.
 *
 * The fix pulls the decision that mattered -- "one price on the button, or
 * a breakdown, given the set of products actually on offer" -- into a pure
 * function (blueline_homepage_registration_cta_pricing()) that never
 * touches WooCommerce, so it is directly testable with plain arrays rather
 * than a real WC_Product. This suite covers that function plus the two
 * thin wrappers around it (the role-label fallback, and the hero content
 * builder that consumes both).
 */
final class RegistrationPricingTest extends TestCase {

	/**
	 * Reset the shared fake-WordPress state before every test, so no test's
	 * registered taxonomies/post types can leak into another's.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * A single offer (by far the common case: one product on sale) puts
	 * that exact price on the CTA and has no breakdown line -- unchanged
	 * from the pre-fix behaviour for the case that was never buggy.
	 */
	public function test_single_offer_puts_its_price_on_the_cta(): void {
		$pricing = blueline_homepage_registration_cta_pricing(
			array(
				array(
					'price_label' => '$550.00',
					'role_label'  => 'Player',
				),
			)
		);

		$this->assertSame( '$550.00', $pricing['cta_price_label'] );
		$this->assertSame( '', $pricing['price_breakdown'] );
	}

	/**
	 * Multiple offers that all happen to share one price are exactly as
	 * unambiguous as a single offer -- still a bare CTA price, no breakdown.
	 */
	public function test_multiple_offers_at_the_same_price_still_use_a_single_cta_price(): void {
		$pricing = blueline_homepage_registration_cta_pricing(
			array(
				array(
					'price_label' => '$100.00',
					'role_label'  => 'Player',
				),
				array(
					'price_label' => '$100.00',
					'role_label'  => 'Goalie',
				),
			)
		);

		$this->assertSame( '$100.00', $pricing['cta_price_label'] );
		$this->assertSame( '', $pricing['price_breakdown'] );
	}

	/**
	 * The exact bug: a Goalie offer and a Player offer at different prices
	 * must NEVER put either one alone on the CTA -- the fix drops the price
	 * from the button entirely and puts both in an honest breakdown,
	 * highest first, so the visitor never meets a different number than the
	 * one the button advertised.
	 */
	public function test_offers_at_different_prices_drop_the_cta_price_and_list_both(): void {
		$pricing = blueline_homepage_registration_cta_pricing(
			array(
				array(
					'price_label' => '$550.00',
					'role_label'  => 'Player',
				),
				array(
					'price_label' => '$145.00',
					'role_label'  => 'Goalie',
				),
			)
		);

		$this->assertSame( '', $pricing['cta_price_label'] );
		$this->assertSame( 'Player $550.00 · Goalie $145.00', $pricing['price_breakdown'] );
	}

	/**
	 * An offer with no resolvable role label (no product_tag, unparseable
	 * title) still contributes its price to the breakdown -- just without a
	 * label -- rather than silently disappearing from the total picture.
	 */
	public function test_breakdown_tolerates_a_missing_role_label(): void {
		$pricing = blueline_homepage_registration_cta_pricing(
			array(
				array(
					'price_label' => '$550.00',
					'role_label'  => '',
				),
				array(
					'price_label' => '$145.00',
					'role_label'  => 'Goalie',
				),
			)
		);

		$this->assertSame( '$550.00 · Goalie $145.00', $pricing['price_breakdown'] );
	}

	/**
	 * No offers at all (every product failed live re-verification) must not
	 * fabricate a price from nothing.
	 */
	public function test_no_offers_yields_no_price_and_no_breakdown(): void {
		$pricing = blueline_homepage_registration_cta_pricing( array() );

		$this->assertSame( '', $pricing['cta_price_label'] );
		$this->assertSame( '', $pricing['price_breakdown'] );
	}

	/**
	 * With no product_tag term available (the common path in this stub
	 * environment, and a legitimate real-world case for an untagged
	 * product), the role label falls back to the product's own title with a
	 * trailing "(Season Label)" parenthetical stripped -- not the literal
	 * title, and never a hardcoded "Player"/"Goalie" string.
	 */
	public function test_role_label_falls_back_to_title_with_season_suffix_stripped(): void {
		$product = new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '550' );

		$this->assertSame( 'Player Registration', blueline_homepage_registration_offer_role_label( $product ) );
	}

	/**
	 * A title with no trailing parenthetical is used verbatim -- the strip
	 * is a no-op, not a truncation.
	 */
	public function test_role_label_falls_back_to_full_title_when_nothing_to_strip(): void {
		$product = new BluelineFakeProduct( 1, 'Roster Addition', '35' );

		$this->assertSame( 'Roster Addition', blueline_homepage_registration_offer_role_label( $product ) );
	}

	/**
	 * A product carrying more than one product_tag term (real-world case:
	 * a $0.00 waitlist product tagged both its role, "Player", AND
	 * "Waitlist") gets a label built from EVERY tag, not just the first --
	 * "Player Waitlist", not merely "Player". Taking only tags[0] made this
	 * waitlist offer's role label identical to the paid "Player
	 * Registration" offer's, so the price breakdown showed two "Player"
	 * lines at different prices with no way to tell them apart.
	 */
	public function test_role_label_joins_every_tag_not_just_the_first(): void {
		blueline_test_register_term( 300, 'product_tag', 'Player' );
		blueline_test_register_term( 301, 'product_tag', 'Waitlist' );
		blueline_test_set_post_terms( 117220, 'product_tag', array( 300, 301 ) );

		$product = new BluelineFakeProduct( 117220, 'Player Waitlist (W2026-27)', '0' );

		$this->assertSame( 'Player Waitlist', blueline_homepage_registration_offer_role_label( $product ) );
	}

	/**
	 * The exact bug this filtering exists to prevent: a Featured product is
	 * also tagged its own role ("Player"), and the join must produce
	 * "Player", never "Featured Player" -- "Featured" is an internal
	 * filtering marker for blueline_homepage_registration_offers(), not a
	 * display-facing role word.
	 */
	public function test_role_label_omits_the_featured_filtering_tag(): void {
		blueline_test_register_term( 300, 'product_tag', 'Player' );
		blueline_test_register_term( 302, 'product_tag', 'Featured' );
		blueline_test_set_post_terms( 116522, 'product_tag', array( 300, 302 ) );

		$product = new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '575' );

		$this->assertSame( 'Player', blueline_homepage_registration_offer_role_label( $product ) );
	}

	/**
	 * A product carrying the exact tag name is recognised regardless of
	 * what else it's also tagged (real-world case: a Featured product also
	 * carries its own role tag, "Player" or "Goalie").
	 */
	public function test_product_has_tag_matches_among_several(): void {
		blueline_test_register_term( 300, 'product_tag', 'Player' );
		blueline_test_register_term( 302, 'product_tag', 'Featured' );
		blueline_test_set_post_terms( 116522, 'product_tag', array( 300, 302 ) );

		$this->assertTrue( blueline_product_has_tag( 116522, 'Featured' ) );
	}

	/**
	 * A product tagged with something else entirely (real-world case: a
	 * Waitlist product, tagged its role plus "Waitlist" but never
	 * "Featured") does not match.
	 */
	public function test_product_has_tag_does_not_match_when_absent(): void {
		blueline_test_register_term( 300, 'product_tag', 'Player' );
		blueline_test_register_term( 301, 'product_tag', 'Waitlist' );
		blueline_test_set_post_terms( 117220, 'product_tag', array( 300, 301 ) );

		$this->assertFalse( blueline_product_has_tag( 117220, 'Featured' ) );
	}

	/**
	 * A product with no product_tag terms at all (real-world case: the
	 * $999.99 late-registration surcharge product, SKU 117085-LR-1, tagged
	 * only "Player") does not spuriously match.
	 */
	public function test_product_has_tag_returns_false_with_no_terms(): void {
		$this->assertFalse( blueline_product_has_tag( 117085, 'Featured' ) );
	}

	/**
	 * End-to-end (still without a real WC_Product): the hero content builder
	 * puts the single price on the CTA when every offer agrees, and no
	 * price-breakdown subcopy line appears.
	 */
	public function test_hero_content_uses_bare_cta_price_when_offers_agree(): void {
		$offers = array(
			array(
				'product'     => new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '550' ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
		);

		$content = blueline_homepage_hero_registration_content( $offers, array() );

		$this->assertSame( 'Register — $550.00', $content['cta_label'] );
		$this->assertSame( array(), $content['subcopy_lines'] );
	}

	/**
	 * The exact P0 bug, end-to-end: Player ($550) and Goalie ($145) offers
	 * together must produce a CTA with NO price and a subcopy line naming
	 * both -- never "Register — $145.00" while a skater owes $550.
	 */
	public function test_hero_content_drops_cta_price_and_shows_breakdown_when_offers_disagree(): void {
		$offers = array(
			array(
				'product'     => new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '550' ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
			array(
				'product'     => new BluelineFakeProduct( 116523, 'Goalie Registration (W2026-27)', '145' ),
				'price_label' => '$145.00',
				'price'       => 145.0,
				'role_label'  => 'Goalie',
			),
		);

		$content = blueline_homepage_hero_registration_content( $offers, array() );

		$this->assertSame( 'Register now', $content['cta_label'] );
		$this->assertSame( array( 'Player $550.00 · Goalie $145.00' ), $content['subcopy_lines'] );
	}

	/**
	 * P1 finding 4: when registration is open AND the season is already
	 * being played, the hero must carry both the price breakdown/CTA AND a
	 * "next game" line -- neither one masks the other.
	 */
	public function test_hero_content_adds_next_game_line_when_playing_and_registration_open(): void {
		$offers = array(
			array(
				'product'     => new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '550' ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
		);

		$content = blueline_homepage_hero_registration_content(
			$offers,
			array(
				'is_playing'    => true,
				'next_event_id' => 42,
			)
		);

		// get_the_date() is stubbed to a fixed value in tests/bootstrap.php,
		// and sp_venue is not a registered taxonomy in this test's state, so
		// the venue-less branch of blueline_homepage_next_event_line() is
		// what's exercised here.
		$this->assertSame( array( 'Next game: Aug 20' ), $content['subcopy_lines'] );
	}

	/**
	 * The next-game line must NOT appear when registration is open but the
	 * season is not currently being played (the ordinary preseason-selling
	 * case) -- there is no "your next game" to answer yet.
	 */
	public function test_hero_content_omits_next_game_line_when_not_playing(): void {
		$offers = array(
			array(
				'product'     => new BluelineFakeProduct( 116522, 'Player Registration (W2026-27)', '550' ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
		);

		$content = blueline_homepage_hero_registration_content(
			$offers,
			array(
				'is_playing'    => false,
				'next_event_id' => 42,
			)
		);

		$this->assertSame( array(), $content['subcopy_lines'] );
	}
}
