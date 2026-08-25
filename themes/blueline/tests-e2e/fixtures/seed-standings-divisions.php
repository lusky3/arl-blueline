<?php
/**
 * E2E fixture: seeds one sp_season term, one sp_event tagged with it (so
 * blueline_homepage_active_event_season_term_id() resolves it), and two
 * sp_table posts titled to match it -- run via `wp eval-file` inside the
 * sportspress-sandbox container by .github/workflows/e2e.yml. Never
 * shipped to, or run against, a real site.
 *
 * The WordPress PHP API is used directly (wp_insert_term()/wp_insert_post()),
 * not the `wp term create`/`wp post create` WP-CLI subcommands: confirmed
 * live against this exact environment that `wp term create sp_season ...`
 * fails outright
 * with "Invalid taxonomy", even though sp_season is real and already
 * populated by the sandbox's own fixture generator (config/scripts/
 * generate-extra-data.php, baked into the image, which seeds it the same
 * way this file does -- through the PHP API via `wp eval-file`, never the
 * CLI subcommand). Whatever bootstrap context WP-CLI's own term/post-create
 * commands check taxonomy/post-type registration in, it isn't the same one
 * a normal WordPress PHP call runs in.
 *
 * This script never renders to a browser -- output below is CI console
 * text (stdout/stderr in a throwaway container), not HTML, so the escaping
 * and WP_Filesystem sniffs WordPress-standard enforces for theme/plugin
 * code don't apply; each is disabled at the specific line with a reason,
 * per this project's own no-blanket-ignoreFile rule.
 *
 * @package blueline
 */

$season_label = getenv( 'BLUELINE_E2E_SEASON_LABEL' ) ? getenv( 'BLUELINE_E2E_SEASON_LABEL' ) : 'E2E Test Season';
$season_slug  = getenv( 'BLUELINE_E2E_SEASON_SLUG' ) ? getenv( 'BLUELINE_E2E_SEASON_SLUG' ) : 'e2e-test-season';

$existing       = get_term_by( 'slug', $season_slug, 'sp_season' );
$season_term_id = $existing ? (int) $existing->term_id : 0;

if ( ! $season_term_id ) {
	$season_term = wp_insert_term( $season_label, 'sp_season', array( 'slug' => $season_slug ) );

	if ( is_wp_error( $season_term ) ) {
		fwrite( STDERR, 'Failed to create sp_season term: ' . $season_term->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CI console output in a throwaway container, not a file this theme ships or a site ever runs.
		exit( 1 );
	}

	$season_term_id = (int) $season_term['term_id'];
}

$event_id = wp_insert_post(
	array(
		'post_type'   => 'sp_event',
		'post_title'  => 'E2E Test Event',
		'post_status' => 'publish',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	fwrite( STDERR, 'Failed to create sp_event: ' . $event_id->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see the earlier fwrite() call's own reason above.
	exit( 1 );
}

wp_set_object_terms( $event_id, array( $season_term_id ), 'sp_season' );

foreach ( array( 1, 2 ) as $division ) {
	$table_id = wp_insert_post(
		array(
			'post_type'   => 'sp_table',
			'post_title'  => "Division {$division} | {$season_label}",
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $table_id ) ) {
		fwrite( STDERR, "Failed to create sp_table for division {$division}: " . $table_id->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see the earlier fwrite() call's own reason above.
		exit( 1 );
	}
}

echo "Seeded sp_season term {$season_term_id}, event {$event_id}, and 2 sp_table divisions.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CI console output in a throwaway container, not HTML a browser ever renders.
