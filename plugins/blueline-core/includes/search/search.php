<?php
/**
 * Search-results relevance: rank an exact or leading title match ahead of a
 * plain substring hit anywhere else in the post.
 *
 * Confirmed live, 2026-09-04 UX audit: searching a team's name (e.g.
 * "Lumberjacks") never visibly surfaced the team's own page -- it exists in
 * the result set (WordPress's default `s=` search already covers every
 * public, not-excluded-from-search post type, SportsPress's own CPTs
 * included), just buried at position 41 of 46, behind every sp_event post
 * whose title happens to contain the team name too (e.g. "Whalers vs
 * Lumberjacks") -- because with no post_type-specific ordering, WordPress's
 * default search falls back to plain post_date DESC, and this site creates
 * many more (frequently-dated) game posts than team posts. A visitor
 * searching a team's own name is almost always looking for that team's
 * page, not one specific past game.
 *
 * Moved from themes/blueline/inc/search.php.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'posts_orderby', 'blueline_search_title_match_first', 10, 2 );
/**
 * Prepend a relevance tier to the main search query's ORDER BY: an exact
 * title match first, a title that STARTS WITH the search term second,
 * everything else (including a mid-title or mid-content match) third --
 * unchanged relative order within each tier, since $orderby is appended
 * after the new tier expression rather than replaced.
 *
 * @param string   $orderby The existing ORDER BY clause.
 * @param WP_Query $query   The query being filtered.
 * @return string
 */
function blueline_search_title_match_first( string $orderby, WP_Query $query ): string {
	if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
		return $orderby;
	}

	$search_term = trim( (string) $query->get( 's' ) );

	if ( '' === $search_term ) {
		return $orderby;
	}

	global $wpdb;

	$like_exact = $wpdb->esc_like( $search_term );
	$like_start = $wpdb->esc_like( $search_term ) . '%';

	$tier = $wpdb->prepare(
		"CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN 0 WHEN {$wpdb->posts}.post_title LIKE %s THEN 1 ELSE 2 END ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is the table name constant, never user input; both %s placeholders below are the only variable content and go through $wpdb->prepare().
		$like_exact,
		$like_start
	);

	return $tier . ( $orderby ? ', ' . $orderby : '' );
}
