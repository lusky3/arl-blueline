<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Covers a live-review finding with zero prior test coverage: team pages
 * showed 5-7 stale/historical division tags (e.g. "Div 4C, Division 4,
 * Division 5, Group G" for a team currently in Division 5), because
 * blueline_sp_team_hero() rendered EVERY sp_league term the team had ever
 * been tagged with, across every season, with no "current" filter.
 * blueline_player_division_name()'s own docblock (inc/account/player-data.php)
 * already documents this exact bug and names blueline_sp_team_hero() as the
 * offender. blueline_sp_team_hero_decide_division_names() is the pure
 * policy fix: always prefer the season-scoped single answer, and treat an
 * empty answer as "nothing to show," never as licence to fall back to the
 * raw, accumulated term list.
 */
final class TeamHeroDivisionNamesTest extends TestCase {

	/**
	 * A real current-season division name becomes the sole entry.
	 */
	public function test_a_current_division_name_is_wrapped_in_an_array(): void {
		$this->assertSame(
			array( 'Division 5' ),
			blueline_sp_team_hero_decide_division_names( 'Division 5' )
		);
	}

	/**
	 * THE EXACT BUG: no current-season division ('') must produce an EMPTY
	 * result -- never a fallback to some other, stale source. This is the
	 * one assertion that would fail if a future edit reintroduced "show the
	 * raw terms when the season-scoped answer is empty."
	 */
	public function test_no_current_division_yields_no_names_at_all(): void {
		$this->assertSame( array(), blueline_sp_team_hero_decide_division_names( '' ) );
	}

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Defensive-only fallback: when blueline_player_division_name() itself
	 * is not defined at all (inc/account/player-data.php not loaded -- never
	 * true on a real request, see this function's own docblock), the raw
	 * sp_league terms are used rather than showing nothing.
	 */
	public function test_falls_back_to_raw_terms_when_the_season_scoped_resolver_is_unavailable(): void {
		if ( function_exists( 'blueline_player_division_name' ) ) {
			// PHP function definitions cannot be unloaded, and PHPUnit runs
			// every test file in one process: whichever OTHER test file
			// happens to require inc/account/player-data.php first (order
			// is not guaranteed) makes this function permanently defined
			// for the rest of the run, which makes the branch this test
			// targets unreachable from here on. Skip rather than fail --
			// this is a property of test run order, not a real regression.
			$this->markTestSkipped( 'blueline_player_division_name() was already loaded by another test file in this same process; the fallback branch this test targets is unreachable once that happens.' );
		}

		$state                                 = &blueline_test_state();
		$state['taxonomies']                   = array( 'sp_league' );
		$state['post_terms'][501]['sp_league'] = array( 1, 2 );
		$state['terms'][1]                     = (object) array(
			'term_id' => 1,
			'name'    => 'Division 4',
		);
		$state['terms'][2]                     = (object) array(
			'term_id' => 2,
			'name'    => 'Division 5',
		);

		$this->assertSame(
			array( 'Division 4', 'Division 5' ),
			blueline_sp_team_hero_division_names( 501 )
		);
	}
}
