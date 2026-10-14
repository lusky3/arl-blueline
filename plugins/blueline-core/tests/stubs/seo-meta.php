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
 * - request[] describes the non-singular request: 'home', 'archive', 'search', '404' (bools),
 *   'tax' (the taxonomy slug of a term archive), 'paged' (int) and 'object' (the queried term).
 * - posts[<id>]['width'|'height'|'sizes'] describe an attachment image (wp_get_attachment_metadata());
 *   post_type_archives[<type>] is that type's archive URL, post_type_labels[<type>] its plural label,
 *   term_links[<id>] a term's URL.
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
	 * Stand-in for get_pagenum_link(): like core it keeps the request's other query args (the
	 * state's request['query'], e.g. 'utm=x') on the link.
	 *
	 * @param int $page_number Page number.
	 * @return string
	 */
	function get_pagenum_link( $page_number = 1 ) {
		$query = (string) ( blueline_test_state()['request']['query'] ?? '' );

		return 'https://example.test/archive/' . ( (int) $page_number > 1 ? 'page/' . (int) $page_number . '/' : '' ) . ( '' !== $query ? '?' . $query : '' );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stand-in for wp_date(): the timestamp formatted in the site timezone (wp_timezone()), as core does.
	 *
	 * @param string   $format    PHP date format.
	 * @param int|null $timestamp Unix timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ) {
		$date = new DateTimeImmutable( '@' . (int) ( null === $timestamp ? time() : $timestamp ) );

		return $date->setTimezone( wp_timezone() )->format( (string) $format );
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Stand-in for wp_get_attachment_metadata(): the full-size `width`/`height` a test gave the
	 * attachment (posts[<id>]['width'|'height'], plus an optional 'sizes' map), or false for none.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|false
	 */
	function wp_get_attachment_metadata( $attachment_id = 0 ) {
		$post = blueline_test_state()['posts'][ (int) $attachment_id ] ?? array();

		if ( empty( $post['width'] ) ) {
			return false;
		}

		return array(
			'width'  => (int) $post['width'],
			'height' => (int) ( $post['height'] ?? 0 ),
			'sizes'  => (array) ( $post['sizes'] ?? array() ),
		);
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Stand-in for get_post_type_object(): an object with `labels->name` from the state's
	 * post_type_labels[<type>], or null.
	 *
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_post_type_object( $post_type ) {
		$label = blueline_test_state()['post_type_labels'][ $post_type ] ?? null;

		return null === $label ? null : (object) array( 'labels' => (object) array( 'name' => $label ) );
	}
}

if ( ! function_exists( 'is_home' ) ) {
	/**
	 * Stand-in for is_home() (the blog posts index).
	 *
	 * @return bool
	 */
	function is_home() {
		return ! empty( blueline_test_state()['request']['home'] );
	}
}

if ( ! function_exists( 'is_archive' ) ) {
	/**
	 * Stand-in for is_archive(): any archive, and every term archive.
	 *
	 * @return bool
	 */
	function is_archive() {
		$request = blueline_test_state()['request'] ?? array();

		return ! empty( $request['archive'] ) || ! empty( $request['tax'] );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	/**
	 * Stand-in for is_tax().
	 *
	 * @param string $taxonomy Optional taxonomy to match.
	 * @return bool
	 */
	function is_tax( $taxonomy = '' ) {
		$current = (string) ( blueline_test_state()['request']['tax'] ?? '' );

		return '' !== $current && ( '' === $taxonomy || $current === $taxonomy );
	}
}

if ( ! function_exists( 'is_search' ) ) {
	/**
	 * Stand-in for is_search().
	 *
	 * @return bool
	 */
	function is_search() {
		return ! empty( blueline_test_state()['request']['search'] );
	}
}

if ( ! function_exists( 'is_404' ) ) {
	/**
	 * Stand-in for is_404().
	 *
	 * @return bool
	 */
	function is_404() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WP core name.
		return ! empty( blueline_test_state()['request']['404'] );
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	/**
	 * Stand-in for get_query_var(): only `paged` is modelled.
	 *
	 * @param string $var_name      Query var.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	function get_query_var( $var_name, $default_value = '' ) {
		return 'paged' === $var_name ? (int) ( blueline_test_state()['request']['paged'] ?? 0 ) : $default_value;
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/**
	 * Stand-in for get_queried_object(): the request's `object`.
	 *
	 * @return object|null
	 */
	function get_queried_object() {
		return blueline_test_state()['request']['object'] ?? null;
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	/**
	 * Stand-in for get_term_link().
	 *
	 * @param object|int $term Term.
	 * @return string
	 */
	function get_term_link( $term ) {
		$id = is_object( $term ) ? (int) $term->term_id : (int) $term;

		return blueline_test_state()['term_links'][ $id ] ?? 'https://example.test/term/' . $id . '/';
	}
}

if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	/**
	 * Stand-in for get_post_type_archive_link(): false unless the state registers an archive URL.
	 *
	 * @param string $post_type Post type.
	 * @return string|false
	 */
	function get_post_type_archive_link( $post_type ) {
		return blueline_test_state()['post_type_archives'][ $post_type ] ?? false;
	}
}
