<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Covers a live-review finding with zero prior test coverage: "The Next
 * Puck Drop" countdown widget got permanently stuck on a game from over a
 * week in the past whose result was never entered, showing
 * 00 Days 00 Hrs 00 Mins 00 Secs forever instead of advancing to the real
 * next dated game -- confirmed live on staging while real future games
 * existed the same week.
 *
 * The function blueline_sp_decide_countdown_event() carries the actual
 * decision this bug turned on: never keep a stale pick when a real future
 * event is available, and flag when it must fall back to one anyway
 * (nothing future exists at all) so the template can render "already
 * played" copy instead of a live countdown. Kept free of WP_Query calls,
 * per this file's docblock on blueline_sp_next_dated_event(), so it is
 * directly unit testable.
 */
final class CountdownEventTest extends TestCase {

	private const NOW = 1_755_820_800; // 2025-08-22T00:00:00Z, arbitrary but fixed.

	/**
	 * A picked event dated in the past is stale.
	 */
	public function test_a_past_post_date_is_past(): void {
		$this->assertTrue( blueline_sp_event_date_is_past( '2025-08-14 18:00:00', self::NOW ) );
	}

	/**
	 * A picked event dated in the future is not stale.
	 */
	public function test_a_future_post_date_is_not_past(): void {
		$this->assertFalse( blueline_sp_event_date_is_past( '2025-08-28 18:00:00', self::NOW ) );
	}

	/**
	 * No date at all (no event picked) is never treated as "past".
	 */
	public function test_no_date_is_not_past(): void {
		$this->assertFalse( blueline_sp_event_date_is_past( '', self::NOW ) );
	}

	/**
	 * A picked event that is already future-dated is used as-is -- the
	 * common, correct case, untouched by this fix.
	 */
	public function test_future_pick_is_used_as_is(): void {
		$picked = array(
			'ID'        => 1,
			'post_date' => '2025-08-28 18:00:00',
		);

		$result = blueline_sp_decide_countdown_event( $picked, null, self::NOW );

		$this->assertSame( $picked, $result['event'] );
		$this->assertTrue( $result['is_future'] );
	}

	/**
	 * Nothing picked at all: nothing to show, and not a "future" event.
	 */
	public function test_no_pick_at_all_stays_null(): void {
		$result = blueline_sp_decide_countdown_event( null, null, self::NOW );

		$this->assertNull( $result['event'] );
		$this->assertFalse( $result['is_future'] );
	}

	/**
	 * THE EXACT BUG: a stale pick (Puck Dynasty vs Hammers, Aug 14, no
	 * result) must be swapped for the real next dated event (Aug 28) when
	 * one exists in scope -- never shown as a frozen 00:00:00:00 countdown.
	 */
	public function test_stale_pick_is_replaced_by_the_real_next_event(): void {
		$stale  = array(
			'ID'        => 1,
			'post_date' => '2025-08-14 18:00:00',
		);
		$future = array(
			'ID'        => 2,
			'post_date' => '2025-08-28 18:00:00',
		);

		$result = blueline_sp_decide_countdown_event( $stale, $future, self::NOW );

		$this->assertSame( $future, $result['event'] );
		$this->assertTrue( $result['is_future'] );
	}

	/**
	 * A stale pick with NOTHING future anywhere in scope survives -- but is
	 * flagged is_future=false so the template renders it as an already-
	 * played result, never a live countdown claiming to be on time.
	 */
	public function test_stale_pick_with_no_future_alternative_survives_flagged_as_not_future(): void {
		$stale = array(
			'ID'        => 1,
			'post_date' => '2025-08-14 18:00:00',
		);

		$result = blueline_sp_decide_countdown_event( $stale, null, self::NOW );

		$this->assertSame( $stale, $result['event'] );
		$this->assertFalse( $result['is_future'] );
	}
}
