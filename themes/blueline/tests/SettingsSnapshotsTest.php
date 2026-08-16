<?php
/**
 * Covers inc/settings/snapshots.php -- Task 10's save history, the restore
 * that undoes a save, and the three-way diff the import preview is built
 * on.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';

/**
 * Snapshot retention, the take-before-the-write ordering, restore, and the
 * diff's carried-forward state.
 */
final class SettingsSnapshotsTest extends TestCase {

	/**
	 * Reset the in-memory option/hook stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * A snapshot round-trips: what went in is what the list hands back.
	 */
	public function test_a_snapshot_round_trips_through_the_list(): void {
		blueline_settings_snapshot_take( array( 'footer_heading' => 'The League' ) );

		$snapshots = blueline_settings_snapshot_list();

		$this->assertCount( 1, $snapshots );
		$this->assertSame( array( 'footer_heading' => 'The League' ), $snapshots[0]['settings'] );
	}

	/**
	 * The spec (line 327) says the last TEN, so the eleventh take must
	 * evict the oldest -- and the list is newest-first, because that is the
	 * order a restore UI reads them in.
	 */
	public function test_only_the_last_ten_snapshots_are_kept_newest_first(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			blueline_settings_snapshot_take( array( 'footer_heading' => 'v' . $i ) );
		}

		$snapshots = blueline_settings_snapshot_list();

