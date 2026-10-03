<?php
/**
 * Unit tests for the current-user resolvers. Moved from the theme's PlayerDataTest.php.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
// blueline_player_current_team_ids() is a theme reader the team resolver composes.
require_once dirname( __DIR__, 3 ) . '/themes/blueline/inc/account/player-data.php';

/**
 * Covers blueline_current_user_player_id() and blueline_current_user_team_ids().
 */
final class CurrentUserPlayerTest extends TestCase {

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
	 * their own coverage (PlayerLinkTest.php here, the theme's PlayerDataTest.php).
	 */
	protected function setUp(): void {
		blueline_test_reset();
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
