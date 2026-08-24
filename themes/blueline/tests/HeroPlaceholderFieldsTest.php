<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- same trade-off as tests/bootstrap.php's identical disable: this file's two term-lookup stubs and its one fixture class are all one-off stand-ins for this file's own tests, with nowhere more useful to live than beside them.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as RegistrationPricingTest.php's identical disable: a one-off fixture class has nowhere more useful to live than beside the one test file that needs it.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/homepage-modules.php';

// wp_get_post_terms() is no longer stubbed here -- tests/bootstrap.php now
// provides a shared stand-in (added for Task 9's registration-term
// resolver tests), backed by blueline_test_register_term()/
// blueline_test_set_post_terms() rather than this file's own
// $GLOBALS['bl_test_post_terms']. wp_get_object_terms() below is still
// this file's own: nothing else in the suite needs it.
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_get_object_terms() -- unlike
	 * wp_get_post_terms() above, nothing else in this suite is stubbed
	 * anywhere else, so this file still carries its own. Returns whatever a
	 * test registered for ($object_id, $taxonomy) in
	 * $GLOBALS['bl_test_object_terms'], or an empty array. The real call
	 * site (blueline_homepage_event_season_label()) always passes
	 * 'fields' => 'names', so this always hands back plain strings.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy name.
	 * @param array  $args      Unused; kept for signature parity with WP core.
	 * @return array
	 */
	function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; this stub's one caller never varies $args.
		return $GLOBALS['bl_test_object_terms'][ $taxonomy ][ (int) $object_id ] ?? array();
	}
}

/**
 * A minimal stand-in for WC_Product -- only get_id() is ever called on it
 * by the code paths this file exercises. Deliberately a distinct class from
 * RegistrationPricingTest.php's BluelineFakeProduct (that one is `final`
 * inside its own file) rather than requiring that whole test file just to
 * reuse a three-method fixture.
 */
final class BluelineHeroFieldFakeProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param int $id Product ID.
	 */
	public function __construct( int $id ) {
		$this->id = $id;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}
}

/**
 * Task 8: the seven hero-copy fields whose stored value becomes a raw
 * sprintf()/printf() format string at its call site in
 * inc/homepage-modules.php -- the highest-risk field type this control
 * panel exposes, per the Task 8 brief. Three things per field, each its own
 * group of tests below:
 *
 * 1. The schema's declared `placeholders` contract matches the exact
 *    literal it replaced, verified against the real call site's sprintf()
 *    argument count and order (not merely assumed from the brief's
 *    inventory table -- see each call site in inc/homepage-modules.php).
 * 2. The Task 3 validator (blueline_sanitize_field()) actually rejects a
 *    dropped placeholder and a stray "%" for these specific fields, with
 *    the actionable message a volunteer needs to fix it.
 * 3. Swapping the hardcoded literal for blueline_settings( $key ) produced
 *    byte-identical rendered output for the field's own default value --
 *    a rendering regression here would be silent on the live site.
 */
final class HeroPlaceholderFieldsTest extends TestCase {

	/**
	 * Reset the shared fake-WordPress state (including the shared
	 * post-terms store wp_get_post_terms() now reads from), plus this
	 * file's own object-terms stub store, before every test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_object_terms'] = array();
	}

	/**
	 * The seven fields and their exact contract, verified against the real
	 * call site each replaces:
	 *
	 * - hero_registration_headline: blueline_hero_headline( $format, 'beginner' )
	 *   -- one substitution -- %s.
	 * - hero_registration_eyebrow: sprintf( $format, $season ) -- one -- %s.
	 * - hero_registration_cta: sprintf( $format, $price_label ) -- one -- %s.
	 * - hero_preseason_headline: blueline_hero_headline( $format, $date )
	 *   -- one -- %s.
	 * - hero_in_season_headline: sprintf( $format, $highlighted_count, $game_word )
	 *   -- two, positional in the order they're passed -- %1$s, %2$s.
	 * - hero_playoffs_eyebrow: sprintf( $format, $season ) -- one -- %s.
	 * - hero_offseason_headline: blueline_hero_headline( $format, 'soon' )
	 *   -- one -- %s.
	 *
	 * @return array<string, string[]>
	 */
	private function field_contracts(): array {
		return array(
			'hero_registration_headline' => array( '%s' ),
			'hero_registration_eyebrow'  => array( '%s' ),
			'hero_registration_cta'      => array( '%s' ),
			'hero_preseason_headline'    => array( '%s' ),
			'hero_in_season_headline'    => array( '%1$s', '%2$s' ),
			'hero_playoffs_eyebrow'      => array( '%s' ),
			'hero_offseason_headline'    => array( '%s' ),
		);
	}

