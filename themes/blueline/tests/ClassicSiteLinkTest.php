<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers the footer's "Switch to Classic Site" link.
 */
final class ClassicSiteLinkTest extends TestCase {

	/**
	 * Request URIs and the path the classic link should carry.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function paths(): array {
		return array(
			'home'                    => array( '/', '/' ),
			'empty'                   => array( '', '/' ),
			'deep page'               => array( '/team/knights', '/team/knights' ),
			'trailing slash'          => array( '/schedule/', '/schedule/' ),
			'query is kept'           => array( '/standings?x=1&y=2', '/standings?x=1&y=2' ),
			'query only on home'      => array( '/?s=ducks', '/?s=ducks' ),
			'paginated and filtered'  => array( '/team/knights/page/2/?season=2026&division=b', '/team/knights/page/2/?season=2026&division=b' ),
			'already a classic URL'   => array( '/classic/team/knights?z=1', '/team/knights?z=1' ),
			'classic root'            => array( '/classic', '/' ),
			'classic root with query' => array( '/classic?s=1', '/?s=1' ),
			'a page named classical'  => array( '/classical', '/classical' ),
			'theme-switch is dropped' => array( '/schedule?theme-switch=rookie-child&x=1', '/schedule?x=1' ),
			'only theme-switch'       => array( '/schedule?theme-switch=rookie-child', '/schedule' ),
			'encoded theme-switch'    => array( '/schedule?theme%2Dswitch=a&x=1', '/schedule?x=1' ),
			'similar key is kept'     => array( '/schedule?theme-switcher=1', '/schedule?theme-switcher=1' ),
			'empty pairs vanish'      => array( '/a?&&x=1&', '/a?x=1' ),
			'array args survive'      => array( '/a?d[]=1&d[]=2', '/a?d[]=1&d[]=2' ),
			'encoding is preserved'   => array( '/a?q=a%20b%26c', '/a?q=a%20b%26c' ),
			'whitespace and CRLF go'  => array( "/a?x=1\r\nSet-Cookie:y=1", '/a?x=1Set-Cookie:y=1' ),
			'fragment is dropped'     => array( '/a?x=1#top', '/a?x=1' ),
		);
	}

	/**
	 * Path building.
	 *
	 * @param string $uri      Request URI.
	 * @param string $expected Expected path.
	 */
	#[DataProvider( 'paths' )]
	public function test_classic_path( string $uri, string $expected ): void {
		$this->assertSame( $expected, blueline_classic_path( $uri ) );
	}

	/**
	 * With the router installed the link is the router route on the current host, same page.
	 */
	public function test_router_link_is_same_page_on_classic_site(): void {
		$this->assertSame( 'https://arlhockey.ca/classic/team/knights', blueline_classic_site_url_for( '/team/knights', '/classic', 'https://arlhockey.ca', false ) );
		$this->assertSame( 'https://arlhockey.ca/classic/', blueline_classic_site_url_for( '/', '/classic', 'https://arlhockey.ca/', true ), 'Theme Switcha is ignored when the router is present' );
	}

	/**
	 * A filtered or paginated page opens with the same query on the classic site.
	 */
	public function test_router_link_keeps_the_query_string(): void {
		$this->assertSame(
			'https://arlhockey.ca/classic/team/knights?x=1',
			blueline_classic_site_url_for( '/team/knights?x=1', '/classic', 'https://arlhockey.ca', false )
		);
	}

	/**
	 * What the footer prints is escaped: a query that tries to leave the attribute does not.
	 */
	public function test_the_printed_link_is_escaped(): void {
		$url = blueline_classic_site_url_for( '/a?x="><script>alert(1)</script>&y=1', '/classic', 'https://arlhockey.ca', false );

		$this->assertStringNotContainsString( '<', esc_url( $url ) );
		$this->assertStringNotContainsString( '"', esc_url( $url ) );
	}

	/**
	 * Without the router the old Theme Switcha link is used while that plugin is active, otherwise no link.
	 */
	public function test_fallback_without_router(): void {
		$this->assertSame( '/?theme-switch=rookie-child', blueline_classic_site_url_for( '/a', null, 'https://example.test', true ) );
		$this->assertSame( '', blueline_classic_site_url_for( '/a', null, 'https://example.test', false ) );
	}
}
