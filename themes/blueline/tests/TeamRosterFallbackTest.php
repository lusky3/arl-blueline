<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * Live-site review: a team's public sp_team page (sportspress/team-lists.php,
 * SportsPress' own SP_Team::lists()) said "Roster not posted yet" for a
 * team the account dashboard's own My Team module showed a real, populated
 * roster for -- confirmed live on staging (2026-08-22): SP_Team::lists()
 * depends on a separate, manually-checked sp_list post per team, and 4 of
 * 143 published teams currently have real sp_current_team-rostered players
 * but no such list. team-lists.php's fix falls back to
 * blueline_get_team_roster() (this file) when SP_Team::lists() has
 * nothing, so these tests cover the scoping/exclusion logic that fallback
 * now depends on.
 *
 * Deliberately does NOT seed a case with a non-empty result: doing so
 * would reach blueline_get_post_titles() (inc/account/player-link.php),
 * which queries a real $wpdb this suite has no stub for by design (see
 * PlayerLinkTest::test_find_player_candidates_short_circuits_on_a_single_token_name()'s
 * own comment on the same boundary) -- an untestable path in this suite,
 * not a gap specific to this fix.
 */
final class TeamRosterFallbackTest extends TestCase {

	/**
	 * Reset every in-memory store this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * An invalid team id never reaches SportsPress' post-type check at all.
	 */
	public function test_a_non_positive_team_id_returns_empty(): void {
		$this->assertSame( array(), blueline_get_team_roster( 0 ) );
		$this->assertSame( array(), blueline_get_team_roster( -5 ) );
	}

	/**
	 * The load-bearing version of the case above: a `$team_id <= 0` guard
	 * that were ever removed would not fail quietly -- `sp_current_team`
	 * stored as the literal string "0" is the exact placeholder value
	 * inc/account/player-data.php's own docblock says the great majority
	 * of multi-row players carry as their FIRST row, so team id 0 is not a
	 * safely "never matches anything" value to query for. Seeding one
	 * here proves the guard itself is what keeps this from ever reaching a
	 * query, not merely that no test data happens to coincide.
	 */
	public function test_team_zero_returns_empty_even_with_a_coincidentally_matching_row(): void {
		$state                   = &blueline_test_state();
		$state['post_types']     = array( 'sp_player' );
		$state['post_meta'][777] = array( 'sp_current_team' => '0' );

		$this->assertSame( array(), blueline_get_team_roster( 0 ) );
	}

	/**
	 * With sp_player not registered (SportsPress inactive, or simply no
	 * such post type in this fake environment), the reader degrades to an
	 * empty roster rather than querying anything.
	 */
	public function test_degrades_to_empty_when_sp_player_is_not_registered(): void {
		$this->assertSame( array(), blueline_get_team_roster( 115100 ) );
	}

	/**
	 * A team with no players carrying its id in sp_current_team resolves
	 * to an empty roster -- this DOES reach the meta_query lookup, unlike
	 * the two guard-clause cases above, but finds nothing to report.
	 */
	public function test_a_team_with_no_matching_players_returns_empty(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		// A player rostered onto a DIFFERENT team must not leak into this
		// team's roster.
		$state['post_meta'][501] = array( 'sp_current_team' => '999999' );

		$this->assertSame( array(), blueline_get_team_roster( 115100 ) );
	}

	/**
	 * The one real teammate on this team is also the viewer themselves
	 * (`$exclude_player_id`) -- excluding them must leave nothing, not a
	 * stale reference to a title lookup that never happens.
	 */
	public function test_excluding_the_only_matching_player_returns_empty(): void {
		$state                   = &blueline_test_state();
		$state['post_types']     = array( 'sp_player' );
		$state['post_meta'][501] = array( 'sp_current_team' => '115100' );

		$this->assertSame( array(), blueline_get_team_roster( 115100, 501 ) );
	}
}
