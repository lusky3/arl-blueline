<?php
/**
 * E2E fixture: seeds one sp_season term, retags whichever sp_event
 * blueline_homepage_active_event_season_term_id() will actually resolve
 * (see the comment on that below for why a brand new event isn't enough),
 * and creates two sp_table posts titled to match it -- run via
 * `wp eval-file` inside the sportspress-sandbox container by
 * .github/workflows/e2e.yml. Never shipped to, or run against, a real site.
 *
 * The WordPress PHP API is used directly (wp_insert_term()/wp_insert_post()),
 * not the `wp term create`/`wp post create` WP-CLI subcommands: confirmed
 * live against this exact environment that `wp term create sp_season ...`
 * fails outright with "Invalid taxonomy".
 *
 * That turned out to be true of wp_insert_term() too, live-confirmed on a
 * second run: SportsPress's own SP_Post_types::register_taxonomies()
 * (ThemeBoy/SportsPress, includes/class-sp-post-types.php) only registers
 * sp_season when `apply_filters( 'sportspress_has_seasons', true )` resolves
 * truthy, and something in this sandbox image's exact plugin/settings state
 * resolves it false -- a real difference from production, not this fixture
 * doing anything wrong. Rather than depend on understanding why (a
 * throwaway CI environment's exact plugin configuration, not this theme's
 * concern), this file registers sp_season itself, with the same object
 * types SportsPress's own core class registers it against, whenever it
 * isn't already registered -- idempotent, and harmless if core's own
 * registration is merely running on a later hook this file's `wp eval-file`
 * invocation runs before.
 *
 * This script never renders to a browser -- output below is CI console
 * text (stdout/stderr in a throwaway container), not HTML, so the escaping
 * and WP_Filesystem sniffs WordPress-standard enforces for theme/plugin
 * code don't apply; each is disabled at the specific line with a reason,
 * per this project's own no-blanket-ignoreFile rule.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! taxonomy_exists( 'sp_season' ) ) {
	register_taxonomy(
		'sp_season',
		array( 'sp_event', 'sp_calendar', 'sp_team', 'sp_table', 'sp_player', 'sp_list', 'sp_staff' ),
		array(
			'label'        => 'Seasons',
			'public'       => true,
			'hierarchical' => true,
			'rewrite'      => array( 'slug' => 'season' ),
		)
	);
}

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

/*
 * Retag whichever sp_event blueline_homepage_active_event_season_term_id()
 * (inc/homepage-modules.php) will ACTUALLY resolve, rather than creating a
 * brand new one and assuming it wins. That function's own algorithm --
 * mirrored exactly below, not called directly, since it only returns the
 * resolved TERM id, not the event id backing it -- is: prefer
 * blueline_season_state_data()['next_event_id'] if set, else the most
 * recently published sp_event. Live-confirmed this matters: this sandbox
 * image's own SportsPress sample data (config/scripts/generate-extra-data.php,
 * baked into the image) already seeds sp_event posts, and one of those was
 * winning the resolution over a freshly wp_insert_post()'d "E2E Test
 * Event" every time -- the tab strip rendered zero divisions as a result,
 * not because the fixture's own posts were wrong, but because the theme
 * was resolving an entirely different, unrelated event's season instead.
 */
$state_data = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();
$event_id   = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;

if ( ! $event_id ) {
	$recent = get_posts(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$event_id = ! empty( $recent ) ? (int) $recent[0] : 0;
}

if ( ! $event_id ) {
	// No sp_event exists at all (shouldn't happen given this sandbox's own
	// sample data, but stay robust) -- create one as a fallback so there is
	// still something to retag.
	$event_id = wp_insert_post(
		array(
			'post_type'   => 'sp_event',
			'post_title'  => 'E2E Test Event',
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $event_id ) ) {
		fwrite( STDERR, 'Failed to create a fallback sp_event: ' . $event_id->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see the earlier fwrite() call's own reason above.
		exit( 1 );
	}
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
