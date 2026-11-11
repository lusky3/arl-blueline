<?php
/**
 * The SportsPress team calendar feed (`?feed=sp-ical`) the team pages and My
 * Team link to (FUNC-24). SportsPress writes floating local times, a TZID
 * property at calendar level (where RFC 5545 has none), a 1970 DTSTAMP and
 * bare post-ID UIDs; the theme rewrites the feed's output on the way out.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class TeamIcalFeedTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * What SportsPress Pro 2.7's feeds/ical.php prints for one game, with
	 * its URL folded the way it folds it.
	 *
	 * @param string $start DTSTART value (site-local, floating).
	 * @param string $end   DTEND value.
	 * @return string
	 */
	private function vendor_feed( string $start = '20261114T184500', string $end = '20261114T201500' ): string {
		return implode(
			"\r\n",
			array(
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//ThemeBoy//SportsPress//EN',
				'URL:https://arlhockey.ca/calendar/kings-calendar/?feed=sp-',
				"\tical",
				'X-WR-CALNAME:Kings',
				'TZID:America/Toronto',
				'X-WR-TIMEZONE:America/Toronto',
				'BEGIN:VEVENT',
				'SUMMARY:Kings vs Ice Bears',
				'UID:117486',
				'STATUS:CONFIRMED',
				'DTSTAMP:19700101T000000',
				'DTSTART:' . $start,
				'DTEND:' . $end,
				'LAST-MODIFIED:20261101T120304',
				'LOCATION:Rink A',
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);
	}

	/**
	 * Normalise a feed in Toronto time for host arlhockey.ca.
	 *
	 * @param string $ics Feed text.
	 * @return string
	 */
	private function normalize( string $ics ): string {
		return blueline_sp_ical_feed_normalize( $ics, new DateTimeZone( 'America/Toronto' ), 'arlhockey.ca' );
	}

	/**
	 * Times become UTC (DST-aware), so a client that ignores X-WR-TIMEZONE
	 * still shows the game at the right time.
	 */
	public function test_floating_times_become_utc(): void {
		$ics = $this->normalize( $this->vendor_feed() );

		$this->assertStringContainsString( "\r\nDTSTART:20261114T234500Z\r\n", $ics, 'EST is UTC-5 in November' );
		$this->assertStringContainsString( "\r\nDTEND:20261115T011500Z\r\n", $ics, 'crossing midnight UTC' );

		$summer = $this->normalize( $this->vendor_feed( '20260915T190000', '20260915T203000' ) );
		$this->assertStringContainsString( "\r\nDTSTART:20260915T230000Z\r\n", $summer, 'EDT is UTC-4' );
	}

	/**
	 * The calendar-level TZID property is dropped; X-WR-TIMEZONE stays.
	 */
	public function test_the_misplaced_tzid_is_dropped(): void {
		$ics = $this->normalize( $this->vendor_feed() );

		$this->assertDoesNotMatchRegularExpression( '/^TZID:/m', $ics );
		$this->assertStringContainsString( "\r\nX-WR-TIMEZONE:America/Toronto\r\n", $ics );
	}

	/**
	 * DTSTAMP is the game's last change (UTC), not 1970; LAST-MODIFIED says UTC.
	 */
	public function test_dtstamp_and_last_modified_are_real_utc_times(): void {
		$ics = $this->normalize( $this->vendor_feed() );

		$this->assertStringContainsString( "\r\nDTSTAMP:20261101T120304Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nLAST-MODIFIED:20261101T120304Z\r\n", $ics );
		$this->assertStringNotContainsString( '19700101', $ics );
	}

	/**
	 * UIDs are globally unique, and match the single-game .ics download.
	 */
	public function test_uids_carry_the_host(): void {
		$this->assertStringContainsString( "\r\nUID:sp-event-117486@arlhockey.ca\r\n", $this->normalize( $this->vendor_feed() ) );
	}

	/**
	 * Everything else, folded lines included, is passed through untouched.
	 */
	public function test_other_lines_are_untouched(): void {
		$ics = $this->normalize( $this->vendor_feed() );

		$this->assertStringContainsString( "URL:https://arlhockey.ca/calendar/kings-calendar/?feed=sp-\r\n\tical\r\n", $ics );
		$this->assertStringContainsString( "\r\nSUMMARY:Kings vs Ice Bears\r\n", $ics );
		$this->assertStringEndsWith( "END:VEVENT\r\nEND:VCALENDAR", $ics );
	}

	/**
	 * Output that is not a calendar (SportsPress's wp_die() page) is left alone.
	 */
	public function test_non_calendar_output_is_left_alone(): void {
		$this->assertSame( '<html>ERROR</html>', blueline_sp_ical_feed_filter_output( '<html>ERROR</html>' ) );
	}

	/**
	 * The feed's output is buffered around SportsPress's own handler.
	 */
	public function test_the_feed_is_wrapped_around_sportspress_s_handler(): void {
		$this->assertSame( 9, has_action( 'do_feed_sp-ical', 'blueline_sp_ical_feed_buffer_start' ) );
		$this->assertSame( 11, has_action( 'do_feed_sp-ical', 'blueline_sp_ical_feed_buffer_end' ) );

		$level = ob_get_level();
		ob_start();
		blueline_sp_ical_feed_buffer_start();
		echo $this->vendor_feed(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test fixture text.
		blueline_sp_ical_feed_buffer_end();
		$out = (string) ob_get_clean();

		$this->assertSame( $level, ob_get_level(), 'its own buffer is closed' );
		$this->assertMatchesRegularExpression( '/\r\nUID:sp-event-117486@[^\r]+\r\n/', $out );
		$this->assertMatchesRegularExpression( '/\r\nDTSTART:\d{8}T\d{6}Z\r\n/', $out );
	}
}
