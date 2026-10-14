<?php
/**
 * The schedule table's per-row helpers (inc/sportspress/core.php).
 *
 * A postponed game with a recorded 0-0 used to read as a final score on
 * /schedule and every team page, because the Result cell never looked at
 * SportsPress's `sp_status` flag; a cancelled game with no score fell through
 * to "Final score coming soon" or a bare dash, exactly like a game still on.
 * The cell's contents, the time link's accessible name and the Arena text are
 * built by pure helpers so they can be exercised here; the template itself
 * cannot be rendered by this suite (see EventListPendingResultTest.php).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class EventListScheduleStatusTest extends TestCase {

	/**
	 * Only the three statuses SportsPress uses for "not on as scheduled" are labelled.
	 */
	public function test_schedule_status_keeps_only_off_schedule_statuses(): void {
		$this->assertSame( 'postponed', blueline_sp_schedule_status( 'postponed' ) );
		$this->assertSame( 'cancelled', blueline_sp_schedule_status( 'cancelled' ) );
		$this->assertSame( 'tbd', blueline_sp_schedule_status( 'tbd' ) );

		foreach ( array( 'ok', '', 'Postponed', 'final', null, false ) as $sp_status ) {
			$this->assertSame( '', blueline_sp_schedule_status( $sp_status ), var_export( $sp_status, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- assertion message.
		}
	}

	/**
	 * Postponed/cancelled reuse the event hero's own words; TBD gets its own.
	 */
	public function test_schedule_status_labels(): void {
		$this->assertSame( 'Postponed', blueline_sp_schedule_status_label( 'postponed' ) );
		$this->assertSame( 'Cancelled', blueline_sp_schedule_status_label( 'cancelled' ) );
		$this->assertSame( 'Time TBD', blueline_sp_schedule_status_label( 'tbd' ) );
		$this->assertSame( blueline_sp_event_status_label( 'postponed' ), blueline_sp_schedule_status_label( 'postponed' ) );
	}

	/**
	 * A postponed game with a recorded 0-0 shows the label next to the score,
	 * never the bare score that reads as a final.
	 */
	public function test_postponed_game_with_a_score_shows_the_label_next_to_it(): void {
		$html = blueline_sp_schedule_result_html(
			array(
				12 => '0',
				34 => '0',
			),
			'postponed',
			'final',
			false
		);

		$this->assertStringContainsString( '<span class="bl-sp-schedule__status bl-sp-schedule__status--postponed">Postponed</span>', $html );
		$this->assertStringContainsString( '<span class="bl-sp-schedule__score">0 - 0</span>', $html );
		$this->assertLessThan(
			strpos( $html, '0 - 0' ),
			strpos( $html, 'Postponed' ),
			'the label comes first, so the score never reads on its own'
		);
	}

	/**
	 * A status game with no score shows only its label: never the "coming
	 * soon" copy (it is not coming) and never the dash a game still on gets.
	 */
	public function test_status_game_without_a_score_never_falls_through(): void {
		foreach ( array( 'postponed', 'cancelled', 'tbd' ) as $sp_status ) {
			foreach ( array( 'pending', 'preview' ) as $state ) {
				$html = blueline_sp_schedule_result_html( array(), $sp_status, $state, true );

				$this->assertStringContainsString( 'bl-sp-schedule__status--' . $sp_status, $html, "$sp_status/$state" );
				$this->assertStringNotContainsString( 'Final score coming soon', $html, "$sp_status/$state" );
				$this->assertStringNotContainsString( '&#8212;', $html, "$sp_status/$state" );
				$this->assertStringNotContainsString( 'bl-sp-schedule__score', $html, "$sp_status/$state" );
			}
		}
	}

	/**
	 * A cancelled game's label is real text, not just a class.
	 */
	public function test_cancelled_label_is_text(): void {
		$html = blueline_sp_schedule_result_html( array(), 'cancelled', 'preview', false );

		$this->assertSame( 'Cancelled', trim( wp_strip_all_tags( $html ) ) );
	}

	/**
	 * An on-time game keeps the existing behaviour: score, then "coming soon"
	 * for a recent unscored game, then the dash.
	 */
	public function test_on_time_games_are_unchanged(): void {
		$this->assertSame(
			'<span class="bl-sp-schedule__score">3 - 2</span>',
			blueline_sp_schedule_result_html(
				array(
					1 => '3',
					2 => '2',
				),
				'ok',
				'final',
				false
			)
		);
		$this->assertStringContainsString(
			'Final score coming soon',
			blueline_sp_schedule_result_html( array(), 'ok', 'pending', true )
		);
		$this->assertStringContainsString(
			'&#8212;',
			blueline_sp_schedule_result_html( array(), 'ok', 'pending', false ),
			'an old unscored game falls through to the dash'
		);
		$this->assertStringContainsString(
			'&#8212;',
			blueline_sp_schedule_result_html( array(), '', 'preview', true )
		);
	}

	/**
	 * The time link's hidden context names both teams and the date, and the
	 * status when there is one, so the link is not just "6:45 pm".
	 */
	public function test_link_context_names_the_teams_and_date(): void {
		$this->assertSame(
			', Lumberjacks vs Ice Bears, October 16, 2026',
			blueline_sp_schedule_link_context( array( 'Lumberjacks', 'Ice Bears' ), 'October 16, 2026' )
		);
		$this->assertSame(
			', Oilers vs Lumberjacks, January 25, 2026, Postponed',
			blueline_sp_schedule_link_context( array( 'Oilers', 'Lumberjacks' ), 'January 25, 2026', 'Postponed' )
		);
	}

	/**
	 * A slot with one team (or none) still produces a sensible name.
	 */
	public function test_link_context_with_missing_teams(): void {
		$this->assertSame( ', Kings, March 1, 2027', blueline_sp_schedule_link_context( array( 'Kings' ), 'March 1, 2027' ) );
		$this->assertSame( ', March 1, 2027', blueline_sp_schedule_link_context( array(), 'March 1, 2027' ) );
		$this->assertSame( '', blueline_sp_schedule_link_context( array(), '' ) );
		$this->assertSame( ', Kings vs Hammers', blueline_sp_schedule_link_context( array( 'Kings', '', 'Hammers' ), '' ) );
	}

	/**
	 * The template wires the helpers above into the row: the status from
	 * sp_status, the hidden link context inside the time link, the Arena text.
	 */
	public function test_template_wires_the_helpers(): void {
		$src = (string) file_get_contents( __DIR__ . '/../sportspress/event-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "get_post_meta( \$event->ID, 'sp_status', true )", $src );
		$this->assertStringContainsString( 'blueline_sp_schedule_status( $status )', $src );
		$this->assertMatchesRegularExpression(
			'#</time>.*<span class="screen-reader-text"><\?php echo esc_html\( blueline_sp_schedule_link_context\( \$bl_team_names, \$bl_date_label, \$bl_status_label \) \); \?></span>\s*</a>#s',
			$src,
			'the hidden context must sit inside the time link, after the visible time'
		);
		$this->assertStringContainsString( 'blueline_sp_schedule_venue_text( (string) blueline_venue_label(', $src );
		$this->assertStringNotContainsString( 'Final score coming soon', $src, 'the Result cell copy lives in the helper only' );
	}

	/**
	 * The Arena text keeps arena-then-pad order and glues the dash to both
	 * sides, so a wrapped label never starts a line with "— Red".
	 */
	public function test_venue_text_never_orphans_the_pad(): void {
		$this->assertSame(
			"Mr. Lube and Tires Arena\u{00A0}—\u{00A0}Red",
			blueline_sp_schedule_venue_text( 'Mr. Lube and Tires Arena — Red' )
		);
		$this->assertSame( 'Central Arena', blueline_sp_schedule_venue_text( 'Central Arena' ) );
		$this->assertSame( '', blueline_sp_schedule_venue_text( '' ) );
	}
}
