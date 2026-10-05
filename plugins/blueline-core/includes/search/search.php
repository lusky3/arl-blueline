<?php
/**
 * Search-results relevance: rank an exact or leading title match ahead of a plain substring hit.
 *
 * Core only ranks "title contains the term", so searching a team's name buried the team's own
 * page under every sp_event whose title also contains it ("Whalers vs Lumberjacks").
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'posts_orderby', 'blueline_search_title_match_first', 10, 2 );
/**
 * Prepend a relevance tier to the main search query's ORDER BY: exact title match first, title
 * that STARTS WITH the term second, everything else third. The existing $orderby is appended,
 * so the relative order within each tier is unchanged.
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
		"CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN 0 WHEN {$wpdb->posts}.post_title LIKE %s THEN 1 ELSE 2 END ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a table name, never user input; the two %s placeholders are the only variable content and go through prepare().
		$like_exact,
		$like_start
	);

	return $tier . ( $orderby ? ', ' . $orderby : '' );
}
