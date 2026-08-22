<?php
/**
 * Occasions: the seasonal and commemorative theming layer, server side.
 *
 * Everything the feature owns outside the stylesheet lives in this one
 * file:
 *
 *   - The Occasion model. Its three closed enumerations (types, motifs,
 *     activation modes), the annually-recurring `MM-DD` shape check, and
 *     blueline_sanitize_occasions() -- the validator inc/settings/page.php
 *     runs the reserved `occasions` key through on every write that
 *     reaches update_option().
 *   - blueline_occasion_presets(): the four shipped templates, as a
 *     READ-ONLY catalog for a future "add from preset" affordance. Never
 *     pre-populated into the stored value, and never read by the resolver.
 *   - The resolution engine. Window matching in SITE timezone, the
 *     precedence comparator, and the AA-override gate, which fails closed
 *     on an accent that fails contrast without a live acknowledgement.
 *   - blueline_occasion_accent_default(), which resolves
 *     --bl-occasion-accent's own declared default (`var(--bl-ice)`) to a
 *     literal hex value by reading style.css's :root block. Deliberately
 *     NOT a general :root parser -- exactly one var() hop for exactly one
 *     token. See
 *     docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md
 *     §4.1: a recursive :root parser was descoped as premature generality
 *     for a need that does not exist yet.
 *   - The shipped motif SVG set, and the clamp that drops the announcement
 *     banner from `urgent` to `info` while a commemorative occasion is
 *     active.
 *   - The single WP-Cron event that purges the page cache at the next
 *     window boundary. Purge TIMING only, never the correctness mechanism:
 *     the resolver recomputes from scratch on every request regardless.
 *   - The block-editor filter that carries the resolved accent into the
 *     editor canvas, so the editor and the front end agree.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log, at most once per call site, that style.css's :root block could not
 * be read to resolve --bl-occasion-accent's default. Mirrors
 * inc/team-colors.php's blueline_contrast_rules_read_failure(): never a
 * fatal, never a _doing_it_wrong() notice a visitor could see, just a
 * server-log trace of a silently degraded fallback.
 *
 * @param string $path   The stylesheet path that could not be used --
 *                        passed through by the caller, which accepts a
 *                        $path_override, so the log names whichever file
 *                        was actually being read rather than always
 *                        "style.css".
 * @param string $reason Human-readable reason, for the log line.
 * @return void
 */
function blueline_occasion_accent_default_read_failure( string $path, string $reason ): void {
	static $logged = false;

	if ( $logged ) {
		return;
	}
	$logged = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: mirrors inc/team-colors.php's blueline_contrast_rules_read_failure() -- see that function's own docblock for why a silent fallback should still leave a server-log trace.
	error_log(
		sprintf( "Blueline: could not resolve --bl-occasion-accent's default from %s (%s).", $path, $reason )
	);
}

/**
 * Resolve --bl-occasion-accent's own declared default to a literal hex
 * value, by reading style.css's :root block directly.
 *
 * Style.css declares `--bl-occasion-accent: var(--bl-ice);` -- one var()
 * hop to a token that is itself a plain hex literal. This follows exactly
 * that one hop and no more: it does not resolve clamp(), rgba(), or a
 * chain of more than one var(). Never fatal: a missing file, a missing
 * :root rule, a --bl-occasion-accent declaration that is not a single
 * var(--bl-*) reference, or a referenced token that is not itself a plain
 * 6-digit hex literal all log (via
 * blueline_occasion_accent_default_read_failure()) and return ''.
 *
 * @param string|null $path_override Explicit path to a stylesheet, for
 *                                    tests. Defaults to the real
 *                                    style.css next to this theme.
 * @return string Lowercase `#rrggbb`, or '' if it could not be resolved.
 */
function blueline_occasion_accent_default( ?string $path_override = null ): string {
	$path = $path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__ ) ) . '/style.css' );

	if ( ! is_readable( $path ) ) {
		blueline_occasion_accent_default_read_failure( $path, 'stylesheet is missing or unreadable' );
		return '';
	}

	$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.

	// Strip comments first so a mention inside one cannot be mistaken for
	// a real declaration.
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

	if ( ! preg_match( '/:root\s*\{(.*?)\}/s', $css, $root_match ) ) {
		blueline_occasion_accent_default_read_failure( $path, 'no :root rule found' );
		return '';
	}

	$root_block = $root_match[1];

	if ( ! preg_match( '/--bl-occasion-accent\s*:\s*var\(\s*(--[a-z0-9-]+)\s*\)\s*;/i', $root_block, $ref_match ) ) {
		blueline_occasion_accent_default_read_failure( $path, '--bl-occasion-accent is not declared as a single var(--bl-*) reference' );
		return '';
	}

	$referenced = $ref_match[1];

	if ( ! preg_match( '/' . preg_quote( $referenced, '/' ) . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $root_block, $hex_match ) ) {
		blueline_occasion_accent_default_read_failure( $path, "referenced token {$referenced} is not declared as a plain hex literal" );
		return '';
	}

	return strtolower( $hex_match[1] );
}

/**
 * The two occasion types the model recognises (design spec §5/§7.1).
 * `commemorative` is validated separately by its own rules elsewhere
 * (Task 5's announcement-severity suppression, and the panel-side "only
 * the poppy motif" restriction 2.1b will add) -- this enumeration is only
 * the shape check.
 *
 * @return string[]
 */
function blueline_occasion_types(): array {
	return array( 'decorative', 'commemorative' );
}

