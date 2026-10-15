<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// These tests set $_SERVER values and feed script tags as plain strings on purpose.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.WP.EnqueuedResources.NonEnqueuedScript

require_once dirname( __DIR__, 3 ) . '/mu-plugins/arl-domain-router.php';
require_once __DIR__ . '/domain-router-stubs.php';

/**
 * Covers the pure parts of the ARL domain router mu-plugin: which face a host gets, where it redirects,
 * and how URLs in a page are pointed at the current host.
 */
final class DomainRouterTest extends TestCase {

	/**
	 * Default configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function cfg(): array {
		return arl_dr_defaults();
	}

	/**
	 * Hosts and the face each one gets.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function families(): array {
		return array(
			'primary'              => array( 'arlhockey.ca', 'primary' ),
			'primary upper+port'   => array( 'ARLHOCKEY.CA:443', 'primary' ),
			'legacy apex'          => array( 'rookiehockey.ca', 'legacy' ),
			'legacy www'           => array( 'www.rookiehockey.ca', 'legacy' ),
			'legacy com'           => array( 'rookiehockey.com', 'legacy' ),
			'alias www primary'    => array( 'www.arlhockey.ca', 'alias' ),
			'alias arlhockey com'  => array( 'arlhockey.com', 'alias' ),
			'alias old brand'      => array( 'adultrecreationalleague.ca', 'alias' ),
			'alias coed'           => array( 'coedhockey.ca', 'alias' ),
			'alias beginner'       => array( 'beginnerhockey.ca', 'alias' ),
			'alias beginner www'   => array( 'www.beginnerhockey.ca', 'alias' ),
			'staging is untouched' => array( 'staging.rookiehockey.ca', 'other' ),
			'empty'                => array( '', 'other' ),
			'lookalike suffix'     => array( 'arlhockey.ca.evil.test', 'other' ),
			'lookalike prefix'     => array( 'evilarlhockey.ca', 'other' ),
			'trailing dot'         => array( 'arlhockey.ca.', 'primary' ),
		);
	}

	/**
	 * A host maps to exactly one face.
	 *
	 * @param string $host     Host header.
	 * @param string $expected Expected face.
	 */
	#[DataProvider( 'families' )]
	public function test_family( string $host, string $expected ): void {
		$this->assertSame( $expected, arl_dr_family( $host, $this->cfg() ) );
	}

	/**
	 * Each face picks its own theme; unknown hosts leave WordPress alone.
	 */
	public function test_theme_for_each_face(): void {
		$this->assertSame( array( 'blueline', 'blueline' ), arl_dr_theme_for( 'primary', $this->cfg() ) );
		$this->assertSame( array( 'rookie', 'rookie-child' ), arl_dr_theme_for( 'legacy', $this->cfg() ) );
		$this->assertNull( arl_dr_theme_for( 'alias', $this->cfg() ) );
		$this->assertNull( arl_dr_theme_for( 'other', $this->cfg() ) );
	}

