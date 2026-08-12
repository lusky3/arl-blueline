<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * P0 finding 2: blueline_sp_event_hero() (and blueline_sp_event_teaser())
 * used to decide "Preview" vs "Final" purely from sp_get_status() ===
 * 'results', which is only ever true once a score has been entered --
 * confirmed live, eleven published events in the last 90 days had no
 * sp_results row at all and still advertised "Preview" with a live "Add to
 * calendar" link well after they had been played.
 *
 * This suite covers blueline_sp_event_state(), the pure extraction of the
 * fixed decision table this bug exposed: whether a result exists, and
 * whether the clock has passed the event's start time, are two independent
 * facts, and the only three states that can come out of them.
 */
final class EventStateTest extends TestCase {

	private const NOW = 1_700_000_000;

	/**
	 * A result on file always means 'final', regardless of the clock --
	 * covers a corrected/backdated entry scored before its listed start.
	 */
	public function test_a_result_on_file_is_always_final(): void {
		$this->assertSame( 'final', blueline_sp_event_state( true, self::NOW + 3600, self::NOW ) );
		$this->assertSame( 'final', blueline_sp_event_state( true, self::NOW - 3600, self::NOW ) );
		$this->assertSame( 'final', blueline_sp_event_state( true, false, self::NOW ) );
	}

	/**
	 * The exact bug: no result, but the start time has already passed --
	 * must be 'pending', never 'preview'.
	 */
	public function test_no_result_and_start_time_passed_is_pending_not_preview(): void {
		$this->assertSame( 'pending', blueline_sp_event_state( false, self::NOW - 1, self::NOW ) );
		$this->assertSame( 'pending', blueline_sp_event_state( false, self::NOW - ( 90 * 86400 ), self::NOW ) );
	}

	/**
	 * A start timestamp exactly equal to "now" has already started --
	 * inclusive boundary, not "starts in zero seconds".
	 */
	public function test_start_time_equal_to_now_is_pending(): void {
		$this->assertSame( 'pending', blueline_sp_event_state( false, self::NOW, self::NOW ) );
	}

	/**
	 * No result, start time still ahead of now -- the only state that gets
	 * a calendar link.
	 */
	public function test_no_result_and_start_time_ahead_is_preview(): void {
		$this->assertSame( 'preview', blueline_sp_event_state( false, self::NOW + 1, self::NOW ) );
	}

	/**
	 * An unknown start timestamp (false) with no result can never be
	 * 'pending' -- there is nothing to compare against, so it must fall
	 * back to 'preview' rather than silently claiming the game happened.
	 */
	public function test_unknown_start_time_with_no_result_is_preview(): void {
		$this->assertSame( 'preview', blueline_sp_event_state( false, false, self::NOW ) );
	}

	/**
	 * $now_timestamp defaults to the real clock when omitted -- a start
	 * time far in the past with no result must still resolve to 'pending'
	 * without a test needing to pass "now" explicitly.
	 */
	public function test_now_timestamp_defaults_to_the_real_clock(): void {
		$this->assertSame( 'pending', blueline_sp_event_state( false, time() - 3600 ) );
		$this->assertSame( 'preview', blueline_sp_event_state( false, time() + 3600 ) );
	}
}
