<?php
/**
 * Guards every `page_id` schema field's fallback path the way ContactUrlTest
 * guards the single contact-page case: a call site that hardcodes
 * home_url( '/schedule' ) instead of reading blueline_resolve_link(
 * 'page_schedule' ) still works today, but silently stops following the
 * Links tab the moment someone reassigns that schema key to a different
 * page -- exactly the bug class the resolver exists to prevent, and the one
 * that already shipped once for the contact page (see ContactUrlTest,
 * af381c3). This test is the generalized version of that guard: it does not
 * special-case any one path, it walks the schema's own `page_id` fallback
 * values and fails on any source file that hardcodes one of them.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';

/**
 * Fails, naming the offending file, if any theme source file hardcodes a
 * `home_url( '/literal-path' )` call whose path matches a `page_id` schema
 * field's fallback -- the only place that literal is allowed to live is the
 * schema declaration itself (inc/settings/defaults.php) and the resolver's
 * own fallback branch (inc/settings/links.php), neither of which this test
 * can match: the schema never calls home_url(), and the resolver's call
 * reads `$field['fallback']` from a variable, not a literal string.
 */
final class PageLinkFallbackGuardTest extends TestCase {

	/**
	 * The `registration_term` schema field is deliberately excluded from this
	 * scan: it is `type => term_id` with an integer fallback (91), not a
	 * `page_id` path string, so it has no `home_url()` literal to guard
	 * against in the first place. blueline_resolve_link() is contracted to
	 * `page_id` fields only; this test does not, and should not, enforce
	 * that contract -- it only guards the paths that ARE `page_id` fallbacks.
	 */
	public function test_no_source_file_hardcodes_a_page_id_fallback_path(): void {
		$root = dirname( __DIR__ );

		$fallback_paths = array();
		foreach ( blueline_settings_schema() as $field ) {
			if ( 'page_id' === ( $field['type'] ?? '' ) && isset( $field['fallback'] ) ) {
				$fallback_paths[] = $field['fallback'];
			}
		}

		// Sanity check on the scan itself: if the schema ever loses its
		// page_id fields, this test would silently pass as vacuously true.
		$this->assertNotEmpty( $fallback_paths, 'expected at least one page_id fallback path to guard' );

		$files = array();

		// The root templates (404.php, page.php, single.php, ...) -- one
		// level only, not recursive.
		foreach ( new DirectoryIterator( $root ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		// inc/, sportspress/, woocommerce/ -- recursive, same as ContactUrlTest.
		foreach ( array( '/inc', '/sportspress', '/woocommerce' ) as $dir ) {
			$path = $root . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$offend = array();

		foreach ( $files as $path ) {
			$src = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

			foreach ( $fallback_paths as $fallback_path ) {
				$pattern = "#home_url\(\s*(['\"])" . preg_quote( $fallback_path, '#' ) . '\1#';
				if ( preg_match( $pattern, $src ) ) {
					$offend[] = str_replace( $root . '/', '', $path ) . " (hardcodes '{$fallback_path}')";
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$offend,
			'these files hardcode a page_id fallback path via home_url() -- use blueline_resolve_link() instead, so a page rename in the Links tab keeps the link working'
		);
	}
}
