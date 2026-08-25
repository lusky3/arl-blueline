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
require_once __DIR__ . '/../inc/season-state.php';
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
 * The function blueline_homepage_registration_offers() itself needs
 * wc_get_product(), which is not defined in this stub environment (see
 * tests/bootstrap.php and HeroEffectiveStateTest.php's identical note) --
 * so it always returns an empty array here, and every test below that asks for
 * 'registration_open' still observes the CTA fall back to "Schedule". That
 * fallback is the CORRECT behaviour for "no live offers", not the bug --
 * the bug was calling a name that could never resolve to true regardless of
 * season state or catalogue. The source-scan test is what actually pins
 * the fix: it fails immediately if the misspelled singular name is ever
 * reintroduced, which no behavioural assertion here could otherwise catch
 * without a real WC_Product (out of scope, per HeroEffectiveStateTest.php's
 * own precedent -- that path is covered by the staging smoke pass and
 * RegistrationPricingTest.php's pure-logic coverage instead).
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
	 * The exact regression, pinned at the source level: blueline_header_cta()
	 * must call the real, PLURAL blueline_homepage_registration_offers(),
	 * and must never call a function named blueline_homepage_registration_offer
	 * (singular) -- which does not exist and never has. A behavioural
	 * assertion cannot distinguish "calls the real function, which returned
	 * no offers" from "calls a nonexistent function, which could never
	 * return offers" without a real WC_Product (see this file's own
	 * docblock), so this reads the actual source instead.
	 */
	public function test_header_cta_calls_the_real_plural_offers_function(): void {
		$src = (string) file_get_contents( __DIR__ . '/../inc/template-tags.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString(
			'blueline_homepage_registration_offers(',
			$src,
			'blueline_header_cta() must call the real, plural offers() function'
		);
		$this->assertStringNotContainsString(
			'blueline_homepage_registration_offer(',
			$src,
			'the misspelled singular function name must never reappear -- it has never been defined, so function_exists() on it is always false and silently disables the Register CTA in every state'
		);
	}

	/**
	 * With no live offers (the only reachable case in this stub environment
	 * -- see the file docblock), 'registration_open' still falls back to the
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
	 * Every non-registration_open state must show the Schedule CTA, with
	 * is_schedule true and is_register false -- the flags
	 * blueline_site_header() reads to drive the nav walker's de-dup.
	 */
	public function test_offseason_shows_schedule_cta_with_correct_flags(): void {
		add_filter( 'blueline_season_state', static fn() => 'offseason' );

		$cta = blueline_header_cta();

		$this->assertSame( 'Schedule', $cta['label'] );
		$this->assertSame( 'bl-btn--secondary', $cta['class'] );
		$this->assertFalse( $cta['is_register'] );
		$this->assertTrue( $cta['is_schedule'] );
	}

	/**
	 * The is_register and is_schedule flags are always mutually exclusive -- exactly
	 * one CTA is ever showing.
	 */
	public function test_is_register_and_is_schedule_are_mutually_exclusive(): void {
		foreach ( array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' ) as $state ) {
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
