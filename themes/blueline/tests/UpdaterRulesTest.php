<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';

/**
 * Covers the pure redirect and URL-scoping rules in inc/updater-rules.php:
 * where the GitHub token may go, and which redirects may be followed.
 */
final class UpdaterRulesTest extends TestCase {

	/**
	 * Repo API base used by the cases below.
	 */
	private const API = 'https://api.github.com/repos/lusky3/arl-blueline/';

	/**
	 * URLs the token may be sent to.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function accepted_urls(): array {
		return array(
			'releases list'  => array( self::API . 'releases?per_page=10' ),
			'asset'          => array( self::API . 'releases/assets/123' ),
			'uppercase host' => array( 'https://API.GITHUB.COM/repos/lusky3/arl-blueline/releases' ),
		);
	}

	/**
	 * URLs the token must never be sent to.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function rejected_urls(): array {
		return array(
			'other repo'            => array( 'https://api.github.com/repos/lusky3/other/releases' ),
			'repo name prefix'      => array( 'https://api.github.com/repos/lusky3/arl-blueline-evil/x' ),
			'http'                  => array( 'http://api.github.com/repos/lusky3/arl-blueline/x' ),
			'lookalike subdomain'   => array( 'https://api.github.com.evil.test/repos/lusky3/arl-blueline/x' ),
			'host in path'          => array( 'https://evil.test/api.github.com/repos/lusky3/arl-blueline/x' ),
			'userinfo'              => array( 'https://user@api.github.com/repos/lusky3/arl-blueline/x' ),
			'port'                  => array( 'https://api.github.com:8443/repos/lusky3/arl-blueline/x' ),
			'github.com not api'    => array( 'https://github.com/lusky3/arl-blueline/releases' ),
			'dot-dot traversal'     => array( 'https://api.github.com/repos/lusky3/arl-blueline/../other/x' ),
			'encoded traversal'     => array( 'https://api.github.com/repos/lusky3/arl-blueline/%2e%2e/other/x' ),
			'encoded traversal alt' => array( 'https://api.github.com/repos/lusky3/arl-blueline/%2E%2E/other/x' ),
			'empty'                 => array( '' ),
		);
	}

	/**
	 * Repo API URLs are accepted.
	 *
	 * @param string $url URL.
	 */
	#[DataProvider( 'accepted_urls' )]
	public function test_is_repo_api_url_accepts( string $url ): void {
		$this->assertTrue( blueline_updater_is_repo_api_url( $url ) );
	}

	/**
	 * Everything else is refused.
	 *
	 * @param string $url URL.
	 */
	#[DataProvider( 'rejected_urls' )]
	public function test_is_repo_api_url_rejects( string $url ): void {
		$this->assertFalse( blueline_updater_is_repo_api_url( $url ) );
	}

	/**
	 * A redirect to signed storage is followed, and without the token.
	 */
	public function test_next_hop_follows_trusted_storage_without_auth(): void {
		foreach ( BLUELINE_UPDATER_STORAGE_HOSTS as $host ) {
			$hop = blueline_updater_next_hop( 302, 'https://' . $host . '/x?sig=1', self::API . 'releases/assets/1', 0 );

			$this->assertSame( 'follow', $hop['action'], $host );
			$this->assertFalse( $hop['send_auth'], $host );
		}

		$this->assertContains( 'release-assets.githubusercontent.com', BLUELINE_UPDATER_STORAGE_HOSTS );
		$this->assertSame( array( 'action' => 'done' ), blueline_updater_next_hop( 200, '', self::API . 'x', 0 ) );
	}

	/**
	 * Unsafe or unexpected redirects are refused with a specific code.
	 *
	 * @return array<string, array{0: int, 1: string, 2: int, 3: string}>
	 */
	public static function refused_hops(): array {
		return array(
			'http location'       => array( 302, 'http://objects.githubusercontent.com/x', 0, 'insecure_redirect' ),
			'relative location'   => array( 302, '/x', 0, 'insecure_redirect' ),
			'lookalike suffix'    => array( 302, 'https://objects.githubusercontent.com.evil.test/x', 0, 'untrusted_host' ),
			'lookalike prefix'    => array( 302, 'https://evil-githubusercontent.com/x', 0, 'untrusted_host' ),
			'other host'          => array( 302, 'https://evil.test/x', 0, 'untrusted_host' ),
			'missing location'    => array( 302, '', 0, 'missing_location' ),
			'too many hops'       => array( 302, 'https://objects.githubusercontent.com/x', 3, 'too_many_redirects' ),
			'not found'           => array( 404, '', 0, 'bad_status' ),
			'not a redirect code' => array( 300, 'https://objects.githubusercontent.com/x', 0, 'bad_status' ),
		);
	}

	/**
	 * Refusals.
	 *
	 * @param int    $status   Status.
	 * @param string $location Location header.
	 * @param int    $hop      Hops so far.
	 * @param string $code     Expected failure code.
	 */
	#[DataProvider( 'refused_hops' )]
	public function test_next_hop_refuses( int $status, string $location, int $hop, string $code ): void {
		$result = blueline_updater_next_hop( $status, $location, self::API . 'releases/assets/1', $hop );

		$this->assertSame( 'fail', $result['action'] );
		$this->assertSame( $code, $result['code'] );
	}

	/**
	 * A redirect back onto the repo API keeps the token.
	 */
	public function test_next_hop_redirect_back_to_repo_api_keeps_auth(): void {
		$hop = blueline_updater_next_hop( 302, self::API . 'releases/assets/2', self::API . 'releases/assets/1', 0 );

		$this->assertSame( 'follow', $hop['action'] );
		$this->assertTrue( $hop['send_auth'] );
	}

	/**
	 * Status classification for Site Health and the cache TTL.
	 *
	 * @return array<string, array{0: int, 1: array<string, string>, 2: string}>
	 */
	public static function statuses(): array {
		return array(
			'200'               => array( 200, array(), 'ok' ),
			'401'               => array( 401, array(), 'unauthorized' ),
			'403 plain'         => array( 403, array(), 'unauthorized' ),
			'403 no quota left' => array( 403, array( 'x-ratelimit-remaining' => '0' ), 'rate_limited' ),
			'403 quota left'    => array( 403, array( 'x-ratelimit-remaining' => '12' ), 'unauthorized' ),
			'429'               => array( 429, array(), 'rate_limited' ),
			'500'               => array( 500, array(), 'error' ),
			'404'               => array( 404, array(), 'error' ),
		);
	}

	/**
	 * Classification.
	 *
	 * @param int                   $code    HTTP status.
	 * @param array<string, string> $headers Lower-cased response headers.
	 * @param string                $expect  Expected class.
	 */
	#[DataProvider( 'statuses' )]
	public function test_classify_status( int $code, array $headers, string $expect ): void {
		$this->assertSame( $expect, blueline_updater_classify_status( $code, $headers ) );
	}
}
