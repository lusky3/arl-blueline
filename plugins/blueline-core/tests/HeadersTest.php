<?php
/**
 * Unit tests for includes/headers/headers.php.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/headers/headers.php';

/**
 * Covers the response headers the plugin adds to front-end pages.
 */
final class HeadersTest extends TestCase {

	/**
	 * Fresh hooks.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * The default set is a Permissions-Policy that turns off camera, microphone and geolocation; HSTS and
	 * a CSP are server decisions and are deliberately not sent from PHP.
	 */
	public function test_default_headers(): void {
		$headers = blueline_core_security_headers();

		$this->assertSame( array( 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()' ), $headers );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers );
	}

	/**
	 * A site can change or drop a header through the filter; a malformed entry (a name that is not a
	 * header token, a value with a line break, an empty value) is never sent.
	 */
	public function test_filter_can_change_and_bad_entries_are_dropped(): void {
		add_filter(
			'blueline_core_security_headers',
			static function ( $headers ) {
				$headers['Permissions-Policy'] = 'camera=(self)';
				$headers['X-Bad Name']         = 'x';
				$headers['X-Split']            = "a\r\nSet-Cookie: x=1";
				$headers['X-Empty']            = '';
				$headers[0]                    = 'no name';
				return $headers;
			}
		);

		$this->assertSame( array( 'Permissions-Policy' => 'camera=(self)' ), blueline_core_security_headers() );
	}

	/**
	 * A filter that returns something other than an array sends nothing.
	 */
	public function test_non_array_filter_result_sends_nothing(): void {
		add_filter( 'blueline_core_security_headers', '__return_false' );

		$this->assertSame( array(), blueline_core_security_headers() );
	}

	/**
	 * Each header is emitted once, replacing any earlier one of the same name from PHP.
	 */
	public function test_emit_sends_each_header_replacing(): void {
		$sent = array();

		blueline_core_emit_headers(
			array(
				'Permissions-Policy' => 'camera=()',
				'X-Other'            => 'y',
			),
			static function ( string $line, bool $replace ) use ( &$sent ): void {
				$sent[] = array( $line, $replace );
			}
		);

		$this->assertSame(
			array(
				array( 'Permissions-Policy: camera=()', true ),
				array( 'X-Other: y', true ),
			),
			$sent
		);
	}

	/**
	 * Sent with WordPress's own front-end headers.
	 */
	public function test_hooked_on_send_headers(): void {
		$this->assertNotFalse( has_action( 'send_headers', 'blueline_core_send_security_headers' ) );
	}
}
