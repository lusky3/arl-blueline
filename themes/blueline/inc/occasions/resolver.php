<?php
/**
 * Occasions: the resolver. Site-timezone window matching, the precedence
 * comparator, and blueline_resolve_active_occasion() with its AA gate.
 *
 * Loaded by inc/occasions.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Today's calendar date, in SITE timezone (not UTC), as 'MM-DD', per
 * design spec §5/§7.5: "Dates compare in site timezone ... not UTC."
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function blueline_occasion_today_md( int $timestamp ): string {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( 'm-d' );
}

/**
 * Whether $today_md falls within the inclusive, annually-recurring
 * window [$start_md, $end_md].
 *
 * Handles a window that crosses the year boundary (start_md > end_md,
 * e.g. New Year's own 12-27..01-02, which blueline_occasion_presets()'s
 * `new-year` entry forces to be real rather than hypothetical) by
 * treating it as "today >= start OR today <= end" instead of the normal
 * "start <= today <= end". String comparison is safe here because every
 * `MM-DD` value is exactly two zero-padded two-digit fields, which sort
 * identically to calendar order.
 *
 * @param string $start_md 'MM-DD'.
 * @param string $end_md   'MM-DD'.
 * @param string $today_md 'MM-DD'.
 * @return bool
 */
function blueline_occasion_window_contains( string $start_md, string $end_md, string $today_md ): bool {
	if ( $start_md <= $end_md ) {
		return $today_md >= $start_md && $today_md <= $end_md;
	}

	return $today_md >= $start_md || $today_md <= $end_md;
}

/**
 * Precedence comparator for usort(): `force_on` beats `auto`;
 * `commemorative` beats `decorative`; then earliest `start_md`; then
 * `id` ascending, per design spec §5/§7.5, in that exact order, so the
 * winner among several eligible candidates is always deterministic.
 *
 * @param array<string, mixed> $a One Occasion.
 * @param array<string, mixed> $b Another Occasion.
 * @return int
 */
function blueline_occasion_compare( array $a, array $b ): int {
	$mode_rank = static function ( array $occasion ): int {
		return 'force_on' === ( $occasion['mode'] ?? '' ) ? 0 : 1;
	};

	if ( $mode_rank( $a ) !== $mode_rank( $b ) ) {
		return $mode_rank( $a ) <=> $mode_rank( $b );
	}

	$type_rank = static function ( array $occasion ): int {
		return 'commemorative' === ( $occasion['type'] ?? '' ) ? 0 : 1;
	};

	if ( $type_rank( $a ) !== $type_rank( $b ) ) {
		return $type_rank( $a ) <=> $type_rank( $b );
	}

	$start_a = $a['window']['start_md'] ?? '';
	$start_b = $b['window']['start_md'] ?? '';

	if ( $start_a !== $start_b ) {
		return $start_a <=> $start_b;
	}

	return ( $a['id'] ?? '' ) <=> ( $b['id'] ?? '' );
}

/**
 * Every eligible Occasion candidate for $today_md, normalized and sorted by
 * precedence. This is the first half of blueline_resolve_active_occasion()'s own
 * job, split out on its own so that function's second half (the AA-override
 * walk that picks the actual winner) reads as a short, separate step. Matches
 * the existing decomposition style of blueline_occasion_window_contains()/
 * blueline_occasion_compare(), this file's other two helpers already carved
 * out of the resolver.
 *
 * Reads only the `$occasions` array passed in, never
 * blueline_occasion_presets(), which is a read-only catalog with no bearing
 * on what is actually live (design spec §5's second ruling; see
 * tests/OccasionsResolverTest.php's own source-scan test for the
 * enforcement).
 *
 * Eligibility: `force_off` is never eligible, regardless of window.
 * `force_on` is always eligible, regardless of window. `auto` (or an
 * unset mode) is eligible only while $today_md currently falls inside its
 * window, and only if both stored bounds are well-formed `MM-DD` values
 * in the first place (see the inline note on that check: a malformed bound
 * would otherwise read as "always active").
 *
 * @param array<int, array<string, mixed>> $occasions Raw stored occasions
 *                                                      (blueline_settings( 'occasions' )'s value, or an equivalent test fixture).
 * @param string                           $today_md  Today's date in SITE
 *                                                     timezone, 'MM-DD' (blueline_occasion_today_md()'s return shape).
 * @return array<int, array<string, mixed>> Eligible candidates, normalized
 *                                            (every entry carries a well-formed `window` array regardless of mode)
 *                                            and ordered by blueline_occasion_compare(). Possibly empty.
 */
