<?php
/**
 * Phone schedule cards put each team on a line of its own.
 *
 * With both teams on one flowing line, a long pair wrapped "vs Away" onto a
 * second line, so cards differed in height and alignment. Each team now takes
 * a full line, with a gutter for the "vs". The layout itself is checked in a
 * browser; here the card's rules are source-scanned.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class ScheduleCardTeamsTest extends TestCase {

	/**
	 * The schedule card block of the SportsPress stylesheet (the narrow-container query).
	 *
	 * @return string
	 */
	private function card_css(): string {
		$css   = (string) file_get_contents( __DIR__ . '/../assets/src/css/sportspress.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$start = strpos( $css, '@container bl-sched (width < 41em)' );

		$this->assertNotFalse( $start, 'the schedule card container query exists' );

		return (string) substr( $css, (int) $start );
	}

	/**
	 * Home and Away each take a full line, in a grid with a "vs" gutter.
	 */
	public function test_each_team_takes_a_full_line_with_a_vs_gutter(): void {
		$css = $this->card_css();

		$this->assertMatchesRegularExpression( '/td\.data-away \{\s*display: grid;\s*grid-template-columns: 1\.75rem minmax\(0, 1fr\);.*?flex: 1 0 100%;/s', $css );
		$this->assertDoesNotMatchRegularExpression( '/td\.data-away \{\s*display: flex;/', $css, 'teams must not share a flowing line' );
	}

	/**
	 * The crest and name start in the second column on both lines.
	 */
	public function test_crest_and_name_share_a_column(): void {
		$this->assertMatchesRegularExpression( '/td\.data-away a \{\s*grid-column: 2;/', $this->card_css() );
	}

	/**
	 * The "vs" is shown (the table hides it) and hangs in the first column.
	 */
	public function test_vs_is_shown_in_the_gutter(): void {
		$this->assertMatchesRegularExpression( '/\.bl-sp-schedule__vs \{\s*display: inline;\s*grid-column: 1;/', $this->card_css() );
	}
}
