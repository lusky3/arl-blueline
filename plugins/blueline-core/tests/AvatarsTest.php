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
