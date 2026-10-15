<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/announcement.php'; // blueline_site_timestamp(), which the override's expiry is resolved through.
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/homepage-modules.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers a live-review finding with zero prior test coverage: the header
 * CTA (blueline_header_cta()) called a function named
 * "blueline_homepage_registration_offer", singular -- which has never been
 * defined anywhere in this theme; the real function is
 * blueline_homepage_registration_offers() (plural, inc/homepage-modules.php).
 * function_exists() on the misspelled name is always false, so `&&`
 * short-circuited before ever calling it, and $show_register was always
 * false -- the header CTA showed "Schedule" in every season state,
 * INCLUDING while the homepage hero (which calls the real, plural function)
 * was showing "REGISTRATION OPEN" with real pricing and a working
 * "Register Now" link. Confirmed live on staging.
 *
 * The offers list needs wc_get_product(), which only the scripted stubs in
 * tests/fixtures/season-state-signal-stubs.php define. They cannot load into
 * the shared process (the season-state and homepage code branch on
 * function_exists()), so the tests that need a live offer run in their own
 * process; everywhere else the list is empty and the CTA falls back to
 * "Schedule", which is the right answer for "nothing to register for".
 */
final class HeaderCtaTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Remove any `blueline_season_state` filter a test added, so it cannot
	 * leak into a later test in the same process.
	 */
	protected function tearDown(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * Seed a registration season with one product tagged Featured, the tag the
	 * hero and this CTA sell from.
	 *
	 * @param array<int, array<int, mixed>> $products Product seeds, keyed by ID (see season-state-signal-stubs.php).
	 */
	private function seed_featured_offers( array $products ): void {
		require_once __DIR__ . '/fixtures/season-state-signal-stubs.php';

		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 300, 'product_cat', 'Fall 2026', 91 );
		blueline_test_register_term( 400, 'product_tag', 'Featured' );
		// Forced open (rather than computed) so the catalogue and schedule queries behind the state stay out of the way.
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'registration_term'           => 91,
				'season_state_override'       => 'registration_open',
				'season_state_override_until' => '2036-12-31',
			)
		);

		foreach ( array_keys( $products ) as $product_id ) {
			blueline_test_set_post_terms( $product_id, 'product_tag', array( 400 ) );
		}
		$GLOBALS['bl_test_wp_query_results'] = array( array_keys( $products ) );
		$GLOBALS['bl_test_wc_products']      = $products;
	}

	/**
	 * The regression this file exists for: the CTA once called a function that
	 * does not exist, so it said "Schedule" in every state, including while the
	 * hero was advertising registration with real prices. With a paid Featured
	 * product on sale and registration open, it offers registration.
	 */
	#[RunInSeparateProcess]
	public function test_registration_open_with_a_live_offer_shows_the_register_cta(): void {
		$this->seed_featured_offers( array( 21 => array( true, true, 'visible', '170' ) ) );

		$cta = blueline_header_cta();

		$this->assertSame( 'Register to Play', $cta['label'] );
		$this->assertSame( 'bl-btn--primary', $cta['class'] );
		$this->assertTrue( $cta['is_register'] );
		$this->assertFalse( $cta['is_schedule'] );
	}

	/**
	 * A Featured product the league has hidden from the catalogue is no offer,
	 * so registration being "open" still shows Schedule.
	 */
	#[RunInSeparateProcess]
	public function test_a_hidden_featured_product_does_not_make_the_register_cta(): void {
		$this->seed_featured_offers( array( 21 => array( true, true, 'hidden', '170' ) ) );

		$cta = blueline_header_cta();

		$this->assertSame( 'Schedule', $cta['label'] );
		$this->assertTrue( $cta['is_schedule'] );
	}

	/**
	 * With no live offers (the default in this stub environment, see the file docblock), 'registration_open' still falls back to the
	 * Schedule CTA, exactly like every other state. This is the CORRECT
	 * "nothing purchasable" behaviour, not the bug.
	 */
	public function test_registration_open_with_no_live_offers_falls_back_to_schedule(): void {
		add_filter( 'blueline_season_state', static fn() => 'registration_open' );

		$cta = blueline_header_cta();

		$this->assertSame( 'Schedule', $cta['label'] );
		$this->assertFalse( $cta['is_register'] );
		$this->assertTrue( $cta['is_schedule'] );
	}

	/**
	 * In season, preseason and playoffs show the Schedule CTA, with
	 * is_schedule true and is_register false -- the flags
	 * blueline_site_header() reads to drive the nav walker's de-dup.
	 */
	public function test_in_season_shows_schedule_cta_with_correct_flags(): void {
		add_filter( 'blueline_season_state', static fn() => 'in_season' );

		$cta = blueline_header_cta();

		$this->assertSame( 'Schedule', $cta['label'] );
		$this->assertSame( 'bl-btn--secondary', $cta['class'] );
		$this->assertFalse( $cta['is_register'] );
		$this->assertTrue( $cta['is_schedule'] );
	}

	/**
	 * Between seasons there are no games to schedule: the header makes the
	 * off-season hero's own offer (its label setting, the contact page),
	 * and neither de-dup flag is set, so the menu keeps every item.
	 */
	public function test_offseason_shows_the_mailing_list_offer(): void {
		add_filter( 'blueline_season_state', static fn() => 'offseason' );

		$cta = blueline_header_cta();

		$this->assertSame( blueline_settings( 'hero_offseason_cta' ), $cta['label'] );
		$this->assertSame( 'Join the mailing list', $cta['label'] );
		$this->assertSame( blueline_contact_url(), $cta['url'] );
		$this->assertSame( 'bl-btn--secondary', $cta['class'] );
		$this->assertFalse( $cta['is_register'] );
		$this->assertFalse( $cta['is_schedule'] );
	}

	/**
	 * The is_register and is_schedule flags are mutually exclusive in every
	 * state that has games -- exactly one of those two CTAs is showing.
	 */
	public function test_is_register_and_is_schedule_are_mutually_exclusive(): void {
		foreach ( array( 'registration_open', 'preseason', 'in_season', 'playoffs' ) as $state ) {
			add_filter( 'blueline_season_state', static fn() => $state );

			$cta = blueline_header_cta();

			$this->assertNotSame(
				$cta['is_register'],
				$cta['is_schedule'],
				"state \"$state\" must show exactly one of Register/Schedule, never both or neither"
			);

			blueline_test_reset_hooks();
		}
	}
}
