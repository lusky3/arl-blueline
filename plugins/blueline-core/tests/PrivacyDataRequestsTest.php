<?php
/**
 * Unit tests for the personal-data exporters and erasers in
 * includes/privacy/privacy.php (avatar pointer + image, player link + photo).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/privacy/privacy.php';

/**
 * Covers the export and erase callbacks and their registration filters.
 */
final class PrivacyDataRequestsTest extends TestCase {

	private const USER   = 5;
	private const OTHER  = 6;
	private const AVATAR = 300;
	private const PLAYER = 100;
	private const PHOTO  = 51;

	/**
	 * Fake $wpdb answering the "thumbnail elsewhere" lookup from post meta.
	 *
	 * @var Blueline_Core_Test_Wpdb
	 */
	private Blueline_Core_Test_Wpdb $wpdb;

	/**
	 * Reset stores. User 5 (five@example.test) and user 6 exist.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_core_test_deleted_attachments']     = array();
		$GLOBALS['bl_core_test_meta_delete_vetoed']      = array();
		$GLOBALS['bl_core_test_delete_attachment_fails'] = array();

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

		$state             = &blueline_test_state();
		$state['users'][5] = (object) array(
			'user_login' => 'five',
			'user_email' => 'five@example.test',
		);
		$state['users'][6] = (object) array(
			'user_login' => 'six',
			'user_email' => 'six@example.test',
		);
	}

	/**
	 * Remove globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['bl_core_test_deleted_attachments'],
			$GLOBALS['bl_core_test_meta_delete_vetoed'],
			$GLOBALS['bl_core_test_delete_attachment_fails']
		);
	}

	/**
	 * Register an attachment.
	 *
	 * @param int  $id      Attachment ID.
	 * @param int  $author  post_author.
	 * @param bool $flagged Whether it carries the player-photo flag.
	 */
	private function attachment( int $id, int $author, bool $flagged = false ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'type'   => 'attachment',
			'status' => 'inherit',
			'author' => $author,
		);
		$state['post_meta'][ $id ] = $flagged ? array( '_blueline_player_photo' => 1 ) : array();
	}

	/**
	 * Give user 5 the avatar attachment 300 (authored by them).
	 *
	 * @param int $author Attachment author.
	 */
	private function give_avatar( int $author = self::USER ): void {
		$this->attachment( self::AVATAR, $author );
		blueline_test_state()['user_meta'][ self::USER ]['blueline_avatar_id'] = (string) self::AVATAR;
	}

	/**
	 * Register an sp_player post linked to a user.
	 *
	 * @param int    $id      Player post ID.
	 * @param int    $user_id Linked user.
	 * @param string $title   Player name.
	 */
	private function player( int $id, int $user_id, string $title = 'Jane Player' ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'type'      => 'sp_player',
			'status'    => 'publish',
			'title'     => $title,
			'permalink' => 'https://example.test/player/' . $id . '/',
		);
		$state['post_meta'][ $id ] = array(
			'sp_user'   => (string) $user_id,
			'sp_number' => '17',
			'sp_goals'  => '9',
		);
	}

	/**
	 * Make an attachment the player's featured image.
	 *
	 * @param int $player_id sp_player post ID.
	 * @param int $photo_id  Attachment ID.
	 */
	private function set_player_photo( int $player_id, int $photo_id ): void {
		$state = &blueline_test_state();

		$state['posts'][ $player_id ]['thumbnail_id']      = $photo_id;
		$state['post_meta'][ $player_id ]['_thumbnail_id'] = (string) $photo_id;
	}

	/**
	 * The flat name => value map of an export item.
	 *
	 * @param array $item Export item.
	 * @return array<string, string>
	 */
	private function fields( array $item ): array {
		return array_column( $item['data'], 'value', 'name' );
	}

	/**
	 * Ids of attachments the code asked to delete.
	 *
	 * @return int[]
	 */
	private function deleted_ids(): array {
		return array_column( $GLOBALS['bl_core_test_deleted_attachments'], 0 );
	}

	// -------------------------------------------------------------------------
	// Registration.
	// -------------------------------------------------------------------------

	/**
	 * Both exporters are registered next to whatever is already there.
	 */
	public function test_exporters_are_registered(): void {
		$exporters = blueline_register_privacy_exporters( array( 'existing' => array( 'callback' => 'x' ) ) );

		$this->assertArrayHasKey( 'existing', $exporters );
		$this->assertSame( 'blueline_export_avatar_personal_data', $exporters['blueline-core-avatar']['callback'] );
		$this->assertSame( 'blueline_export_player_personal_data', $exporters['blueline-core-player']['callback'] );
		$this->assertNotSame( '', $exporters['blueline-core-avatar']['exporter_friendly_name'] );
		$this->assertTrue( is_callable( $exporters['blueline-core-avatar']['callback'] ) );
		$this->assertTrue( is_callable( $exporters['blueline-core-player']['callback'] ) );
	}

	/**
	 * Both erasers are registered next to whatever is already there.
	 */
	public function test_erasers_are_registered(): void {
		$erasers = blueline_register_privacy_erasers( array( 'existing' => array( 'callback' => 'x' ) ) );

		$this->assertArrayHasKey( 'existing', $erasers );
		$this->assertSame( 'blueline_erase_avatar_personal_data', $erasers['blueline-core-avatar']['callback'] );
		$this->assertSame( 'blueline_erase_player_personal_data', $erasers['blueline-core-player']['callback'] );
		$this->assertNotSame( '', $erasers['blueline-core-avatar']['eraser_friendly_name'] );
		$this->assertTrue( is_callable( $erasers['blueline-core-avatar']['callback'] ) );
		$this->assertTrue( is_callable( $erasers['blueline-core-player']['callback'] ) );
	}

	/**
	 * A filter that is handed a non-array still yields a valid registry.
	 */
	public function test_registration_tolerates_a_non_array(): void {
		$this->assertCount( 2, blueline_register_privacy_exporters( null ) );
		$this->assertCount( 2, blueline_register_privacy_erasers( false ) );
	}

	// -------------------------------------------------------------------------
	// Avatar exporter.
	// -------------------------------------------------------------------------

	/**
	 * Unknown and malformed addresses export nothing, in one finished page.
	 */
	public function test_avatar_export_for_an_unknown_email_is_empty(): void {
		$empty = array(
			'data' => array(),
			'done' => true,
		);

		$this->assertSame( $empty, blueline_export_avatar_personal_data( 'nobody@example.test' ) );
		$this->assertSame( $empty, blueline_export_avatar_personal_data( 'not an email' ) );
		$this->assertSame( $empty, blueline_export_avatar_personal_data( '' ) );
		$this->assertSame( $empty, blueline_export_avatar_personal_data( null ) );
	}

	/**
	 * A user without an avatar has nothing to export.
	 */
	public function test_avatar_export_for_a_user_without_an_avatar_is_empty(): void {
		$export = blueline_export_avatar_personal_data( 'five@example.test' );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	/**
	 * A stale pointer (attachment deleted) or one at a non-attachment exports nothing.
	 */
	public function test_avatar_export_ignores_stale_and_non_attachment_pointers(): void {
		$state = &blueline_test_state();
		$state['user_meta'][ self::USER ]['blueline_avatar_id'] = '999';
		$this->assertSame( array(), blueline_export_avatar_personal_data( 'five@example.test' )['data'] );

		$state['posts'][400]                                    = array( 'type' => 'page' );
		$state['user_meta'][ self::USER ]['blueline_avatar_id'] = '400';
		$this->assertSame( array(), blueline_export_avatar_personal_data( 'five@example.test' )['data'] );
	}

	/**
	 * The avatar's attachment ID and URL are exported as one item in a labelled group.
	 */
	public function test_avatar_export_returns_id_and_url(): void {
		$this->give_avatar();

		$export = blueline_export_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );

		$item = $export['data'][0];
		$this->assertSame( 'blueline-avatar', $item['group_id'] );
		$this->assertNotSame( '', $item['group_label'] );
		$this->assertSame( 'blueline-avatar-5', $item['item_id'] );
		$this->assertSame(
			array(
				'Avatar attachment ID' => '300',
				'Avatar image URL'     => 'https://example.test/uploads/original-300.jpg',
			),
			$this->fields( $item )
		);
	}

	/**
	 * One user's export never contains another user's avatar.
	 */
	public function test_avatar_export_is_scoped_to_the_requesting_user(): void {
		$this->give_avatar();

		$this->assertSame( array(), blueline_export_avatar_personal_data( 'six@example.test' )['data'] );
	}

	// -------------------------------------------------------------------------
	// Avatar eraser.
	// -------------------------------------------------------------------------

	/**
	 * Unknown addresses erase nothing and say nothing.
	 */
	public function test_avatar_erase_for_an_unknown_email_is_a_clean_no_op(): void {
		$this->give_avatar();

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			blueline_erase_avatar_personal_data( 'nobody@example.test' )
		);
		$this->assertSame( '300', blueline_test_state()['user_meta'][ self::USER ]['blueline_avatar_id'] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * A user without an avatar: nothing removed, nothing retained.
	 */
	public function test_avatar_erase_for_a_user_without_an_avatar_is_a_clean_no_op(): void {
		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
		$this->assertTrue( $response['done'] );
	}

	/**
	 * The user's own, unshared avatar: pointer and image are both removed.
	 */
	public function test_avatar_erase_removes_the_pointer_and_the_own_attachment(): void {
		$this->give_avatar();

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
		$this->assertTrue( $response['done'] );
		$this->assertArrayNotHasKey( 'blueline_avatar_id', blueline_test_state()['user_meta'][ self::USER ] );
		$this->assertSame( array( array( self::AVATAR, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * An attachment someone else uploaded is not this user's to delete: pointer goes, image stays, retained.
	 */
	public function test_avatar_erase_keeps_an_attachment_authored_by_someone_else(): void {
		$this->give_avatar( self::OTHER );

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertCount( 1, $response['messages'] );
		$this->assertArrayNotHasKey( 'blueline_avatar_id', blueline_test_state()['user_meta'][ self::USER ] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * An avatar that is also another post's featured image is kept.
	 */
	public function test_avatar_erase_keeps_an_attachment_used_as_a_thumbnail(): void {
		$this->give_avatar();
		blueline_test_state()['post_meta'][700]['_thumbnail_id'] = '300';

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * An avatar another user also points at (ours or YITH's) is kept.
	 */
	public function test_avatar_erase_keeps_an_attachment_another_user_points_at(): void {
		foreach ( array( 'blueline_avatar_id', 'yith-wcmap-avatar' ) as $meta_key ) {
			blueline_test_reset_state();
			$state                                       = &blueline_test_state();
			$state['users'][5]                           = (object) array( 'user_email' => 'five@example.test' );
			$state['users'][6]                           = (object) array( 'user_email' => 'six@example.test' );
			$GLOBALS['bl_core_test_deleted_attachments'] = array();
			$this->give_avatar();
			$state['user_meta'][ self::OTHER ][ $meta_key ] = '300';

			$response = blueline_erase_avatar_personal_data( 'five@example.test' );

			$this->assertTrue( $response['items_retained'], $meta_key );
			$this->assertSame( array(), $this->deleted_ids(), $meta_key );
			$this->assertSame( '300', $state['user_meta'][ self::OTHER ][ $meta_key ], $meta_key );
		}
	}

	/**
	 * A failed lookup for other uses fails closed: the image is kept.
	 */
	public function test_avatar_erase_fails_closed_when_the_usage_lookup_errors(): void {
		$this->give_avatar();
		$this->wpdb->last_error = 'Lost connection';

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_retained'] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * A pointer at something that is not a media file never deletes that post.
	 */
	public function test_avatar_erase_never_deletes_a_non_attachment(): void {
		$state               = &blueline_test_state();
		$state['posts'][400] = array(
			'type'   => 'page',
			'author' => self::USER,
		);
		$state['user_meta'][ self::USER ]['blueline_avatar_id'] = '400';

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertCount( 1, $response['messages'] );
		$this->assertSame( array(), $this->deleted_ids() );
		$this->assertArrayHasKey( 400, $state['posts'] );
		$this->assertArrayNotHasKey( 'blueline_avatar_id', $state['user_meta'][ self::USER ] );
	}

	/**
	 * A stale pointer (attachment already gone) is simply removed.
	 */
	public function test_avatar_erase_removes_a_stale_pointer(): void {
		blueline_test_state()['user_meta'][ self::USER ]['blueline_avatar_id'] = '999';

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * A refused pointer delete is reported as retained, not as removed.
	 */
	public function test_avatar_erase_reports_a_refused_pointer_delete(): void {
		$this->give_avatar();
		$GLOBALS['bl_core_test_meta_delete_vetoed'] = array( 'blueline_avatar_id' );

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_retained'] );
		$this->assertNotEmpty( $response['messages'] );
		$this->assertSame( '300', blueline_test_state()['user_meta'][ self::USER ]['blueline_avatar_id'] );
	}

	/**
	 * A failed attachment delete is reported as retained.
	 */
	public function test_avatar_erase_reports_a_failed_attachment_delete(): void {
		$this->give_avatar();
		$GLOBALS['bl_core_test_delete_attachment_fails'] = array( self::AVATAR );

		$response = blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertTrue( $response['items_removed'], 'the pointer was still removed' );
		$this->assertTrue( $response['items_retained'] );
		$this->assertNotEmpty( $response['messages'] );
	}

	/**
	 * Erasing one user leaves another user's avatar untouched.
	 */
	public function test_avatar_erase_is_scoped_to_the_requesting_user(): void {
		$this->give_avatar();
		$this->attachment( 301, self::OTHER );
		blueline_test_state()['user_meta'][ self::OTHER ]['blueline_avatar_id'] = '301';

		blueline_erase_avatar_personal_data( 'five@example.test' );

		$this->assertSame( '301', blueline_test_state()['user_meta'][ self::OTHER ]['blueline_avatar_id'] );
		$this->assertSame( array( self::AVATAR ), $this->deleted_ids() );
	}

	// -------------------------------------------------------------------------
	// Player exporter.
	// -------------------------------------------------------------------------

	/**
	 * No match, malformed address or an unlinked user: nothing to export.
	 */
	public function test_player_export_with_no_link_is_empty(): void {
		$this->player( self::PLAYER, self::OTHER );

		$this->assertSame( array(), blueline_export_player_personal_data( 'nobody@example.test' )['data'] );
		$this->assertSame( array(), blueline_export_player_personal_data( 'five@example.test' )['data'] );
		$this->assertSame( array(), blueline_export_player_personal_data( 'bad' )['data'] );
		$this->assertTrue( blueline_export_player_personal_data( 'five@example.test' )['done'] );
	}

	/**
	 * The linked player's id, name and page are exported.
	 */
	public function test_player_export_returns_the_linked_player(): void {
		$this->player( self::PLAYER, self::USER );

		$export = blueline_export_player_personal_data( 'five@example.test' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'blueline-player', $export['data'][0]['group_id'] );
		$this->assertSame( 'blueline-player-100', $export['data'][0]['item_id'] );
		$this->assertSame(
			array(
				'Player ID'   => '100',
				'Player name' => 'Jane Player',
				'Player page' => 'https://example.test/player/100/',
			),
			$this->fields( $export['data'][0] )
		);
	}

	/**
	 * A draft or private player post is still the user's data and is exported.
	 */
	public function test_player_export_includes_non_published_players(): void {
		$this->player( self::PLAYER, self::USER );
		blueline_test_state()['posts'][ self::PLAYER ]['status'] = 'draft';

		$this->assertCount( 1, blueline_export_player_personal_data( 'five@example.test' )['data'] );
	}

	/**
	 * Every linked player post is exported, one item each.
	 */
	public function test_player_export_covers_a_duplicated_link(): void {
		$this->player( self::PLAYER, self::USER );
		$this->player( 101, self::USER, 'Jane Second' );

		$export = blueline_export_player_personal_data( 'five@example.test' );

		$this->assertSame( array( 'blueline-player-100', 'blueline-player-101' ), array_column( $export['data'], 'item_id' ) );
	}

	/**
	 * The player photo the user uploaded (flagged, authored by them) is exported with the link.
	 */
	public function test_player_export_includes_the_users_own_uploaded_photo(): void {
		$this->player( self::PLAYER, self::USER );
		$this->attachment( self::PHOTO, self::USER, true );
		$this->set_player_photo( self::PLAYER, self::PHOTO );

		$fields = $this->fields( blueline_export_player_personal_data( 'five@example.test' )['data'][0] );

		$this->assertSame( 'https://example.test/uploads/original-51.jpg', $fields['Player photo URL'] );
	}

	/**
	 * A league-supplied or another user's photo is not exported as this user's.
	 */
	public function test_player_export_omits_photos_the_user_did_not_upload(): void {
		$this->player( self::PLAYER, self::USER );
		$this->set_player_photo( self::PLAYER, self::PHOTO );

		// Unflagged (legacy / league-supplied).
		$this->attachment( self::PHOTO, self::USER, false );
		$this->assertArrayNotHasKey( 'Player photo URL', $this->fields( blueline_export_player_personal_data( 'five@example.test' )['data'][0] ) );

		// Flagged, but uploaded by someone else.
		$this->attachment( self::PHOTO, self::OTHER, true );
		$this->assertArrayNotHasKey( 'Player photo URL', $this->fields( blueline_export_player_personal_data( 'five@example.test' )['data'][0] ) );
	}

	// -------------------------------------------------------------------------
	// Player eraser.
	// -------------------------------------------------------------------------

	/**
	 * Unknown address or unlinked user: clean no-op.
	 */
	public function test_player_erase_with_no_link_is_a_clean_no_op(): void {
		$this->player( self::PLAYER, self::OTHER );

		foreach ( array( 'nobody@example.test', 'five@example.test', 'bad' ) as $email ) {
			$this->assertSame(
				array(
					'items_removed'  => false,
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				),
				blueline_erase_player_personal_data( $email ),
				$email
			);
		}

		$this->assertSame( '6', blueline_test_state()['post_meta'][ self::PLAYER ]['sp_user'] );
	}

	/**
	 * Only the sp_user link goes; the league's player record and statistics stay, and the response says so.
	 */
	public function test_player_erase_removes_only_the_link_and_retains_the_player_record(): void {
		$this->player( self::PLAYER, self::USER );

		$response = blueline_erase_player_personal_data( 'five@example.test' );

		$state = blueline_test_state();
		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assertCount( 1, $response['messages'] );
		$this->assertStringContainsString( 'Jane Player', $response['messages'][0] );
		$this->assertStringContainsString( 'statistics', $response['messages'][0] );

		$this->assertArrayNotHasKey( 'sp_user', $state['post_meta'][ self::PLAYER ] );
		$this->assertArrayHasKey( self::PLAYER, $state['posts'], 'the player record itself is kept' );
		$this->assertSame( '17', $state['post_meta'][ self::PLAYER ]['sp_number'] );
		$this->assertSame( '9', $state['post_meta'][ self::PLAYER ]['sp_goals'] );
		$this->assertSame( array(), $this->deleted_ids() );
	}

	/**
	 * Another member's link is never touched.
	 */
	public function test_player_erase_leaves_other_users_links_alone(): void {
		$this->player( self::PLAYER, self::USER );
		$this->player( 101, self::OTHER, 'Other Player' );

		blueline_erase_player_personal_data( 'five@example.test' );

		$this->assertSame( '6', blueline_test_state()['post_meta'][101]['sp_user'] );
	}

	/**
	 * The user's own uploaded player photo is deleted, and the player stops pointing at it.
	 */
	public function test_player_erase_deletes_the_users_own_uploaded_photo(): void {
		$this->player( self::PLAYER, self::USER );
		$this->attachment( self::PHOTO, self::USER, true );
		$this->set_player_photo( self::PLAYER, self::PHOTO );

		$response = blueline_erase_player_personal_data( 'five@example.test' );

		$this->assertSame( array( array( self::PHOTO, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
		$this->assertTrue( $response['items_removed'] );
		$this->assertSame( 0, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertArrayHasKey( self::PLAYER, blueline_test_state()['posts'] );
	}

	/**
	 * League-supplied (unflagged) and another user's photos are never deleted.
	 */
	public function test_player_erase_keeps_photos_the_user_did_not_upload(): void {
		$this->player( self::PLAYER, self::USER );
		$this->set_player_photo( self::PLAYER, self::PHOTO );

		$this->attachment( self::PHOTO, self::USER, false );
		blueline_erase_player_personal_data( 'five@example.test' );
		$this->assertSame( array(), $this->deleted_ids(), 'unflagged photo' );

		$this->player( self::PLAYER, self::USER );
		$this->set_player_photo( self::PLAYER, self::PHOTO );
		$this->attachment( self::PHOTO, self::OTHER, true );
		blueline_erase_player_personal_data( 'five@example.test' );
		$this->assertSame( array(), $this->deleted_ids(), 'photo authored by someone else' );
	}

	/**
	 * A photo another post also uses as its featured image is kept, with an explanatory message.
	 */
	public function test_player_erase_keeps_a_photo_used_elsewhere(): void {
		$this->player( self::PLAYER, self::USER );
		$this->attachment( self::PHOTO, self::USER, true );
		$this->set_player_photo( self::PLAYER, self::PHOTO );
		blueline_test_state()['post_meta'][800]['_thumbnail_id'] = (string) self::PHOTO;

		$response = blueline_erase_player_personal_data( 'five@example.test' );

		$this->assertSame( array(), $this->deleted_ids() );
		$this->assertCount( 2, $response['messages'] );
		$this->assertStringContainsString( 'photo was kept', $response['messages'][0] );
		$this->assertTrue( $response['items_retained'] );
	}

	/**
	 * Every linked player is unlinked, with one retention message each.
	 */
	public function test_player_erase_handles_a_duplicated_link(): void {
		$this->player( self::PLAYER, self::USER );
		$this->player( 101, self::USER, 'Jane Second' );

		$response = blueline_erase_player_personal_data( 'five@example.test' );

		$this->assertArrayNotHasKey( 'sp_user', blueline_test_state()['post_meta'][ self::PLAYER ] );
		$this->assertArrayNotHasKey( 'sp_user', blueline_test_state()['post_meta'][101] );
		$this->assertCount( 2, $response['messages'] );
	}
}