/**
 * The shipped, enumerated motif set (design spec §5/§7.7) -- never
 * uploadable, so this list is exhaustive and closed.
 *
 * @return string[]
 */
function blueline_occasion_motifs(): array {
	return array( 'none', 'maple-leaf', 'poppy', 'snowflake', 'sparkle' );
}

/**
 * The three activation modes (design spec §5/§7.5): `auto` (window-
 * driven), `force_on` (always eligible, regardless of window --
 * "preview it now"), `force_off` (never eligible, regardless of window --
 * "pull it now").
 *
 * @return string[]
 */
function blueline_occasion_modes(): array {
	return array( 'auto', 'force_on', 'force_off' );
}

/**
 * Whether $value is a well-formed, annually-recurring `MM-DD` calendar
 * date.
 *
 * Validated against 2024 (a leap year) deliberately: an occasion window
 * is a RECURRING annual date, not a single instant, so `02-29` must be
 * accepted as a real recurring day even though it does not exist every
 * year -- Task 3's resolver and Task 6's cron boundary calculation are
 * both what actually decide what happens to a `02-29` window in a
 * non-leap target year, not this shape check.
 *
 * @param mixed $value Candidate value.
 * @return bool
 */
function blueline_occasion_valid_md( $value ): bool {
	if ( ! is_string( $value ) || ! preg_match( '/^(\d{2})-(\d{2})$/', $value, $matches ) ) {
		return false;
	}

	return checkdate( (int) $matches[1], (int) $matches[2], 2024 );
}

/**
 * Validate and repair a stored `occasions` value.
 *
 * Called from inc/settings/page.php's blueline_settings_sanitize_callback()
 * reserved-key branch -- the same choke point `_schema`/`aa_acknowledgements`
 * already go through, so this runs on every write that actually reaches
 * update_option() for this option (WP-CLI, a direct update_option() call,
 * a future 2.1b panel save), not only ones that pass through wp-admin.
 *
 * A map keyed by occasion id (design spec §5/§7.1: "stored flat inside
 * blueline_settings['occasions'] -- a map keyed by `id`"). Never fatal
 * and, like blueline_sanitize_band_photos() and
 * blueline_sanitize_acknowledgements() before it, drops a malformed
 * entry rather than corrupting the whole map over one bad row: a
 * non-array input, a row that is not itself an array, a row missing any
 * required key, an `id` that disagrees with its own map key, an empty
 * label, an unrecognised `type`/`motif`/`mode`, a structurally invalid
 * (or calendar-invalid) window bound, a non-empty `accent` that is not a
 * real hex colour, or a `line` containing a literal `%` are all dropped
 * individually.
 *
 * @param mixed $value Raw value to validate.
 * @return array<string, array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string}>
 */
