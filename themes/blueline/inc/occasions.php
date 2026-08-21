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
 * @param string $reason Human-readable reason, for the log line.
 * @return void
 */
function blueline_occasion_accent_default_read_failure( string $reason ): void {
	static $logged = false;

	if ( $logged ) {
		return;
	}
	$logged = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: mirrors inc/team-colors.php's blueline_contrast_rules_read_failure() -- see that function's own docblock for why a silent fallback should still leave a server-log trace.
	error_log(
		sprintf( "Blueline: could not resolve --bl-occasion-accent's default from style.css (%s).", $reason )
	);
}

/**
 * Resolve --bl-occasion-accent's own declared default to a literal hex
 * value, by reading style.css's :root block directly.
 *
 * style.css declares `--bl-occasion-accent: var(--bl-ice);` -- one var()
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
		blueline_occasion_accent_default_read_failure( 'stylesheet is missing or unreadable' );
		return '';
	}

	$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.

	// Strip comments first so a mention inside one cannot be mistaken for
	// a real declaration.
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

	if ( ! preg_match( '/:root\s*\{(.*?)\}/s', $css, $root_match ) ) {
		blueline_occasion_accent_default_read_failure( 'no :root rule found' );
		return '';
	}

	$root_block = $root_match[1];

	if ( ! preg_match( '/--bl-occasion-accent\s*:\s*var\(\s*(--[a-z0-9-]+)\s*\)\s*;/i', $root_block, $ref_match ) ) {
		blueline_occasion_accent_default_read_failure( '--bl-occasion-accent is not declared as a single var(--bl-*) reference' );
		return '';
	}

	$referenced = $ref_match[1];

	if ( ! preg_match( '/' . preg_quote( $referenced, '/' ) . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $root_block, $hex_match ) ) {
		blueline_occasion_accent_default_read_failure( "referenced token {$referenced} is not declared as a plain hex literal" );
		return '';
	}

	return strtolower( $hex_match[1] );
}
