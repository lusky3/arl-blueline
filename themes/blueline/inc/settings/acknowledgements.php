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

/**
 * Record (or replace) the acknowledgement for one scope.
 *
 * Pure: takes the current acknowledgements map and returns a new one
 * with $scope's entry set, using the real clock (time()) for `date` --
 * the same plain time() inc/settings/snapshots.php's
 * blueline_settings_snapshot_take() already uses for its own `time`
 * field, rather than an injected clock. Only one entry is ever live per
 * scope: acknowledging the same scope again (e.g. a changed accent that
 * still fails, re-acknowledged) replaces the previous entry rather than
 * accumulating history.
 *
 * @param array<string, array<string, mixed>> $acknowledgements Current map.
 * @param string                              $scope            What was acknowledged, e.g. `occasion:canada-day`.
 * @param string                              $rule_id          The contrast-rules.json rule id the value failed.
 * @param float                               $ratio            The computed contrast ratio that failed it.
 * @param string                              $inputs_hash      blueline_settings_inputs_hash()'s value at the moment of acknowledgement.
 * @param int                                 $user_id          The acknowledging user's ID.
 * @return array<string, array<string, mixed>> The updated map.
 */
function blueline_record_acknowledgement(
	array $acknowledgements,
	string $scope,
	string $rule_id,
	float $ratio,
	string $inputs_hash,
	int $user_id
): array {
	$acknowledgements[ $scope ] = array(
		'rule_id'     => $rule_id,
		'ratio'       => $ratio,
		'user_id'     => $user_id,
		'date'        => time(),
		'inputs_hash' => $inputs_hash,
		'scope'       => $scope,
	);

	return $acknowledgements;
}

/**
 * Remove the acknowledgement for one scope, if any.
 *
 * Pure. Intended for a Phase 2.1 resolver decision (an admin
 * re-acknowledges, or a scope's underlying value changes to something
 * that passes outright, leaving nothing to acknowledge) -- this function
 * only performs the removal once asked; it does not decide when to.
 *
 * @param array<string, array<string, mixed>> $acknowledgements Current map.
 * @param string                              $scope            Scope to remove.
 * @return array<string, array<string, mixed>> The updated map.
 */
function blueline_invalidate_acknowledgement( array $acknowledgements, string $scope ): array {
	unset( $acknowledgements[ $scope ] );

	return $acknowledgements;
}

/**
 * Whether a live acknowledgement exists for $scope that covers the exact
 * pairing being checked right now.
 *
 * "Covers" means all three: the stored entry's `rule_id` matches, its
 * `ratio` matches the freshly-computed ratio being checked (within a
 * small floating-point tolerance -- the two are independently computed
 * floats, never assumed bit-identical), and its `inputs_hash` still
 * matches $current_inputs_hash. Any mismatch -- no entry for this scope,
 * a different rule, a changed ratio (the admin edited the value since
 * acknowledging), or a stale inputs hash (style.css or
 * contrast-rules.json changed since) -- answers false: per
 * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.5,
 * that is "unacknowledged", not an error.
 *
 * Deliberately generic: it takes the rule id and ratio to check against
 * as plain parameters rather than reading anything about what an
 * Occasion is, so it is fully testable today against synthetic
 * acknowledgement data. Phase 2.1's resolver calls it once a real
 * Occasion model exists to supply those parameters from.
 *
 * @param array<string, array<string, mixed>> $acknowledgements    Current map.
 * @param string                              $scope               Scope to check.
 * @param string                              $rule_id             The contrast-rules.json rule id currently failing.
 * @param float                               $ratio               The freshly-computed contrast ratio currently failing.
 * @param string                              $current_inputs_hash blueline_settings_inputs_hash()'s current value.
 * @return bool
 */
function blueline_acknowledgement_covers(
	array $acknowledgements,
	string $scope,
	string $rule_id,
	float $ratio,
	string $current_inputs_hash
): bool {
	if ( ! isset( $acknowledgements[ $scope ] ) || ! is_array( $acknowledgements[ $scope ] ) ) {
		return false;
	}

	$entry = $acknowledgements[ $scope ];

	if ( ( $entry['rule_id'] ?? null ) !== $rule_id ) {
		return false;
	}

	if ( ( $entry['inputs_hash'] ?? null ) !== $current_inputs_hash ) {
		return false;
	}

	$stored_ratio = isset( $entry['ratio'] ) ? (float) $entry['ratio'] : null;

	if ( null === $stored_ratio || abs( $stored_ratio - $ratio ) > 0.0001 ) {
		return false;
	}

	return true;
}
