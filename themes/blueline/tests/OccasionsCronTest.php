<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/cache.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers the WP-Cron boundary purge (design spec §5/§7.8): cron is used
 * ONLY for the purge timing, never for correctness -- every assertion
 * here is about SCHEDULING, not about what is active (that is
 * tests/OccasionsResolverTest.php's job).
 */
final class OccasionsCronTest extends TestCase {

	/**
	 * Reset shared in-memory state before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * An auto-mode occasion fixture with the given window.
	 *
	 * $id defaults to 'test-occasion', which is fine for a fixture passed
	 * directly to blueline_occasion_next_boundary_timestamp() (it never
	 * looks at 'id' at all). A fixture that instead goes through
	 * update_option( BLUELINE_SETTINGS_OPTION, ... ) is revalidated by
	 * blueline_sanitize_occasions() (inc/occasions.php), which DOES
	 * require 'id' to match the value's own map key -- callers that store
	 * this under a key other than 'test-occasion' must pass that key here
	 * too, or the entry is silently dropped and the test asserts against
	 * an empty `occasions` array instead of the fixture it thinks it set up.
	 *
	 * @param string $start_md 'MM-DD'.
	 * @param string $end_md   'MM-DD'.
	 * @param string $id       Occasion id; must match the array key this
	 *                         fixture is stored under whenever it is
	 *                         written via update_option().
	 * @return array<string, mixed>
	 */
	private function auto_occasion( string $start_md, string $end_md, string $id = 'test-occasion' ): array {
		return array(
			'id'     => $id,
			'label'  => 'Test Occasion',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => $start_md,
				'end_md'   => $end_md,
			),
			'accent' => '',
			'motif'  => 'none',
			'line'   => '',
			'mode'   => 'auto',
		);
	}

	/* --------------------------------------------- next_occurrence_timestamp */

	/**
	 * Asserts an MD still ahead in the current calendar year resolves
	 * within that same year.
	 */
	public function test_next_occurrence_still_ahead_this_year(): void {
		$after = ( new DateTimeImmutable( '2026-01-01 00:00:00', wp_timezone() ) )->getTimestamp();

		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $after );

		$this->assertSame(
			( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
			$next
		);
	}

	/**
	 * Asserts an MD already passed this year rolls forward to next year.
	 */
	public function test_next_occurrence_rolls_to_next_year_once_passed(): void {
		$after = ( new DateTimeImmutable( '2026-08-01 00:00:00', wp_timezone() ) )->getTimestamp();

		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $after );

		$this->assertSame(
			( new DateTimeImmutable( '2027-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
			$next
		);
	}

	/**
	 * Asserts the returned timestamp is always strictly after $after, even
	 * when $after IS the boundary instant itself (the boundary that just
	 * passed must roll to next year, not return itself again).
	 */
	public function test_next_occurrence_is_strictly_after_the_boundary_instant_itself(): void {
		$boundary = ( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp();

		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $boundary );

		$this->assertGreaterThan( $boundary, $next );
	}

	/* ----------------------------------------------- next_boundary_timestamp */

	/**
	 * Asserts no stored occasions at all resolves to null.
	 */
	public function test_next_boundary_null_when_nothing_stored(): void {
		$this->assertNull( blueline_occasion_next_boundary_timestamp( array(), time() ) );
	}

	/**
	 * Asserts a force_on/force_off occasion contributes no boundary --
	 * its activation is not date-driven, so it has none to purge for.
	 */
	public function test_next_boundary_ignores_non_auto_occasions(): void {
		$forced_on          = $this->auto_occasion( '07-01', '07-01' );
		$forced_on['mode']  = 'force_on';
		$forced_off         = $this->auto_occasion( '08-01', '08-01' );
		$forced_off['mode'] = 'force_off';

		$this->assertNull(
			blueline_occasion_next_boundary_timestamp(
				array(
					'a' => $forced_on,
					'b' => $forced_off,
				),
				time()
			)
		);
	}

	/**
	 * Asserts BOTH the start and end of a single auto occasion's window
	 * are considered, and the soonest of the two (here, the end, since
	 * $now already falls inside the window) is what is returned.
	 */
	public function test_next_boundary_considers_both_start_and_end(): void {
		$now = ( new DateTimeImmutable( '2026-12-10 00:00:00', wp_timezone() ) )->getTimestamp();

		$boundary = blueline_occasion_next_boundary_timestamp(
			array( 'christmas' => $this->auto_occasion( '12-01', '12-26' ) ),
			$now
		);

		$this->assertSame(
			( new DateTimeImmutable( '2026-12-26 00:00:00', wp_timezone() ) )->getTimestamp(),
			$boundary
		);
	}

	/**
	 * Asserts the SOONEST boundary across multiple stored occasions wins.
	 */
	public function test_next_boundary_picks_the_soonest_across_multiple_occasions(): void {
		$now = ( new DateTimeImmutable( '2026-01-01 00:00:00', wp_timezone() ) )->getTimestamp();

		$boundary = blueline_occasion_next_boundary_timestamp(
			array(
				'canada-day' => $this->auto_occasion( '07-01', '07-01' ),
				'christmas'  => $this->auto_occasion( '12-01', '12-26' ),
			),
			$now
		);

		$this->assertSame(
			( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
			$boundary
		);
	}

	/* ----------------------------------------- schedule_next_boundary_purge */

	/**
	 * Asserts scheduling with an auto occasion stored actually schedules
	 * the cron hook for its computed boundary.
	 */
	public function test_schedule_next_boundary_purge_schedules_when_an_auto_occasion_exists(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01', 'canada-day' ) ) ) );

		blueline_occasion_schedule_next_boundary_purge();

		$this->assertNotFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
	}

	/**
	 * Asserts scheduling with NO auto occasion stored leaves nothing
	 * scheduled, and clears a previously scheduled event if one exists
	 * (e.g. the last auto occasion was just removed).
	 */
	public function test_schedule_next_boundary_purge_clears_when_no_auto_occasion_remains(): void {
		wp_schedule_single_event( time() + 3600, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array() ) );

		blueline_occasion_schedule_next_boundary_purge();

		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
	}

	/**
	 * Asserts changing which occasion is stored reschedules to the NEW
	 * boundary rather than leaving the stale one in place.
	 */
	public function test_schedule_next_boundary_purge_reschedules_when_the_boundary_changes(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01', 'canada-day' ) ) ) );
		blueline_occasion_schedule_next_boundary_purge();
		$first = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'remembrance-day' => $this->auto_occasion( '11-11', '11-11', 'remembrance-day' ) ) ) );
		blueline_occasion_schedule_next_boundary_purge();
		$second = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

		$this->assertNotSame( $first, $second );
	}

	/* ------------------------------------------------------- cron callback */

	/**
	 * Asserts the cron callback purges the page cache (observed via the
	 * "manual purge needed" flag it sets when BLUELINE_SRCACHE_PURGE is
	 * off, the shipped default) and reschedules for the FOLLOWING
	 * boundary, not the one that just fired.
	 *
	 * Both scheduling calls pass an explicit $now_override, one hour
	 * before and then exactly AT this year's 07-01 boundary respectively
	 * -- simulating real WP-Cron firing at (or just after) the instant it
	 * was scheduled for. Without pinning $now like this, both calls would
	 * run against the real system clock a fraction of a second apart,
	 * both computing the SAME "next 07-01" target (whichever one is still
	 * ahead of today) and making the "reschedules for the FOLLOWING
	 * boundary" claim untestable.
	 */
	public function test_cron_callback_purges_and_reschedules_for_the_following_boundary(): void {
		$this_years_boundary = ( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp();

		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01', 'canada-day' ) ) ) );
		blueline_occasion_schedule_next_boundary_purge( $this_years_boundary - 3600 );
		$before = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
		$this->assertSame( $this_years_boundary, $before );

		blueline_occasion_cron_boundary_purge( $this_years_boundary );

		$this->assertNotFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ) );

		$after = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
		$this->assertNotFalse( $after );
		$this->assertGreaterThan( $before, $after );
		$this->assertSame(
			( new DateTimeImmutable( '2027-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
			$after
		);
	}

	/* -------------------------------------- clear_scheduled_boundary_purge */

	/**
	 * Asserts the clear function removes a scheduled event outright.
	 */
	public function test_clear_scheduled_boundary_purge_removes_the_event(): void {
		wp_schedule_single_event( time() + 3600, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

		blueline_occasion_clear_scheduled_boundary_purge();

		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
	}

	/**
	 * Asserts the clear function is a harmless no-op when nothing is
	 * scheduled.
	 */
	public function test_clear_scheduled_boundary_purge_is_a_noop_when_nothing_is_scheduled(): void {
		blueline_occasion_clear_scheduled_boundary_purge();

		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
	}
}
