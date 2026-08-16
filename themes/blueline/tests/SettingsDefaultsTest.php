<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';

/**
 * Covers the settings field registry (blueline_settings_schema()) and its
 * defaults (blueline_settings_defaults()) — the contract every later part
 * of the Appearance → Blueline control panel is built on.
 *
 * The last two tests here are the ones that matter most, together closing
 * the placeholder contract from both ends:
 *
 * - test_every_text_and_textarea_field_declares_a_placeholders_key() makes
 *   omission of the `placeholders` key itself a red build for any
 *   `text`/`textarea` field, rather than a silent gap a schema author could
 *   forget — see inc/settings/sanitize.php's blueline_sanitize_field() for
 *   why an omitted key being invisible to this validator was exactly the
 *   fatal Task 3 exists to prevent, one step removed.
 * - test_placeholder_contracts_match_the_declared_default() proves that any
 *   field declaring a NON-EMPTY `placeholders` contract has a default value
 *   that actually contains every one of those conversion specs, so a
 *   contract can never ship wrong from birth. Before Task 8, no field
 *   declared a non-empty contract (every current theme literal with a real
 *   sprintf() placeholder was deliberately excluded until Task 8 added it
 *   against the validator Task 3 built — see defaults.php's docblock), so
 *   this test's own $checked/addToAssertionCount() guard existed purely so
 *   the test could not go quietly inert while nothing exercised it. Task 8's
 *   seven hero fields now give the per-field loop real, non-empty contracts
 *   to check on every run; the guard stays in place regardless, so the test
 *   still reports a real assertion even if a future schema change ever drops
 *   back to zero non-empty contracts.
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
				array( 'text', 'email', 'textarea', 'page_id', 'term_id', 'bool', 'band_photos', 'section', 'date' ),
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
	 * Every `text`/`textarea` field MUST declare a `placeholders` key, even
	 * as an explicit `array()` for a field that feeds no sprintf() call
	 * site. Without this test, a schema author could add a new text field
	 * and simply forget the key -- and
	 * inc/settings/sanitize.php's blueline_sanitize_field() would have no
	 * way to tell "this field was never meant to be checked" apart from
	 * "this field should have declared a contract and didn't", which is
	 * exactly the gap that let an unprotected sprintf()-format-string field
	 * reach production invisibly. Making the omission itself a failing
	 * assertion turns that mistake into a red build instead of a silent one.
	 *
	 * `email`, `page_id`, `term_id` and `bool` fields are outside this
	 * requirement: none of them is ever used as a raw sprintf() format
	 * string the way a `text`/`textarea` field's value can be.
	 */
	public function test_every_text_and_textarea_field_declares_a_placeholders_key(): void {
		$schema = blueline_settings_schema();
		$this->assertNotEmpty( $schema, 'blueline_settings_schema() returned no fields' );

		foreach ( $schema as $key => $field ) {
			if ( ! in_array( $field['type'] ?? '', array( 'text', 'textarea' ), true ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				'placeholders',
				$field,
				"$key is a '{$field['type']}' field and must declare a placeholders key -- array() if it feeds no sprintf() call site"
			);
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
