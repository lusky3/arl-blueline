<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers inc/updater-client.php with release signing keys configured.
 *
 * BLUELINE_RELEASE_PUBLIC_KEYS is read when updater-rules.php loads, so every
 * test runs in a fresh process (global state not preserved) and defines the
 * key list before loading the updater files. The keys are throwaway pairs
 * generated per test.
 */
final class UpdaterSignedClientTest extends TestCase {

	private const API     = 'https://api.github.com/repos/lusky3/arl-blueline/';
	private const STORAGE = 'https://release-assets.githubusercontent.com/signed/';

	/**
	 * Secret keys by name: `a` and `b` are trusted, `attacker` is not.
	 *
	 * @var array<string, string>
	 */
	private array $secrets = array();

	/**
	 * Generate keys, trust the given ones and load the updater.
	 *
	 * @param mixed $trusted Names of trusted keys (`a`, `b`), or a raw constant value when not an array of names.
	 */
	private function boot( $trusted = array( 'a', 'b' ) ): void {
		$public = array();
		foreach ( array( 'a', 'b', 'attacker' ) as $name ) {
			$pair                   = sodium_crypto_sign_keypair();
			$this->secrets[ $name ] = sodium_crypto_sign_secretkey( $pair );
			$public[ $name ]        = base64_encode( sodium_crypto_sign_publickey( $pair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the key format the theme ships.
		}

		$keys = $trusted;
		if ( is_array( $trusted ) ) {
			$keys = array();
			foreach ( $trusted as $name ) {
				$keys[] = $public[ $name ];
			}
		}

		define( 'BLUELINE_RELEASE_PUBLIC_KEYS', $keys );
		define( 'BLUELINE_UPDATE_CHANNEL', 'stable' );
		require_once __DIR__ . '/../inc/updater-rules.php';
		require_once __DIR__ . '/../inc/updater-client.php';
		require_once __DIR__ . '/../inc/updater.php';
		blueline_test_reset();
	}

	/**
	 * The asset API URL for a release's zip (1), manifest (2) or signature (3).
	 *
	 * @param int $n    Release number.
	 * @param int $kind 1, 2 or 3.
	 * @return string
	 */
	private function asset_url( int $n, int $kind ): string {
		return self::API . 'releases/assets/' . ( $n * 10 + $kind );
	}

	/**
	 * A GitHub-shaped release, with or without the signature asset.
	 *
	 * @param string $version Version.
	 * @param int    $n       Release number.
	 * @param bool   $signed  Whether manifest.json.sig is attached.
	 * @return array<string, mixed>
	 */
	private function release( string $version, int $n, bool $signed = true ): array {
		$assets = array(
			array(
				'name' => 'blueline-' . $version . '.zip',
				'url'  => $this->asset_url( $n, 1 ),
			),
			array(
				'name' => 'manifest.json',
				'url'  => $this->asset_url( $n, 2 ),
			),
		);
		if ( $signed ) {
			$assets[] = array(
				'name' => 'manifest.json.sig',
				'url'  => $this->asset_url( $n, 3 ),
			);
		}

		return array(
			'tag_name'   => 'v' . $version,
			'draft'      => false,
			'prerelease' => false,
			'html_url'   => 'https://github.com/lusky3/arl-blueline/releases/tag/v' . $version,
			'assets'     => $assets,
		);
	}

	/**
	 * Queue the releases list.
	 *
	 * @param array<int, array<string, mixed>> $releases Releases.
	 */
	private function expect_list( array $releases ): void {
		blueline_test_http_expect(
			self::API . 'releases?per_page=10',
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
	 * Manifest bytes as build-manifest.php writes them (pretty-printed).
	 *
	 * @param string $version Version.
	 * @param string $zip     Zip bytes the sha256 describes.
	 * @return string
	 */
	private function manifest( string $version, string $zip ): string {
		return (string) wp_json_encode(
			array(
				'version'      => $version,
				'requires_wp'  => '6.9',
				'requires_php' => '8.3',
				'tested_wp'    => '7.1',
				'zip'          => 'blueline-' . $version . '.zip',
				'sha256'       => hash( 'sha256', $zip ),
				'changelog'    => '- x',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	}

	/**
	 * The base64 signature file for some bytes.
	 *
	 * @param string $bytes Signed bytes.
	 * @param string $key   Key name.
	 * @return string
	 */
	private function sig( string $bytes, string $key = 'a' ): string {
		return base64_encode( sodium_crypto_sign_detached( $bytes, $this->secrets[ $key ] ) ) . "\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the signature file format.
	}

	/**
	 * Queue one release's manifest and signature.
	 *
	 * @param int    $n         Release number.
	 * @param string $manifest  Manifest bytes served.
	 * @param string $signature Signature file served.
	 */
	private function expect_signed( int $n, string $manifest, string $signature ): void {
		$this->expect_asset( $this->asset_url( $n, 2 ), $manifest );
		$this->expect_asset( $this->asset_url( $n, 3 ), $signature );
	}

	/**
	 * Requested URLs, in order.
	 *
	 * @return string[]
	 */
	private function requested(): array {
		return array_column( $GLOBALS['bl_test_http_log'], 'url' );
	}

	/**
	 * A release signed by a trusted key is offered, and the state says so.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_valid_signature_is_offered(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $manifest, $this->sig( $manifest ) );

		$state = blueline_updater_state();

		$this->assertSame( 'required', $state['signing'] );
		$this->assertSame( '9.0.0', $state['candidate']['version'] );
		$this->assertSame( 'verified', $state['candidate']['signature'] );
		$this->assertNull( $state['rejected'] );
		$this->assertContains( $this->asset_url( 1, 3 ), $this->requested() );
		$this->assertSame( 'good', blueline_updater_health_result( $state )['status'] );
	}

	/**
	 * A manifest changed after signing (here: the sha256 swapped for another
	 * zip's) is not offered, and Site Health is critical.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_tampered_manifest_is_not_offered(): void {
		$this->boot();
		$genuine = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $this->manifest( '9.0.0', 'EVIL' ), $this->sig( $genuine ) );

		$state = blueline_updater_state();

		$this->assertNull( $state['candidate'] );
		$this->assertSame(
			array(
				'version' => '9.0.0',
				'reason'  => 'signature_invalid',
			),
			$state['rejected']
		);
		$this->assertSame( 'critical', blueline_updater_health_result( $state )['status'] );
		$this->assertSame( HOUR_IN_SECONDS, $GLOBALS['bl_test_site_transient_ttl'][ BLUELINE_UPDATER_CACHE_KEY ] );
	}

	/**
	 * A valid signature by a key the theme does not list is refused.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_signature_by_an_untrusted_key_is_not_offered(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $manifest, $this->sig( $manifest, 'attacker' ) );

		$state = blueline_updater_state();

		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'signature_invalid', $state['rejected']['reason'] );
	}

	/**
	 * With keys configured, a release without a signature is never offered:
	 * there is no fallback to the unsigned behaviour. A listed signature
	 * asset that is gone (404) counts as missing too.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_missing_signature_is_not_offered(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1, false ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $manifest );

		$state = blueline_updater_state();

		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'signature_missing', $state['rejected']['reason'] );
		$this->assertStringContainsString( 'without a release signature', blueline_updater_health_result( $state )['description'] );

		blueline_test_reset();
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $manifest );
		blueline_test_http_expect( $this->asset_url( 1, 3 ), array( 'response' => array( 'code' => 404 ) ) );

		$state = blueline_updater_state( true );

		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'signature_missing', $state['rejected']['reason'] );
	}

	/**
	 * Rotation: a release signed by the second listed key is offered.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_second_listed_key_is_accepted(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $manifest, $this->sig( $manifest, 'b' ) );

		$this->assertSame( '9.0.0', blueline_updater_state()['candidate']['version'] );
	}

	/**
	 * The signature is over the published bytes: the same data re-serialised
	 * (compact instead of pretty-printed) does not verify.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_signature_over_different_bytes_is_not_offered(): void {
		$this->boot();
		$pretty  = $this->manifest( '9.0.0', 'ZIP' );
		$compact = (string) wp_json_encode( json_decode( $pretty, true ) );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $compact, $this->sig( $pretty ) );

		$state = blueline_updater_state();

		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'signature_invalid', $state['rejected']['reason'] );
	}

	/**
	 * A correctly signed manifest still has to match the zip: the sha256
	 * check on the download is unchanged.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sha256_mismatch_is_still_rejected(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIPBYTES' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $manifest, $this->sig( $manifest ) );
		$this->expect_asset( $this->asset_url( 1, 1 ), 'TAMPERED' );
		$package = blueline_updater_state()['candidate']['package'];

		$result = blueline_updater_download( $package );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_updater_checksum', $result->get_error_code() );
		unlink( $GLOBALS['bl_test_deleted_files'][0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the wp_delete_file() stub only records; remove the real temp file.
	}

	/**
	 * A good signature and a matching zip install.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_signed_and_matching_zip_downloads(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIPBYTES' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 1, $manifest, $this->sig( $manifest ) );
		$this->expect_asset( $this->asset_url( 1, 1 ), 'ZIPBYTES' );

		$path = blueline_updater_download( blueline_updater_state()['candidate']['package'] );

		$this->assertIsString( $path );
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup of the temp file.
	}

	/**
	 * A badly signed newest release falls through to an older, correctly signed
	 * one, and the bad one stays reported as critical.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bad_newest_falls_back_to_older_signed_release(): void {
		$this->boot();
		$new = $this->manifest( '9.1.0', 'NEW' );
		$old = $this->manifest( '9.0.0', 'OLD' );
		$this->expect_list( array( $this->release( '9.1.0', 2 ), $this->release( '9.0.0', 1 ) ) );
		$this->expect_signed( 2, $new, $this->sig( $new, 'attacker' ) );
		$this->expect_signed( 1, $old, $this->sig( $old ) );

		$state  = blueline_updater_state();
		$health = blueline_updater_health_result( $state );

		$this->assertSame( '9.0.0', $state['candidate']['version'] );
		$this->assertSame( 'signature_invalid', $state['rejected']['reason'] );
		$this->assertSame( '9.1.0', $state['rejected']['version'] );
		$this->assertSame( 'critical', $health['status'] );
		$this->assertStringContainsString( '9.0.0 is correctly signed', $health['description'] );
	}

	/**
	 * A signature that cannot be fetched is a failed check, not a pass; a
	 * rate limit on it is reported as one.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unreachable_signature_is_an_error_not_an_offer(): void {
		$this->boot();
		$manifest = $this->manifest( '9.0.0', 'ZIP' );
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $manifest );
		blueline_test_http_expect( $this->asset_url( 1, 3 ), array( 'response' => array( 'code' => 500 ) ) );

		$state = blueline_updater_state();

		$this->assertSame( 'error', $state['status'] );
		$this->assertNull( $state['candidate'] );

		blueline_test_reset();
		$this->expect_list( array( $this->release( '9.0.0', 1 ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $manifest );
		blueline_test_http_expect(
			$this->asset_url( 1, 3 ),
			array(
				'response' => array( 'code' => 429 ),
				'headers'  => array( 'retry-after' => '60' ),
			)
		);

		$this->assertSame( 'rate_limited', blueline_updater_state( true )['status'] );
	}

	/**
	 * A state cached before the key list changed (here: before any key
	 * existed, offering an unsigned release) is not reused.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cache_from_another_key_list_is_ignored(): void {
		$this->boot();
		$stale            = blueline_updater_make_state(
			'ok',
			array(
				'version'     => '9.0.0',
				'release_url' => '',
				'package'     => $this->asset_url( 1, 1 ),
				'manifest'    => array(
					'version' => '9.0.0',
					'zip'     => 'blueline-9.0.0.zip',
					'sha256'  => hash( 'sha256', 'UNSIGNED' ),
				),
				'signature'   => 'not_required',
			)
		);
		$stale['keys']    = '';
		$stale['signing'] = 'off';
		set_site_transient( BLUELINE_UPDATER_CACHE_KEY, $stale, 12 * HOUR_IN_SECONDS );
		$this->expect_list( array( $this->release( '9.0.0', 1, false ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $this->manifest( '9.0.0', 'UNSIGNED' ) );

		$state = blueline_updater_state();

		$this->assertContains( self::API . 'releases?per_page=10', $this->requested(), 'rechecked under the new key list' );
		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'signature_missing', $state['rejected']['reason'] );
	}

	/**
	 * Even a cached candidate under the current key list is not downloaded
	 * unless its signature was verified.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_download_refuses_an_unverified_cached_candidate(): void {
		$this->boot();
		$package = $this->asset_url( 1, 1 );
		set_site_transient(
			BLUELINE_UPDATER_CACHE_KEY,
			blueline_updater_make_state(
				'ok',
				array(
					'version'     => '9.0.0',
					'release_url' => '',
					'package'     => $package,
					'manifest'    => array(
						'version' => '9.0.0',
						'zip'     => 'blueline-9.0.0.zip',
						'sha256'  => hash( 'sha256', 'X' ),
					),
				)
			),
			12 * HOUR_IN_SECONDS
		);

		$result = blueline_updater_download( $package );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_updater_unsigned', $result->get_error_code() );
		$this->assertSame( array(), $this->requested(), 'nothing was downloaded' );
	}

	/**
	 * A key list mistyped as a bare string still requires a signature.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_non_array_key_constant_fails_closed(): void {
		$this->boot( 'not-a-list' );
		$this->expect_list( array( $this->release( '9.0.0', 1, false ) ) );
		$this->expect_asset( $this->asset_url( 1, 2 ), $this->manifest( '9.0.0', 'ZIP' ) );

		$state = blueline_updater_state();

		$this->assertSame( array( 'not-a-list' ), blueline_updater_public_keys() );
		$this->assertSame( 'required', $state['signing'] );
		$this->assertNull( $state['candidate'] );
		$this->assertSame( 'critical', blueline_updater_run_signing_health_test()['status'] );
	}
}
