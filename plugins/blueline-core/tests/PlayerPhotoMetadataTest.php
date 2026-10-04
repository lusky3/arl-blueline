<?php
/**
 * Unit tests for the player-photo metadata strip (SEC-05).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-photo/player-photo.php';

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
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_image_editor']  = null;
		$GLOBALS['bl_test_deleted_files'] = array();

		$GLOBALS['bl_core_test_imagick_opened'] = array();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_image_editor'], $GLOBALS['bl_test_deleted_files'], $GLOBALS['bl_core_test_imagick_opened'] );
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

	/**
	 * Skip when the real Imagick extension is loaded: these tests rely on the recording stub.
	 */
	private function require_imagick_stub(): void {
		if ( ! ( new ReflectionClass( 'Imagick' ) )->isUserDefined() ) {
			$this->markTestSkipped( 'The real Imagick extension is loaded; the recording stub is not in use.' );
		}
	}

	/**
	 * A-13: the Imagick editor's own handle is stripped BEFORE its single save();
	 * the file is never re-opened for a second decode/encode, and the EXIF/GPS,
	 * XMP and IPTC profiles are gone (only the colour profile survives).
	 */
	public function test_imagick_editor_is_stripped_on_its_own_handle_before_one_save(): void {
		$this->require_imagick_stub();

		$image                           = new Imagick();
		$editor                          = new WP_Image_Editor_Imagick( $image );
		$GLOBALS['bl_test_image_editor'] = $editor;

		$this->assertTrue( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ) );

		$this->assertSame( array(), $GLOBALS['bl_core_test_imagick_opened'], 'No second Imagick decode of the file.' );
		$this->assertSame( 'rotate', $editor->calls[0] );
		$this->assertSame( array( 'save', '/up/a.jpg', 'image/jpeg', array( 'icc' ) ), $editor->calls[1], 'Only the colour profile was left on the handle when it was saved.' );
		$this->assertCount( 2, $editor->calls, 'Exactly one save.' );
		$this->assertSame( array( 'icc' => 'colour' ), $image->profiles );
		$this->assertContains( array( 'orientation', Imagick::ORIENTATION_TOPLEFT ), $image->calls );
		$this->assertNotContains( array( 'write', '/up/a.jpg' ), $image->calls, 'The handle is not written separately.' );
	}

	/**
	 * A-13: if the editor's handle cannot be reached the old second-pass strip still runs (fail closed on metadata).
	 */
	public function test_imagick_editor_without_a_reachable_handle_falls_back_to_a_second_pass(): void {
		$this->require_imagick_stub();

		$editor                          = new WP_Image_Editor_Imagick( null );
		$GLOBALS['bl_test_image_editor'] = $editor;

		$this->assertTrue( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ) );

		$this->assertSame( array( '/up/a.jpg' ), $GLOBALS['bl_core_test_imagick_opened'], 'The saved file was re-read to strip it.' );
	}

	/**
	 * A-13: a failed save on the Imagick editor still fails the strip.
	 */
	public function test_imagick_editor_save_failure_reports_false(): void {
		$this->require_imagick_stub();

		$editor = new class( new Imagick() ) extends WP_Image_Editor_Imagick {
			/**
			 * Always fail.
			 *
			 * @param string $path Destination.
			 * @param string $mime MIME type.
			 * @return WP_Error
			 */
			public function save( $path, $mime ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity.
				return new WP_Error( 'save', 'failed' );
			}
		};

		$GLOBALS['bl_test_image_editor'] = $editor;

		$this->assertFalse( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ) );
	}

	/**
	 * GD goes first in the editor list; everything else keeps its order behind it.
	 */
	public function test_gd_is_preferred_when_it_is_available(): void {
		$this->assertSame(
			array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick', 'Other_Editor' ),
			blueline_prefer_gd_image_editor( array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD', 'Other_Editor' ) )
		);
		$this->assertSame(
			array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ),
			blueline_prefer_gd_image_editor( array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ) )
		);
	}

	/**
	 * A host without a usable GD keeps Imagick (the list is returned untouched), and odd input is safe.
	 */
	public function test_without_gd_the_editor_list_is_untouched(): void {
		$this->assertSame( array( 'WP_Image_Editor_Imagick' ), blueline_prefer_gd_image_editor( array( 'WP_Image_Editor_Imagick' ) ) );
		$this->assertSame( array(), blueline_prefer_gd_image_editor( array() ) );
		$this->assertSame( 'nope', blueline_prefer_gd_image_editor( 'nope' ) );
	}

	/**
	 * The GD preference applies to the strip only: the filter is gone once it returns.
	 */
	public function test_the_gd_preference_filter_does_not_leak(): void {
		$GLOBALS['bl_test_image_editor'] = new WP_Error( 'none', 'no editor' );

		$this->assertFalse( blueline_strip_image_metadata( '/up/a.jpg', 'image/jpeg' ) );
		$this->assertFalse( has_filter( 'wp_image_editors', 'blueline_prefer_gd_image_editor' ) );
	}
}
