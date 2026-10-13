<?php
/**
 * Pins the wiring behind the phone fix for cut-off team names on the schedule.
 *
 * On a phone a 13-letter team name broke mid-word ("Lumberjac/ks") because the
 * Home/Away cells set `overflow-wrap: anywhere`. The fix gives the visible name
 * its own element so a name too long for its column is clamped with an ellipsis
 * and offered in full (assets/src/js/team-name-tooltip.js, unit-tested in
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
	 * The clamp must turn off the cell's mid-word breaking for the name itself,
	 * and be able to shrink below its content or it never takes effect.
	 */
	public function test_stylesheet_clamps_the_name_instead_of_breaking_mid_word(): void {
		$css = $this->theme_file( 'assets/src/css/sportspress.css' );

		$this->assertMatchesRegularExpression(
			'/\.bl-sp-schedule \.bl-sp-team-name\s*\{[^}]*min-width:\s*0;[^}]*overflow:\s*hidden;[^}]*overflow-wrap:\s*normal;[^}]*text-overflow:\s*ellipsis;/s',
			$css
		);
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