function blueline_sanitize_occasions( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$clean = array();

	foreach ( $value as $id => $entry ) {
		if ( ! is_string( $id ) || '' === $id || ! is_array( $entry ) ) {
			continue;
		}

		if ( ! isset( $entry['id'], $entry['label'], $entry['type'], $entry['window'], $entry['accent'], $entry['motif'], $entry['mode'] ) ) {
			continue;
		}

		if ( ! is_string( $entry['id'] ) || $entry['id'] !== $id ) {
			continue;
		}

		$label = is_string( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '';
		if ( '' === $label ) {
			continue;
		}

		if ( ! is_string( $entry['type'] ) || ! in_array( $entry['type'], blueline_occasion_types(), true ) ) {
			continue;
		}

		if ( ! is_array( $entry['window'] )
			|| ! isset( $entry['window']['start_md'], $entry['window']['end_md'] )
			|| ! blueline_occasion_valid_md( $entry['window']['start_md'] )
			|| ! blueline_occasion_valid_md( $entry['window']['end_md'] )
		) {
			continue;
		}

		if ( ! is_string( $entry['accent'] ) ) {
			continue;
		}

		$accent = '';
		if ( '' !== $entry['accent'] ) {
			$accent = blueline_sanitize_hex_color( $entry['accent'] );
			if ( '' === $accent ) {
				// Non-empty but not a real hex colour: reject the whole
				// entry rather than silently coercing it to the default,
				// matching blueline_sanitize_band_photos()'s "reject, don't
				// repair" posture for a value the admin actually typed.
				continue;
			}
		}

		if ( ! is_string( $entry['motif'] ) || ! in_array( $entry['motif'], blueline_occasion_motifs(), true ) ) {
			continue;
		}

		if ( ! is_string( $entry['mode'] ) || ! in_array( $entry['mode'], blueline_occasion_modes(), true ) ) {
			continue;
		}

		$line = '';
		if ( isset( $entry['line'] ) ) {
			if ( ! is_string( $entry['line'] ) ) {
				continue;
			}

			$line = sanitize_text_field( $entry['line'] );

			if ( false !== strpos( $line, '%' ) ) {
				// No placeholders permitted at all (design spec §5/§7.1):
				// this value is never passed through sprintf(), so there
				// is no legitimate conversion spec for it to carry, unlike
				// the panel's sprintf()-fed content fields
				// (inc/settings/sanitize.php).
				continue;
			}
		}

		$clean[ $id ] = array(
			'id'     => $id,
			'label'  => $label,
			'type'   => $entry['type'],
			'window' => array(
				'start_md' => $entry['window']['start_md'],
				'end_md'   => $entry['window']['end_md'],
			),
			'accent' => $accent,
			'motif'  => $entry['motif'],
			'line'   => $line,
			'mode'   => $entry['mode'],
		);
	}

	return $clean;
}

/**
 * The four shipped occasion templates -- design spec §5's second ruling:
 * a READ-ONLY catalog for a future admin UI's "add from preset"
 * affordance (2.1b), never pre-populated into the real stored
 * `occasions` array. blueline_resolve_active_occasion() (Task 3) never
 * calls this function; nothing here is "live" until something else (a
 * future panel save, or `wp blueline settings occasions` in Phase 2.2)
 * copies one of these into blueline_settings( 'occasions' )'s real
 * stored value.
 *
 * Every preset ships `mode => 'auto'`, deliberately: there is no
 * `enabled` field in the model at all (an `auto` entry sitting in the
 * REAL stored array during its calendar window would activate whether
 * or not an admin ever opened the panel -- the model has no separate
 * on/off switch, by design), so "shipped but inert" is achieved entirely
 * by these presets living here instead of in the real stored array, not
 * by any flag on the entries themselves.
 *
 * Every `accent` is '' (use the resolved default): 2.1a ships no
 * contrast-vetted festive palette, and an admin choosing one is exactly
 * 2.1b's job.
 *
 * @return array<string, array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string}>
 */
function blueline_occasion_presets(): array {
	return array(
		'canada-day'      => array(
			'id'     => 'canada-day',
			'label'  => 'Canada Day',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '07-01',
				'end_md'   => '07-01',
			),
			'accent' => '',
			'motif'  => 'maple-leaf',
			'line'   => '',
			'mode'   => 'auto',
		),
		'remembrance-day' => array(
			'id'     => 'remembrance-day',
			'label'  => 'Remembrance Day',
			'type'   => 'commemorative',
			'window' => array(
				'start_md' => '11-11',
				'end_md'   => '11-11',
			),
			'accent' => '',
			'motif'  => 'poppy',
			'line'   => 'Lest we forget.',
			'mode'   => 'auto',
		),
		'christmas'       => array(
			'id'     => 'christmas',
			'label'  => 'Christmas',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '12-01',
				'end_md'   => '12-26',
			),
			'accent' => '',
			'motif'  => 'snowflake',
			'line'   => '',
			'mode'   => 'auto',
		),
		// Crosses the year boundary (start_md > end_md) by design -- see
		// Task 3's blueline_occasion_window_contains() and Task 6's
		// blueline_occasion_next_occurrence_timestamp() for the two places
		// that must, and do, handle this correctly.
		'new-year'        => array(
			'id'     => 'new-year',
			'label'  => 'New Year',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '12-27',
				'end_md'   => '01-02',
			),
			'accent' => '',
			'motif'  => 'sparkle',
			'line'   => '',
			'mode'   => 'auto',
		),
	);
}

/**
 * Assign each submitted occasions row a server-derived, de-duplicated
 * `id` -- design spec §5.1's first ruling: the admin never types an id
 * directly.
 *
 * A row keeps its own existing id when its derived slug is unchanged
 * from `_original_id`. A row whose derived slug differs from
 * `_original_id` (a brand-new row, where `_original_id` is '', or an
 * existing row whose label edit changed the derived slug -- a rename)
 * is checked for a collision against both the rest of THIS batch and
 * the currently-stored array, EXCLUDING the row's own original slot,
 * and bumped with an incrementing numeric suffix on collision -- the
 * same shape wp_unique_post_slug() already uses for post slugs.
 *
 * Deliberately does not call blueline_sanitize_occasions() itself, and
 * does not validate anything beyond having a usable label: the caller
 * (inc/settings/page.php's sanitize-callback carve-out) runs the
 * result through that unchanged validator immediately afterwards. Any
 * OTHER key a row carries (e.g. a save-time override checkbox a later
 * task reads) is copied through untouched -- this function only ever
 * reads `_original_id`/`label` and writes `id`.
 *
 * @param mixed                               $submitted Raw submitted rows, keyed by an opaque per-request row identifier.
 * @param array<string, array<string, mixed>> $stored    Currently stored `occasions` map, read BEFORE this save.
 * @return array<string, array<string, mixed>> The same rows, re-keyed by their final, unique, derived id.
 */
function blueline_occasions_assign_unique_ids( $submitted, array $stored ): array {
	if ( ! is_array( $submitted ) ) {
		return array();
	}

	$taken  = array();
	$result = array();

	foreach ( $submitted as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$original_id = is_string( $row['_original_id'] ?? null ) ? $row['_original_id'] : '';
		$label       = is_string( $row['label'] ?? null ) ? $row['label'] : '';
		$base        = sanitize_title( $label );

		if ( '' === $base ) {
			// No usable label at all -- blueline_sanitize_occasions()
			// will reject this row for its own empty-label reason
			// regardless, so there is no id worth manufacturing for it.
			continue;
		}

		$final_id = $base;
		$suffix   = 2;

		while (
			isset( $taken[ $final_id ] )
			|| ( isset( $stored[ $final_id ] ) && $final_id !== $original_id )
		) {
			$final_id = $base . '-' . $suffix;
			++$suffix;
		}

		$taken[ $final_id ] = true;

		$row['id'] = $final_id;
		unset( $row['_original_id'] );

		$result[ $final_id ] = $row;
	}

	return $result;
}

