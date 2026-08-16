<?php
/**
 * Save snapshots for the Appearance -> Blueline control panel: a short,
 * timestamped history of what the settings option held BEFORE each save,
 * the restore that puts one back, and the three-way diff both the restore
 * screen's sibling (`wp blueline settings import --dry-run`) and any future
 * preview are built on.
 *
 * Why a history at all, when the panel already has `reset`: reset writes
 * defaults over everything, which for content fields discards every word
 * anyone ever typed. A snapshot is the cheaper and strictly better undo --
 * the design spec's own words (§6.8).
 *
 * ## Stored separately, and never autoloaded
 *
 * These live in their own option (BLUELINE_SETTINGS_SNAPSHOTS_OPTION), not
 * inside BLUELINE_SETTINGS_OPTION, and are written with an EXPLICIT
 * `false` autoload argument rather than whatever the default happens to be
 * -- the spec requires non-autoloaded twice (§6.1 and §6.8). The reason is
 * concrete: this is write-only history that nothing on the front end ever
 * reads, and autoloading it would put up to ten copies of the whole
 * settings array into every single request, including anonymous ones.
 *
 * ## Why the snapshot is taken on `pre_update_option_{$option}`
 *
 * That filter runs BEFORE the new value is written and receives the value
 * currently stored, so it is the one place where the state being REPLACED
 * is still available without a second get_option() call. Snapshotting the
 * incoming value instead would produce an "undo" that restores the change
 * you were trying to undo.
 *
 * The callback below is registered at priority 20, AFTER
 * inc/settings/store.php's blueline_settings_merge() at 10, so the value it
 * compares against storage is the fully merged one that is actually about
 * to be written -- not the partial, single-tab submission that arrived.
 * Without that ordering, every tab save would look like a change to every
 * other tab's fields. The callback returns its input untouched: it is an
 * observation point on this filter, not a transformation.
 *
 * Note the argument order this hook uses -- `( $value, $old_value, $option )`
 * for `pre_update_option_{$option}`, which is NOT the order the generic
 * `pre_update_option` filter uses (`( $value, $option, $old_value )`). This
 * project has been bitten by that difference before; the callback below
 * accepts two arguments and is registered on the option-specific hook only.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option the snapshot history is stored under -- deliberately its own
 * option rather than a key inside BLUELINE_SETTINGS_OPTION, so it can be
 * non-autoloaded independently of the settings the front end does read on
 * every request.
 */
const BLUELINE_SETTINGS_SNAPSHOTS_OPTION = 'blueline_settings_snapshots';

/**
 * How many snapshots are kept. Ten, per the design spec's §6.8 ("last 10,
 * non-autoloaded"): enough that a run of small saves does not immediately
 * push the mistake you actually want to undo off the end, small enough that
 * the option stays a few tens of kilobytes at worst.
 */
const BLUELINE_SETTINGS_SNAPSHOT_LIMIT = 10;

add_filter( 'pre_update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_snapshot_on_save', 20, 2 );

/**
 * Record the settings state a save is about to replace.
 *
 * Registered on `pre_update_option_{$option}` at priority 20 -- after
 * inc/settings/store.php's merge at 10 -- and returns $new_value unchanged;
 * see this file's own docblock for why the snapshot belongs on this filter
 * and at this priority.
 *
 * Two writes are deliberately NOT snapshotted:
 *
 * - The first-ever write, where $old_value is `false` rather than an array
 *   because the option does not exist yet. There is no previous state to
 *   restore to, and recording the `false` as if it were one would give the
 *   admin a restore point that writes nothing.
 * - A write whose merged result is identical to what is already stored.
 *   Ten no-op saves would otherwise evict every real undo point from a
 *   ten-deep history. (Whether WordPress core also skips the underlying
 *   database write in that case is core's business and is not relied on
 *   here: if it writes anyway, all this check has skipped is a snapshot of
 *   a state identical to the one already stored.)
 *
 * @param mixed $new_value The merged value about to be written.
 * @param mixed $old_value The value currently stored.
 * @return mixed $new_value, untouched.
 */
