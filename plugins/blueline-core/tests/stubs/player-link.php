<?php
/**
 * Stubs for the player-link module tests (claim handler, ownership CLI).
 *
 * @package blueline-core
 */

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stand-in for esc_url_raw(): returns the URL unchanged.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * Stand-in for wp_list_pluck() over arrays of arrays.
	 *
	 * @param array      $input List of arrays.
	 * @param int|string $field Key to pluck.
	 * @return array
	 */
	function wp_list_pluck( $input, $field ) {
		return array_column( (array) $input, $field );
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	/**
	 * Stand-in for wp_get_referer(): $GLOBALS['bl_core_test_referer'], or false.
	 *
	 * @return string|false
	 */
	function wp_get_referer() {
		return $GLOBALS['bl_core_test_referer'] ?? false;
	}
}

if ( ! function_exists( 'kses_remove_filters' ) ) {
	/**
	 * Stand-in for kses_remove_filters(): counts calls.
	 *
	 * @return void
	 */
	function kses_remove_filters() {
		$GLOBALS['bl_core_test_kses_removed'] = ( $GLOBALS['bl_core_test_kses_removed'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Stand-in for wp_update_post(): records the call and applies post_author
	 * to the registered-posts store. Set $GLOBALS['bl_core_test_update_post_error']
	 * to a WP_Error to make it fail.
	 *
	 * @param array $postarr  Post fields; ID required.
	 * @param bool  $wp_error Whether to return a WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_update_post( $postarr, $wp_error = false ) {
		$GLOBALS['bl_core_test_updated_posts'][] = $postarr;

		if ( ! empty( $GLOBALS['bl_core_test_update_post_error'] ) ) {
			return $wp_error ? $GLOBALS['bl_core_test_update_post_error'] : 0;
		}

		$state = &blueline_test_state();
		$id    = (int) $postarr['ID'];
		if ( isset( $postarr['post_author'] ) ) {
			$state['posts'][ $id ]['author'] = (int) $postarr['post_author'];
		}

		return $id;
	}
}
