<?php
/**
 * Commerce term resolution: turns the Commerce tab's `registration_term`
 * schema field into the product_cat term ID every registration call site
 * should actually use.
 *
 * The trap this file exists to close mirrors inc/settings/links.php's own,
 * one level up the tree: BLUELINE_REGISTRATION_TERM_ID (inc/season-state.php)
 * has always been a hardcoded, UNVERIFIED term ID (documented there as
 * "plausible but unconfirmed" -- an inventory pass tried to confirm 91 by
 * numeric ID and failed; only a slug-based lookup for "registration" found
 * the real, live category). Every call site that read the constant directly
 * therefore trusted a number nobody had actually verified against the
 * database, with no fallback if that number stopped resolving to a real
 * term -- e.g. because a volunteer deleted and recreated the "Registration"
 * category in WooCommerce, which assigns the recreated term a NEW term_id,
 * silently orphaning the old one. The traced failure mode:
 * get_terms( array( 'parent' => <dead ID> ) ) returns empty ->
 * has_purchasable_product stays false ->
 * blueline_decide_registration_open() returns false ->
 * blueline_decide_season_state() falls through to the SportsPress signals
 * -> the homepage renders a valid-looking wrong hero and silently stops
 * selling registrations. No error, no fallback message.
 *
 * The fix, same shape as blueline_resolve_link(): never trust a stored or
 * hardcoded ID at face value. Verify it via get_term() -- which is faithful
 * to core's own contract that a nonexistent term is `null`, never a truthy
 * stand-in -- before using it as a real taxonomy filter, and fall back
 * (verifying the fallback too, for the identical reason) rather than assume
 * either the admin-configured value or the documented constant is still
 * valid.
 *
 * Crucially, blueline_resolve_registration_term() returns `0` -- never the
 * unverified ID itself -- when NEITHER the configured value nor the fallback
 * resolves to a real product_cat term. Every call site MUST treat a `0`
 * return as "no registration term configured, do not query" and skip the
 * lookup entirely, rather than passing `0` on into get_terms()'s `parent`
 * argument: core treats `parent => 0` as a real, meaningful filter ("top
 * -level terms only"), so passing it through unchecked would silently widen
 * a "nothing to find" outcome into "every root-level product category",
 * attributing arbitrary unrelated products to the registration season. `0`
 * is Blueline's own "give up cleanly" sentinel here, not a taxonomy query
 * value.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a specific term ID actually exists in a specific taxonomy right
 * now -- the one check every branch of blueline_resolve_registration_term()
 * relies on, so both the configured value and the fallback get the exact
 * same scrutiny.
 *
 * Core's get_term() is faithful to its own contract here: `null` for a term
 * ID that simply doesn't exist, a WP_Error for one that exists in a
 * DIFFERENT taxonomy than requested -- never a truthy object for either
 * case. Both must be treated as "does not exist" by this guard.
 *
 * @param int    $term_id  Candidate term ID.
 * @param string $taxonomy Taxonomy the term must belong to.
 * @return bool
 */
function blueline_term_id_exists( int $term_id, string $taxonomy ): bool {
	if ( $term_id <= 0 || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
		return false;
	}

	$term = get_term( $term_id, $taxonomy );

	return $term && ! is_wp_error( $term );
}

/**
 * Resolve the `registration_term` schema field to a real, verified
 * product_cat term ID -- the configured value if (and only if) it actually
 * exists in that taxonomy, otherwise the schema's own `fallback` (only if
 * IT also actually exists), otherwise `0`.
 *
 * See this file's docblock for why `0` -- not the unverified ID -- is the
 * "give up" value, and why every call site must special-case it rather than
 * forward it into a `parent` taxonomy query.
 *
 * @return int A verified product_cat term ID, or 0 if none is available.
 */
function blueline_resolve_registration_term(): int {
	$schema   = blueline_settings_schema();
	$field    = $schema['registration_term'] ?? array();
	$taxonomy = (string) ( $field['taxonomy'] ?? 'product_cat' );
	$fallback = (int) ( $field['fallback'] ?? 0 );
	$id       = (int) blueline_settings( 'registration_term' );

	if ( $id > 0 && blueline_term_id_exists( $id, $taxonomy ) ) {
		return $id;
	}

	// Configured value absent, or configured but no longer real -- fall
	// back, but do not blindly trust the fallback either: an inventory pass
	// could not confirm 91 by numeric ID in the first place, so it gets the
	// identical existence check the configured value just failed.
	if ( $fallback > 0 && blueline_term_id_exists( $fallback, $taxonomy ) ) {
		return $fallback;
	}

	// Neither the configured value nor the documented fallback resolves to
	// a real term. Degrade safely: 0 signals "nothing to query" to every
	// call site (see this file's docblock), never a dead ID a caller might
	// forward into a taxonomy filter unchecked.
	return 0;
}
