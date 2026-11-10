<?php
/**
 * Phone schedule cards put each team on a line of its own.
 *
 * With both teams on one flowing line, a long pair wrapped "vs Away" onto a
 * second line, so cards differed in height and alignment. Each team now takes
 * a full line of three columns: a "vs" gutter, a crest and the name. A linked
 * team's `<a>` takes the last two; with team links off (SportsPress's default)
 * the cell holds a bare crest span and name span, each placed in its own
 * column. The layout itself is checked in a browser; here the card's rules are
 * source-scanned, each inside the container query they belong to.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class ScheduleCardTeamsTest extends TestCase {

	private const CARD_QUERY = '@container bl-sched (width < 41em)';

	/**
	 * Read the SportsPress stylesheet source.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( __DIR__ . '/../assets/src/css/sportspress.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * The contents of the at-rule that starts with a prelude, up to its matching closing brace.
	 *
	 * @param string $css     Stylesheet source.
	 * @param string $prelude The at-rule's opening text, e.g. "@media (max-width: 700px)".
	 * @return string
	 */
	private function block( string $css, string $prelude ): string {
		$start = strpos( $css, $prelude );
		$this->assertNotFalse( $start, "{$prelude} exists" );

		$open  = (int) strpos( $css, '{', (int) $start );
		$depth = 0;
		$len   = strlen( $css );

		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
				return substr( $css, $open + 1, $i - $open - 1 );
			}
		}

		$this->fail( "{$prelude} is never closed" );
	}

	/**
	 * Home and Away each take a full line, in a grid with a "vs" gutter and a crest column.
	 */
	public function test_each_team_takes_a_full_line_of_three_columns(): void {
		$card = $this->block( $this->css(), self::CARD_QUERY );

		$this->assertMatchesRegularExpression( '/td\.data-away \{\s*display: grid;\s*grid-template-columns: 1\.75rem 2rem minmax\(0, 1fr\);.*?flex: 1 0 100%;/s', $card );
		$this->assertDoesNotMatchRegularExpression( '/td\.data-away \{\s*display: flex;/', $card, 'teams must not share a flowing line' );
	}

	/**
	 * A linked team's anchor holds crest and name, so it spans the last two columns.
	 */
	public function test_a_linked_team_spans_the_crest_and_name_columns(): void {
		$card = $this->block( $this->css(), self::CARD_QUERY );

		$this->assertMatchesRegularExpression( '/td\.data-away a \{\s*grid-column: 2 \/ -1;\s*grid-row: 1;/', $card );
	}

	/**
	 * With team links off, the bare crest and name are placed in their own columns.
	 */
	public function test_an_unlinked_team_places_crest_and_name_in_their_own_columns(): void {
		$card = $this->block( $this->css(), self::CARD_QUERY );

		foreach ( array( 'data-home', 'data-away' ) as $cell ) {
			$this->assertMatchesRegularExpression( '/td\.' . $cell . ' > \.team-logo[,\s{].*?grid-column: 2;\s*grid-row: 1;/s', $card );
			$this->assertMatchesRegularExpression( '/td\.' . $cell . ' > \.bl-sp-team-name[,\s{].*?grid-column: 3;\s*grid-row: 1;/s', $card );
		}
	}

	/**
	 * The "vs" is shown (the table hides it) and hangs in the first column.
	 */
	public function test_vs_is_shown_in_the_gutter(): void {
		$css  = $this->css();
		$card = $this->block( $css, self::CARD_QUERY );

		$this->assertMatchesRegularExpression( '/\.bl-sp-schedule__vs \{\s*display: inline;\s*grid-column: 1;\s*grid-row: 1;/', $card );
		$this->assertMatchesRegularExpression( '/\n\.bl-sp-schedule__vs \{\s*display: none;/', $css, 'the table still hides the "vs"' );
	}

	/**
	 * None of the card layout leaks outside the container query into the table.
	 */
	public function test_the_card_layout_does_not_leak_into_the_table(): void {
		$css     = $this->css();
		$outside = str_replace( $this->block( $css, self::CARD_QUERY ), '', $css );

		$this->assertStringNotContainsString( 'grid-template-columns: 1.75rem 2rem', $outside );
		$this->assertStringNotContainsString( 'td.data-away > .team-logo', $outside );
	}
}
