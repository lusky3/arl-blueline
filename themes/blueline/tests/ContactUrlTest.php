<?php
/**
 * The footer and the offseason hero must point at the SAME contact page.
 *
 * They did not. The footer used /arl-league-info/contact-us -- the real page
 * (id 6379) -- while the offseason hero's "Join the mailing list" CTA used
 * /contact-us, and no page exists at that slug on the live site. Verified
 * against all 100 published pages. It was a 404 waiting to ship.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards that every contact link in the theme resolves to the page that exists.
 */
final class ContactUrlTest extends TestCase {

	/**
	 * The helper resolves to the real Contact Us page, not the missing slug.
	 */
	public function test_contact_url_points_at_the_page_that_exists(): void {
		$url = blueline_contact_url();

		$this->assertStringContainsString( '/arl-league-info/contact-us', $url );
		$this->assertStringNotContainsString(
			home_url( '/contact-us' ),
			$url,
			'/contact-us does not exist on this site and must never be linked'
		);
	}

	/**
	 * The offseason hero CTA reads the shared helper rather than its own literal,
	 * so the two contact links cannot drift apart again.
	 */
	public function test_offseason_hero_cta_uses_the_shared_contact_url(): void {
		$content = blueline_homepage_hero_offseason_content();

		$this->assertSame( blueline_contact_url(), $content['cta_url'] );
	}

	/**
	 * No theme source file may hardcode the bare /contact-us path. This is the
	 * guard that actually prevents regression -- the two tests above would still
	 * pass if someone introduced a THIRD contact link with the wrong slug.
	 */
	public function test_no_source_file_hardcodes_the_missing_contact_slug(): void {
		$root    = dirname( __DIR__ );
		$offend  = array();
		$targets = array( '/inc', '/sportspress', '/woocommerce' );

		foreach ( $targets as $dir ) {
			$path = $root . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$src = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.
				if ( preg_match( "#home_url\(\s*'/contact-us'#", $src ) ) {
					$offend[] = str_replace( $root . '/', '', $file->getPathname() );
				}
			}
		}

		$this->assertSame(
			array(),
			$offend,
			'these files hardcode /contact-us, which 404s -- use blueline_contact_url()'
		);
	}
}
