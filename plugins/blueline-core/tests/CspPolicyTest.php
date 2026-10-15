<?php
/**
 * Unit tests for includes/headers/csp.php: the Content-Security-Policy and when it is sent.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/headers/headers.php';

/**
 * Covers the policy builder per page type, the mode switch and the request filter.
 */
final class CspPolicyTest extends TestCase {

	private const REPORT_URL = 'https://example.test/wp-json/blueline/v1/csp-report';

	/**
	 * Origins only cart, checkout and account pages may load.
	 */
	private const COMMERCE_ONLY = array(
		'https://*.paypal.com',
		'https://*.paypalobjects.com',
		'https://pay.google.com',
		'https://google.com/pay',
		'https://applepay.cdn-apple.com',
		'https://maps.googleapis.com',
		'https://places.googleapis.com',
		'https://ajax.googleapis.com',
	);

	/**
	 * Fresh hooks and request.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		unset( $GLOBALS['bl_core_test_conditionals'], $GLOBALS['bl_core_test_is_account_page'], $GLOBALS['bl_core_test_doing_cron'], $GLOBALS['bl_core_test_rest_request'] );
	}

	/**
	 * Clean up request globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_core_test_conditionals'], $GLOBALS['bl_core_test_is_account_page'], $GLOBALS['bl_core_test_doing_cron'], $GLOBALS['bl_core_test_rest_request'], $GLOBALS['bl_test_is_admin'] );
	}

	/**
	 * Request flags for an ordinary front-end page.
	 *
	 * @param array<string, bool> $overrides Flags to change.
	 * @return array<string, bool>
	 */
	private function page( array $overrides = array() ): array {
		return array_merge( array_fill_keys( blueline_core_csp_skip_flags(), false ), array( 'commerce' => false ), $overrides );
	}

	/**
	 * Content pages: everything from the site itself, the push-notification, analytics and Cloudflare
	 * scripts every page loads, the Turnstile and Google Maps embeds content can carry, and nothing for
	 * payments.
	 */
	public function test_content_policy(): void {
		$d = blueline_core_csp_directives( false );

		$this->assertSame( array( "'self'" ), $d['default-src'] );
		$this->assertSame( array( "'self'" ), $d['base-uri'] );
		$this->assertSame( array( "'none'" ), $d['object-src'] );
		$this->assertSame( array( "'self'" ), $d['frame-ancestors'] );
		$this->assertSame( array( "'self'" ), $d['form-action'] );
		$this->assertSame( array( "'self'", 'data:', 'https:' ), $d['img-src'] );
		$this->assertSame( array( "'self'", 'data:' ), $d['font-src'] );
		$this->assertSame( array( "'self'", 'blob:' ), $d['worker-src'] );

		foreach ( array( "'self'", "'unsafe-inline'", 'https://cdn.onesignal.com', 'https://static.cloudflareinsights.com', 'https://challenges.cloudflare.com' ) as $source ) {
			$this->assertContains( $source, $d['script-src'] );
		}
		$this->assertNotContains( "'unsafe-eval'", $d['script-src'] );
		$this->assertContains( "'unsafe-inline'", $d['style-src'] );
		$this->assertContains( 'https://*.google-analytics.com', $d['connect-src'] );
		$this->assertContains( 'https://cloudflareinsights.com', $d['connect-src'] );
		$this->assertContains( 'https://maps.google.com', $d['frame-src'] );

		foreach ( $d as $directive => $sources ) {
			foreach ( self::COMMERCE_ONLY as $origin ) {
				$this->assertNotContains( $origin, $sources, "$origin must not be allowed in $directive on a content page" );
			}
		}
	}

	/**
	 * Cart, checkout and account pages add PayPal, Google Pay, Apple Pay and the Google Maps address
	 * lookup, on top of everything a content page allows.
	 */
	public function test_commerce_policy_adds_payment_origins(): void {
		$content  = blueline_core_csp_directives( false );
		$commerce = blueline_core_csp_directives( true );

		foreach ( $content as $directive => $sources ) {
			foreach ( $sources as $source ) {
				$this->assertContains( $source, $commerce[ $directive ], "commerce $directive keeps $source" );
			}
		}

		$this->assertContains( 'https://*.paypal.com', $commerce['script-src'] );
		$this->assertContains( 'https://*.paypalobjects.com', $commerce['script-src'] );
		$this->assertContains( 'https://pay.google.com', $commerce['script-src'] );
		$this->assertContains( 'https://applepay.cdn-apple.com', $commerce['script-src'] );
		$this->assertContains( 'https://maps.googleapis.com', $commerce['script-src'] );
		$this->assertContains( 'https://ajax.googleapis.com', $commerce['style-src'] );
		$this->assertContains( 'https://*.paypal.com', $commerce['connect-src'] );
		$this->assertContains( 'https://google.com/pay', $commerce['connect-src'] );
		$this->assertContains( 'https://*.paypal.com', $commerce['frame-src'] );
		$this->assertContains( 'https://pay.google.com', $commerce['frame-src'] );
		$this->assertSame( array( "'self'", 'https://*.paypal.com' ), $commerce['form-action'] );
	}

