<?php
/**
 * Covers inc/settings/snapshots.php -- Task 10's save history, the restore
 * that undoes a save, and the four-state diff the import preview is built
 * on (changed / unchanged / carried_forward / added).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/occasions.php'; // blueline_sanitize_occasions(), which page.php's sanitize callback dispatches to for the `occasions` reserved key.
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
		blueline_test_reset_state();
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

		// Ids must stay unique ACROSS eviction, and this is the only test that
		// can see it. An id derived from the list position looks correct while
		// the list is still growing -- which is all the id-versus-position test
		// below exercises, with three snapshots -- but once eviction starts,
		// position stops increasing while saves keep happening, so a
		// position-derived id repeats. Three rows here would then share id 11,
		// and blueline_settings_snapshot_get() returns the first match: a
		// restore link labelled with one timestamp would restore a different
		// save. Eleven saves is an ordinary week for a settings panel.
		$ids = array_column( $snapshots, 'id' );
		$this->assertSame( $ids, array_unique( $ids ), 'snapshot ids must not repeat once eviction begins' );
		$this->assertSame( array( 12, 11, 10, 9, 8, 7, 6, 5, 4, 3 ), $ids, 'ids keep counting past the retention limit' );
	}

	/**
	 * Every snapshot carries an id that stays valid as newer snapshots
	 * arrive -- a restore link built from a list POSITION would silently
	 * point at a different snapshot the moment one more save happened.
	 *
	 * THREE snapshots, not two, and that is load-bearing: with two, the
	 * oldest sits at position 1 and carries id 1, so a lookup that keyed by
	 * position would return the right row by coincidence and this test --
	 * the one NAMED for the id-versus-position property -- would pass
	 * against an implementation that does not have it. With three, the
	 * oldest is id 1 at position 2, and the two readings disagree.
	 */
	public function test_snapshot_ids_are_unique_and_stable_as_the_list_grows(): void {
		blueline_settings_snapshot_take( array( 'footer_heading' => 'first' ) );
		$first_id = blueline_settings_snapshot_list()[0]['id'];

		blueline_settings_snapshot_take( array( 'footer_heading' => 'second' ) );
		blueline_settings_snapshot_take( array( 'footer_heading' => 'third' ) );

		$found = blueline_settings_snapshot_get( $first_id );

		$this->assertNotNull( $found );
		$this->assertSame( 'first', $found['settings']['footer_heading'] );
		$this->assertSame(
			2,
			array_search( $first_id, array_column( blueline_settings_snapshot_list(), 'id' ), true ),
			'the fixture only proves anything while that id and its position differ'
		);
		$this->assertNotSame( $first_id, blueline_settings_snapshot_list()[0]['id'] );
	}

	/**
	 * `_posted_fields` and `_tab` describe ONE submission's form, not the
	 * settings -- and a first-ever write leaves them in storage (see
	 * test_a_save_that_changes_nothing_records_no_snapshot() for why), so
	 * the value handed to this function really can carry them. They must
	 * never become part of a snapshot a restore later replays.
	 */
	public function test_a_snapshot_never_records_the_request_scoped_bookkeeping_keys(): void {
		blueline_settings_snapshot_take(
			array(
				'footer_heading' => 'The League',
				'_posted_fields' => array( 'footer_heading' ),
				'_tab'           => 'content',
			)
		);

		$recorded = blueline_settings_snapshot_list()[0]['settings'];

		$this->assertSame( array( 'footer_heading' => 'The League' ), $recorded );
	}

	/**
	 * Nothing validates this option on write except this file, and it is
	 * not autoloaded, so a hand-edited or half-written row is a real
	 * possibility. A malformed row is dropped rather than returned, so the
	 * restore screen cannot fatal on a missing `id`/`time`/`settings`.
	 */
	public function test_a_malformed_stored_row_is_dropped_rather_than_returned(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_SNAPSHOTS_OPTION ] = array(
			'not even an array',
			array( 'id' => 7 ),                       // No time, no settings.
			array(
				'id'       => 8,
				'time'     => 123,
				'settings' => 'not an array',
			),
			array(
				'id'       => 9,
				'time'     => 456,
				'settings' => array( 'footer_heading' => 'The League' ),
			),
		);

		$snapshots = blueline_settings_snapshot_list();

		$this->assertCount( 1, $snapshots );
		$this->assertSame( 9, $snapshots[0]['id'] );
	}

	/**
	 * A snapshot is replayed through the ordinary write path, so a value
	 * the sanitizer refuses is refused here too and the currently-stored
	 * value survives.
	 *
	 * This matters because a snapshot is NOT itself validated on the way
	 * in: it records whatever was stored, and `wp db import` (the same
	 * bypass Task 9's repair path exists for) can put an invalid value
	 * there. Restoring must not be the way that value gets laundered back
	 * into a validated option -- and an admin must be told, which is what
	 * the settings error asserted below becomes on screen.
	 */
	public function test_restoring_an_invalid_value_keeps_the_stored_one_and_reports_it(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'good@example.test' ) );

		blueline_settings_snapshot_take( array( 'contact_email' => 'not an address' ) );

		$this->assertTrue( blueline_settings_snapshot_restore( blueline_settings_snapshot_list()[0]['id'] ) );

		$this->assertSame(
			'good@example.test',
			blueline_settings( 'contact_email' ),
			'a restore must not launder an invalid value into the option'
		);
		$this->assertNotEmpty(
			get_settings_errors( BLUELINE_SETTINGS_OPTION ),
			'and the admin must be told it was refused'
		);
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
	 * A settings state that has settled: two writes of the same value, the
	 * second of which strips the first-write `_posted_fields`/`_tab` quirk
	 * test_a_save_that_changes_nothing_records_no_snapshot() documents, so
	 * the store is genuinely at rest and any snapshot taken after this
	 * point is attributable to the write under test.
	 *
	 * @return array<int, array{id: int, time: int, settings: array<string, mixed>}> The history as it stands once settled.
	 */
	private function settle_the_store(): array {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		return blueline_settings_snapshot_list();
	}

	/**
	 * One valid occasion, in the shape blueline_sanitize_occasions()
	 * accepts -- a REAL, admin-meaningful state change, deliberately not a
	 * bookkeeping key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function one_occasion(): array {
		return array(
			'canada-day' => array(
				'id'     => 'canada-day',
				'label'  => 'Canada Day',
				'type'   => 'decorative',
				'window' => array(
					'start_md' => '07-01',
					'end_md'   => '07-01',
				),
				'accent' => '#274a63',
				'motif'  => 'none',
				'line'   => '',
				'mode'   => 'auto',
			),
		);
	}

	/**
	 * A write whose ONLY difference from the stored value is a bookkeeping
	 * key must not burn a history slot either -- the case the `===` no-op
	 * check above structurally cannot see, since a
	 * `_validated_against`-only diff is never identical to the stored
	 * array.
	 *
	 * This is not hypothetical: inc/settings/validation.php's deploy-drift
	 * check writes `_validated_against` back on every deploy that touches
	 * style.css or contrast-rules.json. At one per deploy, ten deploys --
	 * plausibly one week of active theme work -- would evict an admin's
	 * entire ten-deep undo history and replace it with snapshots of states
	 * nobody chose and that differ from each other in nothing an admin can
	 * see.
	 *
	 * The bookkeeping write itself must still HAPPEN; only the snapshot is
	 * skipped.
	 */
	public function test_a_bookkeeping_only_save_records_no_snapshot(): void {
		$settled = $this->settle_the_store();

		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => 'a-fresh-inputs-hash' ) );

		$stored = get_option( BLUELINE_SETTINGS_OPTION );

		$this->assertSame( 'a-fresh-inputs-hash', $stored['_validated_against'], 'the bookkeeping value must still be written' );
		$this->assertSame( $settled, blueline_settings_snapshot_list(), 'a bookkeeping-only write must not push a snapshot' );
	}

	/**
	 * The discriminating half of the test above, and the one that would
	 * catch a bookkeeping check written as "does `_validated_against`
	 * differ" rather than "does EVERY differing key qualify": a genuine
	 * `occasions` change carrying an incidental `_validated_against`
	 * update alongside it is a real, undoable change and must still
	 * snapshot -- the pre-change state, exactly as any other save does.
	 */
	public function test_a_real_change_alongside_a_bookkeeping_change_still_snapshots(): void {
		$settled = $this->settle_the_store();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions'          => $this->one_occasion(),
				'_validated_against' => 'a-fresh-inputs-hash',
			)
		);

		$snapshots = blueline_settings_snapshot_list();

		$this->assertCount( count( $settled ) + 1, $snapshots, 'a real change must still push a snapshot, bookkeeping alongside it or not' );
		$this->assertArrayNotHasKey( 'occasions', $snapshots[0]['settings'], 'the snapshot records the state being REPLACED -- before the occasion existed' );
		$this->assertArrayHasKey( 'canada-day', get_option( BLUELINE_SETTINGS_OPTION )['occasions'], 'the real change must have been written' );
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