/**
 * Compute the new `aa_acknowledgements` map after an Occasions-tab save
 * -- design spec §5.1's fifth ruling: per-occasion, symmetric
 * record/remove, plus orphan cleanup.
 *
 * For every occasion in $sanitized (already run through
 * blueline_sanitize_occasions() -- this function trusts it is
 * well-formed), resolves its effective accent (its own `accent`, or
 * blueline_occasion_accent_default() when empty), checks contrast
 * against BLUELINE_TOKEN_INK, and either records or removes its
 * acknowledgement accordingly. Then removes every acknowledgement
 * scoped `occasion:*` whose id is not present in $sanitized at all --
 * deleting or renaming an occasion must not leave its acknowledgement
 * behind forever.
 *
 * Pure: takes the current map and returns a new one; never calls
 * update_option() itself, matching blueline_record_acknowledgement()/
 * blueline_remove_acknowledgement()'s own contract.
 *
 * @param array<string, array<string, mixed>> $sanitized        This save's new `occasions` value (post blueline_sanitize_occasions()).
 * @param array<string, bool>                 $raw_overrides    Map of occasion id => whether ITS override checkbox was checked in this submission.
 * @param array<string, array<string, mixed>> $acknowledgements Currently stored `aa_acknowledgements`.
 * @param string                              $inputs_hash      blueline_settings_inputs_hash()'s current value.
 * @param int                                 $user_id          The saving user's id.
 * @return array<string, array<string, mixed>> The updated map, to be stored under `aa_acknowledgements`.
 */
function blueline_occasions_apply_aa_overrides(
	array $sanitized,
	array $raw_overrides,
	array $acknowledgements,
	string $inputs_hash,
	int $user_id
): array {
	foreach ( $sanitized as $id => $occasion ) {
		$scope = 'occasion:' . $id;

		$raw_accent = '' !== ( $occasion['accent'] ?? '' )
			? $occasion['accent']
			: blueline_occasion_accent_default();

		$accent = blueline_sanitize_hex_color( $raw_accent );

		if ( '' === $accent ) {
			// Unresolvable accent -- nothing to acknowledge either way;
			// do not leave a stale acknowledgement behind for a value
			// that no longer means anything.
			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
			continue;
		}

		$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $accent );
		$passes = $ratio >= blueline_contrast_threshold( 'body' );

		if ( ! $passes && ! empty( $raw_overrides[ $id ] ) ) {
			$acknowledgements = blueline_record_acknowledgement(
				$acknowledgements,
				$scope,
				'ink-on-occasion-accent',
				$accent,
				$ratio,
				$inputs_hash,
				$user_id
			);
		} else {
			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
		}
	}

	// Orphan cleanup: an acknowledgement scoped to an occasion id no
	// longer present in this save's own occasions map at all (deleted,
	// or renamed away from) has nothing left to cover.
	foreach ( array_keys( $acknowledgements ) as $scope ) {
		if ( 0 !== strpos( $scope, 'occasion:' ) ) {
			continue; // Not this mechanism's business -- e.g. a future non-occasion scope.
		}

		$id = substr( $scope, strlen( 'occasion:' ) );

		if ( ! isset( $sanitized[ $id ] ) ) {
			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
		}
	}

	return $acknowledgements;
}

/**
 * Classify every `occasion:*`-scoped entry in `aa_acknowledgements` as
 * still-`valid`, `orphaned`, or `stale` against CURRENT reality -- design
 * spec §6.5's ruling that this is the SOLE function both the deploy-drift
 * notice (inc/settings/validation.php's
 * blueline_occasions_maybe_revalidate_on_drift()) and Site Health
 * (inc/settings/site-health.php) read from, so the two surfaces can never
 * independently drift on what counts as stale.
 *
 * For each `occasion:{id}` acknowledgement:
 *
 * - `{id}` absent from blueline_settings( 'occasions' ) entirely ->
 *   `orphaned` (the occasion was deleted or renamed since the
 *   acknowledgement was recorded).
 * - Otherwise, resolve that occasion's CURRENT effective accent (its own
 *   `accent`, or blueline_occasion_accent_default() when empty), sanitize
 *   it exactly as blueline_resolve_active_occasion() does, and check
 *   blueline_acknowledgement_covers() against it with the CURRENT
 *   blueline_settings_inputs_hash() -- `stale` on a `false` result (the
 *   accent value changed, or style.css/contrast-rules.json moved since
 *   the acknowledgement was recorded), `valid` otherwise. An unresolvable
 *   current accent (blueline_sanitize_hex_color() returns '') can never
 *   be covered by anything, so it classifies `stale` too.
 *
 * A scope outside the `occasion:` namespace is not this function's
 * business at all and is skipped entirely -- not merely left `valid` --
 * so the returned map's own keys are exactly this function's domain.
 *
 * Pure and read-only: never calls blueline_record_acknowledgement() or
 * blueline_remove_acknowledgement(). A stale or orphaned acknowledgement
 * is REPORTED, never deleted -- blueline_remove_acknowledgement()'s own
 * docblock already establishes that a stale hash is not a valid reason
 * to call it, and drift discovery is not a stronger reason than
 * staleness itself (design spec §6.5).
 *
 * @return array<string, string> Map of acknowledgement scope => 'valid' | 'orphaned' | 'stale'.
 */
