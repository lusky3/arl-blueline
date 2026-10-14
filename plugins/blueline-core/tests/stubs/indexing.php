<?php
/**
 * Stubs for the indexing module tests: WooCommerce page IDs, the meta box API, revision checks
 * and core's sitemap server.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'wc_get_page_id' ) ) {
	/**
	 * Stand-in for wc_get_page_id(): $GLOBALS['bl_core_test_wc_page_ids'][ $page ], or -1 as
	 * WooCommerce returns for an unset page.
	 *
	 * @param string $page Page key (cart, checkout, myaccount, ...).
	 * @return int
	 */
	function wc_get_page_id( $page ) {
		return (int) ( $GLOBALS['bl_core_test_wc_page_ids'][ $page ] ?? -1 );
	}
}

if ( ! function_exists( 'add_meta_box' ) ) {
	/**
	 * Stand-in for add_meta_box(): records each call in $GLOBALS['bl_core_test_meta_boxes'].
	 *
	 * @param string   $id       Box ID.
	 * @param string   $title    Title.
	 * @param callable $callback Render callback.
	 * @param string   $screen   Post type.
	 * @param string   $context  Context.
	 * @param string   $priority Priority.
	 * @return void
	 */
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
		$GLOBALS['bl_core_test_meta_boxes'][] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'screen'   => $screen,
			'context'  => $context,
			'priority' => $priority,
		);
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	/**
	 * Stand-in for wp_is_post_revision(): IDs listed in $GLOBALS['bl_core_test_revision_ids'].
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Parent ID (any truthy value here), or false.
	 */
	function wp_is_post_revision( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['bl_core_test_revision_ids'] ?? array(), true ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	/**
	 * Stand-in for wp_is_post_autosave(): never an autosave in these tests.
	 *
	 * @param int $post_id Post ID.
	 * @return false
	 */
	function wp_is_post_autosave( $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core.
		return false;
	}
}

if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
	/**
	 * Stand-in for wp_sitemaps_get_server(): an object whose sitemaps_enabled() reports
	 * $GLOBALS['bl_core_test_sitemaps_enabled'] (default true).
	 *
	 * @return object
	 */
	function wp_sitemaps_get_server() {
		return new class() {
			/**
			 * Whether core sitemaps are enabled.
			 *
			 * @return bool
			 */
			public function sitemaps_enabled() {
				return $GLOBALS['bl_core_test_sitemaps_enabled'] ?? true;
			}
		};
	}
}

if ( ! function_exists( 'get_sitemap_url' ) ) {
	/**
	 * Stand-in for get_sitemap_url() (index only).
	 *
	 * @param string $name Sitemap name.
	 * @return string|false
	 */
	function get_sitemap_url( $name ) {
		return 'index' === $name ? home_url( '/wp-sitemap.xml' ) : false;
	}
}
