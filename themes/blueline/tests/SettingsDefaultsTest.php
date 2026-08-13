<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';

/**
 * Covers the settings field registry (blueline_settings_schema()) and its
 * defaults (blueline_settings_defaults()) — the contract every later part
 * of the Appearance → Blueline control panel is built on.
 *
 * The third test here is the one that matters most: it proves that any
 * field declaring a `placeholders` contract has a default value that
 * actually contains every one of those conversion specs, so a contract can
 * never ship wrong from birth. No field declares placeholders yet (Task 1
 * deliberately excludes every field whose current literal has one — see
 * defaults.php's docblock), so today that test's per-field loop finds
 * nothing to check -- but it still asserts the schema itself is non-empty
 * and reports a real (non-risky) assertion either way, so it cannot go
 * quietly inert. It stays in place and green so Task 8, which adds those
 * fields, is held to the same guarantee without needing to touch this file.
 */
final class SettingsDefaultsTest extends TestCase {

	/**
	 * Every field must declare a recognised `type` and a `tab` — the two
	 * things the panel's renderer (later tasks) cannot do without.
	 */
	public function test_schema_declares_a_tab_and_type_for_every_field(): void {
		foreach ( blueline_settings_schema() as $key => $field ) {
			$this->assertArrayHasKey( 'type', $field, "$key has no type" );
			$this->assertArrayHasKey( 'tab', $field, "$key has no tab" );
			$this->assertContains(
				$field['type'],
				array( 'text', 'email', 'textarea', 'page_id', 'term_id', 'bool' ),
				"$key has an unknown type"
			);
		}
	}

	/**
	 * Every field the schema declares must have a corresponding default —
	 * the option array blueline_settings_defaults() returns is what
	 * get_option( BLUELINE_SETTINGS_OPTION, blueline_settings_defaults() )
	 * falls back to before the panel has ever been saved.
	 */
	public function test_defaults_cover_every_schema_field(): void {
		$defaults = blueline_settings_defaults();
		foreach ( blueline_settings_schema() as $key => $field ) {
			$this->assertArrayHasKey( $key, $defaults, "$key has no default" );
		}
	}

	/**
	 * Any field declaring a `placeholders` contract must ship a default
	 * value containing every one of those conversion specs. This is what
	 * stops a sprintf() contract from being wrong the moment it's added.
	 *
	 * Two guards keep this test from going quietly inert while no field
	 * declares a contract (true today; Task 8 changes that):
	 *
	 * - assertNotEmpty() on the schema itself, so the loop below is proven
	 *   to have had something to iterate -- an empty schema and "zero
	 *   fields happen to declare placeholders" must not look the same.
	 * - a running count of contracts actually checked, asserted on at the
	 *   end via addToAssertionCount() when it's zero (the same convention
	 *   TeamColorsTest::test_read_failure_hook_never_fatals() uses for a
	 *   PHPUnit "risky: no assertions" false alarm). Once Task 8 adds a
	 *   placeholder-bearing field, $checked becomes non-zero and the
	 *   assertStringContainsString() calls above start carrying the real
	 *   signal on their own, with no edit to this test required.
	 */
	public function test_placeholder_contracts_match_the_declared_default(): void {
		$schema = blueline_settings_schema();
		$this->assertNotEmpty( $schema, 'blueline_settings_schema() returned no fields' );

		$checked = 0;
		foreach ( $schema as $key => $field ) {
			if ( empty( $field['placeholders'] ) ) {
				continue;
			}
			$default = blueline_settings_defaults()[ $key ];
			foreach ( $field['placeholders'] as $spec ) {
				$this->assertStringContainsString(
					$spec,
					$default,
					"$key declares placeholder $spec but its own default lacks it"
				);
				++$checked;
			}
		}

		if ( 0 === $checked ) {
			$this->addToAssertionCount( 1 );
		}
	}
}