function blueline_occasion_eligible_candidates( array $occasions, string $today_md ): array {
	$candidates = array();

	foreach ( $occasions as $occasion ) {
		if ( ! is_array( $occasion ) ) {
			continue;
		}

		$mode = $occasion['mode'] ?? 'auto';

		if ( 'force_off' === $mode ) {
			continue;
		}

		// Normalize the stored `window` shape for EVERY mode, including
		// `force_on`: `occasions` is a reserved settings key, not a schema
		// field, so blueline_settings_repair()'s schema-field walk never
		// revalidates it, and an out-of-band write (a hand-edited row, a
		// restored dump, a migration script) is the only thing that can put
		// a malformed `window` here. `force_on` skips the date check below,
		// but the winning candidate's `window` still flows into
		// blueline_occasion_compare()'s direct array reads, so a scalar
		// (rather than array) `window` must never survive past this point
		// for ANY mode, not only the ones that gate on it.
		$window             = $occasion['window'] ?? array();
		$window             = is_array( $window ) ? $window : array();
		$start              = $window['start_md'] ?? '';
		$end                = $window['end_md'] ?? '';
		$start              = is_string( $start ) ? $start : '';
		$end                = is_string( $end ) ? $end : '';
		$occasion['window'] = array(
			'start_md' => $start,
			'end_md'   => $end,
		);

		if ( 'force_on' !== $mode ) {
			// Validate both bounds before they reach the window comparison,
			// exactly as blueline_occasion_next_boundary_timestamp() below
			// does with the same stored data. Unvalidated, an empty
			// `start_md` makes blueline_occasion_window_contains() take its
			// non-wrapping branch and return true for EVERY possible
			// $today_md, permanently activating the occasion site-wide.
			// Same reasoning as the blueline_sanitize_hex_color() pass over
			// `accent` further down: validate defensively on read, skip
			// rather than fatal.
			if ( ! blueline_occasion_valid_md( $start ) || ! blueline_occasion_valid_md( $end ) ) {
				continue;
			}

			if ( ! blueline_occasion_window_contains( $start, $end, $today_md ) ) {
				continue;
			}
		}

		$candidates[] = $occasion;
	}

	usort( $candidates, 'blueline_occasion_compare' );

	return $candidates;
}

/**
 * The one active occasion right now, or null.
 *
 * Candidate discovery (window matching, mode eligibility, and precedence
 * ordering) is blueline_occasion_eligible_candidates()'s own job; this
 * function calls that helper and then walks its result applying the
 * AA-override gate (design spec §4.5) to pick the actual winner: resolve
 * each candidate's effective accent in turn (its own `accent`, or
 * blueline_occasion_accent_default() when empty, since that default is computed
 * at most once per call, on first need, and reused for every later candidate
 * with an empty `accent`), run it through blueline_sanitize_hex_color()
 * (blueline_settings() does not sanitize on read, per inc/settings/store.php's
 * own docblock, so a stored `accent` can be malformed even though
 * blueline_sanitize_occasions() rejects one on write), and, if that yields a
 * real hex colour, compute its real contrast ratio against
 * BLUELINE_TOKEN_INK; then, only if it fails
 * blueline_contrast_threshold( 'body' ), require a live, hash-matching
 * acknowledgement scoped to `occasion:{id}`
 * (blueline_acknowledgement_covers()) before accepting it. A candidate
 * whose effective accent cannot be resolved to a real hex colour at all,
 * or that fails the contrast gate unacknowledged, is skipped entirely,
 * falling through to the next-best candidate; the first candidate that
 * either passes contrast outright or is validly acknowledged wins. null
 * if none does (including when nothing is stored, or nothing is eligible,
 * at all).
 *
 * @param int|null $now_override Unix timestamp to evaluate against;
 *                                defaults to the current time. Tests pass
 *                                this so an assertion about a window
 *                                keeps meaning the same thing later.
 * @return array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string, resolved_accent:string}|null
 */
