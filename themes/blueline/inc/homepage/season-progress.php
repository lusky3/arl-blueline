<?php
/**
 * Homepage: where the season is. The schedule's season label, games this
 * week and the season week number the hero copy reads.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Season label for the sp_event driving the schedule right now (e.g.
 * "S2026"), the season being PLAYED, read from the sp_season taxonomy.
 *
 * @param int $event_id sp_event post ID.
 * @return string Empty string if unavailable.
 */
function blueline_homepage_event_season_label( int $event_id ): string {
	if ( ! $event_id || ! taxonomy_exists( 'sp_season' ) ) {
		return '';
	}

	$names = wp_get_object_terms( $event_id, 'sp_season', array( 'fields' => 'names' ) );

	if ( is_wp_error( $names ) || empty( $names ) ) {
		return '';
	}

	return (string) $names[0];
}

/**
 * Count of games (post_status publish OR future) starting within the next
 * 7 days. Upcoming games on this site are 'future', not 'publish'; see
 * the docblock on BLUELINE_PUBLISHED_STATUS in inc/season-state.php. A
 * publish-only query here would silently under-count exactly the way
 * Task 6's first pass did.
 *
 * Drives the in_season headline's "{n} games this week". Postponed and
 * cancelled games are not counted: they are not being played.
 *
 * @param int|null $now Unix timestamp to evaluate against; null means now.
 *                      Resolved through blueline_season_state_moment(), so
 *                      the bounds are site-local like post_date.
 * @return int
 */
function blueline_homepage_games_this_week( ?int $now = null ): int {
	if ( ! post_type_exists( 'sp_event' ) ) {
		return 0;
	}

	list( $now_mysql, $now_ts ) = blueline_season_state_moment( $now );

	$query = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
				array(
					'column' => 'post_date',
					'after'  => $now_mysql,
					'before' => gmdate( 'Y-m-d H:i:s', $now_ts + 7 * DAY_IN_SECONDS ),
				),
			),
			'meta_query'     => blueline_season_state_live_event_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by post_type sp_event and a 7-day date range.
		)
	);

	return count( $query->posts );
}

/**
 * Pure: which week of the season $now_mysql falls in, counting 7-day spans
 * from the day of the season's first game (week 1 is that day and the six
 * after it). Dates only, so a late first game does not push the week
 * boundary to a late hour.
 *
 * @param string $first_event_mysql The first game's site-local post_date.
 * @param string $now_mysql         The moment to evaluate, site-local.
 * @return int The week number, or 0 before the first game or on a bad date.
 */
function blueline_homepage_season_week_number( string $first_event_mysql, string $now_mysql ): int {
	$first = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $first_event_mysql, 0, 10 ) );
	$today = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $now_mysql, 0, 10 ) );

	if ( ! $first || ! $today || $today < $first ) {
		return 0;
	}

	return intdiv( (int) $first->diff( $today )->days, 7 ) + 1;
}

/**
 * The current week of the season $event_id belongs to, counted from that
 * sp_season's first game that went ahead (see
 * blueline_homepage_season_week_number()).
 *
 * @param int      $event_id An sp_event in the season being played.
 * @param int|null $now      Unix timestamp to evaluate against; null means now.
 * @return int The week number, or 0 when it cannot be worked out.
 */
function blueline_homepage_season_week( int $event_id, ?int $now = null ): int {
	if ( ! $event_id || ! taxonomy_exists( 'sp_season' ) ) {
		return 0;
	}

	$season_ids = wp_get_object_terms( $event_id, 'sp_season', array( 'fields' => 'ids' ) );

	if ( is_wp_error( $season_ids ) || empty( $season_ids ) ) {
		return 0;
	}

	$first = get_posts(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one season term, one row.
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => (int) $season_ids[0],
				),
			),
			'meta_query'     => blueline_season_state_live_event_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one season term, one row.
		)
	);

	$first_post = ! empty( $first ) ? get_post( (int) $first[0] ) : null;

	if ( ! $first_post ) {
		return 0;
	}

	list( $now_mysql ) = blueline_season_state_moment( $now );

	return blueline_homepage_season_week_number( (string) $first_post->post_date, $now_mysql );
}
