<?php
/**
 * Unit tests for blueline_store_uploaded_player_photo().
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once __DIR__ . '/../includes/player-photo/player-photo.php';

/**
 * The step between a validated upload and the player's profile: WordPress
 * stores the file as an attachment of the player, with location metadata
 * stripped on the way in, and it becomes the player's photo. The stripping
 * filter must be attached only for this one upload, whether it succeeds or
 * not, so no other upload on the site is touched by it.
 */
final class PlayerPhotoStoreTest extends TestCase {

	private const PLAYER = 100;
	private const NEW    = 51;

	/**
	 * Fresh state and call logs, and a player with no photo yet.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_core_test_media_calls']         = array();
		$GLOBALS['bl_core_test_media_strip_hooked']  = array();
		$GLOBALS['bl_core_test_deleted_attachments'] = array();

		$state                                  = &blueline_test_state();
		$state['posts'][ self::PLAYER ]['type'] = 'sp_player';
		$state['posts'][ self::NEW ]            = array(
			'type'        => 'attachment',
			'status'      => 'inherit',
			'post_parent' => self::PLAYER,
		);
	}

	/**
	 * Clear the stub switches this file sets.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['bl_core_test_media_calls'],
			$GLOBALS['bl_core_test_media_strip_hooked'],
			$GLOBALS['bl_core_test_deleted_attachments'],
			$GLOBALS['bl_core_test_media_result'],
			$GLOBALS['bl_core_test_set_thumbnail']
		);
	}

	/**
	 * Whether the stripping filter is attached right now.
	 *
	 * @return bool
	 */
	private function strip_filter_attached(): bool {
		foreach ( $GLOBALS['bl_test_hooks']['wp_handle_upload'] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( 'blueline_strip_uploaded_photo_metadata' === $callback['cb'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A good upload is stored against the player from the `player_photo`
	 * field with the strip filter in place, becomes the flagged thumbnail,
	 * and leaves the filter detached afterwards.
	 */
	public function test_a_stored_upload_becomes_the_flagged_photo(): void {
		$this->assertTrue( blueline_store_uploaded_player_photo( self::PLAYER ) );

		$this->assertSame( array( array( 'player_photo', self::PLAYER ) ), $GLOBALS['bl_core_test_media_calls'] );
		$this->assertSame( array( true ), $GLOBALS['bl_core_test_media_strip_hooked'], 'metadata is stripped from this upload' );
		$this->assertFalse( $this->strip_filter_attached(), 'and only this upload' );

		$this->assertSame( self::NEW, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( 1, get_post_meta( self::NEW, '_blueline_player_photo', true ) );
	}

	/**
	 * When WordPress refuses the file, nothing changes on the player and the
	 * filter is still detached.
	 */
	public function test_a_refused_upload_changes_nothing(): void {
		$GLOBALS['bl_core_test_media_result'] = new WP_Error( 'upload_error', 'disk full' );

		$this->assertFalse( blueline_store_uploaded_player_photo( self::PLAYER ) );

		$this->assertFalse( $this->strip_filter_attached() );
		$this->assertSame( 0, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( array(), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * Stored, but the thumbnail will not take: the new attachment is removed
	 * rather than left orphaned in the media library.
	 */
	public function test_an_attachment_that_cannot_become_the_photo_is_removed(): void {
		$GLOBALS['bl_core_test_set_thumbnail'] = 'fail';

		$this->assertFalse( blueline_store_uploaded_player_photo( self::PLAYER ) );

		$this->assertSame( array( array( self::NEW, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
		$this->assertFalse( $this->strip_filter_attached() );
	}
}
