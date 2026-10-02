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
 * Covers inc/updater-client.php with a token configured (the class runs in its
 * own processes so the constants below cannot leak into other tests).
 */
final class UpdaterClientTest extends TestCase {

	private const TOKEN   = 'test-token-abc123';
	private const API     = 'https://api.github.com/repos/lusky3/rookiehockey-blueline/';
	private const STORAGE = 'https://release-assets.githubusercontent.com/signed/';

	/**
	 * Configure the token and the beta channel, and reset the in-memory stores.
	 */
	protected function setUp(): void {
		// Each test runs in its own process, so these cannot leak.
		define( 'BLUELINE_GITHUB_TOKEN', self::TOKEN );
		define( 'BLUELINE_UPDATE_CHANNEL', 'beta' );
		blueline_test_reset();
	}

	/**
	 * The releases list URL.
	 *
	 * @return string
	 */
	private function list_url(): string {
		return self::API . 'releases?per_page=10';
	}

	/**
	 * The asset API URL for a release's zip or manifest.
	 *
	 * @param int  $n        Release number (distinguishes releases).
	 * @param bool $manifest Manifest asset instead of the zip.
	 * @return string
	 */
	private function asset_url( int $n, bool $manifest ): string {
		return self::API . 'releases/assets/' . ( $n * 10 + ( $manifest ? 2 : 1 ) );
	}

	/**
	 * A GitHub-shaped release.
	 *
	 * @param string $version Version without the `v`.
	 * @param int    $n       Release number.
	 * @return array<string, mixed>
	 */
	private function release( string $version, int $n ): array {
		return array(
			'tag_name'   => 'v' . $version,
			'draft'      => false,
			'prerelease' => false !== strpos( $version, '-' ),
			'html_url'   => 'https://github.com/lusky3/rookiehockey-blueline/releases/tag/v' . $version,
			'assets'     => array(
				array(
					'name' => 'blueline-' . $version . '.zip',
					'url'  => $this->asset_url( $n, false ),
				),
				array(
					'name' => 'manifest.json',
					'url'  => $this->asset_url( $n, true ),
				),
			),
		);
	}

