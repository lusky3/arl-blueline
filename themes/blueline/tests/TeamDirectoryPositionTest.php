<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Covers the `chrome_team_directory_position` setting: a two-choice field
 * (`footer`/`flyout`) that decides which of the two team-directory
 * renderers is active, independent of the `chrome_footer_teams` master
 * on/off it sits beside.
 */
final class TeamDirectoryPositionTest extends TestCase {

	/**
	 * Reset the stored option before each test so defaults are real
	 * defaults, not leftovers from a previous test.
	 */
	protected function setUp(): void {
		delete_option( BLUELINE_SETTINGS_OPTION );
	}

	/**
	 * An install that has never touched this field gets 'footer' -- the
	 * position the theme has always rendered, so installing this field
	 * changes no rendered output until an admin actually edits it.
	 */
	public function test_defaults_to_footer(): void {
		$this->assertSame( 'footer', blueline_settings( 'chrome_team_directory_position' ) );
	}

	/**
	 * An admin can store 'flyout' and read it back.
	 */
	public function test_flyout_can_be_selected(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'flyout' ) );

		$this->assertSame( 'flyout', blueline_settings( 'chrome_team_directory_position' ) );
	}

	/**
	 * The field is declared as a `choices` field restricted to exactly
	 * 'footer'/'flyout' -- SettingsDefaultsTest's generic schema-shape
	 * tests already assert every choices field's default is one of its own
	 * choices and every text/textarea field declares a `placeholders` key;
	 * this test pins the two literal choice keys themselves, which nothing
	 * else in the suite checks by name.
	 */
	public function test_choices_are_exactly_footer_and_flyout(): void {
		$schema = blueline_settings_schema();

		$this->assertArrayHasKey( 'chrome_team_directory_position', $schema );
		$this->assertSame(
			array( 'footer', 'flyout' ),
			array_keys( $schema['chrome_team_directory_position']['choices'] )
		);
	}

	/**
	 * An unrecognized stored value (a hand-edited row, a raw DB import, a
	 * plugin filtering the option) clamps to 'footer' rather than making
	 * both renderers bail -- see blueline_team_directory_position()'s own
	 * docblock (inc/sportspress.php) for why that matters.
	 */
	public function test_an_unrecognized_value_clamps_to_footer(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'sidebar' ) );

		$this->assertSame( 'footer', blueline_team_directory_position() );
	}
}
