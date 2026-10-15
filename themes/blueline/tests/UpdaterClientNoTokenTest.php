<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/no-release-keys.php';
require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php';

/**
 * The repository is public, so a missing or unusable token means an anonymous
 * request, never "updates off" and never an Authorization header.
 */
final class UpdaterClientNoTokenTest extends TestCase {

	private const LIST_URL = 'https://api.github.com/repos/lusky3/arl-blueline/releases?per_page=10';

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
	 * Queue an empty release list, so a check completes with nothing newer.
	 */
	private function expect_empty_release_list(): void {
		blueline_test_http_expect(
			self::LIST_URL,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
			)
		);
	}

	/**
	 * Nothing defined at all: a real, anonymous check, and its result is cached.
	 */
	public function test_undefined_token_checks_anonymously_and_caches_the_result(): void {
		$this->expect_empty_release_list();

		$this->assertSame( '', blueline_updater_token() );
		$this->assertSame( 'ok', blueline_updater_state()['status'] );
		$this->assertCount( 1, $GLOBALS['bl_test_http_log'] );
		$this->assertSame( self::LIST_URL, $GLOBALS['bl_test_http_log'][0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['bl_test_http_log'][0]['args']['headers'] );

		// A second call is served from the cache, not a second request.
		blueline_updater_state();
		$this->assertCount( 1, $GLOBALS['bl_test_http_log'] );
	}

	/**
	 * Defined but unusable: treated exactly like no token, so no header.
	 *
	 * @param mixed $value Constant value.
	 */
	#[RunInSeparateProcess]
	#[DataProvider( 'unusable_tokens' )]
	public function test_unusable_token_is_treated_as_absent( $value ): void {
		define( 'BLUELINE_GITHUB_TOKEN', $value );
		$this->expect_empty_release_list();

		$this->assertSame( '', blueline_updater_token() );
		$this->assertSame( 'ok', blueline_updater_state()['status'] );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['bl_test_http_log'][0]['args']['headers'] );
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
	 * Requests to the repo API go out without credentials when none are set.
	 */
	public function test_request_to_repo_api_without_token_sends_no_authorization_header(): void {
		$this->expect_empty_release_list();

		$result = blueline_updater_request( self::LIST_URL );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $GLOBALS['bl_test_http_log'] );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['bl_test_http_log'][0]['args']['headers'] );
	}

	/**
	 * A configured token is still sent to the repo API, and only there.
	 */
	#[RunInSeparateProcess]
	public function test_configured_token_is_sent_to_the_repo_api_only(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'tok-123' );
		$this->expect_empty_release_list();
		blueline_test_http_expect(
			'https://example.com/elsewhere',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'x',
			)
		);

		blueline_updater_request( self::LIST_URL );
		blueline_updater_request( 'https://example.com/elsewhere' );

		$this->assertSame( 'Bearer tok-123', $GLOBALS['bl_test_http_log'][0]['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['bl_test_http_log'][1]['args']['headers'] );
	}
}
