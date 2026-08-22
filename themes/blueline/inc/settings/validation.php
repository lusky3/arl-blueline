<?php
/**
 * The shared "inputs hash" primitive.
 *
 * A single hash summarising the external inputs the settings panel's
 * AA-related verdicts depend on: style.css's own last-edit time (via
 * blueline_stylesheet_version(), inc/enqueue.php) and
 * tools/contrast-rules.json's `rules`/`thresholds` keys specifically --
 * deliberately not the whole file, so an edit to its `$comment` or
 * `version` does not invalidate every live acknowledgement.
 *
 * Two consumers share this: an `aa_acknowledgements` entry
 * (inc/settings/acknowledgements.php) stores this hash at the moment of
 * acknowledgement (Phase 2.0); Phase 2.2's `_validated_against` will
 * store it for the settings option as a whole. See
 * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.3.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log, at most once per call site, that tools/contrast-rules.json could
 * not be read while computing the inputs hash. A distinct function from
 * inc/team-colors.php's blueline_contrast_rules_read_failure() (same
 * shape, deliberately not shared, so this file has no reason to depend
 * on that one's internal static).
 *
 * @param string $path   The contrast-rules.json path that could not be used.
 * @param string $reason Human-readable reason, for the log line.
 * @return void
 */
function blueline_settings_inputs_hash_read_failure( string $path, string $reason ): void {
	static $logged = false;

	if ( $logged ) {
		return;
	}
	$logged = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: same reasoning as inc/team-colors.php's blueline_contrast_rules_read_failure().
	error_log(
		sprintf(
			'Blueline: contrast-rules.json unusable (%s) at %s while computing the settings inputs hash -- hashing an empty rules/thresholds set instead.',
			$reason,
			$path
		)
	);
}

/**
 * A stable hash of tools/contrast-rules.json's `rules` and `thresholds`
 * keys only. Never fatal: a missing or malformed file hashes an empty
 * rules/thresholds set instead -- the file's own thresholds are already
 * unusable in that case (see blueline_load_contrast_thresholds()), so
 * this only means an acknowledgement recorded while the file was broken
 * will need re-acknowledging once it is readable again.
 *
 * @param string $path Path to contrast-rules.json.
 * @return string A sha256 hex digest.
 */
function blueline_contrast_rules_content_hash( string $path ): string {
	$rules      = array();
	$thresholds = array();

	if ( is_readable( $path ) ) {
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
		$json = json_decode( (string) $raw, true );

		if ( is_array( $json ) ) {
			$rules      = is_array( $json['rules'] ?? null ) ? $json['rules'] : array();
			$thresholds = is_array( $json['thresholds'] ?? null ) ? $json['thresholds'] : array();
		} else {
			blueline_settings_inputs_hash_read_failure( $path, 'invalid JSON' );
		}
	} else {
		blueline_settings_inputs_hash_read_failure( $path, 'file is missing or unreadable' );
	}

	return hash(
		'sha256',
		(string) wp_json_encode(
			array(
				'rules'      => $rules,
				'thresholds' => $thresholds,
			)
		)
	);
}

/**
 * The shared inputs hash: a single string that changes if, and only if,
 * either style.css's own last-edit time or contrast-rules.json's
 * `rules`/`thresholds` change.
 *
 * @param string|null $contrast_rules_path_override Explicit path to
 *                                                   contrast-rules.json,
 *                                                   for tests. Defaults
 *                                                   to the real
 *                                                   tools/contrast-rules.json
 *                                                   next to this theme.
 * @return string A sha256 hex digest.
 */
function blueline_settings_inputs_hash( ?string $contrast_rules_path_override = null ): string {
	$path = $contrast_rules_path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__, 2 ) ) . '/tools/contrast-rules.json' );

	return hash( 'sha256', blueline_stylesheet_version() . '|' . blueline_contrast_rules_content_hash( $path ) );
}

/**
 * The settings option's own `_validated_against` bookkeeping value: the
 * blueline_settings_inputs_hash() this option was last checked against
 * for deploy-drift revalidation (Phase 2.2,
 * blueline_occasions_maybe_revalidate_on_drift()). '' means either a
 * fresh install, or one whose drift check has genuinely never run yet.
 *
 * Reads get_option() directly, the same shape
 * blueline_stored_acknowledgements() (inc/settings/acknowledgements.php)
 * already uses for its own reserved key: `_validated_against` is
 * deliberately excluded from blueline_settings_defaults()'s return (see
 * that function's own docblock, inc/settings/defaults.php), so
 * blueline_settings() can never return it -- there is no ordinary caller
 * asking for a field VALUE that has any business reading migration/drift
 * bookkeeping back through that accessor.
 *
 * @return string
 */
function blueline_validated_against(): string {
	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	return is_string( $stored['_validated_against'] ?? null ) ? $stored['_validated_against'] : '';
}
