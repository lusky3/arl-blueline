<?php
/**
 * Page links to the address the site actually serves (inc/template-tags.php).
 *
 * This site's permalinks have no trailing slash, and core redirects the slash
 * form to the slashless one. A page that the Page Links To plugin points at a
 * path with a slash ("Regular Season" -> /standings/, "Rosters" -> /rosters/)
 * printed that slash form into the nav and the XML sitemap, so every click and
 * every crawl took a 301 first.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';

/**
 * See this file's own docblock.
 */
final class CanonicalPageLinkTest extends TestCase {

	/**
	 * Reset the fake WordPress state, with slashless permalinks like the site.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_options']['permalink_structure'] = '/%postname%';
	}

	/**
	 * The slash form of an internal page becomes the served address.
	 */
	public function test_an_internal_trailing_slash_is_dropped(): void {
		$this->assertSame( 'https://example.test/standings', blueline_canonical_page_link( 'https://example.test/standings/' ) );
		$this->assertSame( 'https://example.test/arl-league-info', blueline_canonical_page_link( 'https://example.test/arl-league-info/' ) );
		$this->assertSame( 'https://example.test/list/goalies', blueline_canonical_page_link( 'https://example.test/list/goalies/' ) );
	}

	/**
	 * A path-only target (Page Links To lets an editor type one) is handled too.
	 */
	public function test_a_relative_path_is_handled(): void {
		$this->assertSame( '/rosters-and-stats', blueline_canonical_page_link( '/rosters-and-stats/' ) );
	}

	/**
	 * A query string and a fragment stay where they were.
	 */
	public function test_query_and_fragment_are_kept(): void {
		$this->assertSame( 'https://example.test/standings?season=2026', blueline_canonical_page_link( 'https://example.test/standings/?season=2026' ) );
		$this->assertSame( 'https://example.test/standings#top', blueline_canonical_page_link( 'https://example.test/standings/#top' ) );
	}

	/**
	 * Already canonical, the site root and non-page targets are left alone.
	 */
	public function test_everything_else_is_left_alone(): void {
		foreach (
			array(
				'https://example.test/standings',
				'https://example.test/',
				'/',
				'#',
				'',
				'mailto:league@example.test',
				'https://www.flickr.com/groups/arl_photos/',
				'https://example.test.evil.test/standings/',
			) as $link
		) {
			$this->assertSame( $link, blueline_canonical_page_link( $link ), $link );
		}
	}

	/**
	 * A site that does use trailing slashes keeps them.
	 */
	public function test_a_trailing_slash_site_is_untouched(): void {
		foreach ( array( '/%postname%/', '' ) as $structure ) {
			$GLOBALS['bl_test_options']['permalink_structure'] = $structure;

			$this->assertSame( 'https://example.test/standings/', blueline_canonical_page_link( 'https://example.test/standings/' ), "structure '$structure'" );
		}
	}

	/**
	 * It is wired to the page, post and custom post type permalinks, and runs
	 * after the Page Links To plugin (priority 20), whose stored target it
	 * corrects.
	 */
	public function test_it_filters_every_permalink_after_page_links_to(): void {
		foreach ( array( 'page_link', 'post_link', 'post_type_link' ) as $hook ) {
			add_filter(
				$hook,
				static fn() => 'https://example.test/standings/',
				20
			);

			$this->assertSame( 'https://example.test/standings', apply_filters( $hook, 'https://example.test/regular-season', 1287 ), $hook );
		}
	}
}
