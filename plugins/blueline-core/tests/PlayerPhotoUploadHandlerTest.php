<?php
/**
 * Unit tests for blueline_player_photo_file_status() (every validation branch, in order) and for
 * blueline_handle_player_photo_upload() (the redirect each outcome maps to).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once __DIR__ . '/../includes/player-photo/player-photo.php';

/**
 * Runs the validator and the upload handler against real temp files (real getimagesize()).
 */
final class PlayerPhotoUploadHandlerTest extends TestCase {

	private const PLAYER = 100;
	private const USER   = 5;
	private const NEW    = 51;

	/**
	 * Temp files created by a test, removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Reset stores and make user 5 the verified owner of player 100.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$_REQUEST = array();
		$_FILES   = array();

		$GLOBALS['bl_core_test_deleted_attachments'] = array();
		$GLOBALS['bl_core_test_media_calls']         = array();

		$state                              = &blueline_test_state();
		$state['current_user_id']           = self::USER;
		$state['post_types']                = array( 'sp_player' );
		$state['users'][ self::USER ]       = (object) array( 'roles' => array( 'sp_player' ) );
		$state['posts'][ self::PLAYER ]     = array(
			'type'   => 'sp_player',
			'author' => self::USER,
		);
		$state['post_meta'][ self::PLAYER ] = array( 'sp_user' => (string) self::USER );
	}

	/**
	 * Remove temp files and globals.
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
			$GLOBALS['bl_core_test_deleted_attachments'],
			$GLOBALS['bl_core_test_media_calls'],
			$GLOBALS['bl_core_test_media_result'],
			$GLOBALS['bl_core_test_set_thumbnail']
		);
	}

	/**
	 * Write a temp file.
	 *
	 * @param string $bytes File contents.
	 * @return string Path.
	 */
	private function temp_file( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'blphoto' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture outside WordPress.

		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * A PNG whose IHDR declares $width x $height. getimagesize() reads only the
	 * header, so no pixel data is needed -- exactly what a decompression bomb relies on.
	 *
	 * @param int $width  Declared width.
	 * @param int $height Declared height.
	 * @return string
	 */
	private function png_header( int $width, int $height ): string {
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );

		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
	}

	/**
	 * A minimal 3x2 BMP: a real image type that is not on the allow-list.
	 *
	 * @return string
	 */
	private function bmp_header(): string {
		return 'BM' . pack( 'V', 54 ) . pack( 'vv', 0, 0 ) . pack( 'V', 54 ) . pack( 'V', 40 ) . pack( 'V', 3 ) . pack( 'V', 2 )
			. pack( 'vv', 1, 24 ) . str_repeat( pack( 'V', 0 ), 6 );
	}

	/**
	 * A $_FILES entry for a fixture.
	 *
	 * @param string   $bytes File contents.
	 * @param string   $name  Client file name.
	 * @param int|null $size  Reported size (defaults to the real one).
	 * @param int      $error PHP upload error code.
	 * @return array
	 */
	private function file( string $bytes, string $name, ?int $size = null, int $error = UPLOAD_ERR_OK ): array {
		return array(
			'name'     => $name,
			'type'     => 'image/png',
			'tmp_name' => $this->temp_file( $bytes ),
			'error'    => $error,
			'size'     => $size ?? strlen( $bytes ),
		);
	}

	/**
	 * A $_FILES entry PHP refused before storing anything (no temp file).
	 *
	 * @param int $error PHP upload error code.
	 * @return array
	 */
	private function refused_file( int $error ): array {
		return array(
			'name'     => 'a.png',
			'type'     => '',
			'tmp_name' => '',
			'error'    => $error,
			'size'     => 0,
		);
	}

	/**
	 * Point $_FILES['player_photo'] at a fixture.
	 *
	 * @param string   $bytes File contents.
	 * @param string   $name  Client file name.
	 * @param int|null $size  Reported size (defaults to the real one).
	 */
	private function upload( string $bytes, string $name, ?int $size = null ): void {
		$_FILES['player_photo'] = $this->file( $bytes, $name, $size );
	}

