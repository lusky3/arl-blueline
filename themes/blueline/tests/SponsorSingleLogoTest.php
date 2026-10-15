<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * B-17: single sponsor pages print the sponsor's logo before SportsPress's
 * "Visit Sponsor Site" link.
 */
final class SponsorSingleLogoTest extends TestCase {

	/**
	 * Runs before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * No logo (or no post) prints nothing rather than an empty frame.
	 */
	public function test_no_logo_prints_nothing(): void {
		$this->assertSame( '', blueline_sp_sponsor_logo_figure( 0 ) );
		$this->assertSame( '', blueline_sp_sponsor_logo_figure( 2104 ) );
	}

	/**
	 * A sponsor with a logo gets a figure around a decorative image: the h1
	 * right above it already names the sponsor, so the image's alt is empty.
	 */
	public function test_a_logo_is_a_figure_around_a_decorative_image(): void {
		$state                = &blueline_test_state();
		$state['posts'][2104] = array( 'thumbnail_id' => 77 );

		$this->assertSame(
			'<figure class="bl-sp-sponsor-logo"><img class="wp-post-image" src="https://example.test/thumb-77.jpg" alt=""></figure>',
			blueline_sp_sponsor_logo_figure( 2104 )
		);
		$calls = $GLOBALS['bl_test_thumbnail_calls'];
		$this->assertSame( 'medium', end( $calls )['size'] );
	}

	/**
	 * The logo prints before SportsPress's own single-sponsor sections.
	 */
	public function test_the_logo_is_printed_before_the_single_sponsor_sections(): void {
		$this->assertNotFalse( has_action( 'sportspress_before_single_sponsor', 'blueline_sp_sponsor_single_logo' ) );
	}
}
