<?php
/**
 * `aa_acknowledgements` storage.
 *
 * Real storage for what, before this file, was only an import-time
 * discard target (inc/settings/import.php unsets it unconditionally on
 * every import -- that does not change here; consent is not something a
 * file can assert on an admin's behalf). A map of acknowledgement id (a
 * `scope` string, e.g. `occasion:canada-day`) => {rule_id, ratio,
 * user_id, date, inputs_hash, scope}.
 *
 * `aa_acknowledgements` is protected the same way `_schema` already is
 * (inc/settings/page.php's BLUELINE_SETTINGS_RESERVED_KEYS,
 * blueline_settings_sanitize_callback()'s reserved-key branch, and
 * inc/settings/store.php's blueline_settings_merge() carry-forward)
 * rather than being a real schema field: there is no panel tab or form
 * field that owns it in Phase 2.0, or even Phase 2.1's Occasions tab --
 * see docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md
 * §4.4/§4.5 -- so a schema `type`/`tab` entry would have nothing real to
 * render.
 *
 * The pure record/covers/invalidate mechanism functions this file will
 * also carry (Phase 2.0's next task) are what Phase 2.1's resolver calls
 * once a real Occasion model exists to supply their parameters from.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate and repair a stored `aa_acknowledgements` value.
 *
 * Called from inc/settings/page.php's blueline_settings_sanitize_callback()
 * reserved-key branch, the same choke point `_schema` already goes
 * through -- so this runs on every write path (WP-CLI, a direct
 * update_option() call, this file's own future record/invalidate
 * functions), not only ones that pass through wp-admin. Never fatal: a
 * non-array input, or any entry missing a required key, carrying the
 * wrong type for one, or whose own `scope` field disagrees with its map
 * key, is dropped rather than allowed to corrupt the option or crash a
 * later reader -- the same defensive posture
 * blueline_load_contrast_thresholds() takes for a malformed
 * contrast-rules.json.
 *
 * @param mixed $value Raw value to validate.
 * @return array<string, array{rule_id:string, ratio:float, user_id:int, date:int, inputs_hash:string, scope:string}>
 */
function blueline_sanitize_acknowledgements( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$clean = array();

	foreach ( $value as $scope => $entry ) {
		if ( ! is_string( $scope ) || '' === $scope || ! is_array( $entry ) ) {
			continue;
		}

		if ( ! isset( $entry['rule_id'], $entry['ratio'], $entry['user_id'], $entry['date'], $entry['inputs_hash'], $entry['scope'] ) ) {
			continue;
		}

		if ( ! is_string( $entry['rule_id'] ) || '' === $entry['rule_id']
			|| ! is_numeric( $entry['ratio'] )
			|| ! is_numeric( $entry['user_id'] )
			|| ! is_numeric( $entry['date'] )
			|| ! is_string( $entry['inputs_hash'] ) || '' === $entry['inputs_hash']
			|| ! is_string( $entry['scope'] ) || $entry['scope'] !== $scope
		) {
			continue;
		}

		$clean[ $scope ] = array(
			'rule_id'     => $entry['rule_id'],
			'ratio'       => (float) $entry['ratio'],
			'user_id'     => (int) $entry['user_id'],
			'date'        => (int) $entry['date'],
			'inputs_hash' => $entry['inputs_hash'],
			'scope'       => $scope,
		);
	}

	return $clean;
}
