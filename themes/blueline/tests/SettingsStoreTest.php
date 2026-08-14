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
	 * $stored['page_faqs'] would not exist at all.
	 *
	 * Uses REAL, flat schema keys (`contact_email`, `page_faqs`) rather
	 * than a fictional nested `content`/`links` shape: since Task 7, the
	 * actual write path also runs inc/settings/page.php's
	 * blueline_settings_sanitize_callback() (wired unconditionally at file
	 * scope, exactly like this file's own blueline_settings_merge()),
	 * which only lets a real schema field (or a reserved bookkeeping key)
	 * survive a write -- a fictional top-level key would be dropped by
	 * that filtering before the merge ever saw it, which is correct there
	 * but would make this test assert nothing meaningful about the real
	 * write path.
	 */
	public function test_saving_one_tab_does_not_wipe_another(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'contact_email' => 'a@example.com',
				'page_faqs'     => 42,
			)
		);

		// A Content-tab submission posts only its own field.
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'contact_email' => 'b@example.com',
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( 'b@example.com', $stored['contact_email'] );
		$this->assertSame( 42, $stored['page_faqs'], 'the Links tab was wiped' );
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
	 * Direct unit coverage of blueline_settings_merge()'s `_posted_fields`
	 * contract: a field named in `_posted_fields` but absent from the
	 * submission is a deliberate delete (an unchecked checkbox), while a
	 * field named in NEITHER the submission nor `_posted_fields` still
	 * belongs to a tab this submission didn't touch, and survives.
	 */
	public function test_merge_posted_fields_deletes_a_named_absent_field_but_keeps_an_unnamed_one(): void {
		$old = array(
			'newsletter_enabled' => true,
			'contact_email'      => 'kept@example.com',
		);
		$new = array(
			'_posted_fields' => array( 'newsletter_enabled' ),
		);

		$merged = blueline_settings_merge( $new, $old );

		$this->assertArrayNotHasKey(
			'newsletter_enabled',
			$merged,
			'named in _posted_fields but absent from the submission must be deleted, not carried forward'
		);
		$this->assertSame(
			'kept@example.com',
			$merged['contact_email'],
			'not named in _posted_fields and absent must still be carried forward -- it belongs to another tab'
		);
	}

	/**
	 * A field actually present in the submission is kept regardless of
	 * whether it is also named in `_posted_fields` -- naming a field only
	 * disambiguates an OMISSION; it never overrides a value the submission
	 * did include.
	 */
	public function test_merge_posted_fields_does_not_override_a_value_actually_submitted(): void {
		$old = array( 'newsletter_enabled' => false );
		$new = array(
			'newsletter_enabled' => true,
			'_posted_fields'     => array( 'newsletter_enabled' ),
		);

		$this->assertTrue( blueline_settings_merge( $new, $old )['newsletter_enabled'] );
	}

	/**
	 * Without `_posted_fields` at all, behaviour is unchanged from before
	 * this contract existed: every key absent from the submission is
	 * carried forward. This is the fallback every caller gets until the
	 * renderer that emits `_posted_fields` exists (a later task), so
	 * nothing already working breaks in the meantime.
	 */
	public function test_merge_carries_everything_forward_when_posted_fields_is_absent(): void {
		$old = array(
			'newsletter_enabled' => true,
			'contact_email'      => 'kept@example.com',
		);
		$new = array( 'contact_email' => 'changed@example.com' );

		$merged = blueline_settings_merge( $new, $old );

		$this->assertTrue(
			$merged['newsletter_enabled'],
			'no _posted_fields means carry-forward-everything, unchanged from before this contract existed'
		);
		$this->assertSame( 'changed@example.com', $merged['contact_email'] );
	}

	/**
	 * `_posted_fields` is reserved bookkeeping for this one merge decision
	 * -- it must never itself land in the stored option on a normal,
	 * steady-state save, whether read straight off the merge's return
	 * value or round-tripped through update_option()/get_option().
	 *
	 * The round-trip half seeds the option with a prior write first so the
	 * save under test is not the option's first-ever write: core's real
	 * update_option() delegates a first-ever write to add_option(), which
	 * independently re-applies sanitize_option_{$option} to a value that
	 * has already been through this very merge once (and so no longer has
	 * `_posted_fields` in it) -- re-adding an EMPTY `_posted_fields` array
	 * that nothing then strips a second time, since add_option()'s own
	 * write path has no merge stage at all
	 * (BootstrapFidelityTest::test_update_option_first_write_sanitizes_twice_but_merges_once()
	 * pins this exact core quirk in isolation, and
	 * inc/settings/page.php's own docblock explains why production leaves
	 * it as a real, cosmetic, self-healing fact about every WordPress
	 * option rather than working around it). That first-write case is not
	 * what this test exists to cover; seeding isolates the steady-state
	 * guarantee instead.
	 */
	public function test_merge_never_persists_the_posted_fields_key_itself(): void {
		$merged = blueline_settings_merge(
			array(
				'contact_email'  => 'a@example.com',
				'_posted_fields' => array( 'contact_email' ),
			),
			array( 'contact_email' => 'old@example.com' )
		);
		$this->assertArrayNotHasKey( '_posted_fields', $merged );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'old@example.com' ) ); // Not the first-ever write -- see this test's own docblock.

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'contact_email'  => 'b@example.com',
				'_posted_fields' => array( 'contact_email' ),
			)
		);
		$this->assertArrayNotHasKey( '_posted_fields', get_option( BLUELINE_SETTINGS_OPTION ) );
	}

	/**
	 * The same delete-vs-carry-forward disambiguation, exercised through
	 * the full update_option()/get_option() round trip rather than calling
	 * blueline_settings_merge() directly -- proving the contract holds on
	 * the actual write path a real tab save (or WP-CLI, or an import) uses,
	 * not just the merge function in isolation.
	 *
	 * Uses a REAL schema field (`footer_heading`) rather than a fictional
	 * one: since Task 7, the actual write path also runs
	 * inc/settings/page.php's blueline_settings_sanitize_callback()
	 * (wired unconditionally at file scope, exactly like this file's own
	 * blueline_settings_merge()), which filters `_posted_fields` to keys
	 * the schema actually declares -- a fictional field name would be
	 * dropped by that filtering before the merge ever saw it, which is
	 * correct there but would make this test assert nothing meaningful
	 * about the real write path.
	 */
	public function test_saving_with_posted_fields_deletes_an_unchecked_field_but_keeps_an_untouched_one(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'The League',
				'contact_email'  => 'a@example.com',
			)
		);

		// A Content-tab submission that owns `footer_heading` and this
		// time omitted it -- the same mechanism a real unchecked checkbox
		// (HTML omits it from $_POST entirely) would rely on, so the field
		// the tab owns is named explicitly instead.
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertArrayNotHasKey( 'footer_heading', $stored, 'named-but-absent must be deleted' );
		$this->assertSame(
			'a@example.com',
			$stored['contact_email'],
			'unnamed-and-absent must survive -- it belongs to a tab this submission did not touch'
		);
		$this->assertArrayNotHasKey( '_posted_fields', $stored );
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
	 *
	 * Writes the newer `_schema` value directly to the in-memory option
	 * store, bypassing update_option() (and therefore
	 * inc/settings/page.php's sanitize_option_ callback) on purpose: since
	 * Task 7 that callback clamps any `_schema` it sanitizes to THIS
	 * running code's own BLUELINE_SETTINGS_SCHEMA_VERSION -- the correct
	 * behaviour for a real write reachable through the panel, WP-CLI, or an
	 * import, none of which can legitimately claim a schema newer than the
	 * code actually running. A genuinely newer value in this test needs to
	 * simulate data a DIFFERENT (newer) deploy already wrote, before this
	 * process's code -- and therefore this process's own version constant
	 * and clamp -- ever existed, which going through that same sanitize
	 * path cannot represent. Same technique already used in
	 * FooterAndHeroSettingsRenderTest.php to simulate pre-existing/direct-DB
	 * data bypassing the sanitizer entirely.
	 */
	public function test_migration_refuses_to_downgrade_a_newer_schema(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 5 );
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
