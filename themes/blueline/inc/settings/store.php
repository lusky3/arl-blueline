<?php
/**
 * Settings store: the single read accessor, the cross-tab merge, and the
 * forward-only schema migration for the Appearance -> Blueline control
 * panel's one option.
 *
 * The panel has five tabs writing into ONE option (BLUELINE_SETTINGS_OPTION,
 * defined in inc/settings/defaults.php). The Settings API hands
 * update_option() exactly what was in $_POST for that option, and a per-tab
 * form contains only its own tab's fields -- so without a merge, saving the
 * Content tab would silently delete Links, Commerce and Sections.
 *
 * The fix uses two hooks doing two different jobs, both core's own design:
 *
 * - sanitize_option_{$option} receives only the keys THIS submission
 *   posted. Validation belongs there (a later task's job, not this file's).
 * - pre_update_option_{$option} receives ($value, $old_value) with the full
 *   stored value as a parameter. The merge belongs HERE, because no
 *   get_option() call and no race is needed to see what is currently
 *   stored.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_merge', 10, 2 );

/**
 * Carry forward any top-level key this submission did not post -- except a
 * key this submission OWNS but chose to omit, which is a deliberate delete,
 * and ownership by omission is only ever available to a TAB-SCOPED
 * submission (one that also names `_tab`).
 *
 * The Settings API hands update_option() exactly what was in $_POST for this
 * option -- and a per-tab form only contains its own tab's fields. Without
 * this merge, saving Content would delete Links, Commerce and Sections.
 *
 * $old_value is a parameter here, which is why the merge lives on this
 * filter and not on sanitize_option_* (where it would need its own
 * get_option() call and a subtler race).
 *
 * Plain "absent from the submission means carry it forward" is right for a
 * field owned by another tab, but wrong for an unchecked checkbox: HTML
 * omits an unchecked checkbox from $_POST entirely, so a naive carry-forward
 * can never durably turn a toggle back off -- the stale "on" value from
 * $old_value would simply be restored on every subsequent save, from any
 * tab. Relying on the renderer to always post an explicit `false` for an
 * unchecked box was considered and rejected: that is a convention the first
 * person to add a toggle would not know to follow, which is exactly how
 * this bug would recur.
 *
 * Instead, a submission that wants deletion semantics for some of its own
 * absent keys names them in a reserved `_posted_fields` key: the list of
 * field keys THIS submission owns (typically: every field the posting tab's
 * form renders, checked or not). That reserved key is never written to the
 * option -- it exists only to disambiguate this one merge decision:
 *
 * - A key present in $new_value: keep the submitted value, regardless of
 *   whether it is also named in `_posted_fields`.
 * - A key named in `_posted_fields` (of a TAB-SCOPED submission -- see
 *   below) but absent from $new_value: the submission owns this field and
 *   chose not to include it -- an unchecked checkbox. Deleted: NOT carried
 *   forward.
 * - A key present in $old_value but named in neither: belongs to a tab this
 *   submission didn't touch. Carried forward untouched.
 * - `_posted_fields` absent entirely, OR present but this is NOT a
 *   tab-scoped submission (see below): every key absent from $new_value is
 *   carried forward from $old_value.
 *
 * ## `_tab` gates `_posted_fields` here too, not only in page.php
 *
 * A submission naming a reserved `_tab` key came from this panel's own
 * rendered form -- inc/settings/page.php's blueline_settings_sanitize_callback()
 * forwards `_tab` into its own return value (the sanitize_option_ pass that
 * runs immediately before this one, on every real write) specifically so
 * this function can read it here. Absent (or empty) means a PROGRAMMATIC
 * write -- WP-CLI, a JSON import, blueline_settings_migrate()'s own
 * update_option() call -- which never has a tab to scope a delete decision
 * to. Its values are authoritative for whatever it names a real value for
 * (handled by the `array_key_exists( $key, $new_value )` check above,
 * unconditionally -- a present key, even `false`, was never at risk of
 * being overwritten by this function regardless of `_tab`), but naming a
 * field ONLY in `_posted_fields`, without ALSO posting a value for it,
 * carries no ownership at all without `_tab` to legitimise it: this task
 * found exactly that gap on staging -- `update_option( OPTION, array(
 * '_posted_fields' => array( 'chrome_sponsors' ) ) )`, with no `_tab`,
 * deleted `chrome_sponsors` outright, indistinguishable once read back
 * through blueline_settings()'s default-fallback from "silently reverted to
 * its default value". A programmatic write that wants to actually change a
 * field's value must post that value; it cannot rely on the "unchecked
 * checkbox" omission idiom, because it has no tab-scoped ownership to make
 * that omission unambiguous.
 *
 * inc/settings/page.php's own `_posted_fields`-to-`_tab` matching (dropping
 * any entry whose schema `tab` does not equal the submission's `_tab`)
 * already reduces `_posted_fields` to an empty array for any programmatic
 * write that reaches THIS function through the normal sanitize_option_ ->
 * pre_update_option_ dispatch chain -- no real schema field's `tab` is ever
 * the empty string a programmatic write's `_tab` resolves to. That is real,
 * but it is a DIFFERENT file's protection: this function is registered
 * independently, at file scope, on `pre_update_option_{$option}`, and
 * nothing before this fix stopped it from being reached directly (a future
 * write path, or -- as this project's own test suite already does --
 * calling it in a unit test) without page.php's cooperation. The check
 * below makes the `_tab` boundary this function's OWN guarantee, not merely
 * an inherited one.
 *
 * @param mixed $new_value The value about to be written.
 * @param mixed $old_value The value currently stored.
 * @return mixed
 */
