<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-link.php';
require_once __DIR__ . '/../inc/account/player-data.php';

/**
 * Unit tests.
 */
final class PlayerDataTest extends TestCase {

	/**
	 * Seed a player whose sp_current_team is stored the way SportsPress
	 * actually stores it: several rows under one key.
	 *
	 * @param int               $player_id Player post id.
	 * @param array<int,string> $rows      Row values, lowest meta_id first.
	 * @param int               $team_id   Team post to publish.
	 * @return void
	 */
	private function seed_player_with_team_rows( int $player_id, array $rows, int $team_id ): void {
		blueline_test_reset_state();
		$state                            = &blueline_test_state();
		$state['post_types']              = array( 'sp_player', 'sp_team' );
		$state['posts'][ $team_id ]       = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/team/',
			'type'      => 'sp_team',
			'title'     => 'Mammoth',
		);
		$state['post_meta'][ $player_id ] = array(
			'sp_current_team' => new Blueline_Test_Meta_Rows( $rows ),
		);
	}

	/**
	 * Production stores sp_current_team as MULTIPLE rows, and for 728 of the
	 * 754 players that have more than one, the first row is a '0' placeholder.
	 * get_post_meta( ..., true ) returns that first row, so reading this field
	 * single-value resolves every one of those players to team 0 and blanks
	 * their whole account dashboard -- team, division, jersey, roster, next
	 * game. Real shape, taken from player 66 (Cody Lusk -> Mammoth).
	 */
	public function test_current_team_resolves_past_a_leading_zero_placeholder_row(): void {
		$this->seed_player_with_team_rows( 66, array( '0', '115100' ), 115100 );

		$team = blueline_get_player_team( 66 );

		$this->assertNotNull( $team, 'a player whose real team sits behind a 0 placeholder row must still resolve' );
		$this->assertSame( 115100, $team['team_id'] );
	}

	/**
	 * A player genuinely on several current teams (player 972 carries three)
	 * must resolve to the first real one, matching SportsPress' own
	 * array_filter( $player->current_teams() ) in player-details.php.
	 */
	public function test_current_team_picks_the_first_real_team_when_several_are_stored(): void {
		$this->seed_player_with_team_rows( 972, array( '0', '100251', '2469', '79' ), 100251 );

		$team = blueline_get_player_team( 972 );

		$this->assertNotNull( $team );
		$this->assertSame( 100251, $team['team_id'] );
	}

	/**
	 * Unrostered players must still degrade to null rather than resolving to
	 * the placeholder as if it were a team.
	 */
	public function test_a_player_with_only_placeholder_rows_has_no_team(): void {
		$this->seed_player_with_team_rows( 500, array( '0', '' ), 115100 );

		$this->assertNull( blueline_get_player_team( 500 ) );
	}

	/**
	 * An unpublished first team must not shadow a published later one.
	 */
	public function test_current_team_skips_a_team_that_is_not_published(): void {
		$this->seed_player_with_team_rows( 77, array( '0', '9001', '115100' ), 115100 );

		$team = blueline_get_player_team( 77 );

		$this->assertNotNull( $team, 'a draft/deleted team must not blank the dashboard' );
		$this->assertSame( 115100, $team['team_id'] );
	}

	/**
	 * The league whose season bucket names the player's current team is the one
	 * whose figures belong beside the team the dashboard shows.
	 */
	public function test_stats_league_is_the_one_matching_this_season_team(): void {
		$this->seed_player_with_team_rows( 66, array( '0', '115100' ), 115100 );
		$state                                = &blueline_test_state();
		$state['post_meta'][66]['sp_leagues'] = array(
			7 => array( 666 => -1 ),
			6 => array( 666 => 115100 ),
			0 => array( 666 => 1 ),
		);

		$this->assertSame( 6, blueline_player_stats_league_id( 66, 666 ) );
	}

	/**
	 * With no league matching the current team, fall back to SportsPress' own
	 * all-leagues bucket rather than guessing.
	 */
	public function test_stats_league_falls_back_to_zero_when_nothing_matches(): void {
		$this->seed_player_with_team_rows( 66, array( '0', '115100' ), 115100 );
		$state                                = &blueline_test_state();
		$state['post_meta'][66]['sp_leagues'] = array( 7 => array( 666 => -1 ) );

		$this->assertSame( 0, blueline_player_stats_league_id( 66, 666 ) );
	}

	/**
	 * An unrostered player has no team to match a league against.
	 */
	public function test_stats_league_is_zero_without_a_team(): void {
		$this->seed_player_with_team_rows( 66, array( '0' ), 115100 );

		$this->assertSame( 0, blueline_player_stats_league_id( 66, 666 ) );
	}

	/**
	 * Test case.
	 */
	public function test_missing_stats_are_zero_filled(): void {
		$this->assertSame(
			array(
				'gp'  => 0,
				'g'   => 0,
				'a'   => 0,
				'pim' => 0,
			),
			blueline_normalize_player_stats( array() )
		);
	}

	/**
	 * Test case.
	 */
	public function test_string_values_are_cast_to_int(): void {
		$out = blueline_normalize_player_stats(
			array(
				'gp'  => '14',
				'g'   => '3',
				'a'   => '7',
				'pim' => '2',
			)
		);
		$this->assertSame(
			array(
				'gp'  => 14,
				'g'   => 3,
				'a'   => 7,
				'pim' => 2,
			),
			$out
		);
	}

	/**
	 * Test case.
	 */
	public function test_unknown_keys_are_dropped(): void {
		$out = blueline_normalize_player_stats(
			array(
				'gp'   => 5,
				'hits' => 99,
			)
		);
		$this->assertArrayNotHasKey( 'hits', $out );
		$this->assertSame( 5, $out['gp'] );
	}

	/**
	 * Test case.
	 */
	public function test_empty_string_becomes_zero_not_null(): void {
		$out = blueline_normalize_player_stats( array( 'g' => '' ) );
		$this->assertSame( 0, $out['g'] );
	}

	/**
	 * Link $user_id to $player_id the way blueline_get_linked_player_id()
	 * actually resolves a link: an sp_player post carrying sp_user meta
	 * equal to the user id. Does not touch sp_current_team -- callers add
	 * that separately via seed_player_with_team_rows() when a test needs one.
	 *
	 * @param int $user_id   WordPress user id.
	 * @param int $player_id sp_player post id to link.
	 * @return void
	 */
	private function link_user_to_player( int $user_id, int $player_id ): void {
		$state = &blueline_test_state();

		if ( ! in_array( 'sp_player', $state['post_types'], true ) ) {
			$state['post_types'][] = 'sp_player';
		}

		$state['post_meta'][ $player_id ]['sp_user'] = (string) $user_id;
	}

	/**
	 * Both resolvers under test here are pure composition of two
	 * already-tested helpers (blueline_get_linked_player_id(),
	 * blueline_player_current_team_ids()), so these tests fake THEIR
	 * inputs (current_user_id, sp_user meta, sp_current_team rows) rather
	 * than re-proving either helper's own internals -- those already have
	 * their own coverage (PlayerLinkTest.php, this file's team-rows tests
	 * above).
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Core's get_current_user_id() returns 0 for a logged-out visitor --
	 * both resolvers must degrade cleanly without ever asking
	 * blueline_get_linked_player_id() to resolve a link for user id 0.
	 */
	public function test_no_current_user_resolves_to_no_player_and_no_teams(): void {
		$this->assertNull( blueline_current_user_player_id() );
		$this->assertSame( array(), blueline_current_user_team_ids() );
	}

	/**
	 * A logged-in visitor who has never claimed a player
	 * (blueline_get_linked_player_id() returns null) must resolve to no
	 * player and no teams, not a fatal or a stray query result.
	 */
	public function test_logged_in_unclaimed_user_resolves_to_no_player_and_no_teams(): void {
		$state                    = &blueline_test_state();
		$state['post_types'][]    = 'sp_player';
		$state['current_user_id'] = 42;

		$this->assertNull( blueline_current_user_player_id() );
		$this->assertSame( array(), blueline_current_user_team_ids() );
	}

	/**
	 * A logged-in, claimed player with exactly one current team resolves
	 * both the player id and that team's id.
	 */
	public function test_logged_in_claimed_user_with_one_current_team(): void {
		// seed_player_with_team_rows() itself resets state, so it must run
		// BEFORE current_user_id/sp_user are set, or it would wipe them out.
		$this->seed_player_with_team_rows( 66, array( '115100' ), 115100 );
		$this->link_user_to_player( 42, 66 );
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;

		$this->assertSame( 66, blueline_current_user_player_id() );
		$this->assertSame( array( 115100 ), blueline_current_user_team_ids() );
	}

	/**
	 * A player can carry more than one current team (across divisions) --
	 * blueline_current_user_team_ids() must surface all of them, unlike the
	 * singular blueline_player_current_team_id(), which this feature is
	 * explicitly forbidden from using for exactly this reason.
	 */
	public function test_logged_in_claimed_user_with_multiple_current_teams(): void {
		// seed_player_with_team_rows() itself resets state, so it must run
		// BEFORE current_user_id/sp_user are set, or it would wipe them out.
		$this->seed_player_with_team_rows( 972, array( '0', '100251', '2469', '79' ), 100251 );
		$this->link_user_to_player( 42, 972 );
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;

		$this->assertSame( 972, blueline_current_user_player_id() );
		$this->assertSame( array( 100251, 2469, 79 ), blueline_current_user_team_ids() );
	}
}
