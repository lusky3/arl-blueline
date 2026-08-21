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
