<?php
/**
 * The single-game .ics: plain-text titles, RFC 5545 line folding and escaping.
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
final class EventIcsTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Build a VCALENDAR for a title and location.
	 *
	 * @param string $summary  Event title.
	 * @param string $location Location.
	 * @return string
	 */
	private function ics( string $summary, string $location = '' ): string {
		return blueline_ics_single_event(
			array(
				'summary'  => $summary,
				'location' => $location,
				'start'    => 1792191600,
				'end'      => 1792197000,
				'uid'      => 'sp-event-1@example.test',
				'host'     => 'example.test',
				'stamp'    => 1792000000,
			)
		);
	}

	/**
	 * Undo RFC 5545 folding: a CRLF followed by a space joins two lines.
	 *
	 * @param string $ics Folded text.
	 * @return string
	 */
	private function unfold( string $ics ): string {
		return str_replace( "\r\n ", '', $ics );
	}

	/**
	 * Every physical line is at most 75 octets, CRLF excluded.
	 *
	 * @param string $ics Folded text.
	 */
	private function assertLinesWithin75Octets( string $ics ): void {
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'line is ' . strlen( $line ) . ' octets: ' . $line );
		}
	}

	/**
	 * Register a game with the given display title.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Title as get_the_title() returns it (texturized HTML).
	 */
	private function event_titled( int $id, string $title ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'status' => 'future',
			'type'   => 'sp_event',
			'title'  => $title,
		);
	}

	/**
	 * A texturized title becomes the real characters, not entity codes.
	 */
	public function test_the_title_is_decoded_to_plain_text(): void {
		$this->event_titled( 4865, 'Kings vs Ducks (Dan&#8217;s Welding) &amp; <em>Sons</em>' );

		$this->assertSame( "Kings vs Ducks (Dan\u{2019}s Welding) & Sons", blueline_sp_event_calendar_title( 4865 ) );
	}

	/**
	 * The SUMMARY carries the decoded character, escaped like any text.
	 */
	public function test_the_summary_has_no_entity_codes(): void {
		$summary = blueline_sp_event_calendar_title( $this->seed( 4865, 'Kings vs Ducks (Dan&#8217;s Welding)' ) );
		$ics     = $this->ics( $summary );

		$this->assertStringContainsString( "SUMMARY:Kings vs Ducks (Dan\u{2019}s Welding)\r\n", $ics );
		$this->assertStringNotContainsString( '&#', $ics );
	}

	/**
	 * Register and return an event id.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Title.
	 * @return int
	 */
	private function seed( int $id, string $title ): int {
		$this->event_titled( $id, $title );

		return $id;
	}

	/**
	 * A short line is left alone.
	 */
	public function test_a_short_line_is_not_folded(): void {
		$this->assertSame( 'SUMMARY:Kings vs Ducks', blueline_ics_fold_line( 'SUMMARY:Kings vs Ducks' ) );
	}

	/**
	 * Exactly 75 octets is not folded; 76 is.
	 */
	public function test_the_fold_boundary_is_75_octets(): void {
		$this->assertSame( str_repeat( 'a', 75 ), blueline_ics_fold_line( str_repeat( 'a', 75 ) ) );
		$this->assertSame( str_repeat( 'a', 75 ) . "\r\n a", blueline_ics_fold_line( str_repeat( 'a', 76 ) ) );
	}

	/**
	 * A long name with an accent folds at 75 octets, never splits the accented
	 * letter in half, and unfolds back to the original text.
	 */
	public function test_a_long_accented_title_folds_without_splitting_a_character(): void {
		$ics = $this->ics( "Kings vs Ducks \u{2014} Caf\u{e9} Z\u{fc}rich Hockey Club presents the Ren\u{e9}e Beaulieu Memorial Tournament Final", "Mr. Lube and Tires Arena \u{2014} Black, 1179 Northside Rd, Burlington, ON L7M, Canada" );

		$this->assertLinesWithin75Octets( $ics );
		$this->assertStringContainsString( "\r\n ", $ics, 'a long title must have been folded' );

		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertTrue( mb_check_encoding( $line, 'UTF-8' ), 'a fold must not cut a multi-byte character: ' . bin2hex( $line ) );
		}

		$this->assertStringContainsString( "SUMMARY:Kings vs Ducks \u{2014} Caf\u{e9} Z\u{fc}rich Hockey Club presents the Ren\u{e9}e Beaulieu Memorial Tournament Final\r\n", $this->unfold( $ics ) );
		$this->assertStringContainsString( "LOCATION:Mr. Lube and Tires Arena \u{2014} Black\\, 1179 Northside Rd\\, Burlington\\, ON L7M\\, Canada\r\n", $this->unfold( $ics ) );
	}

	/**
	 * A line made only of two-octet letters folds on character boundaries.
	 */
	public function test_folding_a_run_of_multibyte_letters_keeps_each_letter_whole(): void {
		$folded = blueline_ics_fold_line( 'SUMMARY:' . str_repeat( "\u{e9}", 60 ) );

		foreach ( explode( "\r\n", $folded ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
			$this->assertTrue( mb_check_encoding( $line, 'UTF-8' ) );
		}

		$this->assertSame( 'SUMMARY:' . str_repeat( "\u{e9}", 60 ), $this->unfold( $folded ) );
	}

	/**
	 * Every line a calendar is made of obeys the limit, and the document is
	 * still CRLF-terminated.
	 */
	public function test_the_whole_document_is_crlf_and_within_the_limit(): void {
		$ics = $this->ics( str_repeat( 'Long name ', 20 ), str_repeat( 'Somewhere, ', 20 ) );

		$this->assertLinesWithin75Octets( $ics );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringEndsWith( "END:VEVENT\r\nEND:VCALENDAR\r\n", $ics );
		$this->assertSame( 0, preg_match( '/(?<!\r)\n/', $ics ), 'no bare LF anywhere' );
	}

	/**
	 * Commas, semicolons, backslashes and newlines are escaped; a bare CR is not left behind.
	 */
	public function test_text_escaping(): void {
		$this->assertSame( 'a\\\\b\\,c\\;d\\ne\\nf', blueline_ics_escape_text( "a\\b,c;d\ne\r\nf" ) );
	}

	/**
	 * With no location there is no LOCATION line.
	 */
	public function test_no_location_line_when_there_is_no_location(): void {
		$this->assertStringNotContainsString( 'LOCATION', $this->ics( 'Kings vs Ducks' ) );
	}
}