function blueline_settings_merge( $new_value, $old_value ) {
	if ( ! is_array( $new_value ) ) {
		return $old_value;
	}

	// Absent (isset() false for a null/missing key) resolves to '', the
	// same "no tab" value a programmatic write's forwarded `_tab` carries
	// once it reaches here via inc/settings/page.php.
	$submitted_tab = isset( $new_value['_tab'] ) ? (string) $new_value['_tab'] : '';
	unset( $new_value['_tab'] );

	$posted_fields = null;
	if ( '' !== $submitted_tab && array_key_exists( '_posted_fields', $new_value ) ) {
		$posted_fields = (array) $new_value['_posted_fields'];
	}
	unset( $new_value['_posted_fields'] );

	if ( ! is_array( $old_value ) ) {
		return $new_value;
	}

	foreach ( $old_value as $key => $stored ) {
		if ( array_key_exists( $key, $new_value ) ) {
			continue; // Posted this submission -- keep the new value.
		}

		if ( null !== $posted_fields && in_array( $key, $posted_fields, true ) ) {
			// Owned by this TAB-SCOPED submission but omitted: a deliberate
			// delete (e.g. an unchecked checkbox). Do not restore it.
			continue;
		}

		// Belongs to a tab this submission didn't touch -- carry it
		// forward. Also the whole-array fallback when `_posted_fields`
		// itself is absent, or this is a programmatic write (no `_tab`),
		// where `_posted_fields` carries no ownership at all.
		$new_value[ $key ] = $stored;
	}

	// Defence in depth: the loop above carries forward any $old_value key
	// not otherwise accounted for, and `_posted_fields`/`_tab` are such keys
	// if either were ever (incorrectly) persisted by an earlier bug or a
	// write that bypassed this filter -- e.g. the first-ever-write quirk
	// this file's own docblock references, where add_option()'s own
	// re-sanitize pass has no merge stage to strip them a second time.
	// Strip both again so neither can ever resurface.
	unset( $new_value['_posted_fields'], $new_value['_tab'] );

	return $new_value;
}

/**
 * The single read accessor for the settings panel's option.
 *
 * Every field the schema declares always has a value: any field absent from
 * storage (a fresh install, or a field added to the schema after the option
 * was last saved) falls back to blueline_settings_defaults(). Keys stored
 * but not part of the current schema -- notably `_schema` itself -- are
 * deliberately excluded from the returned array, since callers ask this
 * function for field values, not for the migration bookkeeping stored
 * alongside them.
 *
 * @param string $key Optional. A single field key to return. Omit for the
 *                     whole settings array.
 * @return mixed The whole settings array, a single field's value, or null
 *               if $key names a field the schema does not declare.
 */
function blueline_settings( string $key = '' ) {
	$defaults = blueline_settings_defaults();
	$stored   = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored   = is_array( $stored ) ? $stored : array();

	$values = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );

	if ( '' === $key ) {
		return $values;
	}

	return $values[ $key ] ?? null;
}

/**
 * Forward-only migration of the settings option to the current schema.
 *
 * Runs on `init`, not `admin_init`: `admin_init` never fires for anonymous,
 * cron, REST or WP-CLI traffic, and on a volunteer-run site the gap between
 * a deploy and the first admin login is unbounded -- the option must be
 * current before anything else reads it, not just before an admin next
 * visits wp-admin.
 *
 * Guarded by two things doing two different jobs:
 *
 * - An integer compare of the stored `_schema` against
 *   BLUELINE_SETTINGS_SCHEMA_VERSION. This is the actual correctness
 *   guarantee. It is also what makes the migration forward-only: a stored
 *   `_schema` NEWER than the running code's version refuses to write,
 *   because that only happens when this theme has been rolled back to an
 *   older version after a newer one already migrated the option -- and a
 *   rollback silently downgrading (and so corrupting) that newer data would
 *   be far worse than a rolled-back site simply running against
 *   already-migrated data it doesn't fully understand yet.
 * - A wp_cache_add() lock with a TTL, narrowing (never guaranteeing) the
 *   window in which two concurrent requests both attempt the write. The TTL
 *   matters on its own: without one, a crash mid-migration would hold the
 *   lock forever with no self-healing path. But the lock is a best-effort
 *   nicety, not the guarantee -- without a persistent object cache (the
 *   common case on a volunteer-run site), wp_cache_add() is per-request and
 *   locks nothing at all. The integer compare above is what actually keeps
 *   this function safe to call from every single request.
 */
function blueline_settings_migrate(): void {
	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	$stored_schema = isset( $stored['_schema'] ) ? (int) $stored['_schema'] : 0;

	if ( $stored_schema >= BLUELINE_SETTINGS_SCHEMA_VERSION ) {
		// Already current, or (a rolled-back theme) newer than this code
		// knows about. Forward-only: never downgrade.
		return;
	}

	$got_lock = wp_cache_add( 'blueline_migrating', 1, 'blueline', 60 );
	if ( ! $got_lock ) {
		// Another request is (or very recently was) migrating. Not this
		// call's job to guarantee -- see the docblock above.
		return;
	}

	$merged            = array_merge( blueline_settings_defaults(), $stored );
	$merged['_schema'] = BLUELINE_SETTINGS_SCHEMA_VERSION;

	update_option( BLUELINE_SETTINGS_OPTION, $merged );
}
add_action( 'init', 'blueline_settings_migrate' );
