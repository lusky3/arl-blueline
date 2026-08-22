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

/**
 * Relay a request's drift classification (whatever
 * blueline_occasions_maybe_revalidate_on_drift() found, filtered down to
 * non-'valid' entries) from the `admin_init` hook that computes it to the
 * `admin_notices` hook that renders it -- both fire within the SAME
 * request (design spec §6.5's ruling that the notice is a one-time,
 * same-request surface, never a persisted, recurring one), so a
 * module-level static is all this needs -- the identical pattern
 * blueline_settings_page_hook() (inc/settings/page.php) already uses for
 * an unrelated same-request handoff.
 *
 * @param array<string, string>|null|false $classifications Omit (or pass
 *                                                           `false`) to
 *                                                           read without
 *                                                           writing. Pass
 *                                                           an array to
 *                                                           set it, or
 *                                                           `null` to
 *                                                           explicitly
 *                                                           clear it
 *                                                           (used by
 *                                                           tests to
 *                                                           guarantee no
 *                                                           leakage
 *                                                           between
 *                                                           cases -- this
 *                                                           static is
 *                                                           NOT one of
 *                                                           the stores
 *                                                           blueline_test_reset()
 *                                                           already
 *                                                           clears).
 * @return array<string, string>|null
 */
function blueline_occasions_drift_notice_payload( $classifications = false ): ?array {
	static $stored = null;

	if ( false !== $classifications ) {
		$stored = $classifications;
	}

	return $stored;
}

add_action( 'admin_init', 'blueline_occasions_maybe_revalidate_on_drift' );
/**
 * Deploy-drift revalidation (design spec §6.2, ruled on further in
 * §6.5): if blueline_settings_inputs_hash() has changed since the last
 * check (blueline_validated_against()), classify every stored
 * acknowledgement (blueline_occasions_classify_acknowledgements()) and,
 * if anything is no longer `valid`, hand that off to
 * blueline_render_occasions_drift_notice() via
 * blueline_occasions_drift_notice_payload() for THIS SAME request's
 * `admin_notices` to render. Either way, `_validated_against` is updated
 * to the current hash immediately -- design spec §6.5's ruling that this
 * is a one-time notice, not a recurring nag. The next request's cheap
 * hash-compare then short-circuits until the next real drift.
 *
 * Hooked to `admin_init`, deliberately narrower than the spec's own
 * prose ("on init") and deliberately NOT following
 * blueline_settings_migrate()'s choice of the universal `init` hook
 * (design spec §6.5): that migration's correctness has to hold before
 * ANYTHING reads the option, on every kind of request (anonymous, cron,
 * REST, WP-CLI) -- this check's correctness need is different in kind.
 * blueline_resolve_active_occasion() already recomputes contrast and
 * acknowledgement coverage from scratch on every single request
 * regardless of whether this check has ever run at all (the fail-closed
 * guarantee, design spec §4.5, holds unconditionally already); this
 * check's ONLY job is *surfacing* drift to an admin via a notice and
 * (inc/settings/site-health.php) a Site Health field -- pure
 * diagnostics, with zero front-end/cron/REST/WP-CLI consumer.
 * blueline_settings_inputs_hash() costs a real filesystem stat plus a
 * hash, unlike blueline_settings_migrate()'s O(1) integer-compare guard,
 * so paying that on every anonymous front-end request for a value
 * nothing on the front end ever reads back would be pure waste. Not
 * `update_option_*`/`add_option_*` either (2.1a's own boundary-purge
 * pattern), since this must ALSO catch drift from causes other than a
 * settings write -- a deploy touching style.css's mtime, an edited
 * contrast-rules.json -- which no options hook would ever fire for.
 *
 * @return void
 */
function blueline_occasions_maybe_revalidate_on_drift(): void {
	$current_hash = blueline_settings_inputs_hash();

	if ( blueline_validated_against() === $current_hash ) {
		// Cheap guard: nothing this mechanism cares about has changed
		// since the last check. Skip the more expensive classification
		// walk entirely.
		return;
	}

	$classifications = blueline_occasions_classify_acknowledgements();

	$non_valid = array_filter(
		$classifications,
		static function ( $status ) {
			return 'valid' !== $status;
		}
	);

	if ( array() !== $non_valid ) {
		blueline_occasions_drift_notice_payload( $non_valid );
	}

	// Updated unconditionally, regardless of what the classification
	// found -- a one-time notice, not a recurring nag. The NEXT request's
	// cheap hash-compare above then short-circuits until the next real
	// drift.
	$stored                       = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored                       = is_array( $stored ) ? $stored : array();
	$stored['_validated_against'] = $current_hash;

	update_option( BLUELINE_SETTINGS_OPTION, $stored );
}

/**
 * A human-readable label for a drift-notice list item: the occasion's
 * own `label` when the scope names one that still exists (a `stale`
 * classification), or the raw scope string when it doesn't (an
 * `orphaned` classification, or -- defensively -- any future scope shape
 * this function does not specifically recognise).
 *
 * @param string                              $scope     A
 *                                                        blueline_occasions_classify_acknowledgements()
 *                                                        map key, e.g.
 *                                                        `occasion:canada-day`.
 * @param array<string, array<string, mixed>> $occasions blueline_settings( 'occasions' ).
 * @return string
 */
function blueline_occasions_drift_notice_label( string $scope, array $occasions ): string {
	if ( 0 !== strpos( $scope, 'occasion:' ) ) {
		return $scope;
	}

	$id = substr( $scope, strlen( 'occasion:' ) );

	return isset( $occasions[ $id ]['label'] ) && is_string( $occasions[ $id ]['label'] ) && '' !== $occasions[ $id ]['label']
		? $occasions[ $id ]['label']
		: $scope;
}

add_action( 'admin_notices', 'blueline_render_occasions_drift_notice' );
/**
 * Render the deploy-drift notice, IF
 * blueline_occasions_maybe_revalidate_on_drift() found anything
 * non-`valid` on THIS SAME request (see
 * blueline_occasions_drift_notice_payload()'s own docblock for why a
 * same-request static, not persisted storage, is what carries that
 * here).
 *
 * A `<section>`, never a `<div>` (tests/NoticeDivGuardTest.php) -- and
 * the first admin notice in this codebase naming a variable-length list
 * (design spec §6.5): a `<ul>` inside the `<section>`, one `<li>` per
 * non-valid acknowledgement, naming its occasion's label when resolvable
 * (blueline_occasions_drift_notice_label()) and stating whether it is
 * orphaned (the occasion no longer exists) or stale (it still exists,
 * but no longer covers current reality).
 *
 * @return void
 */
function blueline_render_occasions_drift_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$classifications = blueline_occasions_drift_notice_payload();

	if ( empty( $classifications ) ) {
		return;
	}

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();
	?>
	<section class="notice notice-warning">
		<p>
			<?php
			esc_html_e(
				'Blueline detected a change to style.css or contrast-rules.json since the last check. The following accessibility acknowledgements no longer reflect current reality:',
				'blueline'
			);
			?>
		</p>
		<ul>
			<?php foreach ( $classifications as $scope => $status ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: the occasion's label (or its raw scope string, if it no longer exists), 2: why it needs attention. */
							__( '%1$s — %2$s', 'blueline' ),
							blueline_occasions_drift_notice_label( $scope, $occasions ),
							'orphaned' === $status
								? __( 'this occasion no longer exists; its acknowledgement is still on record but has nothing left to cover', 'blueline' )
								: __( 'no longer matches its acknowledged value or the current contrast rules; it needs re-review', 'blueline' )
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}
