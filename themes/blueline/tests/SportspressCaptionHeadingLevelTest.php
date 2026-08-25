<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Regression coverage for blueline_sp_caption_heading_level() -- the
 * accessibility fix for a heading-level skip a live-site review found in
 * SportsPress's own auto-injected table/list captions
 * ("Division 1 | S2026", "Upcoming Games", a roster list's title, a
 * player's per-league stats caption).
 *
 * Before this fix, sportspress/league-table.php, event-list.php,
 * team-lists.php and player-statistics-league.php all hardcoded these
 * captions as <h4>, regardless of what actually preceded them in the page
 * outline. That skipped ONE level on the homepage (h2 "Standings" -> h4)
 * and TWO on every SportsPress singular page (h1 -> h4, with nothing else
 * in between -- confirmed live). This suite pins the function that now
 * decides the correct level for each context, so a future change cannot
 * silently reintroduce either skip.
 */
final class SportspressCaptionHeadingLevelTest extends TestCase {

	/**
	 * Runs before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Test case: not a SportsPress singular view (e.g. the homepage, or any
	 * other request the fake environment has not marked as viewing one of
	 * sp_post_types()) -- the caption is a subsection of an h2 that already
	 * precedes it (blueline_homepage_module_standings_snippet()'s own
	 * "Standings" h2), so the correct next level is h3.
	 */
	public function test_non_sportspress_singular_context_returns_h3(): void {
		$this->assertSame( 3, blueline_sp_caption_heading_level() );
	}

	/**
	 * Test case: a normal WordPress post/page is still, technically, a
	 * singular view -- but not one of SportsPress's OWN post types, so it
	 * must not be treated as the "directly under this page's one <h1>, with
	 * nothing else in between" case that only genuinely applies to
	 * sp_team/sp_player/sp_staff/sp_event singulars.
	 */
	public function test_ordinary_page_singular_context_returns_h3(): void {
		blueline_test_set_queried_post_type( 'page' );

		$this->assertSame( 3, blueline_sp_caption_heading_level() );
	}

	/**
	 * Test case: a single team page. The theme's own hero prints the
	 * page's one <h1> and then the_content() drops SportsPress's
	 * auto-injected roster/schedule/standings sections straight in with
	 * nothing else between -- so the correct next level is h2.
	 */
	public function test_sp_team_singular_context_returns_h2(): void {
		blueline_test_set_queried_post_type( 'sp_team' );

		$this->assertSame( 2, blueline_sp_caption_heading_level() );
	}

	/**
	 * Test case: a single player page -- same structural gap as sp_team
	 * (h1 straight into player-statistics-league.php's own caption), so
	 * the same h2 answer applies.
	 */
	public function test_sp_player_singular_context_returns_h2(): void {
		blueline_test_set_queried_post_type( 'sp_player' );

		$this->assertSame( 2, blueline_sp_caption_heading_level() );
	}
}