	/**
	 * Every field named in the Task 8 brief's inventory exists in the
	 * schema, as a `text` field on the `content` tab, declaring exactly the
	 * verified contract, with a non-empty label.
	 */
	public function test_each_hero_field_declares_its_verified_contract(): void {
		$schema = blueline_settings_schema();

		foreach ( $this->field_contracts() as $key => $placeholders ) {
			$this->assertArrayHasKey( $key, $schema, "$key is missing from the schema" );
			$this->assertSame( 'text', $schema[ $key ]['type'], "$key must be a text field" );
			$this->assertSame( 'content', $schema[ $key ]['tab'], "$key must be on the content tab" );
			$this->assertNotEmpty( $schema[ $key ]['label'] ?? '', "$key must declare a label" );
			$this->assertSame(
				$placeholders,
				$schema[ $key ]['placeholders'],
				"$key's declared contract does not match its verified call site"
			);
		}
	}

	/**
	 * Every field's label (not just its `placeholders` array) must itself
	 * name every conversion spec the field requires -- a volunteer edits the
	 * label text, not the schema array, so the explanation of what a
	 * placeholder becomes has to live where they can actually read it.
	 */
	public function test_each_hero_field_label_names_its_own_placeholders(): void {
		$schema = blueline_settings_schema();

		foreach ( $this->field_contracts() as $key => $placeholders ) {
			foreach ( $placeholders as $spec ) {
				$this->assertStringContainsString(
					$spec,
					$schema[ $key ]['label'],
					"$key's label does not mention its own required placeholder $spec"
				);
			}
		}
	}

	/**
	 * Each field's default is the exact literal it replaced, byte-for-byte
	 * -- a drifted default is either a silent copy change or a contract that
	 * would fail validation on its own first save.
	 */
	public function test_each_hero_field_default_is_the_original_literal(): void {
		$defaults = blueline_settings_defaults();

		$expected = array(
			'hero_registration_headline' => 'Burlington’s %s league.',
			'hero_registration_eyebrow'  => '%s · Registration open',
			'hero_registration_cta'      => 'Register — %s',
			'hero_preseason_headline'    => 'Puck drops %s.',
			'hero_in_season_headline'    => '%1$s %2$s this week.',
			'hero_playoffs_eyebrow'      => '%s · Playoffs',
			'hero_offseason_headline'    => 'Back on the ice %s.',
		);

		foreach ( $expected as $key => $literal ) {
			$this->assertSame( $literal, $defaults[ $key ], "$key's default drifted from the literal it replaced" );
		}
	}

	/**
	 * Guard proof #1: dropping the required %s from the registration
	 * headline is rejected, not silently saved. Verified on this runtime:
	 * blueline_hero_headline() always calls sprintf() with exactly one
	 * argument (the highlighted word), and PHP's sprintf() does NOT throw
	 * when a format string consumes fewer placeholders than arguments
	 * supplied -- it silently ignores the extra one. So the real-world harm
	 * of this specific drop is not a fatal, it is silent: the highlighted
	 * word this field exists to carry (e.g. "beginner") would vanish from
	 * the rendered headline with no error anywhere, which is exactly the
	 * "instantly-recoverable false positive traded against a silent failure"
	 * choice inc/settings/sanitize.php's own docblock describes -- reject
	 * loudly at save time rather than let content quietly disappear on the
	 * live page.
	 */
	public function test_dropping_the_required_placeholder_is_rejected_with_an_actionable_message(): void {
		$field  = blueline_settings_schema()['hero_registration_headline'];
		$result = blueline_sanitize_field( 'Burlington’s beginner league.', $field );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_placeholder_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( '%s', $result->get_error_message() );
		$this->assertStringContainsString( 'which is missing', $result->get_error_message() );
	}

	/**
	 * Guard proof #2: an ordinary stray "%" in the two-placeholder in-season
	 * headline is rejected, not silently saved and later mangled or fatal at
	 * the real sprintf() call site -- verified on this runtime (see
	 * inc/settings/sanitize.php's own docblock).
	 */
	public function test_a_stray_percent_is_rejected_with_an_actionable_message(): void {
		$field  = blueline_settings_schema()['hero_in_season_headline'];
		$result = blueline_sanitize_field( '%1$s %2$s this week, save 10%!', $field );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_unsafe_format_specifier', $result->get_error_code() );
		$this->assertStringContainsString( '"%', $result->get_error_message() );
	}

