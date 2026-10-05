<?php
/**
 * Unit tests for the player-photo upload handler and photo replacement.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once __DIR__ . '/../includes/player-photo/player-photo.php';

/**
 * Covers blueline_handle_player_photo_upload()'s guards, blueline_set_player_photo(),
 * blueline_replaced_player_photo_is_deletable() and the /profile-picture 301.
 */
final class PlayerPhotoTest extends TestCase {

	private const PLAYER   = 100;
	private const PREVIOUS = 50;
	private const NEW      = 51;

	/**
	 * Fake $wpdb answering the "thumbnail elsewhere" lookup.
	 *
	 * @var Blueline_Core_Test_Wpdb
	 */
	private Blueline_Core_Test_Wpdb $wpdb;

	/**
	 * Temp files created by upload_fixture(), removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Reset stores; install a fake $wpdb that reads _thumbnail_id from post meta.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$_REQUEST = array();
		$_FILES   = array();

		$GLOBALS['bl_core_test_deleted_attachments'] = array();

		$this->wpdb               = new Blueline_Core_Test_Wpdb();
		$this->wpdb->var_callback = static function ( array $args ) {
			list( $attachment_id, $exclude ) = $args;
			foreach ( blueline_test_state()['post_meta'] as $post_id => $meta ) {
				if ( (int) $post_id !== (int) $exclude && (string) ( $meta['_thumbnail_id'] ?? '' ) === (string) $attachment_id ) {
					return (string) $post_id;
				}
			}
			return null;
		};
		$GLOBALS['wpdb']          = $this->wpdb;
	}

	/**
	 * Remove globals.
	 */
	protected function tearDown(): void {
		$_REQUEST = array();
		$_FILES   = array();

		foreach ( $this->temp_files as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test temp file outside WordPress.
			}
		}
		$this->temp_files = array();

		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['bl_core_test_deleted_attachments'],
			$GLOBALS['bl_core_test_is_account_page'],
			$GLOBALS['bl_core_test_set_thumbnail'],
			$GLOBALS['bl_core_test_media_result'],
			$GLOBALS['bl_core_test_media_calls'],
			$GLOBALS['wp']
		);
	}

	/**
	 * Register an attachment.
	 *
	 * @param int  $id      Attachment ID.
	 * @param int  $parent_id post_parent.
	 * @param bool $flagged   Whether it carries the handler's flag.
	 */
	private function attachment( int $id, int $parent_id, bool $flagged ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'type'        => 'attachment',
			'status'      => 'inherit',
			'post_parent' => $parent_id,
		);
		$state['post_meta'][ $id ] = $flagged ? array( '_blueline_player_photo' => 1 ) : array();
	}

	/**
	 * The player currently shows the previous photo.
	 */
	private function player_with_previous_photo(): void {
		$state                                  = &blueline_test_state();
		$state['posts'][ self::PLAYER ]['type'] = 'sp_player';
		$state['posts'][ self::PLAYER ]['thumbnail_id']      = self::PREVIOUS;
		$state['post_meta'][ self::PLAYER ]['_thumbnail_id'] = (string) self::PREVIOUS;
	}

	/**
	 * Run the upload handler and return where it redirected.
	 *
	 * @return string
	 */
	private function upload_redirect(): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'blueline_upload_player_photo' );

		try {
			blueline_handle_player_photo_upload();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		$this->fail( 'The handler must redirect.' );
	}

	/**
	 * A flagged photo of this player, unused elsewhere, is deletable.
	 */
	public function test_flagged_previous_photo_of_this_player_is_deletable(): void {
		$this->attachment( self::PREVIOUS, self::PLAYER, true );

		$this->assertTrue( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
		$this->assertSame( array( (string) self::PREVIOUS, self::PLAYER ), $this->wpdb->prepared_args );
	}

	/**
	 * (a) An unflagged (legacy) attachment is never deleted.
	 */
	public function test_unflagged_legacy_photo_is_never_deletable(): void {
		$this->attachment( self::PREVIOUS, self::PLAYER, false );

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
	}

	/**
	 * (b) The new image itself, or no previous image, is never deleted.
	 */
	public function test_new_image_or_no_previous_image_is_not_deletable(): void {
		$this->attachment( self::NEW, self::PLAYER, true );

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::NEW, self::NEW, self::PLAYER ) );
		$this->assertFalse( blueline_replaced_player_photo_is_deletable( 0, self::NEW, self::PLAYER ) );
	}

	/**
	 * (c) A photo still used as another post's thumbnail is kept.
	 */
	public function test_photo_used_as_another_posts_thumbnail_is_kept(): void {
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		blueline_test_state()['post_meta'][200]['_thumbnail_id'] = (string) self::PREVIOUS;

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
	}

	/**
	 * (c) A failed lookup counts as "in use" (fail closed).
	 */
	public function test_failed_thumbnail_lookup_keeps_the_photo(): void {
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		$this->wpdb->last_error = 'Lost connection';

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
	}

	/**
	 * (d) A flagged photo parented to a different player is kept.
	 */
	public function test_photo_parented_to_another_player_is_kept(): void {
		$this->attachment( self::PREVIOUS, 999, true );

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
	}

	/**
	 * A previous thumbnail that is not an attachment post is kept.
	 */
	public function test_non_attachment_previous_id_is_kept(): void {
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		blueline_test_state()['posts'][ self::PREVIOUS ]['type'] = 'page';

		$this->assertFalse( blueline_replaced_player_photo_is_deletable( self::PREVIOUS, self::NEW, self::PLAYER ) );
	}

	/**
	 * Replacing a flagged photo sets the new thumbnail, flags it and force-deletes the old one.
	 */
	public function test_set_player_photo_replaces_and_deletes_a_flagged_previous_photo(): void {
		$this->player_with_previous_photo();
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		$this->attachment( self::NEW, self::PLAYER, false );

		blueline_set_player_photo( self::PLAYER, self::NEW );

		$this->assertSame( self::NEW, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( 1, get_post_meta( self::NEW, '_blueline_player_photo', true ) );
		$this->assertSame( array( array( self::PREVIOUS, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * Replacing a legacy photo flags the new one but leaves the old one alone.
	 */
	public function test_set_player_photo_keeps_a_legacy_previous_photo(): void {
		$this->player_with_previous_photo();
		$this->attachment( self::PREVIOUS, self::PLAYER, false );

		blueline_set_player_photo( self::PLAYER, self::NEW );

		$this->assertSame( self::NEW, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( 1, get_post_meta( self::NEW, '_blueline_player_photo', true ) );
		$this->assertSame( array(), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * A first photo (no previous thumbnail) deletes nothing.
	 */
	public function test_first_photo_deletes_nothing(): void {
		blueline_test_state()['posts'][ self::PLAYER ]['type'] = 'sp_player';

		blueline_set_player_photo( self::PLAYER, self::NEW );

		$this->assertSame( self::NEW, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( array(), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * A successful swap reports true.
	 */
	public function test_set_player_photo_reports_success(): void {
		$this->player_with_previous_photo();

		$this->assertTrue( blueline_set_player_photo( self::PLAYER, self::NEW ) );
	}

	/**
	 * When set_post_thumbnail() fails the new attachment is deleted, the
	 * previous (flagged, deletable) photo is kept and failure is reported.
	 */
	public function test_set_player_photo_failure_deletes_the_new_attachment_and_keeps_the_old_photo(): void {
		$this->player_with_previous_photo();
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		$this->attachment( self::NEW, self::PLAYER, false );
		$GLOBALS['bl_core_test_set_thumbnail'] = 'fail';

		$this->assertFalse( blueline_set_player_photo( self::PLAYER, self::NEW ) );

		$this->assertSame( array( array( self::NEW, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
		$this->assertSame( self::PREVIOUS, get_post_thumbnail_id( self::PLAYER ), 'The profile still shows the old photo.' );
		$this->assertEmpty( get_post_meta( self::NEW, '_blueline_player_photo', true ), 'The orphan is not flagged as ours.' );
	}

	/**
	 * A set that claims success but does not read back as the new
	 * attachment must not delete the previous photo either.
	 */
	public function test_set_player_photo_unconfirmed_thumbnail_keeps_the_old_photo(): void {
		$this->player_with_previous_photo();
		$this->attachment( self::PREVIOUS, self::PLAYER, true );
		$GLOBALS['bl_core_test_set_thumbnail'] = 'silent';

		$this->assertFalse( blueline_set_player_photo( self::PLAYER, self::NEW ) );

		$this->assertSame( array( array( self::NEW, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
		$this->assertSame( self::PREVIOUS, get_post_thumbnail_id( self::PLAYER ) );
	}

	/**
	 * Logged-out uploads die.
	 */
	public function test_upload_dies_when_logged_out(): void {
		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_handle_player_photo_upload();
	}

	/**
	 * An account with no linked player is sent back with "unlinked".
	 */
	public function test_upload_without_a_linked_player_is_refused(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['post_types']      = array( 'sp_player' );

		$this->assertStringContainsString( 'blueline_photo=unlinked', $this->upload_redirect() );
	}

	/**
	 * A name-claimed link (no Player role / not post_author) cannot change the photo.
	 */
	public function test_upload_by_a_name_claimed_link_is_refused(): void {
		$state                              = &blueline_test_state();
		$state['current_user_id']           = 5;
		$state['post_types']                = array( 'sp_player' );
		$state['users'][5]                  = (object) array( 'roles' => array( 'customer' ) );
		$state['posts'][ self::PLAYER ]     = array(
			'type'   => 'sp_player',
			'author' => 1,
		);
		$state['post_meta'][ self::PLAYER ] = array( 'sp_user' => '5' );

		$this->assertStringContainsString( 'blueline_photo=not_owner', $this->upload_redirect() );
	}

	/**
	 * A verified owner without a file is sent back without a status (nothing uploaded).
	 */
	public function test_verified_owner_without_a_file_is_sent_back(): void {
		$state                              = &blueline_test_state();
		$state['current_user_id']           = 5;
		$state['post_types']                = array( 'sp_player' );
		$state['users'][5]                  = (object) array( 'roles' => array( 'sp_player' ) );
		$state['posts'][ self::PLAYER ]     = array(
			'type'   => 'sp_player',
			'author' => 5,
		);
		$state['post_meta'][ self::PLAYER ] = array( 'sp_user' => '5' );

		$this->assertSame( 'https://example.test/account/player-profile/', $this->upload_redirect() );
	}

	/**
	 * The legacy /account/profile-picture/ endpoint 301s to Player Profile.
	 */
	public function test_profile_picture_endpoint_redirects_to_player_profile(): void {
		$GLOBALS['bl_core_test_is_account_page'] = true;
		$GLOBALS['wp']                           = (object) array( 'query_vars' => array( 'profile-picture' => '' ) );

		try {
			blueline_redirect_profile_picture_endpoint();
			$this->fail( 'Expected a redirect.' );
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'player-profile', $e->location );
		}
	}

	/**
	 * Other account pages are left alone: no redirect is issued for an unrelated endpoint.
	 */
	public function test_other_account_pages_are_not_redirected(): void {
		$GLOBALS['bl_core_test_is_account_page'] = true;
		$GLOBALS['wp']                           = (object) array( 'query_vars' => array( 'orders' => '' ) );

		$location = null;
		try {
			blueline_redirect_profile_picture_endpoint();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			$location = $e->location;
		}

		$this->assertNull( $location, 'No redirect expected.' );
	}

	/**
	 * Outside the account page the endpoint check does not even look at the query vars.
	 */
	public function test_profile_picture_query_var_is_ignored_off_the_account_page(): void {
		$GLOBALS['bl_core_test_is_account_page'] = false;
		$GLOBALS['wp']                           = (object) array( 'query_vars' => array( 'profile-picture' => '' ) );

		$location = null;
		try {
			blueline_redirect_profile_picture_endpoint();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			$location = $e->location;
		}

		$this->assertNull( $location, 'No redirect expected.' );
	}
}
