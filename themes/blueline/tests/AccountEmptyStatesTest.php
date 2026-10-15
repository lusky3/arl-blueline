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
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * The My Team and My Schedule tabs for the accounts with nothing to show
 * yet: a user with no linked player, a linked player on no team, and a
 * rostered player with no games scheduled. Each must say what is going on
 * (and, unlinked, how to get linked) instead of rendering an empty box.
 *
 * Linked-player cases run in a separate process and load
 * tests/fixtures/linked-player-stubs.php there (see that file for why).
 */
final class AccountEmptyStatesTest extends TestCase {

	private const USER   = 7;
	private const PLAYER = 500;
	private const KINGS  = 12881;

	/**
	 * Fresh state, logged in as USER.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$state                    = &blueline_test_state();
		$state['current_user_id'] = self::USER;
		$state['post_types']      = array( 'sp_player', 'sp_team', 'sp_event' );

		// No teammates: the roster lookup is not what these tests are about.
		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) {
			return 'sp_player' === ( $args['post_type'] ?? '' ) ? array() : null;
		};
	}

	/**
	 * Do not leak the get_posts() model into other test classes.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_get_posts_model'] );
	}

	/**
	 * Capture an endpoint's echoed output.
	 *
	 * @param callable $renderer Zero-arg callable that echoes markup.
	 * @return string
	 */
	private function render( callable $renderer ): string {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	/**
	 * Link USER to PLAYER (separate-process tests only).
	 */
	private function link_player(): void {
		require_once __DIR__ . '/fixtures/linked-player-stubs.php';
		$GLOBALS['bl_test_linked_players']   = array( self::USER => self::PLAYER );
		$GLOBALS['bl_test_wp_query_results'] = array();
		$GLOBALS['bl_test_wp_query_args']    = array();
	}

	/**
	 * Put PLAYER on the Kings' current roster.
	 */
	private function roster_on_kings(): void {
		$state                              = &blueline_test_state();
		$state['posts'][ self::KINGS ]      = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/team/kings/',
			'type'      => 'sp_team',
			'title'     => 'Kings',
		);
		$state['post_meta'][ self::PLAYER ] = array(
			'sp_current_team' => new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS ) ),
		);
	}

	/**
	 * Unlinked, My Team is the claim card with a hint of what the tab will
	 * show, never an empty team module.
	 */
	public function test_my_team_without_a_linked_player_offers_the_claim_card(): void {
		$html = $this->render( 'blueline_account_my_team_endpoint' );

		$this->assertStringContainsString( 'bl-account-module--claim', $html );
		$this->assertStringContainsString( esc_html( blueline_claim_card_context_hint( 'my-team' ) ), $html );
		$this->assertStringNotContainsString( 'bl-account-module--my-team', $html );
	}

	/**
	 * Unlinked, My Schedule is the claim card too, with its own hint and no
	 * next-game card.
	 */
	public function test_my_schedule_without_a_linked_player_offers_the_claim_card(): void {
		$html = $this->render( 'blueline_account_my_schedule_endpoint' );

		$this->assertStringContainsString( 'bl-account-module--claim', $html );
		$this->assertStringContainsString( esc_html( blueline_claim_card_context_hint( 'my-schedule' ) ), $html );
		$this->assertStringNotContainsString( 'bl-account-module--next-game', $html );
		$this->assertStringNotContainsString( 'bl-account-module--schedule', $html );
	}

	/**
	 * Linked to a player who is on no team: My Team says so, and runs no
	 * event query at all.
	 */
	#[RunInSeparateProcess]
	public function test_my_team_for_a_player_on_no_team_says_so(): void {
		$this->link_player();

		$html = $this->render( 'blueline_account_my_team_endpoint' );

		$this->assertStringContainsString( 'bl-account-module--my-team', $html );
		$this->assertStringContainsString( 'not on a roster yet', $html );
		$this->assertStringNotContainsString( 'bl-account-module--claim', $html );
		$this->assertSame( array(), $GLOBALS['bl_test_wp_query_args'] );
	}

	/**
	 * Linked, rostered, but nothing scheduled: both schedule modules render
	 * their empty states (the next-game one from the admin-editable copy),
	 * and the season calendar subscription is still offered.
	 */
	#[RunInSeparateProcess]
	public function test_my_schedule_with_no_upcoming_games_shows_both_empty_states(): void {
		$this->link_player();
		$this->roster_on_kings();
		// The next-game lookup, then the schedule lookup: both find nothing.
		$GLOBALS['bl_test_wp_query_results'] = array( array(), array(), array() );

		$html = $this->render( 'blueline_account_my_schedule_endpoint' );

		$this->assertStringContainsString( 'bl-account-module--next-game', $html );
		$this->assertStringContainsString( esc_html( blueline_settings( 'account_empty_next_game' ) ), $html );
		$this->assertStringNotContainsString( 'data-bl-next-game-account=', $html );

		$this->assertStringContainsString( 'bl-account-module--schedule', $html );
		$this->assertStringContainsString( 'No other games are on the schedule yet.', $html );
		$this->assertStringNotContainsString( 'bl-account-schedule__item', $html );
	}

	/**
	 * The admin can switch both schedule modules off; the tab then renders
	 * nothing rather than an empty shell.
	 */
	#[RunInSeparateProcess]
	public function test_my_schedule_with_the_section_off_renders_nothing(): void {
		$this->link_player();
		$this->roster_on_kings();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_next_game' => false ) );

		$this->assertSame( '', trim( $this->render( 'blueline_account_my_schedule_endpoint' ) ) );
	}
}
