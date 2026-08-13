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
 * Carry forward any top-level key this submission did not post.
 *
 * The Settings API hands update_option() exactly what was in $_POST for this
 * option -- and a per-tab form only contains its own tab's fields. Without
 * this merge, saving Content would delete Links, Commerce and Sections.
 *
 * $old_value is a parameter here, which is why the merge lives on this
 * filter and not on sanitize_option_* (where it would need its own
 * get_option() call and a subtler race).
 *
 * @param mixed $new_value The value about to be written.
 * @param mixed $old_value The value currently stored.
 * @return mixed
 */
function blueline_settings_merge( $new_value, $old_value ) {
	if ( ! is_array( $new_value ) ) {
		return $old_value;
	}
	if ( ! is_array( $old_value ) ) {
		return $new_value;
	}
	foreach ( $old_value as $key => $stored ) {
		if ( ! array_key_exists( $key, $new_value ) ) {
			$new_value[ $key ] = $stored;
		}
	}
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
