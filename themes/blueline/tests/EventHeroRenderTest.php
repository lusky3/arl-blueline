<?php
/**
 * Renders the single-event hero (inc/sportspress/heroes.php) and asserts on its markup: the matchup is
 * the page's h1, the status comes from SportsPress's own sp_status flag, a recorded score shows only
 * for a game that was played, postponed or cancelled, and the add-to-calendar links are named as a group.
 *
 * Runs in a separate process: the hero only renders when sp_get_status() exists, and defining it (and a
 * real get_gmt_from_date()) in the shared process would change what every other test sees.
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
require_once __DIR__ . '/../inc/homepage-modules.php'; // The faceoff rings the hero draws.

/**
 * See this file's own docblock.
 */
final class EventHeroRenderTest extends TestCase {

	/**
	 * A start that is always in the future.
	 */
	private const FUTURE = '2099-10-16 21:45:00';

	/**
	 * A start that is always in the past.
	 */
	private const PAST = '2020-01-10 19:00:00';

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Register game 500 between two published teams (10 and 11).
	 *
	 * @param string                         $post_date Site-local start, 'Y-m-d H:i:s'.
	 * @param string                         $sp_status SportsPress's sp_status value, '' for none.
	 * @param array<int, array<string, int>> $results   Recorded results per team id, empty for none.
	 */
	private function seed_game( string $post_date, string $sp_status = '', array $results = array() ): void {
		require_once __DIR__ . '/fixtures/event-calendar-stubs.php';
		require_once __DIR__ . '/fixtures/event-hero-stubs.php';

		$state                   = &blueline_test_state();
		$state['posts'][500]     = array(
			'status'    => 'publish',
			'type'      => 'sp_event',
			'title'     => 'Kings vs Hammers',
			'post_date' => $post_date,
		);
		$state['posts'][10]      = array(
			'status'    => 'publish',
			'type'      => 'sp_team',
			'title'     => 'Kings',
			'permalink' => 'https://example.test/team/kings/',
		);
		$state['posts'][11]      = array(
			'status'    => 'publish',
			'type'      => 'sp_team',
			'title'     => 'Hammers',
			'permalink' => 'https://example.test/team/hammers/',
		);
		$state['post_meta'][500] = array(
			'sp_team'    => new Blueline_Test_Meta_Rows( array( 10, 11 ) ),
			'sp_status'  => $sp_status,
			'sp_results' => $results,
		);

		$GLOBALS['bl_test_sp_event_status'][500] = $results ? 'results' : 'scheduled';
	}

	/**
	 * Render the hero for game 500.
	 *
	 * @return string
	 */
	private function render_hero(): string {
		ob_start();
		blueline_sp_event_hero( 500 );

		return (string) ob_get_clean();
	}

	/**
	 * The matchup is the page's h1 (never a div), names both teams as links to their pages, and a game
	 * still to come reads "Preview" with no score.
	 */
	#[RunInSeparateProcess]
	public function test_the_matchup_is_the_h1_with_both_teams_linked(): void {
		$this->seed_game( self::FUTURE );

		$html = $this->render_hero();

		$this->assertSame( 1, preg_match( '#<h1 class="sp-scoreboard__matchup">(.*?)</h1>#s', $html, $h1 ) );
		$this->assertStringNotContainsString( '<div class="sp-scoreboard__matchup">', $html );
		$this->assertStringContainsString( '<a class="sp-scoreboard__team-link" href="https://example.test/team/kings/">Kings</a>', $h1[1] );
		$this->assertStringContainsString( '<a class="sp-scoreboard__team-link" href="https://example.test/team/hammers/">Hammers</a>', $h1[1] );
		$this->assertStringContainsString( 'sp-scoreboard--preview', $html );
		$this->assertStringContainsString( '<span>Preview</span>', $html );
		$this->assertStringNotContainsString( 'sp-scoreboard__score', $html );
	}

	/**
	 * Both add-to-calendar links sit in one group a screen reader names as such, with the visible text
	 * as the group's own label.
	 */
	#[RunInSeparateProcess]
	public function test_the_calendar_links_are_a_named_group(): void {
		$this->seed_game( self::FUTURE );

		$html = $this->render_hero();

		$this->assertSame( 1, preg_match( '#<div class="sp-scoreboard__calendar" data-calendar-links role="group" aria-label="Add this game to your calendar">(.*?)</div>#s', $html, $group ) );
		$this->assertStringContainsString( 'data-calendar="apple"', $group[1] );
		$this->assertStringContainsString( 'data-calendar="google"', $group[1] );
	}

	/**
	 * SportsPress's sp_status wins over the clock: a postponed or cancelled game says so, and offers no
	 * add-to-calendar links for a time that is not happening.
	 */
	#[RunInSeparateProcess]
	public function test_a_postponed_or_cancelled_game_says_so_and_offers_no_calendar_links(): void {
		$labels = array(
			'postponed' => 'Postponed',
			'cancelled' => 'Cancelled',
		);

		foreach ( $labels as $sp_status => $label ) {
			$this->seed_game( self::FUTURE, $sp_status );

			$html = $this->render_hero();

			$this->assertStringContainsString( 'sp-scoreboard--' . $sp_status, $html );
			$this->assertStringContainsString( '<span>' . $label . '</span>', $html );
			$this->assertStringNotContainsString( 'data-calendar-links', $html );
		}
	}

	/**
	 * A played game shows each team's recorded score and "Final"; a postponed game with a score on file
	 * shows the score beside its status instead of hiding it.
	 */
	#[RunInSeparateProcess]
	public function test_recorded_scores_show_for_played_and_postponed_games(): void {
		$results = array(
			10 => array( 'goals' => 2 ),
			11 => array( 'goals' => 4 ),
		);

		$this->seed_game( self::PAST, '', $results );
		$html = $this->render_hero();

		$this->assertStringContainsString( '<span>Final</span>', $html );
		preg_match_all( '#<span class="sp-scoreboard__score">(\d)</span>#', $html, $scores );
		$this->assertSame( array( '2', '4' ), $scores[1] );

		$this->seed_game( self::PAST, 'postponed', $results );
		$html = $this->render_hero();

		$this->assertStringContainsString( '<span>Postponed</span>', $html );
		$this->assertSame( 2, preg_match_all( '#<span class="sp-scoreboard__score">#', $html ) );
	}

	/**
	 * A game with no result yet shows no score, even after its start time: the status reads "Result
	 * pending" rather than inventing a 0-0.
	 */
	#[RunInSeparateProcess]
	public function test_a_started_game_without_a_result_shows_no_score(): void {
		$this->seed_game( self::PAST );

		$html = $this->render_hero();

		$this->assertStringContainsString( '<span>Result pending</span>', $html );
		$this->assertStringNotContainsString( 'sp-scoreboard__score', $html );
	}
}
