<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php';

/**
 * A missing or unusable token means no state, no request, no error.
 */
final class UpdaterClientNoTokenTest extends TestCase {

	/**
	 * Reset the in-memory stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Token values that must count as "no token".
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_tokens(): array {
		return array(
			'empty string' => array( '' ),
			'whitespace'   => array( "  \t\n" ),
			'integer'      => array( 12345 ),
			'array'        => array( array( 'x' ) ),
			'null'         => array( null ),
		);
	}

	/**
	 * Nothing defined at all.
	 */
	public function test_undefined_token_is_empty_and_state_makes_no_request(): void {
		$this->assertSame( '', blueline_updater_token() );
		$this->assertSame( 'no_token', blueline_updater_state()['status'] );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
		$this->assertSame( array(), $GLOBALS['bl_test_site_transients'], 'no_token is never cached' );
	}

	/**
	 * Defined but unusable (Review Focus 3).
	 *
	 * @param mixed $value Constant value.
	 */
	#[RunInSeparateProcess]
	#[DataProvider( 'unusable_tokens' )]
	public function test_unusable_token_is_treated_as_absent( $value ): void {
		define( 'BLUELINE_GITHUB_TOKEN', $value );

		$this->assertSame( '', blueline_updater_token() );
		$this->assertSame( 'no_token', blueline_updater_state()['status'] );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
	}

	/**
	 * A padded token is trimmed, not rejected.
	 */
	#[RunInSeparateProcess]
	public function test_token_is_trimmed(): void {
		define( 'BLUELINE_GITHUB_TOKEN', "  abc \n" );

		$this->assertSame( 'abc', blueline_updater_token() );
	}

	/**
	 * Requests to the repo API never go out unauthenticated.
	 */
	public function test_request_to_repo_api_without_token_fails_without_a_request(): void {
		$result = blueline_updater_request( 'https://api.github.com/repos/lusky3/arl-blueline/releases' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
	}
}