	/**
	 * Redirect cases: host, URI, expected target (null for none) and status.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?string, 3: int}>
	 */
	public static function redirects(): array {
		return array(
			'alias keeps path and query'     => array( 'coedhockey.ca', '/team/ducks?x=1', 'https://arlhockey.ca/team/ducks?x=1', 301 ),
			'alias root'                     => array( 'www.arlhockey.ca', '/', 'https://arlhockey.ca/', 301 ),
			'legacy account'                 => array( 'www.rookiehockey.ca', '/account/registrations', 'https://arlhockey.ca/account/registrations', 302 ),
			'legacy account exact'           => array( 'rookiehockey.ca', '/account', 'https://arlhockey.ca/account', 302 ),
			'legacy checkout order'          => array( 'rookiehockey.com', '/checkout/order-received/5/?key=abc', 'https://arlhockey.ca/checkout/order-received/5/?key=abc', 302 ),
			'legacy cart'                    => array( 'www.rookiehockey.ca', '/cart/', 'https://arlhockey.ca/cart/', 302 ),
			'legacy admin'                   => array( 'www.rookiehockey.ca', '/wp-admin/edit.php', 'https://arlhockey.ca/wp-admin/edit.php', 302 ),
			'legacy login'                   => array( 'www.rookiehockey.ca', '/wp-login.php?redirect_to=x', 'https://arlhockey.ca/wp-login.php?redirect_to=x', 302 ),
			'legacy wc-api stays (webhooks)' => array( 'www.rookiehockey.ca', '/wc-api/paypal/', null, 0 ),
			'legacy admin-ajax stays'        => array( 'www.rookiehockey.ca', '/wp-admin/admin-ajax.php', null, 0 ),
			'legacy home stays'              => array( 'www.rookiehockey.ca', '/', null, 0 ),
			'legacy news stays'              => array( 'www.rookiehockey.ca', '/2026/08/winter-season-registration-update', null, 0 ),
			'prefix is a path segment'       => array( 'www.rookiehockey.ca', '/accounting', null, 0 ),
			'primary account stays'          => array( 'arlhockey.ca', '/account/', null, 0 ),
			'primary to classic deep'        => array( 'arlhockey.ca', '/classic/team/ducks?x=1', 'https://www.rookiehockey.ca/team/ducks?x=1', 302 ),
			'primary to classic home'        => array( 'arlhockey.ca', '/classic', 'https://www.rookiehockey.ca/', 302 ),
			'primary classicist is a page'   => array( 'arlhockey.ca', '/classical', null, 0 ),
			'legacy new root is permanent'   => array( 'www.rookiehockey.ca', '/new/', 'https://arlhockey.ca/', 301 ),
			'legacy new bare keeps query'    => array( 'www.rookiehockey.ca', '/new?utm_source=x', 'https://arlhockey.ca/?utm_source=x', 301 ),
			'primary new root is home'       => array( 'arlhockey.ca', '/new', 'https://arlhockey.ca/', 301 ),
			'primary new slash is home'      => array( 'arlhockey.ca', '/new/', 'https://arlhockey.ca/', 301 ),
			'legacy new deep waits for wp'   => array( 'www.rookiehockey.ca', '/new/standings', null, 0 ),
			'primary new deep waits for wp'  => array( 'arlhockey.ca', '/new/standings', null, 0 ),
			'legacy newsletter is a page'    => array( 'www.rookiehockey.ca', '/newsletter', null, 0 ),
			'primary newsletter is a page'   => array( 'arlhockey.ca', '/newsletter', null, 0 ),
			'com keeps path and query'       => array( 'rookiehockey.com', '/team/whalers?x=1', 'https://www.rookiehockey.ca/team/whalers?x=1', 301 ),
			'www com keeps path'             => array( 'www.rookiehockey.com', '/schedule/', 'https://www.rookiehockey.ca/schedule/', 301 ),
			'com root'                       => array( 'rookiehockey.com', '/', 'https://www.rookiehockey.ca/', 301 ),
			'com new root goes straight'     => array( 'rookiehockey.com', '/new/', 'https://arlhockey.ca/', 301 ),
			'legacy sitemap index'           => array( 'www.rookiehockey.ca', '/wp-sitemap.xml', 'https://arlhockey.ca/wp-sitemap.xml', 301 ),
			'legacy sitemap page'            => array( 'rookiehockey.ca', '/wp-sitemap-posts-sp_team-1.xml', 'https://arlhockey.ca/wp-sitemap-posts-sp_team-1.xml', 301 ),
			'legacy sitemap stylesheet'      => array( 'www.rookiehockey.ca', '/wp-sitemap-index.xsl', 'https://arlhockey.ca/wp-sitemap-index.xsl', 301 ),
			'legacy sitemap.xml alias'       => array( 'www.rookiehockey.ca', '/sitemap.xml', 'https://arlhockey.ca/sitemap.xml', 301 ),
			'legacy sitemap_index alias'     => array( 'www.rookiehockey.ca', '/sitemap_index.xml', 'https://arlhockey.ca/sitemap_index.xml', 301 ),
			'legacy sitemap-like page stays' => array( 'www.rookiehockey.ca', '/wp-sitemap-guide/', null, 0 ),
			'primary sitemap stays'          => array( 'arlhockey.ca', '/wp-sitemap.xml', null, 0 ),
			'unknown host untouched'         => array( 'staging.rookiehockey.ca', '/account/', null, 0 ),
			'unknown host new untouched'     => array( 'staging.rookiehockey.ca', '/new/', null, 0 ),
			'empty uri becomes root'         => array( 'coedhockey.ca', '', 'https://arlhockey.ca/', 301 ),
		);
	}