	/**
	 * The policy string ends with both report directives pointing at the endpoint.
	 */
	public function test_policy_string(): void {
		$policy = blueline_core_csp_policy( false, self::REPORT_URL );

		$this->assertStringStartsWith( "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; ", $policy );
		$this->assertStringContainsString( "; img-src 'self' data: https:; ", $policy );
		$this->assertStringEndsWith( '; report-uri ' . self::REPORT_URL . '; report-to blueline-csp', $policy );
		$this->assertStringNotContainsString( 'paypal', $policy );
		$this->assertStringContainsString( 'https://*.paypal.com', blueline_core_csp_policy( true, self::REPORT_URL ) );
	}

	/**
	 * The filter sees the directives and the page type; a malformed directive name or a source that could
	 * break out of its directive is never sent.
	 */
	public function test_policy_filter(): void {
		$seen = null;
		add_filter(
			'blueline_core_csp_policy',
			static function ( $directives, $context ) use ( &$seen ) {
				$seen                                    = $context;
				$directives['frame-src'][]               = 'https://www.youtube-nocookie.com';
				$directives['img-src'][]                 = "https://a.test; script-src 'unsafe-eval'";
				$directives['bad name']                  = array( "'self'" );
				$directives['script-src'][]              = "https://b.test\nX: y";
				$directives['upgrade-insecure-requests'] = array();
				return $directives;
			},
			10,
			2
		);

		$policy = blueline_core_csp_policy( true, self::REPORT_URL );

		$this->assertSame( array( 'commerce' => true ), $seen );
		$this->assertStringContainsString( 'https://www.youtube-nocookie.com', $policy );
		$this->assertStringEndsWith( '; report-to blueline-csp; upgrade-insecure-requests', $policy );
		$this->assertStringNotContainsString( 'unsafe-eval', $policy );
		$this->assertStringNotContainsString( 'bad name', $policy );
		$this->assertStringNotContainsString( 'b.test', $policy );
		$this->assertStringNotContainsString( "\n", $policy );
	}

	/**
	 * A filter that returns no directives sends no policy at all.
	 */
	public function test_non_array_filter_sends_nothing(): void {
		add_filter( 'blueline_core_csp_policy', '__return_false' );

		$this->assertSame( '', blueline_core_csp_policy( false, self::REPORT_URL ) );
		$this->assertSame( array(), blueline_core_csp_headers_for( 'report-only', $this->page(), self::REPORT_URL ) );
	}

	/**
	 * Report-only is the default; enforce and off are opt-in through the filter; anything else falls
	 * back to report-only rather than enforcing by accident.
	 */
	public function test_mode(): void {
		$this->assertSame( 'report-only', blueline_core_csp_mode() );

		foreach ( array( 'enforce', 'off', 'report-only' ) as $mode ) {
			add_filter( 'blueline_core_csp_mode', static fn() => $mode );
			$this->assertSame( $mode, blueline_core_csp_mode() );
			blueline_test_reset_hooks();
		}

		add_filter( 'blueline_core_csp_mode', static fn() => 'ENFORCE!' );
		$this->assertSame( 'report-only', blueline_core_csp_mode() );
	}

	/**
	 * Report-only sends the report-only header plus the Reporting-Endpoints entry the report-to
	 * directive names; enforce sends the enforcing header instead; off sends nothing.
	 */
	public function test_headers_per_mode(): void {
		$report_only = blueline_core_csp_headers_for( 'report-only', $this->page(), self::REPORT_URL );
		$this->assertSame( array( 'Content-Security-Policy-Report-Only', 'Reporting-Endpoints' ), array_keys( $report_only ) );
		$this->assertSame( 'blueline-csp="' . self::REPORT_URL . '"', $report_only['Reporting-Endpoints'] );
		$this->assertSame( blueline_core_csp_policy( false, self::REPORT_URL ), $report_only['Content-Security-Policy-Report-Only'] );

		$enforce = blueline_core_csp_headers_for( 'enforce', $this->page(), self::REPORT_URL );
		$this->assertSame( array( 'Content-Security-Policy', 'Reporting-Endpoints' ), array_keys( $enforce ) );

		$this->assertSame( array(), blueline_core_csp_headers_for( 'off', $this->page(), self::REPORT_URL ) );
	}

