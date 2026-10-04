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
	 * Hooked before the single-sponsor sections, with a decorative alt (the h1 names it).
	 */
	public function test_hook_and_markup(): void {
		$src = (string) file_get_contents( __DIR__ . '/../inc/sportspress/core.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "add_action( 'sportspress_before_single_sponsor', 'blueline_sp_sponsor_single_logo' );", $src );
		$this->assertStringContainsString( "'<figure class=\"bl-sp-sponsor-logo\">' . get_the_post_thumbnail( \$sponsor_id, 'medium', array( 'alt' => '' ) )", $src );
	}
}
