<?php
/**
 * Unit tests for includes/headers/csp-report.php: the CSP report endpoint and its aggregated store.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/headers/headers.php';
require_once __DIR__ . '/../includes/privacy/privacy.php';
// Stand-ins for the WordPress page lookups (the theme's domain-router test stubs, driven by globals).
require_once dirname( __DIR__, 3 ) . '/themes/blueline/tests/domain-router-stubs.php';

/**
 * Covers validation, size and content-type limits, the per-visitor rate limit, aggregation, eviction,
 * what is (not) stored, the REST route and the privacy module's REST lockdown.
 */
final class CspReportTest extends TestCase {

	private const IP = '203.0.113.7';
	private const UA = 'Mozilla/5.0 (CspReportTest) Chrome/130';

	/**
	 * Fresh options, transients and hooks; one visitor.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		unset( $GLOBALS['bl_core_test_rest_routes'] );

		// The site's real pages: only reports from these (or other paths that resolve) are counted.
		$GLOBALS['arl_dr_test_post_ids']   = array(
			'https://example.test/schedule'      => 10,
			'https://example.test/team/mustangs' => 11,
			'https://example.test/player/x'      => 12,
		);
		$GLOBALS['arl_dr_test_pages']      = array();
		$GLOBALS['arl_dr_test_taxonomies'] = array();
		$GLOBALS['arl_dr_test_terms']      = array();
	}

	/**
	 * Remove the visitor address and the registered pages.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $GLOBALS['bl_core_test_rest_routes'], $GLOBALS['arl_dr_test_post_ids'], $GLOBALS['arl_dr_test_pages'], $GLOBALS['arl_dr_test_taxonomies'], $GLOBALS['arl_dr_test_terms'] );
	}

	/**
	 * A report-uri style body (one violation, `application/csp-report`).
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return string
	 */
	private function legacy_body( array $overrides = array() ): string {
		return (string) wp_json_encode(
			array(
				'csp-report' => array_merge(
					array(
						'document-uri'        => 'https://example.test/schedule/?team=12&email=a@b.test#top',
						'referrer'            => 'https://example.test/',
						'violated-directive'  => 'script-src-elem',
						'effective-directive' => 'script-src-elem',
						'original-policy'     => "default-src 'self'",
						'disposition'         => 'report',
						'blocked-uri'         => 'https://cdn.evil.test/x.js?token=secret',
						'status-code'         => 200,
					),
					$overrides
				),
			)
		);
	}

	/**
	 * One Reporting API report (`application/reports+json` bodies are arrays of these).
	 *
	 * @param string $directive Effective directive.
	 * @param string $blocked   Blocked URL or keyword.
	 * @param string $url       Document URL.
	 * @return array<string, mixed>
	 */
	private function api_report( string $directive, string $blocked, string $url = 'https://example.test/team/mustangs' ): array {
		return array(
			'type'       => 'csp-violation',
			'age'        => 10,
			'url'        => $url,
			'user_agent' => self::UA,
			'body'       => array(
				'documentURL'        => $url,
				'blockedURL'         => $blocked,
				'effectiveDirective' => $directive,
				'originalPolicy'     => "default-src 'self'",
				'disposition'        => 'report',
				'statusCode'         => 200,
			),
		);
	}