	/**
	 * A checkout, cart or account page gets the commerce policy.
	 */
	public function test_commerce_page_gets_commerce_policy(): void {
		$headers = blueline_core_csp_headers_for( 'report-only', $this->page( array( 'commerce' => true ) ), self::REPORT_URL );

		$this->assertSame( blueline_core_csp_policy( true, self::REPORT_URL ), $headers['Content-Security-Policy-Report-Only'] );
	}

	/**
	 * Only front-end HTML pages get a policy: never wp-admin, admin-ajax, cron, REST, feeds, robots.txt,
	 * the favicon, trackbacks, oEmbed embeds (rendered inside other sites), sitemaps or wc-ajax.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function skipped_requests(): array {
		$cases = array();
		foreach ( array( 'admin', 'ajax', 'cron', 'rest', 'feed', 'robots', 'favicon', 'trackback', 'embed', 'sitemap', 'wc_ajax' ) as $flag ) {
			$cases[ $flag ] = array( $flag );
		}
		return $cases;
	}

	/**
	 * See skipped_requests().
	 *
	 * @param string $flag The request flag that is set.
	 */
	#[DataProvider( 'skipped_requests' )]
	public function test_not_sent_outside_front_end_pages( string $flag ): void {
		$this->assertContains( $flag, blueline_core_csp_skip_flags() );
		$this->assertSame( array(), blueline_core_csp_headers_for( 'report-only', $this->page( array( $flag => true ) ), self::REPORT_URL ) );
	}

	/**
	 * The request flags come from WordPress's own conditionals.
	 */
	public function test_request_flags(): void {
		$flags = blueline_core_csp_request_flags();
		$this->assertSame( $this->page(), $flags );

		$GLOBALS['bl_core_test_conditionals'] = array(
			'is_feed'  => true,
			'is_embed' => true,
		);
		$GLOBALS['bl_test_is_admin']          = true;
		$GLOBALS['bl_core_test_rest_request'] = true;
		$GLOBALS['bl_core_test_doing_cron']   = true;

		$flags = blueline_core_csp_request_flags();
		foreach ( array( 'feed', 'embed', 'admin', 'rest', 'cron' ) as $flag ) {
			$this->assertTrue( $flags[ $flag ], $flag );
		}
	}

	/**
	 * Cart, checkout and every account page are commerce pages, and so is every page when the theme's
	 * `blueline_keep_payment_scripts` filter keeps the payment scripts everywhere.
	 */
	public function test_commerce_flag(): void {
		foreach ( array( 'is_cart', 'is_checkout' ) as $conditional ) {
			$GLOBALS['bl_core_test_conditionals'] = array( $conditional => true );
			$this->assertTrue( blueline_core_csp_request_flags()['commerce'], $conditional );
		}

		$GLOBALS['bl_core_test_conditionals']    = array();
		$GLOBALS['bl_core_test_is_account_page'] = true;
		$this->assertTrue( blueline_core_csp_request_flags()['commerce'] );

		$GLOBALS['bl_core_test_is_account_page'] = false;
		$this->assertFalse( blueline_core_csp_request_flags()['commerce'] );

		add_filter( 'blueline_keep_payment_scripts', '__return_true' );
		$this->assertTrue( blueline_core_csp_request_flags()['commerce'] );
	}

	/**
	 * The report URL is the plugin's REST route.
	 */
	public function test_report_url(): void {
		$this->assertSame( self::REPORT_URL, blueline_core_csp_report_url() );
	}

	/**
	 * Emitted without replacing: another component's policy (WooCommerce sends `frame-ancestors 'self'`
	 * on account pages) or reporting endpoint must survive next to ours.
	 */
	public function test_emit_does_not_replace_other_headers(): void {
		$sent = array();

		blueline_core_emit_csp_headers(
			static function ( string $line, bool $replace ) use ( &$sent ): void {
				$sent[] = array( $line, $replace );
			}
		);

		$this->assertCount( 2, $sent );
		$this->assertStringStartsWith( "Content-Security-Policy-Report-Only: default-src 'self';", $sent[0][0] );
		$this->assertFalse( $sent[0][1] );
		$this->assertSame( array( 'Reporting-Endpoints: blueline-csp="' . self::REPORT_URL . '"', false ), $sent[1] );
	}

	/**
	 * Nothing is emitted for a request that is not a front-end page.
	 */
	public function test_emit_skips_non_pages(): void {
		$GLOBALS['bl_core_test_conditionals'] = array( 'is_feed' => true );
		$sent                                 = array();

		blueline_core_emit_csp_headers(
			static function ( string $line ) use ( &$sent ): void {
				$sent[] = $line;
			}
		);

		$this->assertSame( array(), $sent );
	}

	/**
	 * Sent once the main query has run (the page-type conditionals need it), before any output.
	 */
	public function test_hooked_on_template_redirect(): void {
		$this->assertNotFalse( has_action( 'template_redirect', 'blueline_core_send_csp_headers' ) );
	}
}
