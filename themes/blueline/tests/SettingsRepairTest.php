<?php
/**
 * Covers blueline_settings_repair() (inc/settings/store.php) -- Task 9's
 * explicit, reported repair path for values that reached the option
 * WITHOUT passing through blueline_sanitize_field(): a `wp db import`, a
 * `$wpdb` write, a hand-edited row.
 *
 * NOT `update_option()`, from a migration script or anywhere else. That
 * runs the sanitizer like every other write -- inc/settings/page.php
 * registers the callback on `sanitize_option_{$option}` at file scope
 * precisely so no PHP write path can miss it. Only a write that goes around
 * the option API entirely, straight to the database, produces the values
 * this repair path exists for.
 *
 * The interesting cases are not "is a bad value replaced" (one assertion)
 * but "does every sanitizer branch that can reject actually route through
 * this repair" -- `email`, `date` and `choices` each grew their own
 * rejection branch in a different task, and each is asserted separately
 * below rather than assumed to behave like the `text` branch.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/store.php';

/**
 * Covers the repair walk: what it replaces, what it leaves alone, and what
 * it reports.
 */
final class SettingsRepairTest extends TestCase {

	/**
	 * Reset the in-memory option/hook stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * A stored value that would throw at its sprintf() call site is
	 * replaced by that field's default and named in `repaired`.
	 *
	 * 'save 50% today' is the exact shape inc/settings/sanitize.php's own
	 * docblock records as a PHP 8.3 ValueError ("Unknown format specifier
	 * \"t\"": " " is a valid flag, "t" is not a valid type).
	 */
	public function test_a_stored_value_that_would_fatal_is_replaced_by_its_default(): void {
		$result = blueline_settings_repair(
			array( 'hero_registration_headline' => 'save 50% today' )
		);

		$this->assertSame(
			blueline_settings_defaults()['hero_registration_headline'],
			$result['settings']['hero_registration_headline']
		);
		$this->assertContains( 'hero_registration_headline', $result['repaired'] );
	}

	/**
	 * The whole default set is valid by construction, so repairing it must
	 * change nothing and report nothing.
	 */
	public function test_a_valid_store_is_returned_untouched_and_reports_nothing(): void {
		$defaults = blueline_settings_defaults();

		$result = blueline_settings_repair( $defaults );

		$this->assertSame( array(), $result['repaired'] );
		$this->assertSame( $defaults, $result['settings'] );
	}

	/**
	 * A `date` field rejects a malformed value with a WP_Error (the
	 * round-trip check that refuses '2026-02-30' rather than rolling it
	 * over to 2 March), so it must be repairable through exactly the same
	 * walk as `email` -- verified here rather than assumed from the shared
	 * WP_Error return.
	 */
	public function test_a_malformed_date_is_repaired(): void {
		$result = blueline_settings_repair(
			array( 'announcement_from' => '2026-02-30' )
		);

		$this->assertSame(
			blueline_settings_defaults()['announcement_from'],
			$result['settings']['announcement_from']
		);
		$this->assertContains( 'announcement_from', $result['repaired'] );
	}

	/**
	 * A `choices` field rejects a value that is not on its own list, so an
	 * off-list season-state override -- the break-glass typo that a `wp db
	 * import` can carry in -- is repaired back to "no override".
	 */
	public function test_an_off_list_choice_is_repaired(): void {
		$result = blueline_settings_repair(
			array( 'season_state_override' => 'playofs' )
		);

		$this->assertSame( '', $result['settings']['season_state_override'] );
		$this->assertContains( 'season_state_override', $result['repaired'] );
	}

	/**
	 * A value ON a `choices` field's list still has to survive the walk --
	 * the sanitizer deliberately falls THROUGH to the text checks after the
	 * choice check passes, and a repair that mistook that for a rejection
	 * would silently reset every valid dropdown on the site.
	 */
	public function test_a_valid_choice_is_left_exactly_as_stored(): void {
		$result = blueline_settings_repair(
			array( 'season_state_override' => 'playoffs' )
		);

		$this->assertSame( 'playoffs', $result['settings']['season_state_override'] );
		$this->assertSame( array(), $result['repaired'] );
	}

	/**
	 * An `email` field rejects anything is_email() rejects.
	 */
	public function test_an_invalid_email_is_repaired(): void {
		$result = blueline_settings_repair(
			array( 'contact_email' => 'not an address' )
		);

		$this->assertSame(
			blueline_settings_defaults()['contact_email'],
			$result['settings']['contact_email']
		);
		$this->assertContains( 'contact_email', $result['repaired'] );
	}

	/**
	 * Every rejected key is named, not just the first one found.
	 */
	public function test_every_broken_key_is_reported(): void {
		$result = blueline_settings_repair(
			array(
				'contact_email'     => 'not an address',
				'footer_heading'    => 'The League',
				'announcement_from' => '2026-02-30',
			)
		);

		sort( $result['repaired'] );

		$this->assertSame( array( 'announcement_from', 'contact_email' ), $result['repaired'] );
		$this->assertSame( 'The League', $result['settings']['footer_heading'] );
	}

	/**
	 * A key the stored option simply does not carry is NOT invented: it
	 * already falls back to its default on read (blueline_settings()), so
	 * there is nothing broken about it and nothing to report.
	 */
	public function test_an_absent_key_is_neither_added_nor_reported(): void {
		$result = blueline_settings_repair( array( 'footer_heading' => 'The League' ) );

		$this->assertSame( array( 'footer_heading' => 'The League' ), $result['settings'] );
		$this->assertSame( array(), $result['repaired'] );
	}

	/**
	 * `_schema` is migration bookkeeping, not a schema field, and the walk
	 * must carry it through untouched -- a repair that dropped it would
	 * hand blueline_settings_migrate() a store that looks unmigrated.
	 */
	public function test_a_non_schema_key_is_carried_through_untouched(): void {
		$result = blueline_settings_repair(
			array(
				'_schema'        => BLUELINE_SETTINGS_SCHEMA_VERSION,
				'footer_heading' => 'The League',
			)
		);

		$this->assertSame( BLUELINE_SETTINGS_SCHEMA_VERSION, $result['settings']['_schema'] );
		$this->assertSame( array(), $result['repaired'] );
	}

	/**
	 * A value that passes is returned EXACTLY as it was stored, not as
	 * sanitize_text_field() would rewrite it. `repaired` is the complete
	 * list of what this function changed, and a silent trim would make
	 * that claim false.
	 */
	public function test_a_passing_value_is_not_silently_rewritten(): void {
		$result = blueline_settings_repair(
			array( 'footer_heading' => '  The League  ' )
		);

		$this->assertSame( '  The League  ', $result['settings']['footer_heading'] );
		$this->assertSame( array(), $result['repaired'] );
	}
}
