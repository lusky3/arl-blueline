<?php
/**
 * Stubs for the season module (includes/season/season.php).
 *
 * @package blueline-core
 */

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_get_object_terms() over the shared term store: the terms a
	 * test associated with a post via blueline_test_set_post_terms(), in that order, as IDs with
	 * `'fields' => 'ids'` and as term objects otherwise.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $taxonomy  Taxonomy.
	 * @param array  $args      Only `fields` is honoured.
	 * @return array<int, int|object>|WP_Error
	 */
	function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
		$terms = wp_get_post_terms( (int) $object_id, (string) $taxonomy );

		if ( is_wp_error( $terms ) || 'ids' !== ( $args['fields'] ?? '' ) ) {
			return $terms;
		}

		return array_map(
			static function ( $term ) {
				return (int) $term->term_id;
			},
			$terms
		);
	}
}
