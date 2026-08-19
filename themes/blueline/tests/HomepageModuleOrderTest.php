<?php
/**
 * Covers blueline_homepage_module_order()'s consumption of Task 1's section
 * presence toggles: a module whose `module_*` toggle is off is dropped from
 * the render order, but never ALL of them -- WCAG 2.4.5 requires two ways to
 * find content, so an empty homepage is not a configuration a volunteer is
 * allowed to reach by unticking boxes. See the floor comment inline in
 * blueline_homepage_module_order() itself (inc/homepage-modules.php) for the
 * mechanism.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * Covers blueline_homepage_module_order()'s toggle-filtering and floor.
 */
final class HomepageModuleOrderTest extends TestCase {

	/**
	 * Reset every in-memory store this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * A module whose own `module_*` toggle is off is dropped from the order.
	 */
	public function test_disabled_modules_are_dropped(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'module_latest_news' => false ) );

		$this->assertNotContains( 'latest_news', blueline_homepage_module_order( 'in_season' ) );
	}

	/**
	 * Every module toggle off at once must not empty the homepage -- the
	 * floor keeps the first module of that state's own order.
	 */
	public function test_the_last_enabled_module_cannot_be_removed(): void {
		$all_off = array();
		foreach ( blueline_section_definitions() as $key => $unused ) {
			$all_off[ $key ] = false;
		}
		update_option( BLUELINE_SETTINGS_OPTION, $all_off );

		$order = blueline_homepage_module_order( 'in_season' );

		$this->assertNotEmpty( $order, 'an empty homepage is not a reachable configuration' );
	}

	/**
	 * The floor keeps the module the visitor would actually have seen FIRST,
	 * not the first entry of the raw order table.
	 *
	 * Those two readings coincide for most states, which is why
	 * test_the_last_enabled_module_cannot_be_removed() above cannot tell them
	 * apart -- it uses 'in_season', where no override applies. They diverge
	 * under registration_open + is_playing, the one combination the order
	 * matrix overrides (P1 finding 4): the raw table entry there starts with
	 * 'new_here', while the effective order starts with 'next_games'.
	 *
	 * blueline_homepage_module_order()'s own docblock argues this point at
	 * length and the Sections tab repeats it beside the four module_*
	 * checkboxes. Nothing pinned it: switching the floor to the raw table
	 * entry left the whole suite green, so the promise made to an admin in
	 * the panel was resting on a comment.
	 *
	 * @return void
	 */
	public function test_the_floor_keeps_the_module_the_visitor_would_have_seen_first(): void {
		$all_off = array();
		foreach ( blueline_section_definitions() as $key => $unused ) {
			$all_off[ $key ] = false;
		}
		update_option( BLUELINE_SETTINGS_OPTION, $all_off );

		$this->assertSame(
			array( 'next_games' ),
			blueline_homepage_module_order( 'registration_open', array( 'is_playing' => true ) ),
			'the floor must keep the first EFFECTIVE module, not the first raw-table one'
		);
	}

	/**
	 * Regression guard: the registration_open + is_playing early-return order
	 * (P1 finding 4) must survive the restructure into a single filtered
	 * exit untouched, when every toggle is left at its default (enabled).
	 */
	public function test_the_order_matrix_is_otherwise_untouched(): void {
		$this->assertSame(
			array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' ),
			blueline_homepage_module_order( 'registration_open', array( 'is_playing' => true ) )
		);
	}
}