	/**
	 * Queue the releases list.
	 *
	 * @param array<int, array<string, mixed>> $releases Releases.
	 */
	private function expect_list( array $releases ): void {
		blueline_test_http_expect(
			$this->list_url(),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $releases ),
			)
		);
	}

	/**
	 * Queue an asset: the API 302s to storage, storage serves the body.
	 *
	 * @param string $api_url API asset URL.
	 * @param string $body    File contents.
	 */
	private function expect_asset( string $api_url, string $body ): void {
		$signed = self::STORAGE . md5( $api_url ) . '?sig=1';

		blueline_test_http_expect(
			$api_url,
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => $signed ),
				'body'     => '',
			)
		);
		blueline_test_http_expect(
			$signed,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => $body,
			)
		);
	}

	/**
	 * A manifest JSON for a version and zip contents.
	 *
	 * @param string $version Version.
	 * @param string $zip     Zip bytes the sha256 describes.
	 * @return string
	 */
	private function manifest_json( string $version, string $zip ): string {
		return (string) wp_json_encode(
			array(
				'version'      => $version,
				'requires_wp'  => '6.9',
				'requires_php' => '8.3',
				'tested_wp'    => '7.1',
				'zip'          => 'blueline-' . $version . '.zip',
				'sha256'       => hash( 'sha256', $zip ),
				'changelog'    => '- x',
			)
		);
	}

	/**
	 * Header names of a logged request, lower-cased.
	 *
	 * @param int $index Index into the request log.
	 * @return string[]
	 */
	private function sent_headers( int $index ): array {
		return array_map( 'strtolower', array_keys( $GLOBALS['bl_test_http_log'][ $index ]['args']['headers'] ) );
	}

	/**
	 * Only repo API URLs carry the token, and every hop is manual.
	 */
	#[RunInSeparateProcess]
	public function test_token_header_only_on_repo_api_urls(): void {
		$url = $this->asset_url( 1, true );
		$this->expect_asset( $url, 'body' );

		$response = blueline_updater_request( $url, array( 'accept' => 'application/octet-stream' ) );

		$this->assertSame( 'body', wp_remote_retrieve_body( $response ) );
		$this->assertCount( 2, $GLOBALS['bl_test_http_log'] );
		$this->assertContains( 'authorization', $this->sent_headers( 0 ) );
		$this->assertSame( 'Bearer ' . self::TOKEN, $GLOBALS['bl_test_http_log'][0]['args']['headers']['Authorization'] );
		$this->assertNotContains( 'authorization', $this->sent_headers( 1 ) );
		$this->assertSame( 0, $GLOBALS['bl_test_http_log'][0]['args']['redirection'] );
		$this->assertSame( 0, $GLOBALS['bl_test_http_log'][1]['args']['redirection'] );
		$this->assertSame( 'application/octet-stream', $GLOBALS['bl_test_http_log'][0]['args']['headers']['Accept'] );
	}

	/**
	 * A redirect off GitHub is refused before a second request, without leaking.
	 */
	#[RunInSeparateProcess]
	public function test_redirect_to_untrusted_host_returns_error_and_token_never_in_error_text(): void {
		$url = $this->asset_url( 1, true );
		blueline_test_http_expect(
			$url,
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'https://evil.test/steal?x=1' ),
			)
		);

		$result = blueline_updater_request( $url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['bl_test_http_log'] );
		$this->assertStringNotContainsString( self::TOKEN, $result->get_error_message() . $result->get_error_code() . wp_json_encode( $result->get_error_data() ) );
		$this->assertStringNotContainsString( 'evil.test', $result->get_error_message() );
	}

	/**
	 * A manifest that fails validation falls through to the next-older release.
	 */
	#[RunInSeparateProcess]
	public function test_state_picks_newest_valid_candidate_and_skips_bad_manifest_to_next_older(): void {
		$this->expect_list( array( $this->release( '1.2.0', 2 ), $this->release( '1.1.0', 1 ) ) );
		$this->expect_asset( $this->asset_url( 2, true ), '{"version":"1.2.0","zip":"blueline-1.2.0.zip","sha256":"abc"}' );
		$this->expect_asset( $this->asset_url( 1, true ), $this->manifest_json( '1.1.0', 'zip-1.1.0' ) );

		$state = blueline_updater_state();

		$this->assertSame( 'ok', $state['status'] );
		$this->assertSame( '1.1.0', $state['candidate']['version'] );
		$this->assertSame( $this->asset_url( 1, false ), $state['candidate']['package'] );
		$this->assertSame( 'https://github.com/lusky3/rookiehockey-blueline/releases/tag/v1.1.0', $state['candidate']['release_url'] );
	}

	/**
	 * Zero releases is a healthy "nothing to offer".
	 */
	#[RunInSeparateProcess]
	public function test_state_ok_with_no_candidate_when_zero_releases(): void {
		$this->expect_list( array() );

		$state = blueline_updater_state();

		$this->assertSame( 'ok', $state['status'] );
		$this->assertNull( $state['candidate'] );
	}

	/**
	 * Empty or non-JSON bodies are an error, not a warning.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bad_bodies(): array {
		return array(
			'empty'    => array( '' ),
			'not json' => array( '<html>oops</html>' ),
			'scalar'   => array( '"x"' ),
		);
	}

	/**
	 * Bad list bodies.
	 *
	 * @param string $body Response body.
	 */
	#[RunInSeparateProcess]
	#[DataProvider( 'bad_bodies' )]
	public function test_state_error_on_empty_or_non_json_body( string $body ): void {
		blueline_test_http_expect(
			$this->list_url(),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => $body,
			)
		);

		$state = blueline_updater_state();

		$this->assertSame( 'error', $state['status'] );
		$this->assertNull( $state['candidate'] );
	}

	/**
	 * Response statuses mapped to states.
	 *
	 * @return array<string, array{0: int, 1: array<string, string>, 2: string}>
	 */
	public static function failures(): array {
		return array(
			'401'              => array( 401, array(), 'unauthorized' ),
			'403'              => array( 403, array(), 'unauthorized' ),
			'403 rate limited' => array( 403, array( 'x-ratelimit-remaining' => '0' ), 'rate_limited' ),
			'500'              => array( 500, array(), 'error' ),
		);
	}

	/**
	 * API failures.
	 *
	 * @param int                   $code    Status.
	 * @param array<string, string> $headers Headers.
	 * @param string                $expect  Expected state status.
	 */
	#[RunInSeparateProcess]
	#[DataProvider( 'failures' )]
	public function test_state_classifies_api_failures( int $code, array $headers, string $expect ): void {
		blueline_test_http_expect(
			$this->list_url(),
			array(
				'response' => array( 'code' => $code ),
				'headers'  => $headers,
				'body'     => '{"message":"x"}',
			)
		);

		$this->assertSame( $expect, blueline_updater_state()['status'] );
	}

	/**
	 * Success is cached 12h, failure 1h, and a flush clears it.
	 */
	#[RunInSeparateProcess]
	public function test_state_cached_ok_12h_error_1h_and_flush(): void {
		$this->expect_list( array() );
		blueline_updater_state();
		$this->assertSame( 12 * HOUR_IN_SECONDS, $GLOBALS['bl_test_site_transient_ttl'][ BLUELINE_UPDATER_CACHE_KEY ] );

		blueline_updater_flush();
		$this->assertFalse( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ) );

		blueline_test_http_expect( $this->list_url(), array( 'response' => array( 'code' => 401 ) ) );
		blueline_updater_state();
		$this->assertSame( HOUR_IN_SECONDS, $GLOBALS['bl_test_site_transient_ttl'][ BLUELINE_UPDATER_CACHE_KEY ] );
	}

	/**
	 * A cached state means no network.
	 */
	#[RunInSeparateProcess]
	public function test_second_call_makes_no_http_request(): void {
		$this->expect_list( array() );
		blueline_updater_state();
		$before = count( $GLOBALS['bl_test_http_log'] );

		blueline_updater_state();

		$this->assertSame( $before, count( $GLOBALS['bl_test_http_log'] ) );
	}

	/**
	 * Seed the cache with a candidate whose zip is `$zip`, optionally lying about the checksum.
	 *
	 * @param string $zip         Real zip bytes served by GitHub.
	 * @param string $manifest_of Bytes the manifest's sha256 describes.
	 * @return string The package URL.
	 */
	private function seed_candidate( string $zip, string $manifest_of ): string {
		$this->expect_list( array( $this->release( '1.2.0', 2 ) ) );
		$this->expect_asset( $this->asset_url( 2, true ), $this->manifest_json( '1.2.0', $manifest_of ) );
		$this->expect_asset( $this->asset_url( 2, false ), $zip );
		blueline_updater_state();

		return $this->asset_url( 2, false );
	}

	/**
	 * A matching checksum returns the temp file holding the zip.
	 */
	#[RunInSeparateProcess]
	public function test_download_verifies_sha256_and_returns_tempfile(): void {
		$package = $this->seed_candidate( 'ZIPBYTES', 'ZIPBYTES' );

		$path = blueline_updater_download( $package );

		$this->assertIsString( $path );
		$this->assertSame( 'ZIPBYTES', file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local temp file in a unit test.
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup of the temp file.
	}

	/**
	 * A tampered zip is refused and its temp file removed.
	 */
	#[RunInSeparateProcess]
	public function test_download_checksum_mismatch_deletes_file_and_errors(): void {
		$package = $this->seed_candidate( 'TAMPERED', 'ZIPBYTES' );

		$result = blueline_updater_download( $package );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_updater_checksum', $result->get_error_code() );
		$this->assertCount( 1, $GLOBALS['bl_test_deleted_files'] );
		$this->assertStringNotContainsString( self::TOKEN, $result->get_error_message() );

		unlink( $GLOBALS['bl_test_deleted_files'][0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the wp_delete_file() stub only records; remove the real temp file.
	}

	/**
	 * Only the package that was offered may be downloaded.
	 */
	#[RunInSeparateProcess]
	public function test_download_refuses_package_not_matching_cached_candidate(): void {
		$this->seed_candidate( 'ZIPBYTES', 'ZIPBYTES' );
		$logged = count( $GLOBALS['bl_test_http_log'] );

		foreach ( array( 'https://downloads.wordpress.org/x.zip', self::API . 'releases/assets/999', 'https://api.github.com/repos/lusky3/other/releases/assets/1' ) as $url ) {
			$result = blueline_updater_download( $url );

			$this->assertInstanceOf( WP_Error::class, $result, $url );
			$this->assertSame( 'blueline_updater_unknown_package', $result->get_error_code() );
		}
		$this->assertSame( $logged, count( $GLOBALS['bl_test_http_log'] ), 'no request was made' );
	}

	/**
	 * A failed download removes its temp file and returns the error.
	 */
	#[RunInSeparateProcess]
	public function test_download_transport_failure_deletes_temp_file(): void {
		$package = $this->seed_candidate( 'ZIPBYTES', 'ZIPBYTES' );
		blueline_test_http_expect( $package, new WP_Error( 'http_request_failed', 'boom' ) );

		$result = blueline_updater_download( $package );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['bl_test_deleted_files'] );

		unlink( $GLOBALS['bl_test_deleted_files'][0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the wp_delete_file() stub only records; remove the real temp file.
	}

	/**
	 * The channel setting reaches the selector (beta is defined for this class).
	 */
	#[RunInSeparateProcess]
	public function test_channel_is_beta_in_this_process(): void {
		$this->assertSame( 'beta', blueline_updater_channel() );
	}
}
