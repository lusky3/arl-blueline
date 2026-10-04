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
	 * $GLOBALS['bl_core_test_set_thumbnail'] = 'fail' returns false without
	 * writing; 'silent' returns true without writing.
	 *
	 * @param int $post         Post ID.
	 * @param int $thumbnail_id Attachment ID.
	 * @return bool
	 */
	function set_post_thumbnail( $post, $thumbnail_id ) {
		$mode = $GLOBALS['bl_core_test_set_thumbnail'] ?? 'ok';

		if ( 'fail' === $mode ) {
			return false;
		}

		if ( 'silent' === $mode ) {
			return true; // Claims success but writes nothing.
		}

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

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stand-in for sanitize_file_name(): basename, whitespace to dashes.
	 *
	 * @param string $filename File name.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return preg_replace( '/\s+/', '-', basename( (string) $filename ) );
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	/**
	 * Stand-in for wp_check_filetype_and_ext(): the extension must map to a
	 * known MIME type AND, for images, the file's real bytes (getimagesize())
	 * must agree, as core's does; otherwise ext and type are false.
	 *
	 * @param string $file     Temp file path.
	 * @param string $filename Client file name.
	 * @return array{ext: string|false, type: string|false, proper_filename: string|false}
	 */
	function wp_check_filetype_and_ext( $file, $filename ) {
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$bad = array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		);

		if ( ! isset( $map[ $ext ] ) ) {
			return $bad;
		}

		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stub; a non-image returns false.
		if ( false === $info || $info['mime'] !== $map[ $ext ] ) {
			return $bad;
		}

		return array(
			'ext'             => $ext,
			'type'            => $map[ $ext ],
			'proper_filename' => false,
		);
	}
}

if ( ! function_exists( 'media_handle_upload' ) ) {
	/**
	 * Stand-in for media_handle_upload(): records the call and returns
	 * $GLOBALS['bl_core_test_media_result'] (an attachment ID by default; set a
	 * WP_Error to simulate a failed upload).
	 *
	 * @param string $file_id $_FILES key.
	 * @param int    $post_id Parent post.
	 * @return int|WP_Error
	 */
	function media_handle_upload( $file_id, $post_id ) {
		$GLOBALS['bl_core_test_media_calls'][] = array( $file_id, (int) $post_id );

		return $GLOBALS['bl_core_test_media_result'] ?? 51;
	}
}