function blueline_settings_snapshot_on_save( $new_value, $old_value ) {
	if ( ! is_array( $old_value ) ) {
		return $new_value; // First-ever write: nothing is being replaced.
	}

	if ( $new_value === $old_value ) {
		return $new_value; // Nothing changes; nothing to undo.
	}

	blueline_settings_snapshot_take( $old_value );

	return $new_value;
}

/**
 * Push $settings onto the front of the history, evicting anything past
 * BLUELINE_SETTINGS_SNAPSHOT_LIMIT.
 *
 * `_posted_fields` and `_tab` are stripped first: they are request-scoped
 * bookkeeping describing ONE submission's form (see
 * blueline_settings_merge()'s docblock), never part of the settings a
 * restore should replay. They are normally stripped before storage
 * already; the one documented exception is a first-ever write, where
 * add_option()'s own re-sanitize pass has no merge stage to strip them a
 * second time, so the value this function is handed can still carry them.
 *
 * Each snapshot carries an `id` one greater than the highest currently in
 * the list. A restore control has to name a snapshot in a form submission,
 * and a list POSITION would silently come to mean a different snapshot as
 * soon as one more save happened -- including the save the restore itself
 * performs.
 *
 * @param array<string, mixed> $settings The settings state being recorded.
 * @return void
 */
function blueline_settings_snapshot_take( array $settings ): void {
	unset( $settings['_posted_fields'], $settings['_tab'] );

	$snapshots = blueline_settings_snapshot_list();

	$highest_id = 0;
	foreach ( $snapshots as $snapshot ) {
		$highest_id = max( $highest_id, (int) $snapshot['id'] );
	}

	array_unshift(
		$snapshots,
		array(
			'id'       => $highest_id + 1,
			'time'     => time(),
			'settings' => $settings,
		)
	);

	update_option(
		BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
		array_slice( $snapshots, 0, BLUELINE_SETTINGS_SNAPSHOT_LIMIT ),
		false
	);
}

/**
 * The stored snapshots, newest first -- the order a restore screen reads
 * them in.
 *
 * Rows that are not the shape this file writes are dropped rather than
 * returned: this option is not autoloaded and not schema-validated on
 * write by anything else, so a hand-edited or partially-restored row must
 * not be able to fatal a restore screen that assumes `id`/`time`/`settings`
 * are all there.
 *
 * @return array<int, array{id: int, time: int, settings: array<string, mixed>}>
 */
function blueline_settings_snapshot_list(): array {
	$stored = get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	$snapshots = array();

	foreach ( $stored as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['id'], $row['time'] ) || ! isset( $row['settings'] ) || ! is_array( $row['settings'] ) ) {
			continue;
		}

		$snapshots[] = array(
			'id'       => (int) $row['id'],
			'time'     => (int) $row['time'],
			'settings' => $row['settings'],
		);
	}

	return $snapshots;
}

/**
 * One snapshot by id.
 *
 * @param int $id A snapshot id from blueline_settings_snapshot_list().
 * @return array{id: int, time: int, settings: array<string, mixed>}|null The
 *               snapshot, or null when no snapshot with that id is stored
 *               (it was evicted, or the id was never real).
 */
function blueline_settings_snapshot_get( int $id ): ?array {
	foreach ( blueline_settings_snapshot_list() as $snapshot ) {
		if ( $snapshot['id'] === $id ) {
			return $snapshot;
		}
	}

	return null;
}

/**
 * Write a snapshot's settings back, through the ordinary
 * update_option() path -- so the same sanitize callback and the same merge
 * every other write runs through apply here too.
 *
 * Two consequences worth being explicit about, both covered by
 * tests/SettingsSnapshotsTest.php rather than assumed:
 *
 * - The restore is itself a save, so it records the pre-restore state as a
 *   new snapshot. A restore can therefore be undone.
 * - Merge semantics still apply: a key the snapshot does not carry keeps
 *   its CURRENT value rather than being reset. A restore is "put these
 *   values back", not "make storage identical to this snapshot".
 *
 * @param int $id A snapshot id from blueline_settings_snapshot_list().
 * @return bool True when a snapshot with that id was found and written,
 *              false when it was not (nothing is written in that case).
 */