	/**
	 * A value that keeps every required placeholder is accepted -- the
	 * guard is not simply refusing every field submission wholesale.
	 */
	public function test_a_value_preserving_the_contract_is_accepted(): void {
		$field  = blueline_settings_schema()['hero_registration_headline'];
		$result = blueline_sanitize_field( 'Rookie Hockey’s %s league.', $field );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'Rookie Hockey’s %s league.', $result );
	}

	/**
	 * Rendering regression check: the registration hero's headline and
	 * (single-offer, agreeing-price) CTA are byte-identical to what the
	 * hardcoded literals produced before this task.
	 */
	public function test_registration_headline_and_cta_render_unchanged_with_the_default_value(): void {
		$offers = array(
			array(
				'product'     => new BluelineHeroFieldFakeProduct( 1 ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
		);

		$content = blueline_homepage_hero_registration_content( $offers, array() );

		$this->assertSame(
			'Burlington’s <span class="bl-hero__highlight">beginner</span> league.',
			$content['headline_html']
		);
		$this->assertSame( 'Register — $550.00', $content['cta_label'] );
	}

	/**
	 * Rendering regression check: the registration eyebrow's season-truthy
	 * branch (the one that actually reaches hero_registration_eyebrow) is
	 * byte-identical to what the hardcoded literal produced before this
	 * task. Since Task 9, blueline_homepage_registration_season_label()
	 * resolves the registration term through
	 * blueline_resolve_registration_term() rather than reading
	 * BLUELINE_REGISTRATION_TERM_ID directly, so the fixture registers a
	 * real, verifiable parent term (91, the schema's documented fallback --
	 * no `registration_term` setting is configured here) and a real child
	 * season term, via tests/bootstrap.php's shared
	 * blueline_test_register_term()/blueline_test_set_post_terms() helpers.
	 */
	public function test_registration_eyebrow_renders_unchanged_with_a_resolved_season(): void {
		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 300, 'product_cat', 'Winter 2026-27', 91 );
		blueline_test_set_post_terms( 7, 'product_cat', array( 300 ) );

		$offers = array(
			array(
				'product'     => new BluelineHeroFieldFakeProduct( 7 ),
				'price_label' => '$550.00',
				'price'       => 550.0,
				'role_label'  => 'Player',
			),
		);

		$content = blueline_homepage_hero_registration_content( $offers, array() );

		$this->assertSame( 'Winter 2026-27 · Registration open', $content['eyebrow'] );
	}

	/**
	 * Rendering regression check: the preseason headline is byte-identical
	 * to what the hardcoded literal produced before this task. get_the_date()
	 * is a fixed stub in tests/bootstrap.php (always returns 'Aug 20'
	 * regardless of $format/$post), so that literal string is what the real
	 * call site would have substituted before this change too.
	 */
	public function test_preseason_headline_renders_unchanged_with_the_default_value(): void {
		$content = blueline_homepage_hero_preseason_content( array( 'next_event_id' => 42 ) );

		$this->assertSame(
			'Puck drops <span class="bl-hero__highlight">Aug 20</span>.',
			$content['headline_html']
		);
	}

	/**
	 * Rendering regression check: the in-season headline is byte-identical
	 * to what the hardcoded literal produced before this task. sp_event is
	 * never registered as a post type in this suite's default state, so
	 * blueline_homepage_games_this_week() (gated on post_type_exists())
	 * returns 0, and _n( 'game', 'games', 0, ... ) resolves to the plural.
	 */
	public function test_in_season_headline_renders_unchanged_with_the_default_value(): void {
		$content = blueline_homepage_hero_in_season_content( array( 'next_event_id' => 42 ) );

		$this->assertSame(
			'<span class="bl-hero__highlight">0</span> games this week.',
			$content['headline_html']
		);
	}

	/**
	 * Rendering regression check: the playoffs eyebrow's season-truthy
	 * branch (the one that actually reaches hero_playoffs_eyebrow) is
	 * byte-identical to what the hardcoded literal produced before this
	 * task. Requires this file's own wp_get_object_terms() stub -- see its
	 * docblock -- since no other test in this suite ever registers
	 * 'sp_season'.
	 */
	public function test_playoffs_eyebrow_renders_unchanged_with_a_resolved_season(): void {
		$state                 = &blueline_test_state();
		$state['taxonomies'][] = 'sp_season';

		$GLOBALS['bl_test_object_terms']['sp_season'][42] = array( 'S2026' );

		$content = blueline_homepage_hero_playoffs_content( array( 'next_event_id' => 42 ) );

		$this->assertSame( 'S2026 · Playoffs', $content['eyebrow'] );
	}

	/**
	 * Rendering regression check: the off-season headline is byte-identical
	 * to what the hardcoded literal produced before this task.
	 */
	public function test_offseason_headline_renders_unchanged_with_the_default_value(): void {
		$content = blueline_homepage_hero_offseason_content();

		$this->assertSame(
			'Back on the ice <span class="bl-hero__highlight">soon</span>.',
			$content['headline_html']
		);
	}
}
