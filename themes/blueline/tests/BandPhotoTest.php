<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';

/**
 * Unit tests for band photography selection.
 */
final class BandPhotoTest extends TestCase {

	/**
	 * Define BLUELINE_URI, which functions.php normally supplies.
	 *
	 * That file is not loaded by this suite, so the one function reading the
	 * constant needs a stand-in. Defined here rather than in bootstrap.php
	 * because this is the only test that touches it.
	 *
	 * @return void
	 */
	/**
	 * Reset the in-memory post store so attachments registered by one test
	 * cannot satisfy another's assertions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Define BLUELINE_URI, which functions.php normally supplies.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BLUELINE_URI' ) ) {
			define( 'BLUELINE_URI', 'https://example.test/wp-content/themes/blueline' );
		}
	}

	/**
	 * Every declared slug must have a file behind it, or the band renders a
	 * broken url() and shows plain navy while claiming to show a photograph.
	 */
	public function test_every_declared_shot_exists_on_disk(): void {
		$dir = __DIR__ . '/../assets/images/bands';

		foreach ( blueline_band_shots() as $slug ) {
			$this->assertFileExists( "{$dir}/{$slug}.webp", "missing band photo: {$slug}" );
		}
	}

	/**
	 * And nothing is shipped that no code points at -- an unused image is dead
	 * payload on every page load.
	 */
	public function test_no_unreferenced_images_are_shipped(): void {
		$dir   = __DIR__ . '/../assets/images/bands';
		$files = glob( "{$dir}/*.webp" );

		$on_disk  = array_map(
			static fn( $f ) => basename( $f, '.webp' ),
			$files ? $files : array()
		);
		$declared = blueline_band_shots();

		sort( $on_disk );
		$sorted_declared = $declared;
		sort( $sorted_declared );

		$this->assertSame( $sorted_declared, $on_disk );
	}

	/**
	 * The whole set is a per-page-load cost on the pages that use it, so it
	 * stays inside the budget it was chosen against.
	 */
	public function test_the_photo_set_stays_within_its_payload_budget(): void {
		$dir   = __DIR__ . '/../assets/images/bands';
		$total = 0;

		foreach ( blueline_band_shots() as $slug ) {
			$total += (int) filesize( "{$dir}/{$slug}.webp" );
		}

		$this->assertLessThan(
			200 * 1024,
			$total,
			'band photography exceeded its 200KB ceiling; re-encode rather than raise this'
		);
	}





