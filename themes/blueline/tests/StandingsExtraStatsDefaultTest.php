<?php
/**
 * Covers `standings_extra_stats_default` -- the one setting that decides
 * whether sportspress/league-table.php's CSS-only "Show full stats"
 * disclosure starts open.
 *
 * ## Why this is a plain `bool` and NOT a section toggle
 *
 * Every other "should this show" control in this panel lives in
 * blueline_section_definitions() (inc/settings/sections.php) and is read
 * through blueline_section_enabled(), whose contract is "unknown keys are
 * never enabled -- fail closed, because a consumer's typo must not silently
 * render something nothing controls".
 *
 * This setting cannot join them without breaking that contract's meaning.
 * Both states of the disclosure stay reachable by the reader either way --
 * the extra columns are in the DOM regardless, and the label toggles them
 * with no JavaScript -- so this decides the INITIAL state of a
 * user-controlled disclosure, not whether anything renders. Putting it in
 * the section registry would make blueline_section_enabled() mean "starts
 * expanded" for one key and "renders at all" for the other sixteen.
 * test_is_not_a_section_toggle() below pins that decision so a later edit
 * cannot quietly move it.
 *
 * ## What the template-side test here does and does not prove
 *
 * sportspress/league-table.php cannot be rendered by this suite: it
 * instantiates SP_League_Table and calls sp_get_post_mode()/sp_array_value(),
 * none of which exist without a real SportsPress install, and stubbing them
 * would mean asserting against a fake table rather than the real template.
 * So test_template_renders_the_toggle_checked_from_this_setting() asserts
 * two things instead, and claims nothing beyond them:
 *
 * 1. The template's own source pairs the `bl-sp-standings-toggle-input`
 *    checkbox with a `checked()` call reading this exact key -- a source
 *    scan, the same technique tests/SchemaFieldCoverageTest.php and
 *    tests/NoticeDivGuardTest.php already use for source-level guarantees.
 * 2. That expression, evaluated here against the same checked() the
 *    template would call, actually produces the attribute for a stored
 *    `true` and nothing for a stored `false`.
 *
 * Together those cover the wiring and the value, not "a real standings
 * table came back expanded in a browser" -- which no test in this suite can
 * currently claim.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';

/**
 * See this file's own docblock.
 */
final class StandingsExtraStatsDefaultTest extends TestCase {

	/**
	 * Reset the in-memory option store between cases.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * The field exists, is a plain `bool` on the Appearance tab, and ships
	 * OFF -- installing this release must not change how any standings
	 * table already renders.
	 */
	public function test_schema_declares_a_bool_appearance_field_defaulting_off(): void {
		$schema = blueline_settings_schema();

		$this->assertArrayHasKey( 'standings_extra_stats_default', $schema );
		$this->assertSame( 'bool', $schema['standings_extra_stats_default']['type'] );
		$this->assertSame( 'appearance', $schema['standings_extra_stats_default']['tab'] );

		$this->assertFalse( blueline_settings_defaults()['standings_extra_stats_default'] );
		$this->assertFalse( blueline_settings( 'standings_extra_stats_default' ) );
	}

	/**
	 * Deliberately NOT a section toggle -- see this file's own docblock for
	 * why blueline_section_enabled()'s fail-closed contract depends on it.
	 */
	public function test_is_not_a_section_toggle(): void {
		$this->assertArrayNotHasKey( 'standings_extra_stats_default', blueline_section_definitions() );
		$this->assertFalse(
			blueline_section_enabled( 'standings_extra_stats_default' ),
			'blueline_section_enabled() must treat this key as unknown, exactly as it treats any other non-section key'
		);
	}

	/**
	 * A stored value round-trips through the same sanitizer every write
	 * path runs, and reads back as a real boolean.
	 */
	public function test_a_saved_value_round_trips(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( 'standings_extra_stats_default' => '1' )
		);

		$this->assertTrue( blueline_settings( 'standings_extra_stats_default' ) );

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( 'standings_extra_stats_default' => false )
		);

		$this->assertFalse( blueline_settings( 'standings_extra_stats_default' ) );
	}

	/**
	 * The template's disclosure checkbox is actually wired to this setting,
	 * and the expression it uses produces the attribute for a stored `true`
	 * and nothing for a stored `false`. See this file's own docblock for
	 * the two halves this covers and the one it does not.
	 */
	public function test_template_renders_the_toggle_checked_from_this_setting(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/sportspress/league-table.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test, not an HTTP fetch.

		$this->assertMatchesRegularExpression(
			'/bl-sp-standings-toggle-input[^>]*checked\(\s*blueline_settings\(\s*\'standings_extra_stats_default\'\s*\)\s*\)/s',
			$source,
			'the standings disclosure checkbox must take its initial state from this setting'
		);

		update_option( BLUELINE_SETTINGS_OPTION, array( 'standings_extra_stats_default' => true ) );
		$this->assertSame(
			' checked="checked"',
			checked( blueline_settings( 'standings_extra_stats_default' ), true, false )
		);

		update_option( BLUELINE_SETTINGS_OPTION, array( 'standings_extra_stats_default' => false ) );
		$this->assertSame(
			'',
			checked( blueline_settings( 'standings_extra_stats_default' ), true, false )
		);
	}
}
