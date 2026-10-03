<?php
/**
 * Stubs for the player-photo module tests.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	/**
	 * Stand-in for set_post_thumbnail(): writes both the registered-posts
	 * store (read by get_post_thumbnail_id()) and the _thumbnail_id meta.
	 *
	 * @param int $post         Post ID.
	 * @param int $thumbnail_id Attachment ID.
	 * @return bool
	 */
	function set_post_thumbnail( $post, $thumbnail_id ) {
		$state = &blueline_test_state();

		$state['posts'][ (int) $post ]['thumbnail_id']      = (int) $thumbnail_id;
		$state['post_meta'][ (int) $post ]['_thumbnail_id'] = (string) $thumbnail_id;

		return true;
	}
}

if ( ! function_exists( 'is_account_page' ) ) {
	/**
	 * Stand-in for WooCommerce's is_account_page(): $GLOBALS['bl_core_test_is_account_page'].
	 *
	 * @return bool
	 */
	function is_account_page() {
		return ! empty( $GLOBALS['bl_core_test_is_account_page'] );
	}
}

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * Stand-in for WooCommerce's wc_get_account_endpoint_url().
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://example.test/account/' . $endpoint . '/';
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/**
	 * Stand-in for wp_delete_attachment(): records the call.
	 *
	 * @param int  $post_id      Attachment ID.
	 * @param bool $force_delete Whether to bypass the trash.
	 * @return bool
	 */
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		$GLOBALS['bl_core_test_deleted_attachments'][] = array( (int) $post_id, (bool) $force_delete );

		return true;
	}
}
