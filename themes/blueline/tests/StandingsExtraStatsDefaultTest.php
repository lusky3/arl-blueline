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
 * ## Where the template side is tested
 *
 * That the disclosure's checkbox actually takes its starting state from this
 * setting is asserted against the rendered template, in
 * StandingsViewerRowsTest::test_the_full_stats_disclosure_starts_open_only_when_the_setting_is_on():
 * sportspress/league-table.php needs SP_League_Table, so that test runs in a
 * separate process with the standings fixture.
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
}
