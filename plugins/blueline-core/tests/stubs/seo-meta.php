<?php
/**
 * Stubs for the seo-meta module tests.
 *
 * Per-test inputs live in blueline_test_state() (reset by blueline_test_reset_state()):
 * - posts[<id>]['excerpt'|'content'|'protected'] feed get_post() / post_password_required().
 * - queried_object_id is the "current post" get_the_ID() reports.
 * - gmt_offset is the site's offset from UTC in seconds (default 0); gmt_unresolvable makes
 *   get_gmt_from_date() return '' (an event whose date cannot be resolved).
 * - theme_mods[<name>] feeds get_theme_mod() (e.g. 'custom_logo' => an attachment id).
 * - $GLOBALS['shortcode_tags'] is the registered-shortcode registry strip_shortcodes() honours.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	/**
	 * Stand-in for get_gmt_from_date(): local time minus the state's `gmt_offset` seconds
	 * (default 0, i.e. the site timezone is UTC).
	 *
	 * @param string $date_string Local date/time string.
	 * @return string GMT 'Y-m-d H:i:s', or '' when the input does not parse (or the state says so).
	 */
	function get_gmt_from_date( $date_string ) {
		$timestamp = strtotime( (string) $date_string . ' +0000' );

		if ( false === $timestamp || ! empty( blueline_test_state()['gmt_unresolvable'] ) ) {
			return '';
		}

		$offset = (int) ( blueline_test_state()['gmt_offset'] ?? 0 );

		return gmdate( 'Y-m-d H:i:s', $timestamp - $offset );
	}
}

if ( ! function_exists( 'sp_get_status' ) ) {
	/**
	 * Stand-in for SportsPress's sp_get_status(): its presence is what gates the sp_event branches.
	 *
	 * @return string
	 */
	function sp_get_status() {
		return 'ok';
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	/**
	 * Stand-in for get_the_ID(): the state's queried object id.
	 *
	 * @return int
	 */
	function get_the_ID() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WP core name.
		return (int) ( blueline_test_state()['queried_object_id'] ?? 0 );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stand-in for get_post(): an object with the registered post's fields, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	function get_post( $post_id = 0 ) {
		$state = blueline_test_state();
		$id    = (int) $post_id;

		if ( ! isset( $state['posts'][ $id ] ) ) {
			return null;
		}

		$post = $state['posts'][ $id ];

		return (object) array(
			'ID'            => $id,
			'post_title'    => (string) ( $post['title'] ?? '' ),
			'post_excerpt'  => (string) ( $post['excerpt'] ?? '' ),
			'post_content'  => (string) ( $post['content'] ?? '' ),
			'post_password' => empty( $post['protected'] ) ? '' : str_repeat( 'x', 8 ),
		);
	}
}

if ( ! function_exists( 'post_password_required' ) ) {
	/**
	 * Stand-in for post_password_required(): true for a post registered as `protected`.
	 *
	 * @param object|int $post Post object or ID.
	 * @return bool
	 */
	function post_password_required( $post = null ) {
		return '' !== (string) ( is_object( $post ) ? $post->post_password : '' );
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	/**
	 * Stand-in for strip_shortcodes(): like core, removes only REGISTERED shortcodes
	 * ($GLOBALS['shortcode_tags']) -- `[tag ...]`, `[tag]...[/tag]` (enclosed content too) and
	 * stray `[/tag]` markers; unregistered `[tags]` stay.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	function strip_shortcodes( $content ) {
		$content = (string) $content;
		$tags    = array_keys( (array) ( $GLOBALS['shortcode_tags'] ?? array() ) );

		if ( ! $tags ) {
			return $content;
		}

		$names   = implode( '|', array_map( 'preg_quote', $tags ) );
		$content = (string) preg_replace( '/\[(' . $names . ')(?:\s[^\]]*)?\].*?\[\/\1\]/s', '', $content );

		return (string) preg_replace( '/\[\/?(?:' . $names . ')(?:\s[^\]]*)?\]/', '', $content );
	}
}

if ( ! function_exists( 'get_theme_mod' ) ) {
	/**
	 * Stand-in for get_theme_mod(): the value registered under the state's `theme_mods`.
	 *
	 * @param string $name          Theme mod name.
	 * @param mixed  $default_value Returned when the mod is not set.
	 * @return mixed
	 */
	function get_theme_mod( $name, $default_value = false ) {
		return blueline_test_state()['theme_mods'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	/**
	 * Stand-in for wp_trim_words(): the same word-count trimming core does for word-based locales.
	 *
	 * @param string      $text      Text.
	 * @param int         $num_words Words to keep.
	 * @param string|null $more      Suffix when trimmed (default an ellipsis entity, as core).
	 * @return string
	 */
	function wp_trim_words( $text, $num_words = 55, $more = null ) {
		$more  = null === $more ? '&hellip;' : (string) $more;
		$words = preg_split( '/[\n\r\t ]+/', wp_strip_all_tags( (string) $text ), (int) $num_words + 1, PREG_SPLIT_NO_EMPTY );

		if ( count( $words ) > $num_words ) {
			array_pop( $words );

			return implode( ' ', $words ) . $more;
		}

		return implode( ' ', $words );
	}
}

if ( ! function_exists( 'get_pagenum_link' ) ) {
	/**
	 * Stand-in for get_pagenum_link().
	 *
	 * @param int $page_number Page number.
	 * @return string
	 */
	function get_pagenum_link( $page_number = 1 ) {
		return 'https://example.test/archive/' . ( (int) $page_number > 1 ? 'page/' . (int) $page_number . '/' : '' );
	}
}
