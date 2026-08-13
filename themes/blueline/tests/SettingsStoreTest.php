<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';

/**
 * Covers the settings store: the read accessor (blueline_settings()), the
 * cross-tab merge (blueline_settings_merge(), living on
 * pre_update_option_{$option}) and the forward-only migration
 * (blueline_settings_migrate()).
 *
 * The first test below is the one the whole panel depends on: the Settings
 * API hands update_option() exactly what one tab's form posted, so without
 * the merge, saving Content silently deletes Links (and Commerce, and
 * Sections). It exercises that merge through a direct update_option() call
 * rather than a simulated form submission, which is deliberate -- that is
 * also the exact path WP-CLI and a JSON import use, and the merge must
 * protect those too, not just the admin screen's own save handler.
 */
final class SettingsStoreTest extends TestCase {

	/**
	 * Reset every in-memory store before each test. Required here because
	 * blueline_settings_migrate() uses a wp_cache_add() lock: without a
	 * reset, whichever test first acquires that lock would hold it for
	 * every test that runs after it in this process, since the stub models
	 * no elapsed time for the lock's TTL to expire against.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * The load-bearing test. Without blueline_settings_merge() registered on
	 * pre_update_option_{$option}, the second update_option() call below
	 * would overwrite the option with only its own posted key, and
	 * $stored['links'] would not exist at all.
	 */
	public function test_saving_one_tab_does_not_wipe_another(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'content' => array( 'contact_email' => 'a@example.com' ),
				'links'   => array( 'page_faqs' => 42 ),
			)
		);

		// A Content-tab submission posts only its own subkey.
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'content' => array( 'contact_email' => 'b@example.com' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( 'b@example.com', $stored['content']['contact_email'] );
		$this->assertSame( 42, $stored['links']['page_faqs'], 'the Links tab was wiped' );
	}

	/**
	 * The merge must be a plain pass-through when there is nothing stored
	 * yet -- the very first save of the panel, where $old_value is `false`
	 * (get_option()'s own default), not an array.
	 */
	public function test_merge_passes_new_value_through_when_nothing_was_stored_yet(): void {
		$new = array( 'contact_email' => 'first@example.com' );

		$this->assertSame( $new, blueline_settings_merge( $new, false ) );
	}

	/**
	 * A non-array new value (a filter elsewhere gone wrong, or a malformed
	 * direct write) must not be allowed to replace a good stored value --
	 * the merge falls back to keeping what was already there.
	 */
	public function test_merge_keeps_old_value_when_new_value_is_not_an_array(): void {
		$old = array( 'contact_email' => 'kept@example.com' );

		$this->assertSame( $old, blueline_settings_merge( 'not-an-array', $old ) );
	}

	/**
	 * The read accessor must return every schema field with its default
	 * value when the option has never been saved.
	 */
	public function test_read_accessor_falls_back_to_defaults_for_unset_keys(): void {
		delete_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( blueline_settings_defaults(), blueline_settings() );
	}

	/**
	 * The single-key form of the accessor must return the same value the
	 * whole-array form would, for a field a test has actually saved.
	 */
	public function test_read_accessor_returns_a_single_saved_field(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'single@example.com' ) );

		$this->assertSame( 'single@example.com', blueline_settings( 'contact_email' ) );
		// Every other field the schema declares still falls back to its default.
		$this->assertSame( blueline_settings_defaults()['footer_heading'], blueline_settings( 'footer_heading' ) );
	}

	/**
	 * A key the schema does not declare -- most notably `_schema` itself,
	 * migration bookkeeping rather than a field value -- must not leak into
	 * either form of the accessor's return value.
	 */
	public function test_read_accessor_excludes_non_schema_keys(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array_merge( blueline_settings_defaults(), array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION ) )
		);

		$this->assertArrayNotHasKey( '_schema', blueline_settings() );
		$this->assertNull( blueline_settings( '_schema' ) );
	}

	/**
	 * A stored `_schema` newer than the running code must refuse to write --
	 * a rolled-back theme must never downgrade a newer install's data.
	 */
	public function test_migration_refuses_to_downgrade_a_newer_schema(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 5 )
		);
		blueline_settings_migrate();
		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame(
			BLUELINE_SETTINGS_SCHEMA_VERSION + 5,
			$stored['_schema'],
			'a rolled-back theme must not overwrite a newer schema'
		);
	}

	/**
	 * The forward path: an option with no `_schema` at all (a fresh
	 * install, or one saved before the panel existed) is treated as schema
	 * 0 and migrated up to the current version, backfilled with defaults
	 * for anything not already present.
	 */
	public function test_migration_bumps_an_unversioned_option_to_the_current_schema(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'kept-through-migration@example.com' ) );

		blueline_settings_migrate();

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( BLUELINE_SETTINGS_SCHEMA_VERSION, $stored['_schema'] );
		$this->assertSame( 'kept-through-migration@example.com', $stored['contact_email'] );
		$this->assertSame( blueline_settings_defaults()['footer_heading'], $stored['footer_heading'] );
	}

	/**
	 * A second call once the option is already current must be a true
	 * no-op: no write, so no risk of the merge/migration path ever
	 * clobbering a value in between.
	 */
	public function test_migration_is_a_no_op_once_already_current(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array_merge( blueline_settings_defaults(), array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION ) )
		);
		$before = get_option( BLUELINE_SETTINGS_OPTION );

		blueline_settings_migrate();

		$this->assertSame( $before, get_option( BLUELINE_SETTINGS_OPTION ) );
	}

	/**
	 * The migration lock is "add if absent": a second call while the first
	 * still holds it must not perform a second write. This does not test
	 * real concurrency (PHPUnit is single-threaded) -- it tests that
	 * blueline_settings_migrate() actually checks wp_cache_add()'s return
	 * value rather than ignoring it, by holding the lock open before
	 * calling the function under test.
	 */
	public function test_migration_skips_the_write_when_the_lock_is_already_held(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'should-not-move@example.com' ) );

		wp_cache_add( 'blueline_migrating', 1, 'blueline', 60 );

		blueline_settings_migrate();

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertArrayNotHasKey( '_schema', $stored, 'migration must not write while another caller holds the lock' );
	}
}
