<?php
/**
 * Covers blueline_settings_delete_all_data() (inc/settings/delete-data.php) --
 * the spec's section 6.7 teardown, and the most destructive thing this theme
 * can do to its own data.
 *
 * Every claim the admin-facing copy and the WP-CLI confirmation prompt make
 * about this operation is pinned here, because "delete everything" is precisely
 * the copy nobody can afford to have be aspirational: an admin reads it once,
 * at the moment they are deciding whether to run it, and cannot check it
 * afterwards.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/cache.php';
require_once __DIR__ . '/../inc/settings/delete-data.php';

/**
 * Pins the scope and the reporting of the section 6.7 teardown.
 *
 * @package blueline
 */
final class SettingsDeleteDataTest extends TestCase {

	/**
	 * Reset the in-memory option and transient stores between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Seed all three options plus the transient, so a test asserting they are
	 * gone is asserting a change rather than an absence that was always true.
	 *
	 * This is the premise-assertion habit the read-clamp tests established: a
	 * teardown test that seeds nothing passes just as well when the teardown
	 * does nothing at all.
	 */
	private function seed_everything(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );
		update_option(
			BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
			array(
				array(
					'id'       => 1,
					'settings' => array(),
				),
			)
		);
		update_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, true );
		set_transient( BLUELINE_SEASON_STATE_TRANSIENT, array( 'state' => 'in_season' ), 900 );
	}

	/**
	 * Every option this theme's settings layer created is removed.
	 *
	 * @return void
	 */
	public function test_every_option_the_settings_layer_owns_is_deleted(): void {
		$this->seed_everything();

		// Premise: all three really are stored before we delete anything.
		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ), 'premise: settings were seeded' );
		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, false ), 'premise: snapshots were seeded' );
		$this->assertNotFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ), 'premise: purge flag was seeded' );

		blueline_settings_delete_all_data();

		$this->assertFalse( get_option( BLUELINE_SETTINGS_OPTION, false ) );
		$this->assertFalse( get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, false ) );
		$this->assertFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ) );
	}

	/**
	 * The snapshot history is the half an admin is most likely to assume
	 * survives, since it is the undo mechanism for everything else. The CLI
	 * prompt promises it does not.
	 */
	public function test_the_snapshot_history_goes_too(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertSame( array(), blueline_settings_snapshot_list() );
	}

	/**
	 * A stale season-state cache must not outlive the settings it derived from.
	 *
	 * @return void
	 */
	public function test_the_season_state_transient_is_cleared(): void {
		$this->seed_everything();
		$this->assertNotFalse( get_transient( BLUELINE_SEASON_STATE_TRANSIENT ), 'premise: transient was seeded' );

		blueline_settings_delete_all_data();

		$this->assertFalse( get_transient( BLUELINE_SEASON_STATE_TRANSIENT ) );
	}

	/**
	 * The trap this function was written around: the ordinary purge path calls
	 * blueline_mark_cache_purge_needed(), which update_option()s
	 * BLUELINE_CACHE_PURGE_NEEDED_OPTION back into existence. A teardown that
	 * purged through it would delete three rows and leave a fourth behind.
	 */
	public function test_the_purge_does_not_recreate_the_option_it_just_deleted(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertFalse(
			get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ),
			'the cache-purge marker must not be written back by the purge attempt'
		);
	}

	/**
	 * The report names each row that was actually removed.
	 *
	 * @return void
	 */
	public function test_it_reports_only_what_was_actually_deleted(): void {
		$this->seed_everything();

		$result = blueline_settings_delete_all_data();

		$this->assertContains( BLUELINE_SETTINGS_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_CACHE_PURGE_NEEDED_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_SEASON_STATE_TRANSIENT, $result['deleted'] );
	}

	/**
	 * Running it twice must say so. `delete_option()` returns false for a row
	 * that was not there, and a report that claimed four deletions on an empty
	 * database would tell a worried admin the opposite of the truth.
	 */
	public function test_a_second_run_reports_nothing_deleted(): void {
		$this->seed_everything();
		blueline_settings_delete_all_data();

		$second = blueline_settings_delete_all_data();

		$this->assertSame( array(), $second['deleted'] );
	}

	/**
	 * A teardown on a site that stored nothing reports nothing.
	 *
	 * @return void
	 */
	public function test_nothing_stored_reports_nothing_deleted(): void {
		$result = blueline_settings_delete_all_data();

		$this->assertSame( array(), $result['deleted'] );
	}

	/**
	 * After a delete, reads fall back to defaults rather than erroring -- the
	 * difference from `reset` is what is IN the database, not how the site
	 * renders. If this failed, a teardown would take the front end down.
	 */
	public function test_settings_still_read_as_defaults_afterwards(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertSame(
			blueline_settings_defaults()['footer_heading'],
			blueline_settings( 'footer_heading' )
		);
	}

	/**
	 * Pins the SCOPE, not just the behaviour. If a later task adds an option to
	 * the settings layer and forgets to add it here, this list is where the
	 * omission should show up as a deliberate decision rather than an oversight.
	 */
	public function test_the_deletable_option_list_is_exactly_the_settings_layer_options(): void {
		$this->assertSame(
			array(
				BLUELINE_SETTINGS_OPTION,
				BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
				BLUELINE_CACHE_PURGE_NEEDED_OPTION,
			),
			blueline_settings_deletable_options()
		);
	}
}