function blueline_settings_snapshot_restore( int $id ): bool {
	$snapshot = blueline_settings_snapshot_get( $id );

	if ( null === $snapshot ) {
		return false;
	}

	update_option( BLUELINE_SETTINGS_OPTION, $snapshot['settings'] );

	return true;
}

/**
 * A snapshot's timestamp as a label for the restore list: `Y-m-d H:i` in
 * the site's own timezone.
 *
 * A fixed, unambiguous format rather than the site's `date_format` option:
 * this list is read to answer "which of these is the one from just before
 * I broke it", and two entries minutes apart have to be distinguishable,
 * which a date-only format is not. The site timezone (wp_timezone(), the
 * same source inc/season-state.php uses) rather than UTC, because the
 * admin reading it thinks in local time.
 *
 * @param int $timestamp A Unix timestamp, as stored on a snapshot.
 * @return string
 */
function blueline_settings_snapshot_time_label( int $timestamp ): string {
	return ( new DateTimeImmutable( '@' . $timestamp ) )
		->setTimezone( wp_timezone() )
		->format( 'Y-m-d H:i' );
}

/**
 * Compare two settings states key by key, reporting FOUR distinct states
 * rather than only "what differs".
 *
 * The distinction this function exists for: a write that OMITS a key
 * carries the stored value forward -- it does NOT reset that key to its
 * default (inc/settings/store.php's blueline_settings_merge(), which is
 * deliberate merge-not-replace behaviour and is staying). A diff that
 * listed only keys whose values differ would therefore read as "these are
 * the only differences" when every omitted key is simply invisible in it.
 * An admin previewing a partial import would conclude the unlisted keys are
 * unchanged-because-equal when they are unlisted-because-absent. Those are
 * different facts and only one of them is safe to act on, so the absent
 * ones are reported explicitly, with the value that will survive.
 *
 * The four statuses:
 *
 * - `changed`         -- present in $to with a different value.
 * - `unchanged`       -- present in $to with the same value.
 * - `carried_forward` -- absent from $to. `to` is the current value,
 *                        because that is what a merge-not-replace write
 *                        leaves in place.
 * - `added`           -- present in $to, absent from $from. `from` is null,
 *                        which is a stand-in for "no previous value", not a
 *                        stored null.
 *
 * Values are compared with `===`, so `''`, `0` and `false` are three
 * different values here -- which they are for this schema (a `page_id`
 * field's `0` means "use the fallback", a `date` field's `''` means "not
 * set", and a `bool` field's `false` means off).
 *
 * @param array<string, mixed> $from The current state.
 * @param array<string, mixed> $to   The state being previewed -- an import
 *                                     payload or a snapshot, which may
 *                                     legitimately carry only some keys.
 * @return array<string, array{status: string, from: mixed, to: mixed}> One
 *               entry per key on either side, $from's keys first (in their
 *               own order), then any key only $to carries.
 */
function blueline_settings_diff( array $from, array $to ): array {
	$diff = array();

	foreach ( $from as $key => $current ) {
		if ( ! array_key_exists( $key, $to ) ) {
			$diff[ $key ] = array(
				'status' => 'carried_forward',
				'from'   => $current,
				'to'     => $current,
			);
			continue;
		}

		$diff[ $key ] = array(
			'status' => $to[ $key ] === $current ? 'unchanged' : 'changed',
			'from'   => $current,
			'to'     => $to[ $key ],
		);
	}

	foreach ( $to as $key => $incoming ) {
		if ( array_key_exists( $key, $from ) ) {
			continue;
		}

		$diff[ $key ] = array(
			'status' => 'added',
			'from'   => null,
			'to'     => $incoming,
		);
	}

	return $diff;
}