function blueline_resolve_active_occasion( ?int $now_override = null ): ?array {
	static $memo = array();

	$now      = $now_override ?? time();
	$today_md = blueline_occasion_today_md( $now );

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();

	$candidates = blueline_occasion_eligible_candidates( $occasions, $today_md );

	if ( array() === $candidates ) {
		return null;
	}

	// Memoised per request on every input the walk below reads, so the 5+ callers share one resolve.
	$memo_key = md5( (string) wp_json_encode( array( $today_md, $candidates, blueline_settings(), blueline_stored_acknowledgements() ) ) );

	if ( array_key_exists( $memo_key, $memo ) ) {
		return $memo[ $memo_key ];
	}

	$memo[ $memo_key ] = blueline_resolve_active_occasion_uncached( $candidates );

	return $memo[ $memo_key ];
}

/**
 * The AA-gated walk over blueline_resolve_active_occasion()'s eligible
 * candidates (that function's docblock describes it).
 *
 * @param array $candidates Eligible candidates, in precedence order.
 * @return array|null
 */
function blueline_resolve_active_occasion_uncached( array $candidates ): ?array {

	$inputs_hash      = blueline_settings_inputs_hash();
	$acknowledgements = blueline_stored_acknowledgements();
	$default_accent   = null;

	foreach ( $candidates as $candidate ) {
		$themed_default = blueline_occasion_themed_accent_default( (string) ( $candidate['id'] ?? '' ) );

		$raw_accent = '' !== ( $candidate['accent'] ?? '' )
			? $candidate['accent']
			: ( '' !== $themed_default
				? $themed_default
				// Computed at most once per request, on first actual need:
				// its own result (a style.css read) cannot change
				// mid-request, and most requests never reach a candidate
				// with an empty accent AND no themed default at all.
				: ( $default_accent ??= blueline_occasion_accent_default() ) );

		if ( '' === $raw_accent ) {
			// The default itself could not be resolved (blueline_occasion_accent_default()
			// already logs why); nothing to
			// apply for this candidate. Skip, never fatal.
			continue;
		}

		// blueline_settings() does not sanitize on read (inc/settings/store.php's
		// own docblock): a stored `accent` can be malformed (a hand-edited
		// row, a migration script) even though blueline_sanitize_occasions()
		// rejects one on write. Run it through the same sanitizer used
		// there before it ever reaches contrast math; never trust a raw
		// stored string as a real hex colour.
		$accent = blueline_sanitize_hex_color( $raw_accent );

		if ( '' === $accent ) {
			// Not a real hex colour: unresolvable, same as an unresolved
			// default above. Skip, never fatal.
			continue;
		}

		$ratio  = blueline_contrast_ratio( blueline_resolved_brand_color( 'ink' ), $accent );
		$passes = $ratio >= blueline_contrast_threshold( 'body' );

		if ( ! $passes ) {
			$covers = blueline_acknowledgement_covers(
				$acknowledgements,
				'occasion:' . $candidate['id'],
				'ink-on-occasion-accent',
				$accent,
				$inputs_hash
			);

			if ( ! $covers ) {
				continue; // Unacknowledged failure: fail closed, try the next candidate.
			}
		}

		$candidate['resolved_accent'] = $accent;

		return $candidate;
	}

	return null;
}
