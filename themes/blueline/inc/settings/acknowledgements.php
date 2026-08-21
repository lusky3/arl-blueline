<?php
/**
 * `aa_acknowledgements` storage.
 *
 * Real storage for what, before this file, was only an import-time
 * discard target (inc/settings/import.php unsets it unconditionally on
 * every import -- that does not change here; consent is not something a
 * file can assert on an admin's behalf). A map of acknowledgement id (a
 * `scope` string, e.g. `occasion:canada-day`) => {rule_id, value, ratio,
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
 * This file's pure record/covers/remove functions --
 * blueline_record_acknowledgement(), blueline_acknowledgement_covers(),
 * blueline_remove_acknowledgement() -- are what Phase 2.1's resolver will
 * call once a real Occasion model exists to supply their parameters from.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate and repair a stored `aa_acknowledgements` value.
 *
 * Called from inc/settings/page.php's blueline_settings_sanitize_callback()
 * reserved-key branch, the same choke point `_schema` already goes
 * through -- so this runs on every write that actually reaches
 * update_option() for this option (WP-CLI, a direct update_option() call),
 * not only ones that pass through wp-admin.
 *
 * This is NOT a write path this file's own record/remove functions go
 * through themselves: blueline_record_acknowledgement() and
 * blueline_remove_acknowledgement() are both pure -- they return a new map
 * for a caller to write, and never call update_option() on their own. A
 * caller that takes one of their return values and passes it to
 * update_option() reaches this validator via THAT write; calling the pure
 * functions themselves does not touch this validator at all.
 *
 * Never fatal: a non-array input, or any entry missing a required key,
 * carrying the wrong type for one, or whose own `scope` field disagrees
 * with its map key, is dropped rather than allowed to corrupt the option
 * or crash a later reader -- the same defensive posture
 * blueline_load_contrast_thresholds() takes for a malformed
 * contrast-rules.json.
 *
 * @param mixed $value Raw value to validate.
 * @return array<string, array{rule_id:string, value:string, ratio:float, user_id:int, date:int, inputs_hash:string, scope:string}>
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

		if ( ! isset( $entry['rule_id'], $entry['value'], $entry['ratio'], $entry['user_id'], $entry['date'], $entry['inputs_hash'], $entry['scope'] ) ) {
			continue;
		}

		if ( ! is_string( $entry['rule_id'] ) || '' === $entry['rule_id']
			|| ! is_string( $entry['value'] ) || '' === $entry['value']
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
			'value'       => $entry['value'],
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
 * @param string                              $value            The exact value being acknowledged, e.g. a hex colour like `#8b0000`.
 * @param float                               $ratio            The computed contrast ratio that failed it.
 * @param string                              $inputs_hash      blueline_settings_inputs_hash()'s value at the moment of acknowledgement.
 * @param int                                 $user_id          The acknowledging user's ID.
 * @return array<string, array<string, mixed>> The updated map.
 */
function blueline_record_acknowledgement(
	array $acknowledgements,
	string $scope,
	string $rule_id,
	string $value,
	float $ratio,
	string $inputs_hash,
	int $user_id
): array {
	$acknowledgements[ $scope ] = array(
		'rule_id'     => $rule_id,
		'value'       => $value,
		'ratio'       => $ratio,
		'user_id'     => $user_id,
		'date'        => time(),
		'inputs_hash' => $inputs_hash,
		'scope'       => $scope,
	);

	return $acknowledgements;
}

/**
 * Remove the acknowledgement for one scope, if any. A HARD removal, via
 * unset() -- not a soft invalidation, and not what a stale `inputs_hash`
 * calls for.
 *
 * A stale `inputs_hash` (style.css or contrast-rules.json changed since
 * the acknowledgement) is NOT a valid reason to call this function: per
 * the design spec's §4.5, a stale-but-still-stored acknowledgement is
 * intentionally RETAINED, not deleted -- it stays visible in Site Health
 * (§6.9's "every live AA acknowledgement") until an admin re-acknowledges
 * or the scope's underlying value changes to something that passes
 * outright. A stale hash simply fails blueline_acknowledgement_covers()'s
 * check on its own; nothing needs to be removed for that to work.
 *
 * The legitimate triggers for an actual removal are: an explicit
 * re-acknowledgement of the same scope -- which does not call this
 * function at all, since blueline_record_acknowledgement() already
 * replaces the existing entry in place -- or the case this function
 * exists for, a scope whose underlying value changed to something that
 * now passes the contrast gate outright, leaving nothing that still needs
 * acknowledging.
 *
 * Pure: this function only performs the removal once asked; it does not
 * decide when to.
 *
 * @param array<string, array<string, mixed>> $acknowledgements Current map.
 * @param string                              $scope            Scope to remove.
 * @return array<string, array<string, mixed>> The updated map.
 */
function blueline_remove_acknowledgement( array $acknowledgements, string $scope ): array {
	unset( $acknowledgements[ $scope ] );

	return $acknowledgements;
}

/**
 * Whether a live acknowledgement exists for $scope that covers the exact
 * value being checked right now.
 *
 * "Covers" means all three: the stored entry's `rule_id` matches, its
 * `inputs_hash` still matches $current_inputs_hash, and its `value`
 * matches $value EXACTLY (strict string equality -- deliberately no
 * tolerance, unlike a freshly-computed float). Contrast ratio plays no
 * part in this decision, and is not even a parameter here: it is a
 * many-to-one projection of colour space, so two different failing hex
 * colours can share the same ratio to several decimal places, and
 * matching on ratio alone would let an admin's acknowledgement of one
 * colour silently cover a completely different one -- a fail-open gap in
 * an accessibility control. Once `value`, `rule_id` and `inputs_hash` all
 * match, `ratio` is a deterministic function of those and needs no
 * independent check; a caller that wants it for logging can read it
 * straight off the stored entry.
 *
 * Any mismatch -- no entry for this scope, a different rule, a different
 * value (the admin changed it since acknowledging), or a stale inputs
 * hash (style.css or contrast-rules.json changed since) -- answers false:
 * per docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md
 * §4.5, that is "unacknowledged", not an error.
 *
 * Deliberately generic: it takes the rule id and value to check against
 * as plain parameters rather than reading anything about what an
 * Occasion is, so it is fully testable today against synthetic
 * acknowledgement data. Phase 2.1's resolver calls it once a real
 * Occasion model exists to supply those parameters from.
 *
 * @param array<string, array<string, mixed>> $acknowledgements    Current map.
 * @param string                              $scope               Scope to check.
 * @param string                              $rule_id             The contrast-rules.json rule id currently failing.
 * @param string                              $value               The exact value currently failing, e.g. a hex colour like `#8b0000`.
 * @param string                              $current_inputs_hash blueline_settings_inputs_hash()'s current value.
 * @return bool
 */
function blueline_acknowledgement_covers(
	array $acknowledgements,
	string $scope,
	string $rule_id,
	string $value,
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

	if ( ( $entry['value'] ?? null ) !== $value ) {
		return false;
	}

	return true;
}

/**
 * The `aa_acknowledgements` map as currently stored.
 *
 * Reads get_option() directly rather than blueline_settings(
 * 'aa_acknowledgements' ): blueline_settings()'s returned key set is
 * exactly blueline_settings_defaults()'s key set (see that function's own
 * docblock), and `aa_acknowledgements` is deliberately excluded from it --
 * so that accessor can never return it, by design.
 *
 * @return array<string, array<string, mixed>>
 */
function blueline_stored_acknowledgements(): array {
	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	return is_array( $stored['aa_acknowledgements'] ?? null ) ? $stored['aa_acknowledgements'] : array();
}