	/**
	 * A POST to the report route.
	 *
	 * @param string $type Content-Type header.
	 * @param string $body Raw body.
	 * @param string $ua   User agent.
	 * @return WP_REST_Request
	 */
	private function request( string $type, string $body, string $ua = self::UA ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/blueline/v1/csp-report' );
		$request->set_header( 'Content-Type', $type );
		$request->set_header( 'User-Agent', $ua );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * The HTTP status a handler result carries.
	 *
	 * @param mixed $result WP_REST_Response or WP_Error.
	 * @return int
	 */
	private function http_status( $result ): int {
		if ( $result instanceof WP_Error ) {
			return (int) ( $result->get_error_data()['status'] ?? 0 );
		}

		return $result->get_status();
	}

	/**
	 * The stored entries, as a list of [directive, blocked, path, count].
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: int}>
	 */
	private function entries(): array {
		$rows = array();
		foreach ( blueline_core_csp_top_entries( blueline_core_csp_store() ) as $entry ) {
			$rows[] = array( $entry['directive'], $entry['blocked'], $entry['path'], $entry['count'] );
		}
		return $rows;
	}

	/**
	 * The route is a public POST: browsers send reports without a nonce and often without cookies.
	 */
	public function test_route_registered(): void {
		do_action( 'rest_api_init' );

		$route = $GLOBALS['bl_core_test_rest_routes']['blueline/v1']['/csp-report'] ?? null;
		$this->assertIsArray( $route );
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( 'blueline_core_csp_report_handle', $route['callback'] );
		$this->assertTrue( call_user_func( $route['permission_callback'] ) );
	}

	/**
	 * The privacy module's REST lockdown removes only the users routes, so this route stays registered
	 * and its requests pass the dispatch gate for an anonymous visitor.
	 */
	public function test_privacy_lockdown_allows_the_route(): void {
		$routes = array(
			'/wp/v2/users'            => array( 'list' ),
			'/blueline/v1/csp-report' => array( 'csp' ),
		);

		$this->assertSame( array( '/blueline/v1/csp-report' => array( 'csp' ) ), blueline_restrict_user_rest_endpoints( $routes ) );
		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( 'application/csp-report', '{}' ) ) );
	}

	/**
	 * A report-uri report is counted under its directive, the blocked origin (never the full URL) and
	 * the page path (never the query string).
	 */
	public function test_report_uri_body(): void {
		$result = blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 204, $result->get_status() );
		$this->assertNull( $result->get_data() );
		$this->assertSame( array( array( 'script-src-elem', 'https://cdn.evil.test', '/schedule/', 1 ) ), $this->entries() );
		$this->assertSame( 1, blueline_core_csp_store()['total'] );
	}

	/**
	 * A Reporting API batch: every csp-violation is counted, other report types are ignored.
	 */
	public function test_reporting_api_batch(): void {
		$body = (string) wp_json_encode(
			array(
				$this->api_report( 'style-src-elem', 'https://fonts.example.test/css2?family=X' ),
				$this->api_report( 'script-src-elem', 'inline' ),
				array(
					'type' => 'deprecation',
					'url'  => 'https://example.test/',
					'body' => array( 'id' => 'x' ),
				),
			)
		);

		$result = blueline_core_csp_report_handle( $this->request( 'application/reports+json', $body ) );

		$this->assertSame( 204, $this->http_status( $result ) );
		$this->assertEqualsCanonicalizing(
			array(
				array( 'style-src-elem', 'https://fonts.example.test', '/team/mustangs', 1 ),
				array( 'script-src-elem', 'inline', '/team/mustangs', 1 ),
			),
			$this->entries()
		);
	}

	/**
	 * Plain `application/json` (with a charset) is tolerated in either shape.
	 */
	public function test_application_json_tolerated(): void {
		$result = blueline_core_csp_report_handle( $this->request( 'application/json; charset=utf-8', $this->legacy_body() ) );

		$this->assertSame( 204, $this->http_status( $result ) );
		$this->assertCount( 1, $this->entries() );
	}

	/**
	 * Any other content type is refused before the body is read.
	 */
	public function test_rejects_other_content_types(): void {
		foreach ( array( 'text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', '' ) as $type ) {
			$this->assertSame( 415, $this->http_status( blueline_core_csp_report_handle( $this->request( $type, $this->legacy_body() ) ) ), $type );
		}
		$this->assertSame( array(), $this->entries() );
	}

	/**
	 * A body over the size limit is refused, whether it says so in Content-Length or not.
	 */
	public function test_rejects_oversized_body(): void {
		$big = $this->legacy_body( array( 'script-sample' => str_repeat( 'a', BLUELINE_CORE_CSP_REPORT_MAX_BYTES ) ) );
		$this->assertSame( 413, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $big ) ) ) );

		$declared = $this->request( 'application/csp-report', $this->legacy_body() );
		$declared->set_header( 'Content-Length', (string) ( BLUELINE_CORE_CSP_REPORT_MAX_BYTES + 1 ) );
		$this->assertSame( 413, $this->http_status( blueline_core_csp_report_handle( $declared ) ) );

		$this->assertSame( array(), $this->entries() );
	}

	/**
	 * Bodies that are not JSON, or JSON that is not a CSP report, are a 400 and store nothing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_bodies(): array {
		return array(
			'not json'          => array( '{"csp-report": ' ),
			'scalar'            => array( '42' ),
			'wrong shape'       => array( '{"hello": "world"}' ),
			'report not object' => array( '{"csp-report": "x"}' ),
			'only other types'  => array( '[{"type":"deprecation","body":{"documentURL":"https://example.test/"}}]' ),
		);
	}

	/**
	 * See invalid_bodies().
	 *
	 * @param string $body Raw body.
	 */
	#[DataProvider( 'invalid_bodies' )]
	public function test_rejects_invalid_bodies( string $body ): void {
		$this->assertSame( 400, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $body ) ) ) );
		$this->assertSame( array(), $this->entries() );
	}

	/**
	 * Reports that do not validate (an unknown directive, a page on another site, a blocked value that
	 * is neither a URL nor a known keyword) are dropped.
	 */
	public function test_rejects_invalid_reports(): void {
		$bad = array(
			array( 'effective-directive' => 'made-up-src' ),
			array( 'effective-directive' => '<script>' ),
			array( 'document-uri' => 'https://evil.test/schedule' ),
			array( 'document-uri' => '/schedule' ),
			array( 'blocked-uri' => '<img src=x>' ),
		);

		foreach ( $bad as $overrides ) {
			$result = blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body( $overrides ) ) );
			$this->assertSame( 400, $this->http_status( $result ), (string) wp_json_encode( $overrides ) );
		}
		$this->assertSame( array(), $this->entries() );
	}

	/**
	 * Without an effective directive (older browsers) the violated directive's name is used.
	 */
	public function test_falls_back_to_violated_directive(): void {
		$body = $this->legacy_body(
			array(
				'effective-directive' => null,
				'violated-directive'  => "img-src 'self' data:",
			)
		);

		$this->assertSame( 204, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $body ) ) ) );
		$this->assertSame( 'img-src', $this->entries()[0][0] );
	}

	/**
	 * How the blocked value is recorded: keywords for inline code, eval and data/blob URLs; the origin
	 * (scheme, host and a non-default port) for URLs; the scheme alone for browser extensions.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function blocked_values(): array {
		return array(
			'empty'          => array( '', 'inline' ),
			'inline'         => array( 'inline', 'inline' ),
			'eval'           => array( 'eval', 'eval' ),
			'wasm-eval'      => array( 'wasm-eval', 'eval' ),
			'data keyword'   => array( 'data', 'data' ),
			'data url'       => array( 'data:image/png;base64,AAAA', 'data' ),
			'blob'           => array( 'blob:https://example.test/1234', 'blob' ),
			'url'            => array( 'https://CDN.Example.test/a/b.js?u=1#f', 'https://cdn.example.test' ),
			'port'           => array( 'https://cdn.example.test:8443/x', 'https://cdn.example.test:8443' ),
			'default port'   => array( 'https://cdn.example.test:443/x', 'https://cdn.example.test' ),
			'websocket'      => array( 'wss://live.example.test/socket', 'wss://live.example.test' ),
			'extension'      => array( 'chrome-extension://abcdef/content.js', 'chrome-extension' ),
			'trusted types'  => array( 'trusted-types-sink', 'trusted-types-sink' ),
			'markup'         => array( '<img src=x>', '' ),
			'no host'        => array( 'https:///x', '' ),
			'bad host chars' => array( 'https://exa_mple!.test/', '' ),
		);
	}

	/**
	 * See blocked_values().
	 *
	 * @param string $raw      Reported value.
	 * @param string $expected Recorded label ('' = invalid).
	 */
	#[DataProvider( 'blocked_values' )]
	public function test_blocked_label( string $raw, string $expected ): void {
		$this->assertSame( $expected, blueline_core_csp_blocked_label( $raw ) );
	}

	/**
	 * The page is recorded as its path only, on this site (with or without www) only.
	 */
	public function test_document_path(): void {
		$this->assertSame( '/schedule/', blueline_core_csp_document_path( 'https://example.test/schedule/?team=5&key=x#top', 'example.test' ) );
		$this->assertSame( '/', blueline_core_csp_document_path( 'https://example.test', 'example.test' ) );
		$this->assertSame( '/a', blueline_core_csp_document_path( 'https://www.example.test/a', 'example.test' ) );
		$this->assertSame( '/a', blueline_core_csp_document_path( 'https://example.test/a', 'www.example.test' ) );
		$this->assertSame( '', blueline_core_csp_document_path( 'https://evil.test/a', 'example.test' ) );
		$this->assertSame( '', blueline_core_csp_document_path( 'https://example.test.evil.test/a', 'example.test' ) );
		$this->assertSame( '', blueline_core_csp_document_path( '/relative', 'example.test' ) );
		$this->assertSame( '', blueline_core_csp_document_path( 'javascript:alert(1)', 'example.test' ) );
		$this->assertSame( 200, strlen( blueline_core_csp_document_path( 'https://example.test/' . str_repeat( 'p', 400 ), 'example.test' ) ) );
		$this->assertSame( '/a%20b', blueline_core_csp_document_path( "https://example.test/a%20b\n", 'example.test' ) );
	}

	/**
	 * The same violation again only raises its count and last-seen day.
	 */
	public function test_aggregation(): void {
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body( array( 'document-uri' => 'https://example.test/schedule/?other=1' ) ) ) );

		$this->assertSame( array( array( 'script-src-elem', 'https://cdn.evil.test', '/schedule/', 2 ) ), $this->entries() );
		$store = blueline_core_csp_store();
		$this->assertSame( 2, $store['total'] );
		$this->assertSame( gmdate( 'Y-m-d' ), array_values( $store['entries'] )[0]['last'] );
	}

	/**
	 * At the cap, a new violation evicts the least-seen entry (the oldest of equally rare ones).
	 */
	public function test_eviction(): void {
		$store = blueline_core_csp_empty_store();
		$add   = static function ( array $store, string $blocked, int $times, string $day ): array {
			for ( $i = 0; $i < $times; $i++ ) {
				$store = blueline_core_csp_add_to_store(
					$store,
					array(
						'directive' => 'script-src-elem',
						'blocked'   => $blocked,
						'path'      => '/',
					),
					$day,
					3
				);
			}
			return $store;
		};

		$store = $add( $store, 'https://a.test', 3, '2026-10-01' );
		$store = $add( $store, 'https://b.test', 1, '2026-10-02' );
		$store = $add( $store, 'https://c.test', 1, '2026-10-03' );
		$store = $add( $store, 'https://d.test', 1, '2026-10-04' );

		$blocked = array_column( $store['entries'], 'blocked' );
		$this->assertCount( 3, $store['entries'] );
		$this->assertNotContains( 'https://b.test', $blocked );
		$this->assertContains( 'https://a.test', $blocked );
		$this->assertContains( 'https://c.test', $blocked );
		$this->assertContains( 'https://d.test', $blocked );
		$this->assertSame( 6, $store['total'] );
	}

	/**
	 * The default cap is 200 entries.
	 */
	public function test_default_cap(): void {
		$this->assertSame( 200, BLUELINE_CORE_CSP_REPORT_MAX_ENTRIES );
	}

	/**
	 * A batch counts at most its first BLUELINE_CORE_CSP_REPORT_MAX_PER_BATCH reports.
	 */
	public function test_batch_is_capped(): void {
		$reports = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$reports[] = $this->api_report( 'img-src', 'https://img' . $i . '.test/x.png' );
		}

		blueline_core_csp_report_handle( $this->request( 'application/reports+json', (string) wp_json_encode( $reports ) ) );

		$this->assertSame( BLUELINE_CORE_CSP_REPORT_MAX_PER_BATCH, blueline_core_csp_store()['total'] );
	}

	/**
	 * Each visitor may send a limited number of reports per window; another visitor is unaffected.
	 */
	public function test_rate_limit(): void {
		for ( $i = 0; $i < BLUELINE_CORE_CSP_REPORT_RATE_LIMIT; $i++ ) {
			$this->assertSame( 204, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) ) ), "request $i" );
		}

		$this->assertSame( 429, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) ) ) );
		$this->assertSame( 204, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body(), 'Other browser' ) ) ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->assertSame( 204, $this->http_status( blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) ) ) );

		$this->assertSame( BLUELINE_CORE_CSP_REPORT_RATE_LIMIT + 2, blueline_core_csp_store()['total'] );
	}

	/**
	 * The window is fixed: it starts with the visitor's first request and ends 10 minutes later however
	 * many requests arrive in it, so a steady trickle of reports is not locked out for good. The counter's
	 * lifetime is what is left of the window, never a fresh 10 minutes.
	 */
	public function test_rate_limit_window_is_fixed(): void {
		$start = gmmktime( 12, 0, 0, 10, 15, 2026 );
		$key   = 'bl_csp_rl_' . substr( blueline_core_csp_visitor_key( self::IP, self::UA, '2026-10-15' ), 0, 24 );

		for ( $i = 0; $i < BLUELINE_CORE_CSP_REPORT_RATE_LIMIT; $i++ ) {
			$now = $start + $i * 15;
			$this->assertTrue( blueline_core_csp_report_allow_visitor( self::IP, self::UA, $now ), "request $i" );
			$this->assertSame( BLUELINE_CORE_CSP_REPORT_RATE_WINDOW - $i * 15, $GLOBALS['bl_test_transient_ttl'][ $key ], "ttl after request $i" );
		}

		$this->assertFalse( blueline_core_csp_report_allow_visitor( self::IP, self::UA, $start + BLUELINE_CORE_CSP_REPORT_RATE_WINDOW - 1 ) );
		$this->assertTrue( blueline_core_csp_report_allow_visitor( self::IP, self::UA, $start + BLUELINE_CORE_CSP_REPORT_RATE_WINDOW ) );
		$this->assertSame( BLUELINE_CORE_CSP_REPORT_RATE_WINDOW, $GLOBALS['bl_test_transient_ttl'][ $key ] );
	}

	/**
	 * A counter left over from the old format (a bare integer) or one whose window is somehow in the
	 * future starts a fresh window rather than locking the visitor out.
	 */
	public function test_rate_limit_ignores_damaged_counters(): void {
		$now = gmmktime( 12, 0, 0, 10, 15, 2026 );
		$key = 'bl_csp_rl_' . substr( blueline_core_csp_visitor_key( self::IP, self::UA, '2026-10-15' ), 0, 24 );

		set_transient( $key, 999, 600 );
		$this->assertTrue( blueline_core_csp_report_allow_visitor( self::IP, self::UA, $now ) );
		$this->assertSame(
			array(
				'count' => 1,
				'start' => $now,
			),
			get_transient( $key )
		);

		set_transient(
			$key,
			array(
				'count' => 999,
				'start' => $now + 5000,
			),
			600
		);
		$this->assertTrue( blueline_core_csp_report_allow_visitor( self::IP, self::UA, $now ) );
	}

	/**
	 * Only paths shaped like the site's own pages are considered: a handful of short slug segments.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function path_shapes(): array {
		return array(
			'home'             => array( '/', true ),
			'page'             => array( '/schedule/', true ),
			'nested page'      => array( '/arl-league-info/rules', true ),
			'account endpoint' => array( '/account/edit-address/billing', true ),
			'paged archive'    => array( '/category/news/page/2/', true ),
			'encoded slug'     => array( '/team/caf%C3%A9-crew', true ),
			'dated post'       => array( '/2026/08/winter-season-registration-update/', true ),
			'too deep'         => array( '/a/b/c/d/e/f/g', false ),
			'segment too long' => array( '/' . str_repeat( 'a', 101 ), false ),
			'empty segment'    => array( '/team//mustangs', false ),
			'odd characters'   => array( '/team/<script>', false ),
			'quote'            => array( "/team/o'neil", false ),
			'dot segment'      => array( '/team/../wp-config.php', false ),
			'no leading slash' => array( 'schedule', false ),
		);
	}

	/**
	 * See path_shapes().
	 *
	 * @param string $path     Path.
	 * @param bool   $expected Whether it is a plausible page path.
	 */
	#[DataProvider( 'path_shapes' )]
	public function test_path_shape( string $path, bool $expected ): void {
		$this->assertSame( $expected, blueline_core_csp_path_is_plausible( $path ) );
	}

	/**
	 * A path resolves when it is the front page, a post or page (url_to_postid(), which also covers the
	 * account endpoints), a published page WordPress lists other things on, or a taxonomy term archive;
	 * page 2+ of a list resolves when page 1 does.
	 */
	public function test_path_resolves(): void {
		$GLOBALS['arl_dr_test_post_ids']['https://example.test/account/payment-methods'] = 20;
		$GLOBALS['arl_dr_test_pages']['news']       = (object) array( 'post_status' => 'publish' );
		$GLOBALS['arl_dr_test_pages']['draft-page'] = (object) array( 'post_status' => 'draft' );
		$GLOBALS['arl_dr_test_taxonomies']          = array(
			(object) array(
				'name'    => 'sp_venue',
				'rewrite' => array( 'slug' => 'venue' ),
			),
		);
		$GLOBALS['arl_dr_test_terms']['sp_venue']   = array( 'red' );

		foreach ( array( '/', '/schedule/', '/account/payment-methods', '/news/', '/news/page/3/', '/venue/red/', '/team/mustangs' ) as $path ) {
			$this->assertTrue( blueline_core_csp_path_resolves( $path ), $path );
		}

		foreach ( array( '/verify4-ratelimit', '/draft-page', '/venue/blue', '/nothing/red', '/team/mustangs/extra' ) as $path ) {
			$this->assertFalse( blueline_core_csp_path_resolves( $path ), $path );
		}
	}

	/**
	 * The `blueline_core_csp_report_path_resolves` filter can accept or refuse a path.
	 */
	public function test_path_resolves_filter(): void {
		add_filter(
			'blueline_core_csp_report_path_resolves',
			static function ( $resolves, $path ) {
				return '/special' === $path ? true : $resolves;
			},
			10,
			2
		);

		$this->assertTrue( blueline_core_csp_path_resolves( '/special' ) );
		$this->assertFalse( blueline_core_csp_path_resolves( '/other' ) );
	}

	/**
	 * Reports from a page that is not one of the site's (made-up paths, odd shapes) are not counted, so
	 * junk cannot fill the 200-entry store and push out real rows.
	 */
	public function test_reports_from_unknown_pages_are_not_counted(): void {
		foreach ( array( 'https://example.test/verify4-ratelimit', 'https://example.test/a/b/c/d/e/f/g/h', 'https://example.test/team/%3Cscript%3E%22' ) as $url ) {
			$result = blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body( array( 'document-uri' => $url ) ) ) );
			$this->assertSame( 400, $this->http_status( $result ), $url );
		}

		$batch  = (string) wp_json_encode(
			array(
				$this->api_report( 'img-src', 'https://junk.test/x.png', 'https://example.test/junk-1' ),
				$this->api_report( 'img-src', 'https://real.test/x.png', 'https://example.test/team/mustangs' ),
			)
		);
		$result = blueline_core_csp_report_handle( $this->request( 'application/reports+json', $batch ) );

		$this->assertSame( 204, $this->http_status( $result ) );
		$this->assertSame( array( array( 'img-src', 'https://real.test', '/team/mustangs', 1 ) ), $this->entries() );
		$this->assertSame( 1, blueline_core_csp_store()['total'] );
	}

	/**
	 * A violation already in the store is counted again without looking its page up (the lookup costs
	 * queries, and the page was real when it was first stored).
	 */
	public function test_known_entries_skip_the_page_lookup(): void {
		$lookups = 0;
		add_filter(
			'blueline_core_csp_report_path_resolves',
			static function ( $resolves ) use ( &$lookups ) {
				++$lookups;
				return $resolves;
			}
		);

		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );

		$this->assertSame( 1, $lookups );
		$this->assertSame( 3, $this->entries()[0][3] );
	}

	/**
	 * The visitor key is a keyed hash that changes every day and never contains the address.
	 */
	public function test_visitor_key(): void {
		$today    = blueline_core_csp_visitor_key( self::IP, self::UA, '2026-10-14' );
		$tomorrow = blueline_core_csp_visitor_key( self::IP, self::UA, '2026-10-15' );

		$this->assertNotSame( $today, $tomorrow );
		$this->assertSame( $today, blueline_core_csp_visitor_key( self::IP, self::UA, '2026-10-14' ) );
		$this->assertNotSame( $today, blueline_core_csp_visitor_key( self::IP, 'Other', '2026-10-14' ) );
		$this->assertStringNotContainsString( self::IP, $today );
	}

	/**
	 * Nothing stored anywhere (options or transients) contains the visitor's address, user agent, the
	 * query string of the page or the full blocked URL.
	 */
	public function test_nothing_identifying_is_stored(): void {
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		blueline_core_csp_report_handle( $this->request( 'application/reports+json', (string) wp_json_encode( array( $this->api_report( 'img-src', 'https://img.test/p.png?id=99', 'https://example.test/player/x?ref=mail' ) ) ) ) );

		$stored = serialize( array( $GLOBALS['bl_test_options'], $GLOBALS['bl_test_transients'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flatten everything stored for a substring check.

		foreach ( array( self::IP, self::UA, 'Mozilla', 'team=12', 'email=', 'token=', 'secret', 'id=99', 'ref=mail', '?' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $stored, $needle );
		}
	}

	/**
	 * The store is a non-autoloaded option, so it never weighs on page loads.
	 */
	public function test_store_not_autoloaded(): void {
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );

		$this->assertSame( array( false ), array_unique( $GLOBALS['bl_test_option_autoload'][ BLUELINE_CORE_CSP_REPORT_OPTION ] ) );
	}

	/**
	 * A damaged stored value reads back as an empty store.
	 */
	public function test_damaged_store_reads_empty(): void {
		update_option( BLUELINE_CORE_CSP_REPORT_OPTION, 'garbage', false );
		$this->assertSame( blueline_core_csp_empty_store(), blueline_core_csp_store() );

		update_option( BLUELINE_CORE_CSP_REPORT_OPTION, array( 'entries' => array( 'x' => 'y' ) ), false );
		$this->assertSame( array(), blueline_core_csp_store()['entries'] );
	}

	/**
	 * Uninstalling the plugin deletes the store.
	 */
	public function test_uninstall_deletes_store(): void {
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		$this->assertNotFalse( get_option( BLUELINE_CORE_CSP_REPORT_OPTION ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueline-core/blueline-core.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( BLUELINE_CORE_CSP_REPORT_OPTION ) );
	}

	/**
	 * Reset empties the store.
	 */
	public function test_reset(): void {
		blueline_core_csp_report_handle( $this->request( 'application/csp-report', $this->legacy_body() ) );
		blueline_core_csp_reset_store();

		$this->assertSame( array(), $this->entries() );
		$this->assertSame( 0, blueline_core_csp_store()['total'] );
	}
}
