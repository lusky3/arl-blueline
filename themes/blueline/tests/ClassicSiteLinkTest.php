<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
			'home'                   => array( '/', '/' ),
			'empty'                  => array( '', '/' ),
			'deep page'              => array( '/team/knights', '/team/knights' ),
			'trailing slash'         => array( '/schedule/', '/schedule/' ),
			'query is dropped'       => array( '/standings?x=1&y=2', '/standings' ),
			'query only on home'     => array( '/?s=ducks', '/' ),
			'already a classic URL'  => array( '/classic/team/knights?z=1', '/team/knights' ),
			'classic root'           => array( '/classic', '/' ),
			'a page named classical' => array( '/classical', '/classical' ),
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
		$this->assertSame( 'https://arlhockey.ca/classic/team/knights', blueline_classic_site_url_for( '/team/knights?x=1', '/classic', 'https://arlhockey.ca', false ) );
		$this->assertSame( 'https://arlhockey.ca/classic/', blueline_classic_site_url_for( '/', '/classic', 'https://arlhockey.ca/', true ), 'Theme Switcha is ignored when the router is present' );
	}

	/**
	 * Without the router the old Theme Switcha link is used while that plugin is active, otherwise no link.
	 */
	public function test_fallback_without_router(): void {
		$this->assertSame( '/?theme-switch=rookie-child', blueline_classic_site_url_for( '/a', null, 'https://example.test', true ) );
		$this->assertSame( '', blueline_classic_site_url_for( '/a', null, 'https://example.test', false ) );
	}
}
