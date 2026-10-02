<?php
/**
 * Unit tests for the player-photo metadata strip (SEC-05).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-profile.php';

/**
 * Covers blueline_strip_uploaded_photo_metadata() and
 * blueline_strip_image_metadata() against a recording editor double. The
 * GD/Imagick re-encode itself needs a real WordPress install.
 */
final class PlayerPhotoMetadataTest extends TestCase {

	/**
	 * Reset globals.
	 */
	protected function setUp(): void {
		$GLOBALS['bl_test_image_editor']  = null;
		$GLOBALS['bl_test_deleted_files'] = array();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_image_editor'], $GLOBALS['bl_test_deleted_files'] );
	}

	/**
	 * Install a recording editor double whose save() returns $save_result.
	 *
	 * @param mixed $save_result What save() returns; null echoes the path back.
	 * @return object
	 */
	private function editor( $save_result = null ): object {
		$editor = new class( $save_result ) {
			/**
			 * Calls, in order.
			 *
			 * @var array
			 */
			public array $calls = array();

			/**
			 * Canned save() result.
			 *
			 * @var mixed
			 */
			private $save_result;

			/**
			 * Constructor.
			 *
			 * @param mixed $save_result Canned save() result.
			 */
			public function __construct( $save_result ) {
				$this->save_result = $save_result;
			}

			/**
			 * Record the rotate.
			 */
			public function maybe_exif_rotate() {
				$this->calls[] = 'rotate';
				return false;
			}

			/**
			 * Record the save.
			 *
			 * @param string $path Destination.
			 * @param string $mime MIME type.
			 * @return mixed
			 */
			public function save( $path, $mime ) {
				$this->calls[] = array( 'save', $path, $mime );
				return $this->save_result ?? array( 'path' => $path );
			}
		};

		$GLOBALS['bl_test_image_editor'] = $editor;

		return $editor;
	}

	/**
	 * A JPEG is rotated by its EXIF orientation, then re-saved in place.
	 */
	public function test_jpeg_is_rotated_then_resaved_in_place(): void {
		$editor = $this->editor();

		$this->assertTrue( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ) );
		$this->assertSame( array( 'rotate', array( 'save', '/up/a.jpg', 'image/jpeg' ) ), $editor->calls );
	}

	/**
	 * PNG and WebP are re-saved too.
	 */
	public function test_png_and_webp_are_resaved(): void {
		foreach ( array(
			'image/png'  => '/up/a.png',
			'image/webp' => '/up/a.webp',
		) as $mime => $path ) {
			$editor = $this->editor();

			$this->assertTrue( blueline_strip_image_metadata( $path, $mime ), $mime );
			$this->assertSame( array( 'save', $path, $mime ), $editor->calls[1], $mime );
		}
	}

	/**
	 * GIFs pass untouched so animations survive.
	 */
	public function test_gif_is_left_alone(): void {
		$editor = $this->editor();

		$this->assertTrue( blueline_strip_image_metadata( '/up/a.gif', 'image/gif' ) );
		$this->assertSame( array(), $editor->calls );
	}

	/**
	 * Unknown types, a missing editor, a failed save or a renamed output all fail.
	 */
	public function test_failures_report_false(): void {
		$this->assertFalse( blueline_strip_image_metadata( '/up/a.svg', 'image/svg+xml' ) );
		$this->assertFalse( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ), 'no editor' );

		$this->editor( new WP_Error( 'save', 'failed' ) );
		$this->assertFalse( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ), 'save error' );

		$this->editor( array( 'path' => '/up/a.webp' ) );
		$this->assertFalse( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ), 'renamed output' );
	}

	/**
	 * The upload filter passes a stripped upload through unchanged.
	 */
	public function test_upload_filter_passes_stripped_upload_through(): void {
		$this->editor();
		$upload = array(
			'file' => '/up/a.jpg',
			'url'  => 'https://example.test/a.jpg',
			'type' => 'image/jpeg',
		);

		$this->assertSame( $upload, blueline_strip_uploaded_photo_metadata( $upload ) );
		$this->assertSame( array(), $GLOBALS['bl_test_deleted_files'] );
	}

	/**
	 * The upload filter fails closed: the file is deleted and an error returned.
	 */
	public function test_upload_filter_fails_closed(): void {
		$result = blueline_strip_uploaded_photo_metadata(
			array(
				'file' => '/up/a.jpg',
				'url'  => 'https://example.test/a.jpg',
				'type' => 'image/jpeg',
			)
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( array( '/up/a.jpg' ), $GLOBALS['bl_test_deleted_files'] );
	}

	/**
	 * An upload that already failed is passed through untouched.
	 */
	public function test_upload_filter_ignores_prior_errors(): void {
		$upload = array( 'error' => 'too big' );

		$this->assertSame( $upload, blueline_strip_uploaded_photo_metadata( $upload ) );
	}
}
