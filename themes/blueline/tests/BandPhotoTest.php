<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

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
	 * A style attribute is only produced for a real slug: an unknown one must
	 * yield nothing at all, so the caller falls back to the plain band rather
	 * than emitting url() pointing at a 404.
	 */
	public function test_unknown_slugs_produce_no_style(): void {
		$this->assertSame( '', blueline_band_photo_style( 'nope' ) );
		$this->assertSame( '', blueline_band_photo_style( '' ) );
		$this->assertSame( '', blueline_band_photo_style( '../../wp-config' ) );
	}

	/**
	 * A known slug produces the custom property the stylesheet reads.
	 */
	public function test_known_slug_produces_the_custom_property(): void {
		$style = blueline_band_photo_style( 'skater' );

		$this->assertStringStartsWith( '--bl-band-photo:url(', $style );
		$this->assertStringContainsString( '/assets/images/bands/skater.webp', $style );
	}

	/**
	 * Same seed, same photograph, every time -- a band that reshuffles per
	 * request reads as broken rather than lively.
	 */
	public function test_selection_is_deterministic(): void {
		$first = blueline_band_shot_for( 'team-115100' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $first, blueline_band_shot_for( 'team-115100' ) );
		}
	}

	/**
	 * ...and always a real slug, whatever it is seeded with.
	 */
	public function test_selection_always_returns_a_declared_shot(): void {
		$shots = blueline_band_shots();

		foreach ( array( '', 'a', 'team-1', 'event-116460', '💥', str_repeat( 'x', 300 ) ) as $seed ) {
			$this->assertContains( blueline_band_shot_for( $seed ), $shots );
		}
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
	 * Different seeds should not all collapse onto one photograph, or shipping
	 * six of them is pointless.
	 */
	public function test_selection_spreads_across_the_set(): void {
		$seen = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$seen[ blueline_band_shot_for( 'team-' . $i ) ] = true;
		}

		$this->assertGreaterThanOrEqual( 4, count( $seen ) );
	}
}
