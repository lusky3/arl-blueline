<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * A11Y-04: blueline_sp_sponsor_logo_alt() names alt-less sponsor logo links.
 */
final class SponsorLogoAltTest extends TestCase {

	/**
	 * Test case: an empty alt on a sponsor logo takes the sponsor's title.
	 */
	public function test_empty_alt_takes_title(): void {
		$attr = blueline_sp_sponsor_logo_alt(
			array(
				'class' => 'attachment-sportspress-fit-icon sp-sponsor-logo',
				'alt'   => '',
				'title' => 'Arcturus',
			)
		);

		$this->assertSame( 'Arcturus', $attr['alt'] );
	}

	/**
	 * Test case: an authored alt is kept.
	 */
	public function test_existing_alt_is_kept(): void {
		$attr = blueline_sp_sponsor_logo_alt(
			array(
				'class' => 'sp-sponsor-logo',
				'alt'   => 'Wave Hockey logo',
				'title' => 'Wave Hockey',
			)
		);

		$this->assertSame( 'Wave Hockey logo', $attr['alt'] );
	}

	/**
	 * Test case: other images (no sp-sponsor-logo class) are untouched.
	 */
	public function test_other_images_untouched(): void {
		$in = array(
			'class' => 'wp-post-image',
			'alt'   => '',
			'title' => 'Something',
		);

		$this->assertSame( $in, blueline_sp_sponsor_logo_alt( $in ) );
	}
}
