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
			'alias keeps path and query'   => array( 'coedhockey.ca', '/team/ducks?x=1', 'https://arlhockey.ca/team/ducks?x=1', 301 ),
			'alias root'                   => array( 'www.arlhockey.ca', '/', 'https://arlhockey.ca/', 301 ),
			'legacy account'               => array( 'www.rookiehockey.ca', '/account/registrations', 'https://arlhockey.ca/account/registrations', 302 ),
			'legacy account exact'         => array( 'rookiehockey.ca', '/account', 'https://arlhockey.ca/account', 302 ),
			'legacy checkout order'        => array( 'rookiehockey.com', '/checkout/order-received/5/?key=abc', 'https://arlhockey.ca/checkout/order-received/5/?key=abc', 302 ),
			'legacy cart'                  => array( 'www.rookiehockey.ca', '/cart/', 'https://arlhockey.ca/cart/', 302 ),
			'legacy admin'                 => array( 'www.rookiehockey.ca', '/wp-admin/edit.php', 'https://arlhockey.ca/wp-admin/edit.php', 302 ),
			'legacy login'                 => array( 'www.rookiehockey.ca', '/wp-login.php?redirect_to=x', 'https://arlhockey.ca/wp-login.php?redirect_to=x', 302 ),
			'legacy wc-api'                => array( 'www.rookiehockey.ca', '/wc-api/paypal/', 'https://arlhockey.ca/wc-api/paypal/', 302 ),
			'legacy admin-ajax stays'      => array( 'www.rookiehockey.ca', '/wp-admin/admin-ajax.php', null, 0 ),
			'legacy home stays'            => array( 'www.rookiehockey.ca', '/', null, 0 ),
			'legacy news stays'            => array( 'www.rookiehockey.ca', '/2026/08/winter-season-registration-update', null, 0 ),
			'prefix is a path segment'     => array( 'www.rookiehockey.ca', '/accounting', null, 0 ),
			'primary account stays'        => array( 'arlhockey.ca', '/account/', null, 0 ),
			'primary to classic deep'      => array( 'arlhockey.ca', '/classic/team/ducks?x=1', 'https://www.rookiehockey.ca/team/ducks?x=1', 302 ),
			'primary to classic home'      => array( 'arlhockey.ca', '/classic', 'https://www.rookiehockey.ca/', 302 ),
			'primary classicist is a page' => array( 'arlhockey.ca', '/classical', null, 0 ),
			'legacy to new deep'           => array( 'www.rookiehockey.ca', '/new/standings', 'https://arlhockey.ca/standings', 302 ),
			'legacy newsletter is a page'  => array( 'www.rookiehockey.ca', '/newsletter', null, 0 ),
			'unknown host untouched'       => array( 'staging.rookiehockey.ca', '/account/', null, 0 ),
			'empty uri becomes root'       => array( 'coedhockey.ca', '', 'https://arlhockey.ca/', 301 ),
		);
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
