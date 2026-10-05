<?php
/**
 * Name matching for the player-link module: normalising, token-set scoring and the
 * security gate that decides whether a score may be offered as a candidate identity.
 *
 * Pure: no WordPress calls, so the whole file can be unit tested directly.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lowercase, de-accent, strip punctuation and collapse whitespace.
 *
 * @param string $name Raw name.
 * @return string Normalised name; '' when nothing usable remains.
 */
function blueline_normalize_name( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '';
	}
	$translit = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv raises a notice on input it cannot convert; the false check below handles that.
	if ( false !== $translit ) {
		$name = $translit;
	}
	$name = strtolower( $name );
	$name = str_replace( array( "'", '’' ), '', $name ); // O'Connor becomes oconnor.
	$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
	return trim( preg_replace( '/\s+/', ' ', $name ) );
}

/**
 * Token-set similarity, 0.0 to 1.0. Order-independent ("Lusk Cody" matches "Cody Lusk") and
 * scored against the SMALLER token set, so extra middle names do not punish a real match.
 *
 * @param string $a One name.
 * @param string $b The other name.
 * @return float Similarity.
 */
function blueline_name_match_score( string $a, string $b ): float {
	$ta = array_filter( explode( ' ', blueline_normalize_name( $a ) ) );
	$tb = array_filter( explode( ' ', blueline_normalize_name( $b ) ) );
	if ( empty( $ta ) || empty( $tb ) ) {
		return 0.0;
	}
	$sa     = array_unique( $ta );
	$sb     = array_unique( $tb );
	$common = array_intersect( $sa, $sb );
	$score  = count( $common ) / min( count( $sa ), count( $sb ) );
	return round( (float) $score, 4 );
}

/**
 * The fewest DISTINCT normalised tokens the SHORTER of two names may carry for a score
 * between them to identify a person. One token is a fragment, not a name.
 */
const BLUELINE_MATCH_MIN_TOKENS = 2;

/**
 * The most tokens either name may carry that the other lacks: one real middle name or initial.
 */
const BLUELINE_MATCH_MAX_EXTRA_TOKENS = 1;

/**
 * The distinct normalised tokens of $name: the set blueline_name_match_score() scores against,
 * exposed so the gate below can reason about its size.
 *
 * @param string $name Raw name.
 * @return string[] Distinct tokens in first-seen order; empty for an empty or unnameable string.
 */
function blueline_name_tokens( string $name ): array {
	return array_values( array_unique( array_filter( explode( ' ', blueline_normalize_name( $name ) ) ) ) );
}

/**
 * SECURITY GATE: may a score between these two names be offered as a candidate identity at all?
 *
 * This REDUCES ACCIDENTAL AND CASUAL MISMATCHES; IT DOES NOT AUTHENTICATE IDENTITY. The
 * account-side name is typed by the account holder, so a name match never proves who someone
 * is. A name claim is therefore view-only (no photo, no personal details), and the league can
 * undo a wrong one with `wp blueline-core ownership unlink`.
 *
 * blueline_name_match_score() divides by the SMALLER token set, so any name that is a strict
 * subset of the other scores exactly 1.0 ("Smith" vs "John Smith"). That formula is
 * deliberately unchanged, because it is what lets "Cody James Lusk" match "Cody Lusk". The
 * hazard is which pairs may reach it: the account side comes from billing_first_name and
 * billing_last_name, which the account holder edits at /account/edit-address/. Two attacks
 * follow, and this gate closes both:
 *
 *   1. A single common token ("Matthew", or "Smith" with the surname blanked) would be offered
 *      EVERY current-season player sharing it at 1.0. So the shorter side must carry at least
 *      BLUELINE_MATCH_MIN_TOKENS distinct tokens.
 *   2. A name PADDED with common tokens ("John Mike Dave Smith Brown Jones") is a superset of
 *      "John Smith", "Mike Brown" and "Dave Jones" and scores 1.0 against all of them. So each
 *      side may carry at most BLUELINE_MATCH_MAX_EXTRA_TOKENS tokens the other lacks.
 *      "Cody James Lusk" vs "Cody Lusk" (one middle name) and "Lusk Cody" vs "Cody Lusk"
 *      (reordered) still pass.
 *
 * Residual risk: a name with one extra token still matches both of its two-token sub-names
 * ("John Michael Smith" reaches "John Smith" and "Michael Smith"). The bounded overlap is not
 * a bypass of the claim rules, but the consequence is identity squatting: the claimant sees a
 * stranger's team, roster, jersey number and stats, and the real player is locked out with
 * `already_linked` until the league unlinks it.
 *
 * The gate sits at the candidate boundary (blueline_score_player_candidates()) that every
 * claim path crosses, the sp_user backfill script included, not in the matcher.
 *
 * @param string $a One name.
 * @param string $b The other name.
 * @return bool True if the pair is specific enough to be scored for identity.
 */
function blueline_name_pair_is_specific_enough( string $a, string $b ): bool {
	$ta = blueline_name_tokens( $a );
	$tb = blueline_name_tokens( $b );

	if ( empty( $ta ) || empty( $tb ) ) {
		return false;
	}

	if ( min( count( $ta ), count( $tb ) ) < BLUELINE_MATCH_MIN_TOKENS ) {
		return false;
	}

	$common = count( array_intersect( $ta, $tb ) );

	return max( count( $ta ) - $common, count( $tb ) - $common ) <= BLUELINE_MATCH_MAX_EXTRA_TOKENS;
}
