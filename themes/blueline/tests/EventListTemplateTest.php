<?php
/**
 * Renders the schedule template (sportspress/event-list.php: /schedule and every team page's games) and
 * asserts on its markup: rows grouped under date headings, off-schedule games labelled, the Result cell
 * built from the shared event state, the team names wrapped for clamping, and paging done on the server
 * in whole date groups.
 *
 * Runs in a separate process: the template only takes over from SportsPress's own when SP() and SP_Calendar
 * exist, and defining them in the shared process would change what every other test sees.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class EventListTemplateTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Site-local start at 7:30 pm, `$offset` from now.
	 *
	 * @param string $offset Relative time, e.g. '-2 days'.
	 * @return string 'Y-m-d H:i:s'
	 */
	private function start( string $offset ): string {
		return ( new DateTimeImmutable( $offset, wp_timezone() ) )->setTime( 19, 30 )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Load the stubs, set the site's date formats and register the two teams (10 Kings, 11 Hammers).
	 */
	private function seed(): void {
		require_once __DIR__ . '/fixtures/event-calendar-stubs.php';
		require_once __DIR__ . '/fixtures/sportspress-schedule-stubs.php';

		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );

		$state              = &blueline_test_state();
		$state['posts'][10] = array(
			'status' => 'publish',
			'type'   => 'sp_team',
			'title'  => 'Kings',
		);
		$state['posts'][11] = array(
			'status' => 'publish',
			'type'   => 'sp_team',
			'title'  => 'Hammers',
		);
	}

	/**
	 * Register a game between the two teams and put it on the calendar.
	 *
	 * @param int                $id        Event ID.
	 * @param string             $post_date Site-local start.
	 * @param string             $sp_status SportsPress's sp_status, '' for none.
	 * @param array<int, string> $score     Recorded score as team id => goals, empty for none.
	 */
	private function add_game( int $id, string $post_date, string $sp_status = '', array $score = array() ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'status'    => 'publish',
			'type'      => 'sp_event',
			'title'     => 'Kings vs Hammers',
			'post_date' => $post_date,
			'permalink' => 'https://example.test/event/' . $id . '/',
		);
		$state['post_meta'][ $id ] = array(
			'sp_team'   => new Blueline_Test_Meta_Rows( array( 10, 11 ) ),
			'sp_status' => $sp_status,
		);

		$GLOBALS['bl_test_sp_main_results'][ $id ] = $score;
		$GLOBALS['bl_test_sp_calendar_events'][]   = (object) array(
			'ID'           => $id,
			'post_date'    => $post_date,
			'post_content' => '',
			'post_status'  => 'publish',
		);
	}

	/**
	 * Render the template.
	 *
	 * @param array<string, mixed> $args Template arguments SportsPress would extract (rows, number, ...).
	 * @return string
	 */
	private function render( array $args = array() ): string {
		$title_format = 'homeaway';
		$time_format  = 'combined';
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors sp_get_template(), which extracts the template's args the same way.

		ob_start();
		include __DIR__ . '/../sportspress/event-list.php';

		return (string) ob_get_clean();
	}

	/**
	 * The row for one game.
	 *
	 * @param string $html The rendered table.
	 * @param int    $id   Event ID.
	 * @return string
	 */
	private function row( string $html, int $id ): string {
		$this->assertSame( 1, preg_match( '#<tr class="sp-row[^"]*"[^>]*>(?:(?!</tr>).)*?/event/' . $id . '/.*?</tr>#s', $html, $row ), "game $id has a row" );

		return $row[0];
	}

	/**
	 * Games sharing a day sit under one date heading, which spans every column, and a table that groups by
	 * date is neither sortable nor paged by SportsPress's own client-side script.
	 */
	#[RunInSeparateProcess]
	public function test_rows_are_grouped_under_one_heading_per_date(): void {
		$this->seed();
		$this->add_game( 601, $this->start( '+2 days' ) );
		$this->add_game( 602, $this->start( '+2 days' ) );
		$this->add_game( 603, $this->start( '+3 days' ) );

		$html = $this->render();

		$this->assertSame( 2, preg_match_all( '#<tr class="bl-sp-date-heading"><th scope="colgroup" colspan="9">[A-Z][a-z]+ \d+, \d{4}</th></tr>#', $html ) );
		$this->assertSame( 3, preg_match_all( '#<tr class="sp-row #', $html ) );
		$this->assertDoesNotMatchRegularExpression( '#<table[^>]*sp-(sortable|paginated)-table#', $html );
	}

	/**
	 * Each team name is wrapped in its own element, so a name too long for a narrow column can be clamped
	 * and measured, and the Away cell carries the "vs" that only the stacked phone layout shows.
	 */
	#[RunInSeparateProcess]
	public function test_the_visible_team_name_has_its_own_element(): void {
		$this->seed();
		$this->add_game( 601, $this->start( '+2 days' ) );

		$row = $this->row( $this->render(), 601 );

		$this->assertMatchesRegularExpression( '#<td class="data-home[^>]*><span class="bl-sp-team-name">Kings</span></td>#', $row );
		$this->assertMatchesRegularExpression( '#<td class="data-away[^>]*><span class="bl-sp-schedule__vs">vs</span><span class="bl-sp-team-name">Hammers</span></td>#', $row );
	}

	/**
	 * The Result cell is built from the same event state the single-game hero uses: a game played in the
	 * last two weeks with no score yet says so, an older one or one still to come keeps the dash, and a
	 * recorded score always wins.
	 */
	#[RunInSeparateProcess]
	public function test_the_result_cell_says_pending_only_for_a_recently_played_game(): void {
		$this->seed();
		$this->add_game( 601, $this->start( '-2 days' ) );
		$this->add_game( 602, $this->start( '-40 days' ) );
		$this->add_game( 603, $this->start( '+5 days' ) );
		$this->add_game(
			604,
			$this->start( '-30 days' ),
			'',
			array(
				10 => '3',
				11 => '1',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Final score coming soon', $this->row( $html, 601 ) );
		$this->assertStringContainsString( 'bl-sp-schedule__none', $this->row( $html, 602 ) );
		$this->assertStringNotContainsString( 'coming soon', $this->row( $html, 602 ) );
		$this->assertStringContainsString( 'bl-sp-schedule__none', $this->row( $html, 603 ) );
		$this->assertStringContainsString( '<span class="bl-sp-schedule__score">3 - 1</span>', $this->row( $html, 604 ) );
		$this->assertSame( 1, substr_count( $html, 'Final score coming soon' ) );
	}

	/**
	 * A postponed or cancelled game is labelled in its row, its Result cell and the time link's own name
	 * (never read as a game on schedule), and a game whose time is not settled says so instead of a time.
	 */
	#[RunInSeparateProcess]
	public function test_off_schedule_games_are_labelled_in_the_row_and_the_link_name(): void {
		$this->seed();
		$this->add_game(
			601,
			$this->start( '+2 days' ),
			'postponed',
			array(
				10 => '0',
				11 => '0',
			)
		);
		$this->add_game( 602, $this->start( '+3 days' ), 'cancelled' );
		$this->add_game( 603, $this->start( '+4 days' ), 'tbd' );

		$html = $this->render();

		$postponed = $this->row( $html, 601 );
		$this->assertStringContainsString( 'bl-sp-schedule__row--postponed', $postponed );
		$this->assertStringContainsString( '<span class="bl-sp-schedule__status bl-sp-schedule__status--postponed">Postponed</span> <span class="bl-sp-schedule__score">0 - 0</span>', $postponed );
		// The hidden context sits inside the time link, after the visible time, and names the game.
		$this->assertMatchesRegularExpression( '#<a href="https://example.test/event/601/" itemprop="url">\s*<time[^>]*>[^<]+</time>\s*<span class="screen-reader-text">, Kings vs Hammers, [^<]+, Postponed</span>\s*</a>#', $postponed );

		$cancelled = $this->row( $html, 602 );
		$this->assertStringContainsString( 'bl-sp-schedule__status--cancelled', $cancelled );
		$this->assertStringNotContainsString( 'bl-sp-schedule__score', $cancelled );

		$tbd = $this->row( $html, 603 );
		$this->assertStringContainsString( '<span class="bl-sp-schedule__tbd">Time TBD</span>', $tbd );
		$this->assertStringNotContainsString( '<time', $tbd );
	}

	/**
	 * A season's schedule is paged on the server in whole date groups and opens on the page holding the
	 * next game, with the pager under the table.
	 */
	#[RunInSeparateProcess]
	public function test_a_long_schedule_opens_on_the_page_with_the_next_game(): void {
		$this->seed();
		foreach ( array(
			701 => '-3 days',
			702 => '-2 days',
			703 => '-1 day',
			704 => '+1 day',
			705 => '+2 days',
		) as $id => $offset ) {
			$this->add_game( $id, $this->start( $offset ) );
		}

		$html = $this->render( array( 'rows' => 2 ) );

		$this->assertSame( 2, preg_match_all( '#<tr class="sp-row #', $html ), 'one page of two date groups' );
		$this->assertStringContainsString( '/event/703/', $html, 'page two holds the last played game and the next one' );
		$this->assertStringContainsString( '/event/704/', $html );
		$this->assertStringNotContainsString( '/event/701/', $html );
		$this->assertStringContainsString( '<nav class="bl-sp-pager"', $html );
		$this->assertMatchesRegularExpression( '#rel="prev"#', $html );
		$this->assertMatchesRegularExpression( '#rel="next"#', $html );
	}

	/**
	 * A list capped by a row limit (a team page's next few games) is never paged.
	 */
	#[RunInSeparateProcess]
	public function test_a_list_capped_by_a_row_limit_is_not_paged(): void {
		$this->seed();
		foreach ( range( 1, 5 ) as $day ) {
			$this->add_game( 800 + $day, $this->start( '+' . $day . ' days' ) );
		}

		$html = $this->render(
			array(
				'rows'   => 2,
				'number' => 3,
			)
		);

		$this->assertSame( 3, preg_match_all( '#<tr class="sp-row #', $html ) );
		$this->assertStringNotContainsString( 'bl-sp-pager', $html );
	}

	/**
	 * With nothing to list the template says so in one line, never a bare header row; asked for upcoming
	 * games only, it says nothing is upcoming.
	 */
	#[RunInSeparateProcess]
	public function test_an_empty_list_is_one_line_not_a_bare_header(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringContainsString( 'No games scheduled yet.', $html );
		$this->assertStringNotContainsString( '<table', $html );

		$GLOBALS['bl_test_sp_calendar_status'] = 'future';

		$this->assertStringContainsString( 'No upcoming games scheduled.', $this->render() );
	}
}
