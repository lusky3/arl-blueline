<?php
/**
 * Covers a live-review finding with zero prior test coverage: on
 * /schedule and team pages, a past game with no result entered yet
 * rendered the exact same plain em dash as a genuinely future game --
 * "TBD" and "already played, results pending" read identically, with
 * nothing distinguishing the two.
 *
 * The template sportspress/event-list.php cannot be rendered by this
 * suite: it walks a real SP_Calendar's ->data() and calls several other
 * SportsPress-only functions no stub in this project reproduces (see
 * StandingsExtraStatsDefaultTest.php's own docblock for the identical
 * constraint on league-table.php). This instead source-scans the template
 * for the exact wiring the fix depends on -- reusing
 * blueline_sp_event_state() (inc/sportspress.php), the SAME clock-vs-
 * results decision blueline_sp_event_hero() already uses for the
 * single-event page (unit tested directly in tests/EventStateTest.php),
 * so this table and that page can never disagree about the same event --
 * and separately proves blueline_sp_event_state()'s own 'pending' branch
 * behaves the way the template's new copy assumes it does.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class EventListPendingResultTest extends TestCase {

	/**
	 * The template must compute its "past, no result" state via the same,
	 * already-tested blueline_sp_event_state() the single-event hero uses --
	 * not a second, hand-rolled clock/results check that could disagree
	 * with it.
	 */
	public function test_template_reuses_the_shared_event_state_function(): void {
		$src = (string) file_get_contents( __DIR__ . '/../sportspress/event-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( 'blueline_sp_event_state(', $src );
		$this->assertStringContainsString( 'blueline_sp_event_start_timestamp(', $src );
	}

	/**
	 * The Result cell must branch on the 'pending' state to print the
	 * "coming soon" copy, and must still fall through to the plain em dash
	 * for every other case (a genuinely future game) -- the fix must add a
	 * distinction, not replace the dash outright.
	 */
	public function test_result_cell_branches_on_pending_before_falling_back_to_the_dash(): void {
		$src = (string) file_get_contents( __DIR__ . '/../sportspress/event-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertMatchesRegularExpression(
			'/main_results.*pending.*bl_event_state.*Final score coming soon.*8212/s',
			$src,
			'the Result cell must check for results, then the pending state, then fall back to the plain dash, in that order'
		);
	}

	/**
	 * The function blueline_sp_event_state() itself: no result, and the
	 * clock has passed the start time -- exactly what the template treats
	 * as "pending" and shows "Final score coming soon" for.
	 */
	public function test_no_result_past_start_time_is_pending(): void {
		$this->assertSame(
			'pending',
			blueline_sp_event_state( false, 1_700_000_000 - 3600, 1_700_000_000 )
		);
	}

	/**
	 * The function blueline_sp_event_state() itself: no result, and the
	 * start time is still ahead -- a genuinely future game, which the
	 * template must keep showing as a plain dash.
	 */
	public function test_no_result_future_start_time_is_preview(): void {
		$this->assertSame(
			'preview',
			blueline_sp_event_state( false, 1_700_000_000 + 3600, 1_700_000_000 )
		);
	}
}