	/**
	 * A /new/<path> request (on the primary or a legacy host) is left for WordPress to resolve; the rest of
	 * the URI, query included, is what gets resolved.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?string}>
	 */
	public static function new_paths(): array {
		return array(
			'legacy deep'          => array( 'www.rookiehockey.ca', '/new/team/ducks?x=1', '/team/ducks?x=1' ),
			'primary deep'         => array( 'arlhockey.ca', '/new/standings/', '/standings/' ),
			'upper-case prefix'    => array( 'arlhockey.ca', '/NEW/standings', '/standings' ),
			'bare new is certain'  => array( 'arlhockey.ca', '/new/', null ),
			'newsletter is a page' => array( 'arlhockey.ca', '/newsletter', null ),
			'alias redirects'      => array( 'coedhockey.ca', '/new/standings', null ),
			'legacy com redirects' => array( 'rookiehockey.com', '/new/standings', null ),
			'unknown host'         => array( 'staging.rookiehockey.ca', '/new/standings', null ),
		);
	}

	/**
	 * Which requests wait for WordPress to say whether the path exists.
	 *
	 * @param string  $host     Host header.
	 * @param string  $uri      Request URI.
	 * @param ?string $expected Rest of the URI, or null when the router does not defer.
	 */
	#[DataProvider( 'new_paths' )]
	public function test_new_path_rest( string $host, string $uri, ?string $expected ): void {
		$this->assertSame( $expected, arl_dr_new_path_rest( $host, $uri, $this->cfg() ) );
	}

	/**
	 * A /new/<path> whose path WordPress knows is a permanent redirect to the primary host; an unknown path
	 * is not redirected anywhere.
	 */
	public function test_new_path_redirect_only_when_it_resolves(): void {
		$this->assertSame( array( 'https://arlhockey.ca/standings?x=1', 301 ), arl_dr_new_path_redirect( '/standings?x=1', true, $this->cfg() ) );
		$this->assertNull( arl_dr_new_path_redirect( '/no-such-page', false, $this->cfg() ) );
	}

	/**
	 * Path resolution uses WordPress's own lookups: a single post or page, or a public taxonomy's term
	 * archive. Anything else (an unknown slug, a date archive) does not count as certain.
	 */
	public function test_path_resolves(): void {
		$GLOBALS['arl_dr_test_post_ids']   = array(
			'https://example.test/standings'  => 12,
			'https://example.test/team/ducks' => 34,
		);
		$GLOBALS['arl_dr_test_taxonomies'] = array(
			(object) array(
				'name'    => 'sp_venue',
				'rewrite' => array( 'slug' => 'venue' ),
			),
			(object) array(
				'name'    => 'post_format',
				'rewrite' => false,
			),
		);
		$GLOBALS['arl_dr_test_terms']      = array( 'sp_venue' => array( 'red' ) );
		// The posts page: url_to_postid() reports 0 for it, but it is a published page.
		$GLOBALS['arl_dr_test_pages'] = array(
			'news'   => (object) array( 'post_status' => 'publish' ),
			'hidden' => (object) array( 'post_status' => 'draft' ),
		);

		$this->assertTrue( arl_dr_path_resolves( '/' ) );
		$this->assertTrue( arl_dr_path_resolves( '/standings/' ) );
		$this->assertTrue( arl_dr_path_resolves( '/team/ducks' ) );
		$this->assertTrue( arl_dr_path_resolves( '/news' ) );
		$this->assertTrue( arl_dr_path_resolves( '/news/page/2/' ), 'page 2 of a list that exists' );
		$this->assertTrue( arl_dr_path_resolves( '/venue/red/' ) );
		$this->assertTrue( arl_dr_path_resolves( '/venue/red/page/3' ) );
		$this->assertTrue( arl_dr_path_resolves( '/Venue/red' ), 'the taxonomy base is matched case-insensitively' );
		$this->assertTrue( arl_dr_path_resolves( '/page/2' ), 'page 2 of the home page' );
		$this->assertFalse( arl_dr_path_resolves( '/hidden' ), 'an unpublished page does not count' );
		$this->assertFalse( arl_dr_path_resolves( '/venue/blue' ) );
		$this->assertFalse( arl_dr_path_resolves( '/player-evaluation' ) );
		$this->assertFalse( arl_dr_path_resolves( '/2016/09' ) );
		$this->assertFalse( arl_dr_path_resolves( '/venue/red/extra' ) );

		unset( $GLOBALS['arl_dr_test_post_ids'], $GLOBALS['arl_dr_test_taxonomies'], $GLOBALS['arl_dr_test_terms'], $GLOBALS['arl_dr_test_pages'] );
	}

