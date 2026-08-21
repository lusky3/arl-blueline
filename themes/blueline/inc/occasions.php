<?php
/**
 * Occasions -- Phase 2.0 foundation only.
 *
 * Hosts blueline_occasion_accent_default(): resolving --bl-occasion-accent's
 * own declared default (`var(--bl-ice)`) to --bl-ice's literal hex value,
 * by reading style.css's :root block directly.
 *
 * Deliberately NOT a general :root parser. This resolves exactly one
 * var() hop for exactly one token -- everything Phase 2.0 needs. See
 * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.1:
 * a general recursive :root parser was explicitly descoped as premature
 * generality for a need that does not exist yet. A future phase that
 * needs to resolve arbitrary :root tokens at runtime should build that
 * parser then, against a second real caller.
 *
 * The Occasion model, scheduling, resolution, motifs, cron boundary
 * purge, and editor parity described in the design spec's §7/§5 (Phase
 * 2.1) do NOT live here yet.
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
 * currently falls inside its window.
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