function blueline_occasions_classify_acknowledgements(): array {
	$acknowledgements = blueline_stored_acknowledgements();

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();

	$inputs_hash = blueline_settings_inputs_hash();

	$classifications = array();

	foreach ( $acknowledgements as $scope => $entry ) {
		if ( 0 !== strpos( $scope, 'occasion:' ) ) {
			continue; // Not this mechanism's business -- e.g. a future non-occasion scope.
		}

		$id = substr( $scope, strlen( 'occasion:' ) );

		if ( ! isset( $occasions[ $id ] ) || ! is_array( $occasions[ $id ] ) ) {
			$classifications[ $scope ] = 'orphaned';
			continue;
		}

		$occasion = $occasions[ $id ];

		$raw_accent = '' !== ( $occasion['accent'] ?? '' )
			? $occasion['accent']
			: blueline_occasion_accent_default();

		$accent = is_string( $raw_accent ) ? blueline_sanitize_hex_color( $raw_accent ) : '';

		if ( '' === $accent ) {
			// Unresolvable current accent -- nothing can cover this; the
			// same treatment as a value that plainly changed.
			$classifications[ $scope ] = 'stale';
			continue;
		}

		$covers = blueline_acknowledgement_covers(
			$acknowledgements,
			$scope,
			'ink-on-occasion-accent',
			$accent,
			$inputs_hash
		);

		$classifications[ $scope ] = $covers ? 'valid' : 'stale';
	}

	return $classifications;
}

/**
 * Today's calendar date, in SITE timezone (not UTC), as 'MM-DD' --
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
 * e.g. New Year's own 12-27..01-02 -- blueline_occasion_presets()'s
 * `new-year` entry forces this case to be real, not hypothetical) by
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
 * `id` ascending -- design spec §5/§7.5, in that exact order, so the
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
 * The one active occasion right now, or null.
 *
 * Reads ONLY blueline_settings( 'occasions' ) -- never
 * blueline_occasion_presets(), which is a read-only catalog with no
 * bearing on what is actually live (design spec §5's second ruling; see
 * tests/OccasionsResolverTest.php's own source-scan test for the
 * enforcement).
 *
 * Eligibility: `force_off` is never eligible, regardless of window.
 * `force_on` is always eligible, regardless of window. `auto` (or an
 * unset mode) is eligible only while blueline_occasion_today_md()
 * currently falls inside its window -- and only if both stored bounds are
 * well-formed `MM-DD` values in the first place (see the inline note on
 * that check: a malformed bound would otherwise read as "always active").
 *
 * Among eligible candidates, blueline_occasion_compare() orders by
 * precedence. Each candidate is then checked, in that order, against the
 * AA-override mechanism (design spec §4.5): resolve its effective accent
 * (its own `accent`, or blueline_occasion_accent_default() when empty --
 * that default is computed at most once per call, on first need, and
 * reused for every later candidate with an empty `accent`), run it
 * through blueline_sanitize_hex_color() (blueline_settings() does not
 * sanitize on read -- inc/settings/store.php's own docblock -- so a
 * stored `accent` can be malformed even though blueline_sanitize_occasions()
 * rejects one on write), and, if that yields a real hex colour, compute
 * its real contrast ratio against BLUELINE_TOKEN_INK; and -- only if it
 * fails blueline_contrast_threshold( 'body' ) -- require a live,
 * hash-matching acknowledgement scoped to `occasion:{id}`
 * (blueline_acknowledgement_covers()) before accepting it. A candidate
 * whose effective accent cannot be resolved to a real hex colour at all,
 * or that fails the contrast gate unacknowledged, is skipped entirely,
 * falling through to the next-best candidate; the first candidate that
 * either passes contrast outright or is validly acknowledged wins. null
 * if none does (including when nothing is stored at all).
 *
 * @param int|null $now_override Unix timestamp to evaluate against;
 *                                defaults to the current time. Tests pass
 *                                this so an assertion about a window
 *                                keeps meaning the same thing later.
 * @return array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string, resolved_accent:string}|null
 */
