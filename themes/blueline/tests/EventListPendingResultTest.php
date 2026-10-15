<?php
/**
 * Covers a live-review finding with zero prior test coverage: on
 * /schedule and team pages, a past game with no result entered yet
 * rendered the exact same plain em dash as a genuinely future game --
 * "TBD" and "already played, results pending" read identically, with
 * nothing distinguishing the two.
 *
 * The template itself is rendered in EventListTemplateTest, which proves its Result cell
 * is built from blueline_sp_event_state(), the SAME clock-vs-results decision
 * blueline_sp_event_hero() uses for the single-event page (unit tested directly in
 * tests/EventStateTest.php), so this table and that page cannot disagree about the
 * same event. This file proves the helpers' own 'pending' branch behaves the way the
 * template's copy assumes it does.
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
	 * A recent pending game says "coming soon"; a genuinely future game, and an
	 * old unscored one, keep the plain dash -- the fix adds a distinction, it
	 * does not replace the dash outright.
	 */
	public function test_result_cell_branches_on_pending_before_falling_back_to_the_dash(): void {
		$this->assertStringContainsString( 'Final score coming soon', blueline_sp_schedule_result_html( array(), 'ok', 'pending', true ) );
		$this->assertStringContainsString( '&#8212;', blueline_sp_schedule_result_html( array(), 'ok', 'pending', false ) );
		$this->assertStringContainsString( '&#8212;', blueline_sp_schedule_result_html( array(), 'ok', 'preview', true ) );
		$this->assertStringNotContainsString( 'Final score coming soon', blueline_sp_schedule_result_html( array(), 'ok', 'preview', true ) );
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
