<?php
/**
 * Pins the wiring behind the phone fix for cut-off team names on the schedule.
 *
 * On a phone a 13-letter team name broke mid-word ("Lumberjac/ks") because the
 * Home/Away cells set `overflow-wrap: anywhere`. The fix gives the visible name
 * its own element so a name too long for its column wraps, is clamped past two
 * lines and offered in full (assets/src/js/team-name-tooltip.js, unit-tested in
 * team-name-tooltip.test.mjs). The template cannot be rendered by this suite
 * (see EventListPendingResultTest.php's docblock), so this source-scans the two
 * files the behaviour depends on.
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
	 * The visible name needs its own element, or there is nothing to clamp or
	 * measure; the schema.org name meta must stay alongside it.
	 */
	public function test_template_wraps_the_visible_team_name_in_its_own_element(): void {
		$src = $this->theme_file( 'sportspress/event-list.php' );

		$this->assertStringContainsString( '<span class="bl-sp-team-name">', $src );
		$this->assertMatchesRegularExpression(
			'/itemprop="name" content="[^;]*<span class="bl-sp-team-name">/s',
			$src,
			'the itemprop name meta must stay in front of the wrapped visible name'
		);
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

		$this->assertSame( 1, preg_match( '/@container bl-sched \(width < 41rem\) \{\s*\.sp-event-list\.bl-sp-schedule,.*?\n\}/s', $css, $block ) );
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

		$this->assertSame( 1, preg_match( '/\.sp-event-list\.bl-sp-schedule \{\s*--bl-sched-date:(.*?)table-layout: fixed;/s', $css, $block ) );
		preg_match_all( '/--bl-sched-(date|home|time|away|league|season|venue|article|day):\s*(\d+(?:\.\d+)?);/', $block[1], $found, PREG_SET_ORDER );

		foreach ( $found as $row ) {
			$vars[ $row[1] ] = (float) $row[2];
		}
		$vars['date'] = 5.25;

		$this->assertSame( 41.0, array_sum( $vars ), 'default column weights, in rem' );
		$this->assertStringContainsString( '.sp-template-event-list:has(.bl-sp-schedule) {', $css );
		$this->assertMatchesRegularExpression( '/\.sp-template-event-list:has\(\.bl-sp-schedule\) \{\s*container:\s*bl-sched \/ inline-size;/', $css );
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
	 * The behaviour module must be loaded by the theme's entry point.
	 */
	public function test_tooltip_module_is_imported_by_the_entry_point(): void {
		$this->assertStringContainsString( "import './team-name-tooltip.js';", $this->theme_file( 'assets/src/js/index.js' ) );
	}
}
