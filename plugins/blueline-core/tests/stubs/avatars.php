<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line (the sniff anchors to the open tag); this file holds three one-line stand-ins for core classes next to the avatar/privacy function stubs.
/**
 * Stubs for the avatars and privacy module tests.
 *
 * @package blueline-core
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- the class stand-ins belong with the function stubs that return them.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- the small stand-in classes (WP_User, WP_Post, WP_Comment, a write-refusing meta row).

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Stand-in for WP_User: only the fields the avatar resolver reads.
	 */
	class WP_User {

		/**
		 * User ID.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Build a user.
		 *
		 * @param int $id User ID.
		 */
		public function __construct( $id = 0 ) {
			$this->ID = (int) $id;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stand-in for WP_Post: only the fields the avatar resolver reads.
	 */
	class WP_Post {

		/**
		 * Post author's user ID.
		 *
		 * @var int
		 */
		public $post_author = 0;

		/**
		 * Build a post.
		 *
		 * @param int $post_author Author's user ID.
		 */
		public function __construct( $post_author = 0 ) {
			$this->post_author = (int) $post_author;
		}
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Stand-in for WP_Comment: only the fields the avatar resolver reads.
	 */
	class WP_Comment {

		/**
		 * Commenting user's ID (0 for a logged-out visitor).
		 *
		 * @var int
		 */
		public $user_id = 0;

		/**
		 * Build a comment.
		 *
		 * @param int $user_id Commenting user's ID.
		 */
		public function __construct( $user_id = 0 ) {
			$this->user_id = (int) $user_id;
		}
	}
}

if ( ! class_exists( 'Blueline_Core_Test_Rejecting_Meta_Row' ) ) {
	/**
	 * A user's meta row that silently ignores writes, as update_user_meta() behaves when a plugin
	 * vetoes it through `update_user_metadata`. Assign it to $state['user_meta'][ $user_id ].
	 */
	class Blueline_Core_Test_Rejecting_Meta_Row extends ArrayObject {

		/**
		 * Ignore the write.
		 *
		 * @param mixed $key   Meta key.
		 * @param mixed $value Meta value.
		 */
		public function offsetSet( mixed $key, mixed $value ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- override signature.
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Stand-in for get_users() over the state's 'users' and 'user_meta'.
	 *
	 * Supports the shapes the plugin uses: a first meta_query clause (key,
	 * with optional value and compare of '=' or '!='), or the flat
	 * meta_key/meta_value pair; plus exclude, number and fields. As in core, a
	 * user with no meta row for the key never matches a value comparison,
	 * and with no value the clause means "has a non-empty value" (the legacy
	 * behaviour of this stub). Rows are ID ascending; fields 'ID' returns bare IDs.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	function get_users( $args = array() ) {
		$state   = &blueline_test_state();
		$clause  = $args['meta_query'][0] ?? array();
		$key     = (string) ( $clause['key'] ?? ( $args['meta_key'] ?? '' ) );
		$has_val = array_key_exists( 'value', $clause ) || isset( $args['meta_value'] );
		$value   = (string) ( $clause['value'] ?? ( $args['meta_value'] ?? '' ) );
		$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
		$exclude = array_map( 'intval', (array) ( $args['exclude'] ?? array() ) );
		$found   = array();

		foreach ( $state['users'] as $user_id => $user ) {
			$user_id = (int) $user_id;
			if ( in_array( $user_id, $exclude, true ) ) {
				continue;
			}

			if ( '' !== $key ) {
				$row = (array) ( $state['user_meta'][ $user_id ] ?? array() );
				if ( ! array_key_exists( $key, $row ) ) {
					continue;
				}
				$stored = (string) $row[ $key ];

				if ( ! $has_val ) {
					$matches = '' !== $stored;
				} elseif ( '!=' === $compare ) {
					$matches = $stored !== $value;
				} else {
					$matches = $stored === $value;
				}

				if ( ! $matches ) {
					continue;
				}
			}

			$found[ $user_id ] = (object) array(
				'ID'         => $user_id,
				'user_login' => (string) ( $user->user_login ?? '' ),
			);
		}

		ksort( $found );
		$found = array_values( $found );

		$number = (int) ( $args['number'] ?? 0 );
		if ( $number > 0 ) {
			$found = array_slice( $found, 0, $number );
		}

		if ( 'ID' === ( $args['fields'] ?? '' ) ) {
			return array_map( static fn( $row ) => $row->ID, $found );
		}

		return $found;
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

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	/**
	 * Stand-in for wp_get_attachment_url(): a deterministic URL for a
	 * registered attachment, false otherwise.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false
	 */
	function wp_get_attachment_url( $attachment_id ) {
		return 'attachment' === get_post_type( $attachment_id ) ? 'https://example.test/uploads/original-' . (int) $attachment_id . '.jpg' : false;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Stand-in for delete_user_meta(): removes the key, true when a row existed.
	 * Keys listed in $GLOBALS['bl_core_test_meta_delete_vetoed'] are refused
	 * (returns false), as a plugin vetoing the delete would.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		$state = &blueline_test_state();

		if ( in_array( $key, $GLOBALS['bl_core_test_meta_delete_vetoed'] ?? array(), true ) || ! isset( $state['user_meta'][ (int) $user_id ][ (string) $key ] ) ) {
			return false;
		}

		unset( $state['user_meta'][ (int) $user_id ][ (string) $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/**
	 * Stand-in for wp_delete_attachment(): records the call (as the
	 * player-photo tests expect) and, like core, removes the attachment from
	 * the registered-posts store and clears it as any post's featured image.
	 * IDs in $GLOBALS['bl_core_test_delete_attachment_fails'] fail (false).
	 *
	 * @param int  $post_id      Attachment ID.
	 * @param bool $force_delete Whether to bypass the trash.
	 * @return object|false
	 */
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		$GLOBALS['bl_core_test_deleted_attachments'][] = array( (int) $post_id, (bool) $force_delete );

		if ( in_array( (int) $post_id, $GLOBALS['bl_core_test_delete_attachment_fails'] ?? array(), true ) ) {
			return false;
		}

		$state = &blueline_test_state();
		foreach ( $state['posts'] as $other_id => $registered ) {
			if ( (int) ( $registered['thumbnail_id'] ?? 0 ) === (int) $post_id ) {
				unset( $state['posts'][ $other_id ]['thumbnail_id'], $state['post_meta'][ $other_id ]['_thumbnail_id'] );
			}
		}
		unset( $state['posts'][ (int) $post_id ], $state['post_meta'][ (int) $post_id ] );

		return new WP_Post();
	}
}