	/**
	 * Run the handler with a valid nonce and return where it redirected.
	 *
	 * @return string
	 */
	private function redirect(): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'blueline_upload_player_photo' );

		try {
			blueline_handle_player_photo_upload();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		$this->fail( 'The handler must redirect.' );
	}

	/**
	 * Every validation branch of blueline_player_photo_file_status(), in the order it is checked.
	 *
	 * @return array<string, array{0: callable, 1: string}> Label => [ builder taking the test case, expected status ].
	 */
	public static function file_status_cases(): array {
		return array(
			'valid png'                                 => array( static fn( self $t ) => $t->file( $t->png_header( 600, 800 ), 'a.png' ), 'ok' ),
			'valid gif'                                 => array( static fn( self $t ) => $t->file( "GIF89a\x03\x00\x02\x00\x00\x00\x00;", 'a.gif' ), 'ok' ),
			'exactly the 2MB byte cap'                  => array( static fn( self $t ) => $t->file( $t->png_header( 10, 10 ), 'a.png', 2 * 1024 * 1024 ), 'ok' ),
			'one byte over the 2MB cap'                 => array( static fn( self $t ) => $t->file( $t->png_header( 10, 10 ), 'a.png', 2 * 1024 * 1024 + 1 ), 'too_large' ),
			'PHP upload_max_filesize error'             => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_INI_SIZE ), 'too_large' ),
			'PHP MAX_FILE_SIZE error'                   => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_FORM_SIZE ), 'too_large' ),
			'PHP partial upload'                        => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_PARTIAL ), 'error' ),
			'PHP no temp dir'                           => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_NO_TMP_DIR ), 'error' ),
			'PHP cannot write'                          => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_CANT_WRITE ), 'error' ),
			'PHP extension stopped it'                  => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_EXTENSION ), 'error' ),
			'nothing submitted'                         => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_NO_FILE ), 'none' ),
			'no $_FILES entry at all'                   => array( static fn() => array(), 'none' ),
			'OK but no temp file'                       => array( static fn( self $t ) => $t->refused_file( UPLOAD_ERR_OK ), 'none' ),
			'upload error wins over a stale tmp_name'   => array( static fn( self $t ) => $t->file( $t->png_header( 10, 10 ), 'a.png', null, UPLOAD_ERR_INI_SIZE ), 'too_large' ),
			'byte cap is checked before the bytes'      => array( static fn( self $t ) => $t->file( 'not an image', 'a.png', 2 * 1024 * 1024 + 1 ), 'too_large' ),
			'text file named .jpg'                      => array( static fn( self $t ) => $t->file( 'just some text, not an image', 'x.jpg' ), 'invalid' ),
			'real image type off the allow-list (bmp)'  => array( static fn( self $t ) => $t->file( $t->bmp_header(), 'a.bmp' ), 'invalid' ),
			'zero width'                                => array( static fn( self $t ) => $t->file( $t->png_header( 0, 10 ), 'a.png' ), 'invalid' ),
			'zero height'                               => array( static fn( self $t ) => $t->file( $t->png_header( 10, 0 ), 'a.png' ), 'invalid' ),
			'exactly 40,000,000 px'                     => array( static fn( self $t ) => $t->file( $t->png_header( 8000, 5000 ), 'a.png' ), 'ok' ),
			'one row over 40,000,000 px'                => array( static fn( self $t ) => $t->file( $t->png_header( 8000, 5001 ), 'a.png' ), 'too_large' ),
			'image bomb'                                => array( static fn( self $t ) => $t->file( $t->png_header( 50000, 50000 ), 'a.png' ), 'too_large' ),
			'pixel cap is checked before the extension' => array( static fn( self $t ) => $t->file( $t->png_header( 50000, 50000 ), 'x.jpg' ), 'too_large' ),
			'png bytes under a .jpg name'               => array( static fn( self $t ) => $t->file( $t->png_header( 10, 10 ), 'x.jpg' ), 'invalid' ),
			'image under a non-image extension'         => array( static fn( self $t ) => $t->file( $t->png_header( 10, 10 ), 'x.txt' ), 'invalid' ),
			'image with no client name'                 => array( static fn( self $t ) => array_diff_key( $t->file( $t->png_header( 10, 10 ), 'a.png' ), array( 'name' => 1 ) ), 'invalid' ),
		);
	}

	/**
	 * The validator returns the expected status for each branch.
	 *
	 * @param callable $build    Builds the $_FILES entry.
	 * @param string   $expected Expected status.
	 */
	#[DataProvider( 'file_status_cases' )]
	public function test_file_status( callable $build, string $expected ): void {
		$this->assertSame( $expected, blueline_player_photo_file_status( $build( $this ) ) );
	}

	/**
	 * The pixel cap stays tunable through blueline_core_player_photo_max_pixels.
	 */
	public function test_file_status_honours_the_pixel_cap_filter(): void {
		add_filter(
			'blueline_core_player_photo_max_pixels',
			static function () {
				return 50;
			}
		);

		$this->assertSame( 'too_large', blueline_player_photo_file_status( $this->file( $this->png_header( 10, 10 ), 'a.png' ) ), '100 px > 50' );
		$this->assertSame( 'ok', blueline_player_photo_file_status( $this->file( $this->png_header( 5, 10 ), 'a.png' ) ), '50 px <= 50' );
	}

	/**
	 * A missing nonce is refused before anything else.
	 */
	public function test_missing_nonce_is_refused(): void {
		$this->upload( $this->png_header( 10, 10 ), 'a.png' );

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_handle_player_photo_upload();
	}

	/**
	 * A forged nonce is refused and the file is never handed to WordPress.
	 */
	public function test_bad_nonce_is_refused(): void {
		$this->upload( $this->png_header( 10, 10 ), 'a.png' );
		$_REQUEST['_wpnonce'] = 'forged';

		try {
			blueline_handle_player_photo_upload();
			$this->fail( 'A bad nonce must die.' );
		} catch ( Blueline_Test_WP_Die_Exception $e ) {
			$this->assertSame( array(), $GLOBALS['bl_core_test_media_calls'] );
		}
	}

	/**
	 * A Player-role user who is NOT the post_author of the linked player is refused.
	 */
	public function test_player_role_user_who_is_not_the_post_author_is_refused(): void {
		blueline_test_state()['posts'][ self::PLAYER ]['author'] = 99;
		$this->upload( $this->png_header( 10, 10 ), 'a.png' );

		$this->assertStringContainsString( 'blueline_photo=not_owner', $this->redirect() );
		$this->assertSame( array(), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * A refused file (here over the 2MB byte cap) redirects with its status and never reaches WordPress.
	 */
	public function test_refused_file_redirects_with_its_status(): void {
		$this->upload( $this->png_header( 10, 10 ), 'a.png', 2 * 1024 * 1024 + 1 );

		$this->assertStringContainsString( 'blueline_photo=too_large', $this->redirect() );
		$this->assertSame( array(), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * Nothing submitted is the silent redirect (no status).
	 */
	public function test_no_file_error_redirects_silently(): void {
		$_FILES['player_photo'] = $this->refused_file( UPLOAD_ERR_NO_FILE );

		$this->assertSame( 'https://example.test/account/player-profile/', $this->redirect() );
	}

	/**
	 * Any other upload error (partial upload, no temp dir, ...) reports a generic error.
	 */
	public function test_other_upload_errors_report_error(): void {
		$_FILES['player_photo'] = $this->refused_file( UPLOAD_ERR_PARTIAL );

		$this->assertStringContainsString( 'blueline_photo=error', $this->redirect() );
		$this->assertSame( array(), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * An invalid file redirects with "invalid" and never reaches WordPress.
	 */
	public function test_invalid_file_redirects_as_invalid(): void {
		$this->upload( 'just some text, not an image', 'x.jpg' );

		$this->assertStringContainsString( 'blueline_photo=invalid', $this->redirect() );
		$this->assertSame( array(), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * A failed media_handle_upload() reports "error" and changes nothing.
	 */
	public function test_media_handle_upload_error_reports_error(): void {
		$GLOBALS['bl_core_test_media_result'] = new WP_Error( 'upload_error', 'disk full' );
		$this->upload( $this->png_header( 10, 10 ), 'a.png' );

		$this->assertStringContainsString( 'blueline_photo=error', $this->redirect() );
		$this->assertSame( 0, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertFalse( has_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' ), 'The strip filter is always removed again.' );
	}

	/**
	 * A thumbnail that cannot be set reports "error" and removes the new attachment.
	 */
	public function test_failed_thumbnail_set_reports_error(): void {
		$GLOBALS['bl_core_test_set_thumbnail'] = 'fail';
		$this->upload( $this->png_header( 10, 10 ), 'a.png' );

		$this->assertStringContainsString( 'blueline_photo=error', $this->redirect() );
		$this->assertSame( array( array( self::NEW, true ) ), $GLOBALS['bl_core_test_deleted_attachments'] );
	}

	/**
	 * Success: the upload is parented to the player, becomes its thumbnail and reports "updated".
	 */
	public function test_valid_upload_succeeds(): void {
		$this->upload( $this->png_header( 600, 800 ), 'player.png' );

		$this->assertStringContainsString( 'blueline_photo=updated', $this->redirect() );
		$this->assertSame( array( array( 'player_photo', self::PLAYER ) ), $GLOBALS['bl_core_test_media_calls'] );
		$this->assertSame( self::NEW, get_post_thumbnail_id( self::PLAYER ) );
		$this->assertSame( 1, get_post_meta( self::NEW, '_blueline_player_photo', true ) );
		$this->assertFalse( has_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' ), 'The strip filter is always removed again.' );
	}

	/**
	 * The default pixel cap is the documented 40,000,000 and never drops below 1.
	 */
	public function test_max_pixels_default_and_floor(): void {
		$this->assertSame( 40000000, blueline_player_photo_max_pixels() );

		add_filter(
			'blueline_core_player_photo_max_pixels',
			static function () {
				return -5;
			}
		);

		$this->assertSame( 1, blueline_player_photo_max_pixels() );
	}
}