	/**
	 * THE contrast guard for band photography, and the reason there is no
	 * per-image check anywhere in this theme.
	 *
	 * Compositing is linear over the band's ground, so the brightest result any
	 * photograph can produce is that ground blended with pure white at this
	 * opacity -- independent of the image. Holding the number below its ceiling
	 * therefore guarantees the floor for every photograph, including ones not
	 * taken yet. Measured worst cases for the binding pair (--bl-pale copy on
	 * --bl-ink): 0.12 -> 6.30:1, 0.16 -> 5.51:1, 0.20 -> 4.80:1,
	 * 0.22 -> 4.48:1, which is below the 4.5 AA floor.
	 *
	 * If this fails, the fix is to lower the opacity, NOT to raise the ceiling.
	 */
	public function test_band_photo_opacity_stays_under_its_contrast_ceiling(): void {
		$css = (string) file_get_contents( __DIR__ . '/../assets/src/css/homepage.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

		$this->assertSame(
			1,
			preg_match( '/--bl-band-photo-opacity:\s*([0-9.]+)/', $css, $m ),
			'--bl-band-photo-opacity must be declared exactly once, so there is one number to check'
		);

		$this->assertLessThanOrEqual(
			0.20,
			(float) $m[1],
			'band photography above 0.20 drops --bl-pale copy on --bl-ink below the 4.5:1 AA floor'
		);
	}


	/**
	 * An empty list is the DEFAULT and must survive as an empty array, because
	 * empty means "use the photographs that ship with the theme". Rejecting it,
	 * or coercing it to something else, would make the panel unable to express
	 * its own default.
	 */
	public function test_an_empty_list_is_valid_and_stays_empty(): void {
		$field = array( 'label' => 'Hero photographs' );

		$this->assertSame( array(), blueline_sanitize_band_photos( array(), $field ) );
		$this->assertSame( array(), blueline_sanitize_band_photos( '', $field ) );
		$this->assertSame( array(), blueline_sanitize_band_photos( null, $field ) );
	}

	/**
	 * The id comes from a form and could name any post on the site. Anything
	 * that is not an image attachment is rejected outright rather than dropped,
	 * so the admin is told rather than left with a list that silently shrank.
	 */
	public function test_a_non_image_attachment_is_rejected(): void {
		$state                = &blueline_test_state();
		$state['posts'][4242] = array(
			'status' => 'publish',
			'type'   => 'page',
			'title'  => 'Not an image',
		);

		$result = blueline_sanitize_band_photos(
			array(
				array(
					'id'    => 4242,
					'align' => 'center-center',
				),
			),
			array( 'label' => 'Hero photographs' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_band_photos_not_image', $result->get_error_code() );
	}

	/**
	 * An alignment outside the whitelist falls back to centre rather than being
	 * written through: the value ends up in a stylesheet declaration, so the
	 * render path must never see one it did not define.
	 */
	public function test_an_unknown_alignment_falls_back_to_centre(): void {
		$this->register_image( 77 );

		$result = blueline_sanitize_band_photos(
			array(
				array(
					'id'    => 77,
					'align' => 'url(javascript:alert(1))',
				),
			),
			array( 'label' => 'Hero photographs' )
		);

		$this->assertSame(
			array(
				array(
					'id'    => 77,
					'align' => 'center-center',
				),
			),
			$result
		);
	}

	/**
	 * Every stored alignment must exist in the map the renderer looks it up in,
	 * or a saved value resolves to nothing at render time.
	 */
	public function test_every_alignment_key_has_a_css_value_and_a_label(): void {
		foreach ( blueline_band_photo_alignments() as $key => $css ) {
			$this->assertNotSame( '', trim( (string) $css ), "$key has no background-position" );
			$this->assertNotSame(
				$key,
				blueline_settings_alignment_label( $key ),
				"$key has no human-readable label"
			);
		}
	}

	/**
	 * The list is capped because every photograph in it is downloadable by a
	 * visitor. Over the cap is an error, not a silent truncation.
	 */
	public function test_more_photographs_than_the_cap_are_rejected(): void {
		$rows = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->register_image( 500 + $i );
			$rows[] = array(
				'id'    => 500 + $i,
				'align' => 'center-center',
			);
		}

		$result = blueline_sanitize_band_photos(
			$rows,
			array(
				'label' => 'Hero photographs',
				'max'   => 3,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_band_photos_max', $result->get_error_code() );
	}

	/**
	 * The same photograph twice makes rotation look broken rather than random.
	 */
	public function test_duplicates_are_collapsed(): void {
		$this->register_image( 88 );

		$result = blueline_sanitize_band_photos(
			array(
				array(
					'id'    => 88,
					'align' => 'left-top',
				),
				array(
					'id'    => 88,
					'align' => 'right-top',
				),
			),
			array( 'label' => 'Hero photographs' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'left-top', $result[0]['align'] );
	}

	/**
	 * With nothing configured, the sources fall back to the shipped set -- the
	 * behaviour that lets the hero work before anyone opens the panel.
	 */
	public function test_sources_fall_back_to_the_shipped_photographs(): void {
		$sources = blueline_band_photo_sources();

		$this->assertCount( count( blueline_band_shots() ), $sources );

		foreach ( $sources as $source ) {
			$this->assertStringContainsString( '/assets/images/bands/', $source['url'] );
			$this->assertNotSame( '', $source['position'] );
		}
	}

	/**
	 * Register $id as an image attachment for the sanitizer to accept.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function register_image( int $id ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'status'   => 'inherit',
			'type'     => 'attachment',
			'title'    => 'photo-' . $id,
			'is_image' => true,
		);
	}
}
