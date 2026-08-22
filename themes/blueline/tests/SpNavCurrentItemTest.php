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
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Covers a live-review finding with zero prior test coverage: on any
 * SportsPress singular view (a team, player, or staff page, or a single
 * event page) EVERY top-level primary-menu item rendered identically -- no
 * "you are here" -- even though nav.css already has working CSS for it
 * (keyed off aria-current="page", which Blueline_Nav_Walker only ever sets
 * from the 'current-menu-item'/'current_page_item' classes). WordPress's
 * own current-menu-item detection has no notion that a singular sp_team
 * page and the "Rosters / Stats" Page are related, so it never sets either
 * class there. blueline_sp_primary_nav_current_item() (a wp_nav_menu_objects
 * filter, inc/sportspress.php) adds them to the correct top-level item.
 */
final class SpNavCurrentItemTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_is_singular_post_type'] = '';
		$GLOBALS['bl_test_nav_menu_assignments']  = array();
		$GLOBALS['bl_test_nav_menu_items']        = array();
	}

	/**
	 * Builds a fake top-level, childless nav menu item, matching the shape
	 * wp_nav_menu_objects filters receive.
	 *
	 * @param string $url   Resolved item URL.
	 * @param array  $classes Existing classes.
	 * @return object
	 */
	private function menu_item( string $url, array $classes = array() ) {
		return (object) array(
			'ID'               => 501,
			'title'            => 'Item',
			'url'              => $url,
			'classes'          => $classes,
			'menu_item_parent' => 0,
		);
	}

	/**
	 * Args object for a 'primary' location wp_nav_menu_objects call.
	 *
	 * @param string $location Theme location.
	 * @return stdClass
	 */
	private function args( string $location = 'primary' ) {
		return (object) array( 'theme_location' => $location );
	}

	/**
	 * On an ordinary page/post (no simulated singular SportsPress type),
	 * the filter must leave every item completely untouched -- WordPress's
	 * own detection already handles that case correctly.
	 */
	public function test_ordinary_page_is_left_untouched(): void {
		$items = array( $this->menu_item( 'https://example.test/news' ) );

		$result = blueline_sp_primary_nav_current_item( $items, $this->args() );

		$this->assertSame( array(), (array) $result[0]->classes );
	}

	/**
	 * The filter only ever applies to the 'primary' theme location -- the
	 * 'utility' account menu must never be touched.
	 */
	public function test_non_primary_location_is_left_untouched(): void {
		$GLOBALS['bl_test_is_singular_post_type'] = 'sp_event';

		$items = array( $this->menu_item( 'https://example.test/schedule' ) );

		$result = blueline_sp_primary_nav_current_item( $items, $this->args( 'utility' ) );

		$this->assertSame( array(), (array) $result[0]->classes );
	}

	/**
	 * A singular sp_event view must mark the item whose URL matches
	 * page_schedule's resolved link as current.
	 */
	public function test_singular_event_marks_the_schedule_item_current(): void {
		$GLOBALS['bl_test_is_singular_post_type'] = 'sp_event';
		$state                                    = &blueline_test_state();
		$state['post_types']                      = array( 'sp_team' );

		$items = array(
			$this->menu_item( 'https://example.test/news' ),
			$this->menu_item( 'https://example.test/schedule' ),
		);

		$result = blueline_sp_primary_nav_current_item( $items, $this->args() );

		$this->assertSame( array(), (array) $result[0]->classes, 'an unrelated item must not be marked current' );
		$this->assertContains( 'current-menu-item', (array) $result[1]->classes );
		$this->assertContains( 'current_page_item', (array) $result[1]->classes );
	}

	/**
	 * A singular sp_team/sp_player/sp_staff view must mark the top-level
	 * item whose title contains "Rosters" as current -- the one hub with
	 * no configured page_* settings link (see
	 * blueline_sp_primary_nav_title_url()'s own docblock).
	 */
	public function test_singular_team_marks_the_rosters_item_current(): void {
		$GLOBALS['bl_test_is_singular_post_type'] = 'sp_team';
		$state                                    = &blueline_test_state();
		$state['post_types']                      = array( 'sp_team' );
		$GLOBALS['bl_test_nav_menu_assignments']  = array( 'primary' => 12 );
		$GLOBALS['bl_test_nav_menu_items'][12]    = array(
			(object) array(
				'title'            => 'Rosters / Stats',
				'url'              => 'https://example.test/rosters-and-stats',
				'menu_item_parent' => 0,
			),
		);

		$items = array(
			$this->menu_item( 'https://example.test/rosters-and-stats' ),
			$this->menu_item( 'https://example.test/schedule' ),
		);

		$result = blueline_sp_primary_nav_current_item( $items, $this->args() );

		$this->assertContains( 'current-menu-item', (array) $result[0]->classes );
		$this->assertSame( array(), (array) $result[1]->classes );
	}

	/**
	 * A submenu (non-top-level) item sharing the hub's title must never be
	 * mistaken for the hub itself when resolving via title.
	 */
	public function test_title_resolver_ignores_non_top_level_items(): void {
		$GLOBALS['bl_test_nav_menu_assignments'] = array( 'primary' => 12 );
		$GLOBALS['bl_test_nav_menu_items'][12]   = array(
			(object) array(
				'title'            => 'Past Rosters',
				'url'              => 'https://example.test/past-rosters',
				'menu_item_parent' => 99, // A submenu item -- has a parent.
			),
			(object) array(
				'title'            => 'Rosters / Stats',
				'url'              => 'https://example.test/rosters-and-stats',
				'menu_item_parent' => 0,
			),
		);

		$url = blueline_sp_primary_nav_title_url( 'Rosters' );

		$this->assertSame( 'https://example.test/rosters-and-stats', $url );
	}

	/**
	 * No 'primary' location assigned at all: the title resolver degrades to
	 * '' rather than erroring, and the top-level filter then leaves items
	 * untouched.
	 */
	public function test_no_primary_menu_assigned_degrades_to_empty(): void {
		$this->assertSame( '', blueline_sp_primary_nav_title_url( 'Rosters' ) );
	}
}