	/**
	 * Sitemap addresses: core's index, provider pages and stylesheets, plus the two common aliases. Pages
	 * that merely start with the word do not count.
	 */
	public function test_sitemap_paths(): void {
		foreach ( array( '/wp-sitemap.xml', '/WP-SITEMAP.XML', '/wp-sitemap-posts-page-1.xml', '/wp-sitemap-taxonomies-sp_venue-1.xml', '/wp-sitemap.xsl', '/wp-sitemap-index.xsl', '/sitemap.xml', '/sitemap_index.xml' ) as $path ) {
			$this->assertTrue( arl_dr_is_sitemap_path( $path ), $path );
		}
		foreach ( array( '/', '/sitemap', '/wp-sitemap-guide/', '/sitemap.xml/extra', '/news/sitemap.xml', '/wp-sitemap.xml.bak' ) as $path ) {
			$this->assertFalse( arl_dr_is_sitemap_path( $path ), $path );
		}
	}

	/**
	 * The legacy hosts' robots.txt keeps every rule but names only the primary host's sitemap.
	 */
	public function test_legacy_robots_txt_names_the_primary_sitemap(): void {
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://www.rookiehockey.ca/wp-sitemap.xml\n";
		$out  = arl_dr_legacy_robots_txt( $core, true, $this->cfg() );

		$this->assertSame( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://arlhockey.ca/wp-sitemap.xml\n", $out );
		$this->assertSame( 1, substr_count( strtolower( $out ), 'sitemap:' ) );
	}

	/**
	 * Without a core Sitemap line (sitemaps are switched off on the legacy face) the primary one is still
	 * added; a site that asks not to be indexed gets no sitemap at all, as core does.
	 */
	public function test_legacy_robots_txt_without_core_line_and_when_private(): void {
		$this->assertSame(
			"User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://arlhockey.ca/wp-sitemap.xml\n",
			arl_dr_legacy_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true, $this->cfg() )
		);
		$this->assertSame(
			"User-agent: *\nDisallow: /\n",
			arl_dr_legacy_robots_txt( "User-agent: *\nDisallow: /\nsitemap: https://www.rookiehockey.ca/wp-sitemap.xml\n", false, $this->cfg() )
		);
	}

	/**
	 * On a legacy page the Open Graph URL names the primary host, like the canonical tag; on the primary
	 * face it is left alone.
	 */
	public function test_process_body_points_og_url_at_primary_on_legacy_face(): void {
		$body = '<head><meta property="og:url" content="https://arlhockey.ca/team/whalers"><link rel="canonical" href="' . ARL_DR_CANONICAL_TOKEN . '" /></head>';

		$legacy = arl_dr_process_body( $body, 'www.rookiehockey.ca', 'https://arlhockey.ca/team/whalers', $this->cfg() );
		$this->assertStringContainsString( '<meta property="og:url" content="https://arlhockey.ca/team/whalers">', $legacy );

		$primary = arl_dr_process_body( '<meta property="og:url" content="https://arlhockey.ca/a?b=1&amp;c=2">', 'arlhockey.ca', 'https://arlhockey.ca/a', $this->cfg() );
		$this->assertSame( '<meta property="og:url" content="https://arlhockey.ca/a?b=1&amp;c=2">', $primary );
	}

	/**
	 * The Open Graph URL is escaped as an attribute value.
	 */
	public function test_og_url_replacement_is_escaped(): void {
		$out = arl_dr_point_og_url( "<meta property='og:url' content='https://www.rookiehockey.ca/x'>", 'https://arlhockey.ca/x?a=1&b="2"' );

		$this->assertSame( "<meta property='og:url' content='https://arlhockey.ca/x?a=1&amp;b=&quot;2&quot;'>", $out );
	}

	/**
	 * Redirect decisions.
	 *
	 * @param string  $host     Host header.
	 * @param string  $uri      Request URI.
	 * @param ?string $expected Expected URL or null.
	 * @param int     $status   Expected status.
	 */
	#[DataProvider( 'redirects' )]
	public function test_redirects( string $host, string $uri, ?string $expected, int $status ): void {
		$got = arl_dr_redirect_for( $host, $uri, $this->cfg() );
		if ( null === $expected ) {
			$this->assertNull( $got );
			return;
		}
		$this->assertSame( array( $expected, $status ), $got );
	}