function blueline_resolve_active_occasion( ?int $now_override = null ): ?array {
	$now      = $now_override ?? time();
	$today_md = blueline_occasion_today_md( $now );

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();

	$candidates = array();

	foreach ( $occasions as $occasion ) {
		if ( ! is_array( $occasion ) ) {
			continue;
		}

		$mode = $occasion['mode'] ?? 'auto';

		if ( 'force_off' === $mode ) {
			continue;
		}

		if ( 'force_on' !== $mode ) {
			$window = $occasion['window'] ?? array();
			$start  = $window['start_md'] ?? '';
			$end    = $window['end_md'] ?? '';

			// Validate both bounds before they reach the window comparison,
			// exactly as blueline_occasion_next_boundary_timestamp() below
			// does with the same stored data. `occasions` is a reserved
			// settings key, not a schema field, so blueline_settings_repair()'s
			// schema-field walk never revalidates it: an out-of-band write (a
			// hand-edited row, a restored dump, a migration script) is the only
			// thing that can put a malformed bound here, and nothing else will
			// ever take it back out. Unvalidated, an empty `start_md` makes
			// blueline_occasion_window_contains() take its non-wrapping branch
			// and return true for EVERY possible $today_md -- permanently
			// activating the occasion site-wide. Same reasoning as the
			// blueline_sanitize_hex_color() pass over `accent` further down:
			// validate defensively on read, skip rather than fatal.
			if ( ! blueline_occasion_valid_md( $start ) || ! blueline_occasion_valid_md( $end ) ) {
				continue;
			}

			if ( ! blueline_occasion_window_contains( $start, $end, $today_md ) ) {
				continue;
			}
		}

		$candidates[] = $occasion;
	}

	if ( array() === $candidates ) {
		return null;
	}

	usort( $candidates, 'blueline_occasion_compare' );

	$inputs_hash      = blueline_settings_inputs_hash();
	$acknowledgements = blueline_stored_acknowledgements();
	$default_accent   = null;

	foreach ( $candidates as $candidate ) {
		$raw_accent = '' !== ( $candidate['accent'] ?? '' )
			? $candidate['accent']
			// Computed at most once per request, on first actual need: its
			// own result (a style.css read) cannot change mid-request, and
			// most requests never reach a candidate with an empty accent
			// at all.
			: ( $default_accent ??= blueline_occasion_accent_default() );

		if ( '' === $raw_accent ) {
			// The default itself could not be resolved (Phase 2.0's own
			// blueline_occasion_accent_default() already logs why) --
			// nothing to apply for this candidate. Skip, never fatal.
			continue;
		}

		// blueline_settings() does not sanitize on read (inc/settings/store.php's
		// own docblock): a stored `accent` can be malformed (a hand-edited
		// row, a migration script) even though blueline_sanitize_occasions()
		// rejects one on write. Run it through the same sanitizer used
		// there before it ever reaches contrast math -- never trust a raw
		// stored string as a real hex colour.
		$accent = blueline_sanitize_hex_color( $raw_accent );

		if ( '' === $accent ) {
			// Not a real hex colour: unresolvable, same as an unresolved
			// default above. Skip, never fatal.
			continue;
		}

		$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $accent );
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

/**
 * The maple leaf motif (Canada Day). Purely decorative: aria-hidden, no
 * text alternative needed -- matches the existing house style
 * (inc/template-tags.php's blueline_leaf_mark(), inc/homepage-modules.php's
 * blueline_render_faceoff_rings()): `stroke`/`fill="currentColor"` so CSS
 * (specifically --bl-occasion-accent, once a template applies it as the
 * motif's colour) controls the rendered colour, not this markup.
 *
 * @return void
 */
function blueline_render_occasion_motif_maple_leaf(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--maple-leaf" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 3l4 9 9-4-3 9 8 5-9 3 2 9-9-4-2 9-2-9-9 4 2-9-9-3 8-5-3-9 9 4Z"/></svg>
	<?php
}

/**
 * The poppy motif (Remembrance Day). NOT aria-hidden: design spec
 * §5/§7.7 requires it carry a real accessible name, so it gets a
 * `<title>` (announced by assistive technology as the element's
 * accessible name for a `role="img"` SVG) instead of the `aria-hidden`
 * every other motif here uses.
 *
 * @return void
 */
function blueline_render_occasion_motif_poppy(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--poppy" viewBox="0 0 48 48" role="img" focusable="false"><title><?php esc_html_e( 'Remembrance poppy', 'blueline' ); ?></title><path fill="currentColor" d="M24 22c-4-6-12-8-14-2-2 6 6 10 14 6 8 4 16 0 14-6-2-6-10-4-14 2Z"/><path fill="currentColor" d="M24 26c-2 6-8 12-4 16 4 4 8-4 4-10-4-4-4-2 0-6Z"/><circle cx="24" cy="24" r="4" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/></svg>
	<?php
}

/**
 * The snowflake motif (Christmas). Purely decorative: aria-hidden, no
 * text alternative needed.
 *
 * @return void
 */
function blueline_render_occasion_motif_snowflake(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--snowflake" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="24" y1="4" x2="24" y2="44"></line><line x1="4" y1="24" x2="44" y2="24"></line><line x1="10" y1="10" x2="38" y2="38"></line><line x1="38" y1="10" x2="10" y2="38"></line></g></svg>
	<?php
}

/**
 * The sparkle motif (New Year). Purely decorative: aria-hidden, no text
 * alternative needed.
 *
 * @return void
 */
function blueline_render_occasion_motif_sparkle(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--sparkle" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 2c1 8 4 15 9 20 5 5 12 8 20 9-8 1-15 4-20 9-5 5-8 12-9 20-1-8-4-15-9-20-5-5-12-8-20-9 8-1 15-4 20-9 5-5 8-12 9-20Z"/></svg>
	<?php
}

/**
 * Render the named motif, or nothing for 'none' or an unrecognised name
 * -- never fatal, matching every other read path in this file.
 *
 * @param string $motif One of blueline_occasion_motifs().
 * @return void
 */
function blueline_render_occasion_motif( string $motif ): void {
	switch ( $motif ) {
		case 'maple-leaf':
			blueline_render_occasion_motif_maple_leaf();
			break;
		case 'poppy':
			blueline_render_occasion_motif_poppy();
			break;
		case 'snowflake':
			blueline_render_occasion_motif_snowflake();
			break;
		case 'sparkle':
			blueline_render_occasion_motif_sparkle();
			break;
		case 'none':
		default:
			break;
	}
}

/**
 * Clamp the announcement's severity from 'urgent' down to 'info' while a
 * commemorative occasion is the currently resolved-active one -- design
 * spec §5/§7.6. Hooked onto the `blueline_announcement_severity` filter
 * inc/announcement.php's blueline_announcement_severity() applies its
 * return value through.
 *
 * @param string $severity One of BLUELINE_ANNOUNCEMENT_SEVERITIES.
 * @return string
 */
function blueline_occasion_suppress_urgent_announcement( string $severity ): string {
	if ( 'urgent' !== $severity ) {
		return $severity;
	}

	$active = blueline_resolve_active_occasion();

	if ( null === $active || 'commemorative' !== ( $active['type'] ?? '' ) ) {
		return $severity;
	}

	return 'info';
}
add_filter( 'blueline_announcement_severity', 'blueline_occasion_suppress_urgent_announcement' );

/**
 * The WP-Cron hook name for the boundary purge. A single event is ever
 * scheduled against this hook at a time (design spec §5/§7.8: "a single
 * WP-Cron event").
 */
const BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK = 'blueline_occasion_boundary_purge';

/**
 * The next annual occurrence of calendar date $md (00:00:00, site
 * timezone) strictly after $after.
 *
 * A `02-29` $md in a target year that is not itself a leap year rolls
 * forward to March 1st -- PHP's own DateTimeImmutable date-parsing
 * behaviour for an out-of-range day, accepted here rather than worked
 * around: none of the four shipped presets (blueline_occasion_presets())
 * uses `02-29`, and this is a purge-TIMING calculation only (design spec
 * §5/§7.8) -- a purge landing a day off in a leap-adjacent year for a
 * hypothetical future `02-29` occasion delays cache visibility by at
 * most a day, never producing a wrong resolved result (the resolver,
 * Task 3, is unaffected by this function entirely).
 *
 * @param string $md    'MM-DD'.
 * @param int    $after Unix timestamp; the returned timestamp is always
 *                       strictly greater than this.
 * @return int Unix timestamp.
 */
function blueline_occasion_next_occurrence_timestamp( string $md, int $after ): int {
	$tz   = wp_timezone();
	$year = (int) ( new DateTimeImmutable( '@' . $after ) )->setTimezone( $tz )->format( 'Y' );

	list( $month, $day ) = array_map( 'intval', explode( '-', $md ) );

	$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), $tz );

	if ( $candidate->getTimestamp() <= $after ) {
		$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year + 1, $month, $day ), $tz );
	}

	return $candidate->getTimestamp();
}

