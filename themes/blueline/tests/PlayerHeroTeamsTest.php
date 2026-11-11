<?php
/**
 * The player hero's team links (inc/sportspress/heroes.php, SPT-04): a
 * player on two current teams gets a link to each, as the staff hero and
 * My Team already do, not only the first.
 *
 * SP_Player has to exist for the hero to ask it, so each test runs in a
 * separate process.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Unit tests.
 */
final class PlayerHeroTeamsTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Render the hero for player 116 on the given current teams.
	 *
	 * @param int[] $team_ids What SP_Player::current_teams() answers.
	 * @return string
	 */
	private function render( array $team_ids ): string {
		// Defined here, not in a shared fixture: theme code branches on class_exists( 'SP_Player' ).
		eval( 'class SP_Player { public function __construct( public $id = 0 ) {} public function current_teams() { return $GLOBALS["bl_test_current_teams"]; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- separate-process stand-in for SportsPress's SP_Player.
		$GLOBALS['bl_test_current_teams'] = $team_ids;

		$state                                     = &blueline_test_state();
		$state['posts'][116]                       = array(
			'status' => 'publish',
			'title'  => 'Christian Meyer',
		);
		$state['post_meta'][116]['sp_number']      = '';
		$state['post_meta'][116]['sp_nationality'] = '';

		foreach ( array(
			12881 => array( 'Kings', 'publish' ),
			2570  => array( 'Ice Bears', 'publish' ),
			3000  => array( 'Old Draft', 'draft' ),
		) as $id => [ $title, $status ] ) {
			$state['posts'][ $id ] = array(
				'status'    => $status,
				'title'     => $title,
				'type'      => 'sp_team',
				'permalink' => 'https://example.test/team/' . $id . '/',
			);
		}

		ob_start();
		blueline_sp_player_hero( 116 );

		return (string) ob_get_clean();
	}

	/**
	 * Two published current teams: a link to each, in order.
	 */
	#[RunInSeparateProcess]
	public function test_every_published_current_team_is_linked(): void {
		$html = $this->render( array( 12881, 2570 ) );

		$this->assertSame( 2, substr_count( $html, 'class="bl-sp-hero__team"' ) );
		$this->assertMatchesRegularExpression( '#team/12881/.*Kings.*team/2570/.*Ice Bears#s', $html );
	}

	/**
	 * An unpublished team is skipped, not printed as a dead link.
	 */
	#[RunInSeparateProcess]
	public function test_an_unpublished_team_is_skipped(): void {
		$html = $this->render( array( 3000, 2570 ) );

		$this->assertSame( 1, substr_count( $html, 'class="bl-sp-hero__team"' ) );
		$this->assertStringContainsString( 'Ice Bears', $html );
		$this->assertStringNotContainsString( 'Old Draft', $html );
	}

	/**
	 * No current team: no link.
	 */
	#[RunInSeparateProcess]
	public function test_no_current_team_means_no_link(): void {
		$this->assertStringNotContainsString( 'bl-sp-hero__team', $this->render( array() ) );
	}
}
