<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * Covers the Task 7 regression this suite had ZERO durable coverage for
 * (Task 16 item d): blueline_homepage_hero_content() can be asked for
 * 'registration_open' and, because its own live re-check of the driving
 * product fails, actually render preseason or offseason content instead.
 * The bug was that the caller printed the REQUESTED state's class while the
 * copy came from the FALLBACK state -- a card whose class said
 * "registration_open" but whose words said "Puck drops soon." The fix makes
 * the function return the EFFECTIVE state it actually rendered so the class,
 * the copy, and (via blueline_homepage_module_order()) the module order
 * all agree.
 *
 * Note: wc_get_product() is not defined in this stub environment (see
 * tests/bootstrap.php), so blueline_homepage_registration_offer() always
 * returns null here -- every 'registration_open' request in this file
 * exercises the fallback path, which is exactly the path that shipped
 * broken. A live WooCommerce environment additionally exercises the
 * "offer found" branch; that is covered by the staging smoke pass, not a
 * unit test, since it requires a real WC_Product.
 */
final class HeroEffectiveStateTest extends TestCase {

	/**
	 * A stale/incorrect registration_open request (product no longer
	 * purchasable) with a known upcoming event must fall back to preseason
	 * -- class, copy AND the caller's module order must all read preseason,
	 * never a mix of the two states.
	 */
	public function test_registration_open_with_upcoming_event_falls_back_to_preseason_throughout(): void {
		$state_data = array( 'next_event_id' => 42 );

		$content = blueline_homepage_hero_content( 'registration_open', $state_data );

		$this->assertSame( 'preseason', $content['state'] );
		$this->assertSame(
			blueline_homepage_hero_preseason_content( $state_data ),
			array_diff_key( $content, array( 'state' => null ) ),
			'fallback copy must be byte-identical to calling the preseason content function directly'
		);

		$modules = blueline_homepage_module_order( $content['state'] );
		$this->assertSame( blueline_homepage_module_order( 'preseason' ), $modules );
		$this->assertNotSame(
			blueline_homepage_module_order( 'registration_open' ),
			$modules,
			'the requested state\'s module order must NOT be used once the content has fallen back'
		);
	}

	/**
	 * The same stale request with no upcoming event at all must fall back
	 * to offseason, not preseason -- there is nothing to be "pre" about.
	 */
	public function test_registration_open_with_no_upcoming_event_falls_back_to_offseason_throughout(): void {
		$content = blueline_homepage_hero_content( 'registration_open', array() );

		$this->assertSame( 'offseason', $content['state'] );
		$this->assertSame( blueline_homepage_hero_offseason_content(), array_diff_key( $content, array( 'state' => null ) ) );
		$this->assertSame( blueline_homepage_module_order( 'offseason' ), blueline_homepage_module_order( $content['state'] ) );
	}

	/**
	 * A genuinely requested preseason/in_season/playoffs state must pass
	 * through unchanged -- the effective state always equals the requested
	 * one when there is no registration_open re-check to fail.
	 */
	public function test_non_registration_states_pass_through_as_their_own_effective_state(): void {
		foreach ( array( 'preseason', 'in_season', 'playoffs', 'offseason' ) as $state ) {
			$content = blueline_homepage_hero_content( $state, array( 'next_event_id' => 7 ) );
			$this->assertSame( $state, $content['state'], "requesting $state must not silently change the effective state" );
		}
	}

	/**
	 * The module renderer's dispatch table (blueline_render_module()) and
	 * blueline_homepage_module_order() must never disagree on a module
	 * name -- an order naming a module the renderer doesn't know would
	 * silently render nothing for it.
	 */
	public function test_every_ordered_module_name_is_a_real_renderable_module(): void {
		$known_modules = array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' );

		foreach ( array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' ) as $state ) {
			foreach ( blueline_homepage_module_order( $state ) as $module ) {
				$this->assertContains( $module, $known_modules, "state $state orders unknown module $module" );
			}
		}
	}
}