/**
 * The `MM-DD` on which a window ending on $end_md actually DEACTIVATES --
 * the day AFTER it.
 *
 * The window (blueline_occasion_window_contains()) is INCLUSIVE of the
 * occasion's `end_md`: it is active through all of `end_md` and stops at
 * `end_md + 1 day, 00:00`. So `end_md 00:00` is not a boundary at all --
 * nothing changes at that instant, because the occasion has already been
 * active since `start_md`. The instant something changes is the start of
 * the following day, which is what this returns the calendar date of.
 *
 * Calendar arithmetic, never `+86400`: month lengths (`01-31` -> `02-01`)
 * and the year wrap (`12-31` -> `01-01`) both have to come out right.
 * Stepped in UTC because the value being produced is a recurring `MM-DD`
 * with no clock in it -- there is no wall-clock time here for a DST
 * transition to shift, and the site-timezone 00:00 resolution happens
 * afterwards, in blueline_occasion_next_occurrence_timestamp(). Stepped
 * from 2024, the same leap year blueline_occasion_valid_md() validates
 * against, so a `02-29` end_md is a real date to advance off rather than
 * one PHP would roll forward before the addition ever ran.
 *
 * @param string $end_md 'MM-DD'; callers validate it with
 *                        blueline_occasion_valid_md() first.
 * @return string 'MM-DD'.
 */
