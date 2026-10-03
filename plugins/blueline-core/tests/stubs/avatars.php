<?php
/**
 * Stubs for the avatars module tests.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Stand-in for get_users(), supporting only the shape the YITH migration
	 * uses: users (from the state's 'users') whose meta_query[0] key is
	 * non-empty, ID ascending, as rows with ID and user_login.
	 *
	 * @param array $args Query args.
	 * @return object[]
	 */
	function get_users( $args = array() ) {
		$state = &blueline_test_state();
		$key   = (string) ( $args['meta_query'][0]['key'] ?? '' );
		$found = array();

		foreach ( $state['users'] as $user_id => $user ) {
			if ( '' !== $key && '' === (string) ( $state['user_meta'][ (int) $user_id ][ $key ] ?? '' ) ) {
				continue;
			}
			$found[ (int) $user_id ] = (object) array(
				'ID'         => (int) $user_id,
				'user_login' => (string) ( $user->user_login ?? '' ),
			);
		}

		ksort( $found );

		return array_values( $found );
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * Stand-in for get_user_by( 'email', ... ): matches the state's users by user_email.
	 *
	 * @param string $field Only 'email' is supported.
	 * @param string $value Email address.
	 * @return object|false
	 */
	function get_user_by( $field, $value ) {
		$state = &blueline_test_state();

		foreach ( $state['users'] as $user_id => $user ) {
			if ( 'email' === $field && (string) ( $user->user_email ?? '' ) === (string) $value ) {
				return (object) array( 'ID' => (int) $user_id );
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	/**
	 * Stand-in for wp_get_attachment_image_src(): a deterministic URL for a
	 * registered attachment, false otherwise.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param int[]|string $size       Requested size.
	 * @return array|false
	 */
	function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$width = is_array( $size ) ? (int) $size[0] : 150;

		return array( 'https://example.test/avatar-' . (int) $attachment_id . '-' . $width . '.jpg', $width, $width, true );
	}
}
