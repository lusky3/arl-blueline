<?php
/**
 * Pins the wiring behind the phone fix for cut-off team names on the schedule.
 *
 * On a phone a 13-letter team name broke mid-word ("Lumberjac/ks") because the
 * Home/Away cells set `overflow-wrap: anywhere`. The fix gives the visible name
 * its own element so a name too long for its column wraps, is clamped past two
 * lines and offered in full (assets/src/js/team-name-tooltip.js, unit-tested in
 * team-name-tooltip.test.mjs). The template's half (the name in its own element)
 * is rendered in EventListTemplateTest; this source-scans the stylesheet, which
 * no unit test can execute.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class EventListTeamNameClampTest extends TestCase {

	/**
	 * Read a theme file.
	 *
	 * @param string $relative Path relative to the theme root.
	 * @return string
	 */
	private function theme_file( string $relative ): string {
		return (string) file_get_contents( __DIR__ . '/../' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * A name wraps onto a second line before anything is cut off (WCAG 1.4.12:
	 * text spacing overrides widen every word, and a single-line ellipsis then
	 * hid part of it), is clamped only past two lines, and can shrink below its
	 * content or the clamp never takes effect.
	 */
	public function test_stylesheet_wraps_the_name_to_two_lines_before_clamping(): void {
		$css = $this->theme_file( 'assets/src/css/sportspress.css' );

		$this->assertMatchesRegularExpression(
			'/\.bl-sp-schedule \.bl-sp-team-name\s*\{[^}]*display:\s*-webkit-box;[^}]*-webkit-line-clamp:\s*2;[^}]*min-width:\s*0;[^}]*overflow:\s*hidden;[^}]*overflow-wrap:\s*anywhere;/s',
			$css
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.bl-sp-schedule \.bl-sp-team-name\s*\{[^}]*white-space:\s*nowrap/s',
			$css,
			'a one-line name cannot wrap out of a text-spacing override'
		);
	}

	/**
	 * Once the schedule is narrower than its own minimum width it is cards,
	 * not a sideways-scrolling table: the minimum is dropped and every row is
	 * laid out on its own. The switch is the schedule's own width, so there is
	 * no viewport range in which it scrolls a little with columns cut off.
	 */
	public function test_stylesheet_stacks_rows_as_cards_on_phones(): void {
		$css = $this->theme_file( 'assets/src/css/sportspress.css' );

		$this->assertSame( 1, preg_match( '/@container bl-sched \(width < 41em\) \{\s*\.sp-event-list\.bl-sp-schedule,.*?\n\}/s', $css, $block ) );
		$this->assertMatchesRegularExpression( '/min-width:\s*0;/', $block[0] );
		$this->assertMatchesRegularExpression( '/tbody tr\.sp-row \{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $block[0] );
		$this->assertMatchesRegularExpression( '/td\.data-time \{[^}]*order:\s*2;[^}]*text-align:\s*end;/s', $block[0] );
		$this->assertMatchesRegularExpression( '/td\.data-venue \{[^}]*order:\s*7;/s', $block[0] );
	}

	/**
	 * The card threshold is the table's own minimum width, so the table is
	 * never given less room than it needs without turning into cards: the
	 * default columns add up to the 41rem the container query switches at.
	 */
	public function test_card_threshold_equals_the_tables_default_minimum_width(): void {
		$css  = $this->theme_file( 'assets/src/css/sportspress.css' );
		$vars = array();

		$this->assertSame( 1, preg_match( '/\n\.sp-table-wrapper:has\(\.bl-sp-schedule\) \{\s*--bl-sched-date:(.*?)\n\}/s', $css, $block ) );
		preg_match_all( '/--bl-sched-(date|home|time|away|league|season|venue|article|day):\s*(\d+(?:\.\d+)?);/', $block[1], $found, PREG_SET_ORDER );

		foreach ( $found as $row ) {
			$vars[ $row[1] ] = (float) $row[2];
		}
		$vars['date'] = 5.25;

		$this->assertSame( 41.0, array_sum( $vars ), 'default column weights, in rem' );
		$this->assertMatchesRegularExpression( '/container:\s*bl-sched \/ inline-size;/', $block[1] );
		$this->assertMatchesRegularExpression( '/\.sp-event-list\.bl-sp-schedule \{\s*table-layout: fixed;\s*min-width: calc\(var\(--bl-sched-total\) \* 1rem\);/', $css );
	}

	/**
	 * A calendar showing League and Season (7 columns, 52.25rem) must switch to
	 * cards at its own minimum width, not the default columns' 41rem: between
	 * the two it scrolled up to 178px sideways with Season and Arena cut off.
	 * A size query cannot read the column total, so the wrapper's font size
	 * carries it: the query's 41em is total / 41 x 41 = total of the inherited
	 * size, and the wrapper's children undo the scale so no text changes.
	 */
	public function test_card_threshold_follows_the_columns_actually_shown(): void {
		$css = $this->theme_file( 'assets/src/css/sportspress.css' );

		$this->assertSame( 1, preg_match( '/\n\.sp-table-wrapper:has\(\.bl-sp-schedule\) \{(.*?)\n\}/s', $css, $wrapper ) );
		$this->assertMatchesRegularExpression( '/font-size:\s*calc\(1em \* var\(--bl-sched-total\) \/ 41\);/', $wrapper[1] );
		$this->assertMatchesRegularExpression( '/\.sp-table-wrapper:has\(\.bl-sp-schedule\) > \* \{\s*font-size:\s*calc\(1em \* 41 \/ var\(--bl-sched-total\)\);/', $css );
		$this->assertSame( 1, preg_match_all( '/@container bl-sched \(width < 41em\)/', $css ) );
		$this->assertDoesNotMatchRegularExpression( '/@container bl-sched \([^)]*rem\)/', $css, 'a rem threshold ignores the column set' );

		// Each optional column's weight is set where the container can see it.
		$weights = array();
		foreach ( array( 'league', 'season', 'article', 'day' ) as $column ) {
			$this->assertSame( 1, preg_match( '/\.sp-table-wrapper:has\(\.bl-sp-schedule thead th\.data-' . $column . '\) \{\s*--bl-sched-' . $column . ':\s*(\d+(?:\.\d+)?);/', $css, $m ), $column );
			$weights[ $column ] = (float) $m[1];
		}
		$this->assertSame( 52.25, 41.0 + $weights['league'] + $weights['season'], 'the current-season calendar' );
	}

	/**
	 * Home and Away get enough weight for the widest active team word at the
	 * table's phone-width minimum (10.5rem leaves room for "Stormtroopers" plus
	 * the crest and padding); a regression back to the old 8.5 reintroduces the
	 * mid-word break the clamp is only the safety net for.
	 */
	public function test_home_and_away_columns_are_wide_enough_for_the_longest_active_word(): void {
		$css = $this->theme_file( 'assets/src/css/sportspress.css' );

		$this->assertMatchesRegularExpression( '/--bl-sched-home:\s*(\d+(\.\d+)?);/', $css );
		preg_match( '/--bl-sched-home:\s*(\d+(?:\.\d+)?);/', $css, $home );
		preg_match( '/--bl-sched-away:\s*(\d+(?:\.\d+)?);/', $css, $away );

		$this->assertGreaterThanOrEqual( 10.5, (float) $home[1] );
		$this->assertGreaterThanOrEqual( 10.5, (float) $away[1] );
	}

	/**
	 * The behaviour module must be built into the SportsPress bundle, which
	 * loads wherever a schedule table can render.
	 */
	public function test_tooltip_module_is_part_of_the_sportspress_bundle(): void {
		$this->assertMatchesRegularExpression( "/\\bsportspress: \\[[^\\]]*js\\( 'team-name-tooltip' \\)/", $this->theme_file( 'webpack.config.js' ) );
	}
}
