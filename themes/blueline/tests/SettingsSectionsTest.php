<?php
/**
 * Covers inc/settings/sections.php: the section presence toggles that decide
 * which parts of the site render at all. This task only builds the
 * foundation -- the definition list, the schema/defaults entries, the
 * sanitizer branch, and the ONE read accessor every later task (homepage,
 * account cards, site chrome) must call through rather than reading
 * blueline_settings() directly.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/sections.php';

/**
 * Covers blueline_section_definitions(), blueline_section_enabled() and the
 * `section` sanitizer branch.
 */
final class SettingsSectionsTest extends TestCase {

	/**
	 * Reset every in-memory store this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Unset means enabled: every section shipped visible, and an install
	 * that has never opened the Sections tab must look exactly as it did
	 * before this task existed.
	 */
	public function test_a_section_defaults_to_enabled_when_unset(): void {
		$this->assertTrue( blueline_section_enabled( 'module_next_games' ) );
	}

	/**
	 * An unknown key must fail closed -- a typo in a consumer's own key
	 * must never silently render something nothing controls.
	 */
	public function test_an_unknown_section_key_is_never_enabled(): void {
		$this->assertFalse( blueline_section_enabled( 'not_a_section' ) );
	}

	/**
	 * Every definition must carry a real label and group -- both are read
	 * directly by the Sections tab's renderer, and a blank one would render
	 * an unlabelled or ungrouped checkbox.
	 */
	public function test_every_definition_has_a_label_and_group(): void {
		foreach ( blueline_section_definitions() as $key => $def ) {
			$this->assertNotSame( '', trim( $def['label'] ), "$key has no label" );
			$this->assertNotSame( '', trim( $def['group'] ), "$key has no group" );
		}
	}

	/**
	 * A section explicitly turned off through the real save pipeline must
	 * read as disabled -- proven through update_option(), the real dispatch
	 * path (sanitize_option_blueline_settings -> the schema's own `section`
	 * type -> blueline_sanitize_field()), not by poking the option store
	 * directly.
	 */
	public function test_a_section_explicitly_disabled_reads_as_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'module_next_games' => false ) );

		$this->assertFalse( blueline_section_enabled( 'module_next_games' ) );
	}

	/**
	 * The companion accept path: a section explicitly turned back on (or
	 * left on) reads as enabled, through the same real save pipeline.
	 */
	public function test_a_section_explicitly_enabled_reads_as_enabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'module_next_games' => true ) );

		$this->assertTrue( blueline_section_enabled( 'module_next_games' ) );
	}

	/**
	 * Every key blueline_section_definitions() declares must also be a real
	 * schema field on the 'sections' tab, of type 'section' -- proving the
	 * generated schema entries in inc/settings/defaults.php actually cover
	 * every definition, not just some of them.
	 */
	public function test_every_definition_is_a_schema_field_on_the_sections_tab(): void {
		$schema = blueline_settings_schema();

		foreach ( array_keys( blueline_section_definitions() ) as $key ) {
			$this->assertArrayHasKey( $key, $schema, "$key has no schema entry" );
			$this->assertSame( 'section', $schema[ $key ]['type'], "$key's schema entry is not type 'section'" );
			$this->assertSame( 'sections', $schema[ $key ]['tab'], "$key's schema entry is not on the 'sections' tab" );
		}
	}

	/**
	 * Every definition must also have a matching default of `true` --
	 * blueline_settings_defaults() is what an unsaved install falls back to,
	 * and "unset means enabled" depends on that default actually being
	 * `true`, not merely on blueline_section_enabled()'s own null-check.
	 */
	public function test_every_definition_has_a_true_default(): void {
		$defaults = blueline_settings_defaults();

		foreach ( array_keys( blueline_section_definitions() ) as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "$key has no default" );
			$this->assertTrue( $defaults[ $key ], "$key's default is not true" );
		}
	}

	/**
	 * The sanitizer branch itself: a `section` field sanitizes to a real
	 * PHP boolean, exactly like `bool` -- neither ever reaches the
	 * sprintf()-placeholder checks, since its sanitized value is never a
	 * string.
	 */
	public function test_sanitize_field_casts_section_type_to_boolean(): void {
		$this->assertTrue( blueline_sanitize_field( '1', array( 'type' => 'section' ) ) );
		$this->assertFalse( blueline_sanitize_field( '', array( 'type' => 'section' ) ) );
	}
}