	/**
	 * Redirects keep the request method for anything but GET and HEAD.
	 */
	public function test_redirect_status_preserves_method(): void {
		$this->assertSame( 302, arl_dr_redirect_status( 302, 'GET' ) );
		$this->assertSame( 301, arl_dr_redirect_status( 301, 'head' ) );
		$this->assertSame( 307, arl_dr_redirect_status( 302, 'POST' ) );
		$this->assertSame( 308, arl_dr_redirect_status( 301, 'POST' ) );
		$this->assertSame( 307, arl_dr_redirect_status( 302, 'PUT' ) );
	}

	/**
	 * Page-template overrides apply to the configured slugs only.
	 */
	public function test_primary_page_template_overrides(): void {
		$this->assertSame( 'template-homepage.php', arl_dr_page_template_for( 'home', $this->cfg() ) );
		$this->assertSame( 'default', arl_dr_page_template_for( 'faqs', $this->cfg() ) );
		$this->assertNull( arl_dr_page_template_for( 'register', $this->cfg() ) );
	}

	/**
	 * The primary face gets the new team-page layout (no "tabs" divider); the legacy face gets no overrides.
	 */
	public function test_primary_option_overrides_are_configured(): void {
		$order = $this->cfg()['primary_options']['sportspress_team_template_order'];
		$this->assertSame( array( 'calendar', 'schedule' ), array_slice( $order, 0, 2 ) );
		$this->assertNotContains( 'tabs', $order );
	}

	/**
	 * The legacy face is on by default and can be switched off without losing the host list.
	 */
	public function test_legacy_face_switch(): void {
		$cfg = $this->cfg();
		$this->assertTrue( $cfg['legacy_enabled'] );
		$this->assertContains( 'rookiehockey.ca', arl_dr_known_bases( $cfg ) );
		$cfg['legacy_enabled'] = false;
		$this->assertContains( 'rookiehockey.ca', arl_dr_known_bases( $cfg ), 'still normalised on the primary face' );
		$this->assertSame( 'legacy', arl_dr_family( 'www.rookiehockey.ca', $cfg ) );
	}

	/**
	 * URLs in a page are pointed at the host being served; e-mail addresses and asset sub-domains are not.
	 */
	public function test_rewrite_to_primary_leaves_mail_and_assets(): void {
		$html = '<a href="https://www.rookiehockey.ca/team/ducks">x</a> '
			. '<a href="http://rookiehockey.ca/news">y</a> '
			. '<img src="https://r2.rookiehockey.ca/logo.png"> '
			. '<a href="mailto:play@rookiehockey.ca">m</a> play@rookiehockey.ca '
			. 'https://rookiehockey.ca.evil.test/ https://notrookiehockey.ca/';
		$out  = arl_dr_rewrite_urls( $html, 'arlhockey.ca', $this->cfg() );

		$this->assertStringContainsString( 'href="https://arlhockey.ca/team/ducks"', $out );
		$this->assertStringContainsString( 'href="https://arlhockey.ca/news"', $out, 'http is upgraded to https' );
		$this->assertStringContainsString( 'https://r2.rookiehockey.ca/logo.png', $out );
		$this->assertStringContainsString( 'mailto:play@rookiehockey.ca', $out );
		$this->assertStringContainsString( ' play@rookiehockey.ca ', $out );
		$this->assertStringContainsString( 'https://rookiehockey.ca.evil.test/', $out );
		$this->assertStringContainsString( 'https://notrookiehockey.ca/', $out );
	}

	/**
	 * JSON-escaped and protocol-relative URLs are covered too.
	 */
	public function test_rewrite_handles_json_and_protocol_relative(): void {
		$html = '{"u":"https:\/\/www.rookiehockey.ca\/wp-json\/","v":"\/\/www.rookiehockey.com\/a"} <script src="//rookiehockey.ca/x.js"></script>';
		$out  = arl_dr_rewrite_urls( $html, 'arlhockey.ca', $this->cfg() );

		$this->assertStringContainsString( 'https:\/\/arlhockey.ca\/wp-json\/', $out );
		$this->assertStringContainsString( '\/\/arlhockey.ca\/a', $out );
		$this->assertStringContainsString( '//arlhockey.ca/x.js', $out );
		$this->assertStringNotContainsString( 'rookiehockey', $out );
	}