		$this->assertCount( 10, $snapshots );
		$this->assertSame( 'v11', $snapshots[0]['settings']['footer_heading'], 'newest first' );
		$this->assertSame( 'v2', $snapshots[9]['settings']['footer_heading'], 'v0 and v1 evicted' );
	}

	/**
	 * Every snapshot carries an id that stays valid as newer snapshots
	 * arrive -- a restore link built from a list POSITION would silently
	 * point at a different snapshot the moment one more save happened.
	 */
	public function test_snapshot_ids_are_unique_and_stable_as_the_list_grows(): void {
		blueline_settings_snapshot_take( array( 'footer_heading' => 'first' ) );
		$first_id = blueline_settings_snapshot_list()[0]['id'];

		blueline_settings_snapshot_take( array( 'footer_heading' => 'second' ) );

		$found = blueline_settings_snapshot_get( $first_id );

		$this->assertNotNull( $found );
		$this->assertSame( 'first', $found['settings']['footer_heading'] );
		$this->assertNotSame( $first_id, blueline_settings_snapshot_list()[0]['id'] );
	}

	/**
	 * The spec calls for a NON-AUTOLOADED option (lines 151 and 327): this
	 * history is never read on the front end, and autoloading ten copies of
	 * the settings array on every request would be exactly the cost the
	 * spec is avoiding.
	 *
	 * Asserts the FIRST recorded argument: tests/bootstrap.php's
	 * update_option() delegates a first-ever write to add_option(), which
	 * records a second, `null` entry that no theme code wrote (see
	 * blueline_test_option_autoload_args()'s own docblock).
	 */
	public function test_snapshots_are_stored_non_autoloaded(): void {
		blueline_settings_snapshot_take( array( 'footer_heading' => 'The League' ) );

		$autoload_args = blueline_test_option_autoload_args( BLUELINE_SETTINGS_SNAPSHOTS_OPTION );

		$this->assertNotEmpty( $autoload_args );
		$this->assertFalse( $autoload_args[0], 'the snapshot write must pass an explicit false' );
	}

	/**
	 * The point of taking the snapshot on `pre_update_option_{$option}`: it
	 * records the state being REPLACED, which is the state a restore needs.
	 * Snapshotting the incoming value instead would give an "undo" that
	 * restores the change you were trying to undo.
	 */
	public function test_a_save_snapshots_the_value_being_replaced(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		$snapshots = blueline_settings_snapshot_list();

		$this->assertCount( 1, $snapshots, 'the first-ever write replaced nothing' );
		$this->assertSame( 'The League', $snapshots[0]['settings']['footer_heading'] );
		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * A save that changes nothing must not burn a history slot: ten
	 * identical no-op saves would otherwise evict every real undo point.
	 *
	 * THREE writes, not two, and the reason is a real property of
	 * WordPress' option lifecycle this suite already pins elsewhere
	 * (BootstrapFidelityTest::test_update_option_first_write_sanitizes_twice_but_merges_once()):
	 * a first-ever write is delegated to add_option(), which re-applies
	 * sanitize_option_{$option} with no merge stage behind it to strip the
	 * `_posted_fields`/`_tab` bookkeeping a second time -- so the stored
	 * value after write 1 carries keys write 2 then removes. Write 2 is
	 * therefore a genuine change, not a no-op, and asserting otherwise
	 * would be pinning a wrong belief about the platform rather than
	 * testing this file. Write 3 is the first one that really changes
	 * nothing.
	 */
	public function test_a_save_that_changes_nothing_records_no_snapshot(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		$settled = blueline_settings_snapshot_list();

		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		$this->assertSame( $settled, blueline_settings_snapshot_list() );
	}

	/**
	 * Restoring puts the snapshot's own values back.
	 */
	public function test_restore_writes_the_snapshot_back(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		$id = blueline_settings_snapshot_list()[0]['id'];

		$this->assertTrue( blueline_settings_snapshot_restore( $id ) );
		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * A restore is itself a save, so it records the pre-restore state --
	 * which is what makes the admin-facing claim "a restore can itself be
	 * undone" true rather than hopeful.
	 */
	public function test_a_restore_is_itself_undoable(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		blueline_settings_snapshot_restore( blueline_settings_snapshot_list()[0]['id'] );

		$newest = blueline_settings_snapshot_list()[0];
		$this->assertSame( 'The ARL', $newest['settings']['footer_heading'], 'the pre-restore state was recorded' );

		blueline_settings_snapshot_restore( $newest['id'] );

		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * A key the snapshot does not carry keeps its CURRENT value -- the same
	 * merge-not-replace semantics every other write to this option has (see
	 * blueline_settings_merge()). Pinned here because the restore UI's copy
	 * says so, and copy that claims more than the code does is this
	 * settings layer's defining defect.
	 */
	public function test_restore_leaves_a_key_the_snapshot_does_not_carry_alone(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading'  => 'The ARL',
				'footer_location' => 'Hamilton, Ontario',
			)
		);

		// The only snapshot is the one-key state the second save replaced.
		blueline_settings_snapshot_restore( blueline_settings_snapshot_list()[0]['id'] );

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
		$this->assertSame(
			'Hamilton, Ontario',
			blueline_settings( 'footer_location' ),
			'absent from the snapshot means carried forward, not reset'
		);
	}

	/**
	 * An id that is not in the list restores nothing and says so, rather
	 * than writing something arbitrary.
	 */
	public function test_restoring_an_unknown_id_changes_nothing(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		$this->assertFalse( blueline_settings_snapshot_restore( 9999 ) );
		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * The restore list's timestamp is rendered in the SITE's timezone, to
	 * the minute -- two saves minutes apart have to be distinguishable, and
	 * an admin reading the list thinks in local time.
	 *
	 * 1770000000 is 2026-02-02 02:40:00 UTC; the test environment's site
	 * timezone is America/Toronto (UTC-5 in February), so the label must
	 * read 21:40 on the PREVIOUS day. Both halves of that were checked
	 * against PHP's own timezone database directly rather than read off
	 * this theme's implementation.
	 */
	public function test_a_snapshot_time_is_labelled_in_the_site_timezone(): void {
		blueline_test_reset_state();

		$this->assertSame(
			'2026-02-01 21:40',
			blueline_settings_snapshot_time_label( 1770000000 )
		);
	}

	/**
	 * A key whose value differs is reported as changed, with both sides.
	 */
	public function test_diff_reports_a_changed_key_with_both_values(): void {
		$diff = blueline_settings_diff(
			array( 'footer_heading' => 'The League' ),
			array( 'footer_heading' => 'The ARL' )
		);

		$this->assertSame( 'changed', $diff['footer_heading']['status'] );
		$this->assertSame( 'The League', $diff['footer_heading']['from'] );
		$this->assertSame( 'The ARL', $diff['footer_heading']['to'] );
	}

	/**
	 * A key present on both sides with the same value is reported as
	 * unchanged -- present in the payload, and equal.
	 */
	public function test_diff_reports_an_equal_key_as_unchanged(): void {
		$diff = blueline_settings_diff(
			array( 'contact_email' => 'a@b.test' ),
			array( 'contact_email' => 'a@b.test' )
		);

		$this->assertSame( 'unchanged', $diff['contact_email']['status'] );
	}

	/**
	 * THE ONE THAT MATTERS. A write that OMITS a key carries the stored
	 * value forward -- it does not reset that key to its default (see
	 * blueline_settings_merge()). A diff listing only differing keys would
	 * therefore read as "these are the only differences" when every omitted
	 * key is merely invisible, and an admin would conclude an unlisted key
	 * is unchanged-because-equal when it is unlisted-because-absent.
	 *
	 * So a key absent from $to is reported explicitly, with the value that
	 * will survive the write.
	 */
	public function test_diff_surfaces_a_key_absent_from_the_payload_as_carried_forward(): void {
		$diff = blueline_settings_diff(
			array(
				'footer_heading'     => 'The ARL',
				'chrome_utility_nav' => false,
			),
			array( 'footer_heading' => 'The ARL' )
		);

		$this->assertArrayHasKey( 'chrome_utility_nav', $diff );
		$this->assertSame( 'carried_forward', $diff['chrome_utility_nav']['status'] );
		$this->assertFalse( $diff['chrome_utility_nav']['from'] );
		$this->assertFalse(
			$diff['chrome_utility_nav']['to'],
			'the carried-forward value IS the value after the write'
		);
	}

	/**
	 * A key the payload introduces that the current state has no value for
	 * is its own state -- calling it "changed from nothing" would invent a
	 * previous value that never existed.
	 */
	public function test_diff_reports_a_key_only_the_payload_carries_as_added(): void {
		$diff = blueline_settings_diff(
			array(),
			array( 'footer_heading' => 'The ARL' )
		);

		$this->assertSame( 'added', $diff['footer_heading']['status'] );
		$this->assertSame( 'The ARL', $diff['footer_heading']['to'] );
	}

	/**
	 * Every key on either side appears exactly once -- a preview that
	 * dropped keys would be the very failure this diff exists to prevent.
	 */
	public function test_diff_covers_every_key_from_both_sides(): void {
		$diff = blueline_settings_diff(
			array(
				'footer_heading'  => 'The League',
				'footer_location' => 'Burlington, Ontario',
			),
			array(
				'footer_heading' => 'The ARL',
				'contact_email'  => 'a@b.test',
			)
		);

		$this->assertSame(
			array( 'footer_heading', 'footer_location', 'contact_email' ),
			array_keys( $diff )
		);
	}
}
