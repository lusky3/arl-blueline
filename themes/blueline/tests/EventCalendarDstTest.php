<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * The single-game "Add to calendar" links (Google and .ics) across a
 * daylight-saving change. Games are stored in site-local time (Toronto); the
 * links must carry the real UTC instant on both sides of the change, and a
 * game's end must be its length in elapsed time, not on the wall clock.
 *
 * Each test runs in its own process because the real get_gmt_from_date()
 * these need (tests/fixtures/event-calendar-stubs.php) cannot coexist with
 * the fixed one PlayerScheduleTest loads.
 */
#[RunTestsInSeparateProcesses]
final class EventCalendarDstTest extends TestCase {

	/**
	 * Fresh state, the real date conversion, and Toronto as the site zone.
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/fixtures/event-calendar-stubs.php';
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Register an on-time sp_event starting at a site-local date.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $local    Site-local start, 'Y-m-d H:i:s'.
	 */
	private function event( int $event_id, string $local ): void {
		$state                           = &blueline_test_state();
		$state['posts'][ $event_id ]     = array(
			'status'    => 'future',
			'type'      => 'sp_event',
			'title'     => 'Kings vs Ducks',
			'post_date' => $local,
		);
		$state['post_meta'][ $event_id ] = array( 'sp_status' => 'ok' );
	}

	/**
	 * The DTSTART/DTEND pair of a single-game .ics link.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{0:string, 1:string}
	 */
	private function ics_bounds( int $event_id ): array {
		$ics = rawurldecode( substr( blueline_sp_event_ics_url( $event_id ), strlen( 'data:text/calendar;charset=utf8,' ) ) );

		preg_match( "/\r\nDTSTART:(\\S+)\r\n/", $ics, $start );
		preg_match( "/\r\nDTEND:(\\S+)\r\n/", $ics, $end );

		return array( $start[1] ?? '', $end[1] ?? '' );
	}

	/**
	 * The `dates` value of a single-game Google Calendar link.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	private function google_dates( int $event_id ): string {
		parse_str( (string) wp_parse_url( blueline_sp_event_calendar_url( $event_id ), PHP_URL_QUERY ), $query );

		return (string) ( $query['dates'] ?? '' );
	}

	/**
	 * Games on each side of the 2026 clock changes in Toronto.
	 *
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function games_around_a_clock_change(): array {
		return array(
			'7 PM, the Saturday before clocks go back (EDT, UTC-4)' => array( '2026-10-31 19:00:00', '20261031T230000Z', '20261101T003000Z' ),
			'7 PM, the Sunday clocks went back (EST, UTC-5)' => array( '2026-11-01 19:00:00', '20261102T000000Z', '20261102T013000Z' ),
			'7 PM, the Saturday before clocks go forward (EST)' => array( '2026-03-07 19:00:00', '20260308T000000Z', '20260308T013000Z' ),
			'7 PM, the Sunday clocks went forward (EDT)' => array( '2026-03-08 19:00:00', '20260308T230000Z', '20260309T003000Z' ),
		);
	}

	/**
	 * The same 7 PM local start lands an hour apart in UTC across the change,
	 * and both links say so identically.
	 *
	 * @param string $local Site-local start.
	 * @param string $start Expected UTC start.
	 * @param string $end   Expected UTC end (90 minutes later).
	 */
	#[DataProvider( 'games_around_a_clock_change' )]
	public function test_links_carry_the_utc_instant_on_both_sides_of_a_clock_change( string $local, string $start, string $end ): void {
		$this->event( 900, $local );

		$this->assertSame( array( $start, $end ), $this->ics_bounds( 900 ) );
		$this->assertSame( $start . '/' . $end, $this->google_dates( 900 ) );
	}

	/**
	 * A late game that runs through the repeated 1-2 AM hour on the night the
	 * clocks go back: 12:45 AM EDT plus 90 minutes is 1:15 AM EST on the wall,
	 * but still exactly 90 minutes later in UTC.
	 */
	public function test_a_game_spanning_the_repeated_hour_lasts_exactly_its_length(): void {
		$this->event( 901, '2026-11-01 00:45:00' );

		$this->assertSame( array( '20261101T044500Z', '20261101T061500Z' ), $this->ics_bounds( 901 ) );

		$bounds = blueline_sp_event_calendar_bounds( 901 );
		$this->assertSame( 90 * MINUTE_IN_SECONDS, $bounds['end'] - $bounds['start'] );
	}

	/**
	 * The .ics is a well-formed one-event calendar: CRLF line endings
	 * throughout, and one VEVENT with a stable per-event UID.
	 */
	public function test_the_ics_is_a_single_crlf_terminated_event(): void {
		$this->event( 902, '2026-11-01 19:00:00' );

		$ics = rawurldecode( substr( blueline_sp_event_ics_url( 902 ), strlen( 'data:text/calendar;charset=utf8,' ) ) );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringEndsWith( "END:VEVENT\r\nEND:VCALENDAR\r\n", $ics );
		$this->assertSame( 0, preg_match( "/(?<!\r)\n/", $ics ), 'every line ends in CRLF' );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertMatchesRegularExpression( "/\r\nUID:sp-event-902@[^\r]+\r\n/", $ics );
		$this->assertStringContainsString( "\r\nSUMMARY:Kings vs Ducks\r\n", $ics );
	}
}