function blueline_occasion_end_boundary_md( string $end_md ): string {
	return ( new DateTimeImmutable( '2024-' . $end_md . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )
		->add( new DateInterval( 'P1D' ) )
		->format( 'm-d' );
}

/**
 * The soonest "something changes" instant across every stored auto-mode
 * occasion's activation AND deactivation boundary -- the moment WP-Cron
 * should next fire blueline_occasion_cron_boundary_purge().
 * `force_on`/`force_off` occasions are excluded: their activation is not
 * date-driven, so they have no boundary to purge for.
 *
 * The activation boundary is `start_md 00:00`. The deactivation boundary
 * is the start of the day AFTER `end_md`, not `end_md 00:00` -- see
 * blueline_occasion_end_boundary_md() for why the inclusive window makes
 * `end_md 00:00` an instant at which nothing changes.
 *
 * Never the correctness mechanism itself (design spec §5/§7.8) -- only
 * ever a purge-timing hint. blueline_resolve_active_occasion() (Task 3)
 * always recomputes from scratch on every request regardless of whether
 * this ever ran.
 *
 * @param array<string, array<string, mixed>> $occasions blueline_settings( 'occasions' )'s stored value.
 * @param int                                 $now       Unix timestamp to measure "next" from.
 * @return int|null The soonest boundary strictly after $now, or null if
 *                   there are no auto-mode occasions stored at all.
 */
function blueline_occasion_next_boundary_timestamp( array $occasions, int $now ): ?int {
	$boundaries = array();

	foreach ( $occasions as $occasion ) {
		if ( ! is_array( $occasion ) ) {
			continue;
		}

		$mode = $occasion['mode'] ?? 'auto';

		if ( 'auto' !== $mode ) {
			continue;
		}

		$window = $occasion['window'] ?? array();
		$start  = $window['start_md'] ?? '';
		$end    = $window['end_md'] ?? '';

		if ( ! blueline_occasion_valid_md( $start ) || ! blueline_occasion_valid_md( $end ) ) {
			continue;
		}

		$boundaries[] = blueline_occasion_next_occurrence_timestamp( $start, $now );
		$boundaries[] = blueline_occasion_next_occurrence_timestamp( blueline_occasion_end_boundary_md( $end ), $now );
	}

	if ( array() === $boundaries ) {
		return null;
	}

	return min( $boundaries );
}

/**
 * (Re)schedule the single WP-Cron event that purges the page cache at
 * the next occasion window boundary (design spec §5/§7.8). A no-op when
 * no auto-mode occasion is stored (the common case today: `occasions`
 * defaults to an empty array, and 2.1a ships no UI to populate it) --
 * any previously scheduled event is cleared in that case. Also a no-op
 * when the correct boundary is ALREADY scheduled, mirroring
 * blueline_settings_migrate()'s own early-exit shape
 * (inc/settings/store.php).
 *
 * @param int|null $now_override Unix timestamp to measure "next" from;
 *                                defaults to the current time. Exists
 *                                purely for testability, matching Task
 *                                3's `blueline_resolve_active_occasion()`
 *                                precedent -- never passed by the real
 *                                hook registrations below.
 * @return void
 */
function blueline_occasion_schedule_next_boundary_purge( ?int $now_override = null ): void {
	$now = $now_override ?? time();

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();

	$next      = blueline_occasion_next_boundary_timestamp( $occasions, $now );
	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

	if ( null === $next ) {
		if ( false !== $scheduled ) {
			wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
		}
		return;
	}

	if ( $scheduled === $next ) {
		return; // Already scheduled for the right instant.
	}

	if ( false !== $scheduled ) {
		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
	}

	wp_schedule_single_event( $next, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
}
// Scheduled on activation, and re-derived on every write to the settings
// option -- exactly the two hooks inc/settings/cache.php's own purge
// trigger already uses for "react to a settings write" (add_option_/
// update_option_ -- see that file's own add_action() pair), rather than
// polling on `init` for every ordinary page view.
//
// All three pass `10, 0` deliberately. Every one of these hooks fires with
// arguments this callback must never receive: `after_switch_theme` passes
// the OUTGOING theme's NAME (a string), and the option hooks pass option
// values. Against the `?int $now_override` signature, PHP's coercive
// typing throws a TypeError on a non-numeric string -- a fatal at the
// exact moment an admin activates the theme. Zero accepted args is what
// keeps the real hook registrations honest about "never passed by the real
// hook registrations below" in that parameter's own docblock.
add_action( 'after_switch_theme', 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );
add_action( 'add_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );
add_action( 'update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );

/**
 * The cron callback itself: purge, then reschedule for the FOLLOWING
 * boundary (never the same one twice) -- design spec §5/§7.8's "purges
 * ... and reschedules".
 *
 * @param int|null $now_override Forwarded to
 *                                blueline_occasion_schedule_next_boundary_purge();
 *                                see that function's own docblock.
 * @return void
 */
function blueline_occasion_cron_boundary_purge( ?int $now_override = null ): void {
	blueline_maybe_purge_page_cache();
	blueline_occasion_schedule_next_boundary_purge( $now_override );
}
add_action( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK, 'blueline_occasion_cron_boundary_purge' );

/**
 * Clear the scheduled boundary-purge event when this theme is switched
 * away from. `switch_theme` fires on the OUTGOING theme -- the closest
 * thing a theme has to a deactivation hook, and the same convention
 * inc/account/endpoints.php already establishes for `after_switch_theme`
 * (its own flush_rewrite_rules() registration) on the activation side.
 * Tidiness, not a correctness requirement: a stray scheduled event on a
 * theme that is no longer active simply never finds this callback again
 * once it is deactivated, since add_action() above is registered only
 * while this theme's functions.php actually runs.
 *
 * @return void
 */
function blueline_occasion_clear_scheduled_boundary_purge(): void {
	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

	if ( false !== $scheduled ) {
		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
	}
}
add_action( 'switch_theme', 'blueline_occasion_clear_scheduled_boundary_purge' );

/**
 * Append occasion CSS to the block editor canvas's own style list, via
 * the one filter core actually clones into `.editor-styles-wrapper` --
 * design spec §5/§7.9, and see inc/enqueue.php's own docblock
 * (immediately preceding its `add_theme_support( 'editor-styles' )` /
 * `add_editor_style()` pair in inc/setup.php) for why
 * `enqueue_block_editor_assets()` was tried and reverted instead.
 *
 * Emits ONLY `--bl-occasion-accent` -- the sole occasion-related custom
 * property that exists -- and only when an occasion is actually
 * resolved-active right now. When none is, the token's own CSS default
 * (`var(--bl-ice)`, already present in both style.css and
 * assets/src/css/editor.css) already applies, so there is nothing to
 * override and this filter changes nothing.
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @return array<string, mixed>
 */
function blueline_occasion_editor_styles( array $settings ): array {
	$active = blueline_resolve_active_occasion();

	if ( null === $active ) {
		return $settings;
	}

	$accent = $active['resolved_accent'] ?? '';

	if ( ! preg_match( '/^#[0-9a-f]{6}$/', $accent ) ) {
		// Defensive: never emit anything that is not exactly the validated
		// hex shape the sanitizer/resolver already guarantee -- this is
		// the last point before the value reaches raw CSS text.
		return $settings;
	}

	$styles   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
	$styles[] = array(
		'css'            => ':root, .editor-styles-wrapper { --bl-occasion-accent: ' . $accent . '; }',
		'__unstableType' => 'theme',
	);

	$settings['styles'] = $styles;

	return $settings;
}
add_filter( 'block_editor_settings_all', 'blueline_occasion_editor_styles' );
