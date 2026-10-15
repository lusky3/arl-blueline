<?php
/**
 * Stand-ins for the WordPress lookups arl_dr_path_resolves() makes (mu-plugins/arl-domain-router.php).
 *
 * Each reads what a test put in $GLOBALS, so a lookup with nothing registered is core's "not found".
 *
 * @package blueline
 */

if ( ! function_exists( 'url_to_postid' ) ) {
	/**
	 * Stand-in for url_to_postid(): the ID a test registered for the exact URL, else 0 (core's "no post").
	 *
	 * @param string $url URL.
	 * @return int
	 */
	function url_to_postid( $url ) {
		return (int) ( $GLOBALS['arl_dr_test_post_ids'][ (string) $url ] ?? 0 );
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * Stand-in for get_page_by_path(): the page object a test registered for the path, else null.
	 *
	 * @param string $page_path Page path, no leading or trailing slash.
	 * @return object|null
	 */
	function get_page_by_path( $page_path ) {
		$page = $GLOBALS['arl_dr_test_pages'][ (string) $page_path ] ?? null;
		return is_object( $page ) ? $page : null;
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	/**
	 * Stand-in for get_taxonomies( array( 'public' => true ), 'objects' ): the taxonomy objects a test
	 * registered. Both arguments are accepted for signature parity; tests register only public taxonomies.
	 *
	 * @param array  $args   Query arguments.
	 * @param string $output Output type.
	 * @return array<int, object>
	 */
	function get_taxonomies( $args = array(), $output = 'names' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core.
		return (array) ( $GLOBALS['arl_dr_test_taxonomies'] ?? array() );
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	/**
	 * Stand-in for term_exists( $slug, $taxonomy ): a truthy array for a slug a test registered, else null.
	 *
	 * @param string $term     Term slug.
	 * @param string $taxonomy Taxonomy name.
	 * @return array|null
	 */
	function term_exists( $term, $taxonomy = '' ) {
		$slugs = (array) ( $GLOBALS['arl_dr_test_terms'][ (string) $taxonomy ] ?? array() );
		return in_array( (string) $term, $slugs, true ) ? array( 'term_id' => 1 ) : null;
	}
}