	/**
	 * On the legacy face the primary host is pointed back at the legacy host.
	 */
	public function test_rewrite_to_legacy(): void {
		$out = arl_dr_rewrite_urls( '<a href="https://arlhockey.ca/schedule">s</a> https://www.arlhockey.ca/x', 'www.rookiehockey.ca', $this->cfg() );

		$this->assertSame( '<a href="https://www.rookiehockey.ca/schedule">s</a> https://www.rookiehockey.ca/x', $out );
	}

	/**
	 * A configured stored host (staging) is normalised as well.
	 */
	public function test_rewrite_normalises_configured_stored_host(): void {
		$cfg                 = $this->cfg();
		$cfg['stored_hosts'] = array( 'staging.rookiehockey.ca' );
		$out                 = arl_dr_rewrite_urls( 'https://staging.rookiehockey.ca/a https://r2.rookiehockey.ca/b', 'arlhockey.ca', $cfg );

		$this->assertSame( 'https://arlhockey.ca/a https://r2.rookiehockey.ca/b', $out );
	}

	/**
	 * The canonical URL is always on the primary host, query included.
	 */
	public function test_canonical_is_primary(): void {
		$this->assertSame( 'https://arlhockey.ca/team/ducks?p=2', arl_dr_canonical_url( '/team/ducks?p=2', $this->cfg() ) );
		$this->assertSame( 'https://arlhockey.ca/', arl_dr_canonical_url( '', $this->cfg() ) );
	}

	/**
	 * The canonical placeholder is filled in AFTER URL rewriting, so a legacy page still names the primary host.
	 */
	public function test_process_body_keeps_canonical_on_primary_host(): void {
		$body = '<head><link rel="canonical" href="' . ARL_DR_CANONICAL_TOKEN . '" /></head><a href="https://arlhockey.ca/a">a</a>';
		$out  = arl_dr_process_body( $body, 'www.rookiehockey.ca', 'https://arlhockey.ca/team/ducks', $this->cfg() );

		$this->assertStringContainsString( '<link rel="canonical" href="https://arlhockey.ca/team/ducks" />', $out );
		$this->assertStringContainsString( '<a href="https://www.rookiehockey.ca/a">', $out );
		$this->assertStringNotContainsString( ARL_DR_CANONICAL_TOKEN, $out );
	}

	/**
	 * Without a Host header (WP-CLI, cron) nothing is read from the request.
	 */
	public function test_no_host_means_inert(): void {
		$saved = $_SERVER['HTTP_HOST'] ?? null;
		unset( $_SERVER['HTTP_HOST'] );
		$this->assertSame( '', arl_dr_request_host() );
		if ( null !== $saved ) {
			$_SERVER['HTTP_HOST'] = $saved;
		}
	}

	/**
	 * Hostile header characters are stripped before use.
	 */
	public function test_request_host_is_sanitised(): void {
		$saved                = $_SERVER['HTTP_HOST'] ?? null;
		$_SERVER['HTTP_HOST'] = "ArlHockey.CA\r\nX-Evil: 1";
		$this->assertSame( 'arlhockey.cax-evil', arl_dr_request_host() );
		$_SERVER['HTTP_HOST'] = $saved;
		if ( null === $saved ) {
			unset( $_SERVER['HTTP_HOST'] );
		}
	}

	/**
	 * Header injection through the URI cannot survive into a redirect.
	 */
	public function test_request_uri_is_sanitised(): void {
		$saved                  = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = "/a b\r\nSet-Cookie: x=1";
		$this->assertSame( '/abSet-Cookie:x=1', arl_dr_request_uri() );
		$_SERVER['REQUEST_URI'] = $saved;
		if ( null === $saved ) {
			unset( $_SERVER['REQUEST_URI'] );
		}
	}

	/**
	 * Registered once, late, on muplugins_loaded.
	 */
	public function test_boot_is_hooked_late(): void {
		$this->assertMatchesRegularExpression( "/^add_action\\( 'muplugins_loaded', 'arl_dr_boot', PHP_INT_MAX \\);/m", (string) file_get_contents( dirname( __DIR__, 3 ) . '/mu-plugins/arl-domain-router.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local source in a unit test.
	}
}
