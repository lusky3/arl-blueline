<?php
/**
 * Unit tests for the custom-avatar filter.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/avatars/avatars.php';

/**
 * Covers blueline_pre_get_avatar_data() and blueline_resolve_avatar_user_id().
 */
final class AvatarsTest extends TestCase {

	/**
	 * Reset stores; user 5 has avatar attachment 300.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$state                                       = &blueline_test_state();
		$state['users'][5]                           = (object) array( 'user_email' => 'five@example.test' );
		$state['user_meta'][5]['blueline_avatar_id'] = '300';
		$state['posts'][300]                         = array( 'type' => 'attachment' );
	}

	/**
	 * A user with a stored avatar gets its URL at the requested size.
	 */
	public function test_stored_avatar_replaces_gravatar(): void {
		$args = blueline_pre_get_avatar_data( array( 'size' => 64 ), 5 );

		$this->assertSame( 'https://example.test/avatar-300-64.jpg', $args['url'] );
		$this->assertTrue( $args['found_avatar'] );
	}

	/**
	 * No size falls back to 96, the core default.
	 */
	public function test_missing_size_defaults_to_96(): void {
		$this->assertSame( 'https://example.test/avatar-300-96.jpg', blueline_pre_get_avatar_data( array(), 5 )['url'] );
	}

	/**
	 * An email resolves to its user.
	 */
	public function test_email_resolves_to_the_user(): void {
		$this->assertSame( 5, blueline_resolve_avatar_user_id( 'five@example.test' ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( 'nobody@example.test' ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( 'not an email' ) );
	}

	/**
	 * A numeric ID (int or numeric string) is the user ID; zero resolves to none.
	 */
	public function test_numeric_id_resolves_to_itself(): void {
		$this->assertSame( 5, blueline_resolve_avatar_user_id( 5 ) );
		$this->assertSame( 5, blueline_resolve_avatar_user_id( '5' ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( 0 ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( '0' ) );
	}

	/**
	 * A WP_User resolves to its ID.
	 */
	public function test_wp_user_resolves_to_its_id(): void {
		$this->assertSame( 5, blueline_resolve_avatar_user_id( new WP_User( 5 ) ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( new WP_User( 0 ) ) );
	}

	/**
	 * A WP_Post resolves to its author, so a post's byline avatar is the author's.
	 */
	public function test_wp_post_resolves_to_its_author(): void {
		$this->assertSame( 5, blueline_resolve_avatar_user_id( new WP_Post( 5 ) ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( new WP_Post( 0 ) ) );
	}

	/**
	 * A WP_Comment resolves to its user; a logged-out commenter (user_id 0) to none.
	 */
	public function test_wp_comment_resolves_to_its_user(): void {
		$this->assertSame( 5, blueline_resolve_avatar_user_id( new WP_Comment( 5 ) ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( new WP_Comment( 0 ) ) );
	}

	/**
	 * Anything else (a Gravatar hash, an unrelated object, null, an array) resolves to none.
	 */
	public function test_unsupported_shapes_resolve_to_none(): void {
		$this->assertSame( 0, blueline_resolve_avatar_user_id( 'd41d8cd98f00b204e9800998ecf8427e' ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( new stdClass() ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( null ) );
		$this->assertSame( 0, blueline_resolve_avatar_user_id( array( 5 ) ) );
	}

	/**
	 * The filter gives an avatar to every object shape that resolves to the user,
	 * and leaves a logged-out commenter on Gravatar.
	 */
	public function test_filter_serves_the_avatar_for_object_shapes(): void {
		foreach ( array( new WP_User( 5 ), new WP_Post( 5 ), new WP_Comment( 5 ), 'five@example.test', '5' ) as $shape ) {
			$this->assertSame( 'https://example.test/avatar-300-48.jpg', blueline_pre_get_avatar_data( array( 'size' => 48 ), $shape )['url'] );
		}

		$this->assertSame( array( 'size' => 48 ), blueline_pre_get_avatar_data( array( 'size' => 48 ), new WP_Comment( 0 ) ) );
	}

	/**
	 * A pointer at something that is not an attachment (a page ID) is ignored.
	 */
	public function test_pointer_at_a_non_attachment_falls_through(): void {
		$state                                       = &blueline_test_state();
		$state['posts'][400]                         = array( 'type' => 'page' );
		$state['user_meta'][5]['blueline_avatar_id'] = '400';

		$this->assertSame( array( 'size' => 32 ), blueline_pre_get_avatar_data( array( 'size' => 32 ), 5 ) );
	}

	/**
	 * Guards the test double the migration suite relies on: get_users() honours
	 * the production meta_query's value and compare, not just "key is set".
	 */
	public function test_get_users_stub_honours_value_and_compare(): void {
		// phpcs:disable WordPress.DB.SlowDBQuery -- arguments for the get_users() test double; no query runs.
		$state                                      = &blueline_test_state();
		$state['users'][6]                          = (object) array( 'user_login' => 'six' );
		$state['users'][7]                          = (object) array( 'user_login' => 'seven' );
		$state['user_meta'][5]['yith-wcmap-avatar'] = '300';
		$state['user_meta'][6]['yith-wcmap-avatar'] = '';
		// User 7 has no row for the key at all.

		$non_empty = array(
			'meta_query' => array(
				array(
					'key'     => 'yith-wcmap-avatar',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		$this->assertSame( array( 5 ), array_column( get_users( $non_empty ), 'ID' ) );

		$non_empty['meta_query'][0]['compare'] = '=';
		$non_empty['meta_query'][0]['value']   = '';
		$this->assertSame( array( 6 ), array_column( get_users( $non_empty ), 'ID' ) );

		$this->assertSame(
			array( 5 ),
			get_users(
				array(
					'meta_key'   => 'blueline_avatar_id',
					'meta_value' => '300',
					'fields'     => 'ID',
				)
			)
		);
		$this->assertSame(
			array(),
			get_users(
				array(
					'meta_key'   => 'blueline_avatar_id',
					'meta_value' => '300',
					'exclude'    => array( 5 ),
					'fields'     => 'ID',
				)
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery
	}

	/**
	 * A URL set earlier is never overridden.
	 */
	public function test_an_earlier_url_wins(): void {
		$args = array( 'url' => 'https://other.test/a.png' );

		$this->assertSame( $args, blueline_pre_get_avatar_data( $args, 5 ) );
	}

	/**
	 * Users without the meta, or whose attachment is gone, fall through untouched.
	 */
	public function test_missing_meta_or_deleted_attachment_falls_through(): void {
		$this->assertSame( array( 'size' => 32 ), blueline_pre_get_avatar_data( array( 'size' => 32 ), 6 ) );

		unset( blueline_test_state()['posts'][300] );
		$this->assertSame( array( 'size' => 32 ), blueline_pre_get_avatar_data( array( 'size' => 32 ), 5 ) );
	}

	/**
	 * The meta key is unchanged by the move.
	 */
	public function test_meta_key_is_unchanged(): void {
		$this->assertSame( 'blueline_avatar_id', BLUELINE_AVATAR_META_KEY );
	}
}
