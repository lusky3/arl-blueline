<?php
/**
 * Design-token tunability manifest reader.
 *
 * tools/tokens.json is the committed manifest declaring, for every --bl-*
 * custom property in style.css's :root block, its type/group/tier/bounds
 * and whether the settings panel is ever allowed to expose it as an
 * editable value at all ("tunable"). Every token defaults to non-tunable;
 * only --bl-occasion-accent is marked tunable -- a structural limit, not
 * a promise the panel merely chooses to keep. See
 * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.1.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log, at most once per call site, that tools/tokens.json could not be
 * used, mirroring inc/team-colors.php's blueline_contrast_rules_read_failure():
 * never a fatal, never a _doing_it_wrong() notice a visitor could see --
 * just a server-log trace of a silently degraded fallback.
 *
 * @param string $path   The tokens.json path that could not be used.
 * @param string $reason Human-readable reason, for the log line.
 * @return void
 */
function blueline_token_manifest_read_failure( string $path, string $reason ): void {
	static $logged = false;

	if ( $logged ) {
		return;
	}
	$logged = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: mirrors inc/team-colors.php's blueline_contrast_rules_read_failure(), whose own docblock explains why a silent fallback should still leave a server-log trace.
	error_log(
		sprintf(
			'Blueline: tools/tokens.json unusable (%s) at %s -- falling back to an empty token manifest.',
			$reason,
			$path
		)
	);
}

/**
 * Parse tools/tokens.json at $path into its `tokens` map, dropping any
 * entry that is not an array so one malformed row cannot corrupt the
 * whole read.
 *
 * @param string $path Path to a tokens.json file.
 * @return array<string, array{type:string, group:string, tier:string, bounds:mixed, tunable:bool}>
 */
function blueline_token_manifest_parse_from_path( string $path ): array {
	if ( ! is_readable( $path ) ) {
		blueline_token_manifest_read_failure( $path, 'file is missing or unreadable' );
		return array();
	}

	$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
	$json = json_decode( (string) $raw, true );

	if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
		blueline_token_manifest_read_failure( $path, 'invalid JSON (' . json_last_error_msg() . ')' );
		return array();
	}

	if ( ! is_array( $json ) || ! isset( $json['tokens'] ) || ! is_array( $json['tokens'] ) ) {
		blueline_token_manifest_read_failure( $path, 'missing or non-object top-level "tokens"' );
		return array();
	}

	$tokens = array();

	foreach ( $json['tokens'] as $name => $entry ) {
		if ( ! is_string( $name ) || '' === $name || ! is_array( $entry ) ) {
			continue;
		}

		$tokens[ $name ] = array(
			'type'    => is_string( $entry['type'] ?? null ) ? $entry['type'] : '',
			'group'   => is_string( $entry['group'] ?? null ) ? $entry['group'] : '',
			'tier'    => is_string( $entry['tier'] ?? null ) ? $entry['tier'] : '',
			'bounds'  => $entry['bounds'] ?? null,
			'tunable' => ! empty( $entry['tunable'] ),
		);
	}

	return $tokens;
}

/**
 * The parsed tools/tokens.json manifest, cached for the lifetime of the
 * request when read from its real, default path.
 *
 * @param string|null $path_override Explicit path, for tests. Defaults to
 *                                    the real tools/tokens.json next to
 *                                    this theme. Bypasses the cache: a
 *                                    test that passes an override always
 *                                    gets a fresh read.
 * @return array<string, array{type:string, group:string, tier:string, bounds:mixed, tunable:bool}>
 */
function blueline_token_manifest( ?string $path_override = null ): array {
	static $cache = null;

	if ( null === $path_override && null !== $cache ) {
		return $cache;
	}

	$path = $path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__, 2 ) ) . '/tools/tokens.json' );

	$tokens = blueline_token_manifest_parse_from_path( $path );

	if ( null === $path_override ) {
		$cache = $tokens;
	}

	return $tokens;
}

/**
 * Whether $token_name is marked tunable in tools/tokens.json.
 *
 * An unknown token name, and any lookup made while the manifest itself is
 * unavailable, both answer false -- the same fail-closed default every
 * declared token already carries unless explicitly marked otherwise.
 *
 * @param string $token_name Token name, e.g. '--bl-occasion-accent'.
 * @return bool
 */
function blueline_token_is_tunable( string $token_name ): bool {
	$manifest = blueline_token_manifest();

	return ! empty( $manifest[ $token_name ]['tunable'] );
}
