<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php';
require_once __DIR__ . '/../inc/updater.php';

/**
 * Covers release-manifest signatures in inc/updater-rules.php and the
 * release-signing Site Health test in inc/updater.php. Every key here is a
 * throwaway pair generated for the test run.
 */
final class UpdaterSignatureTest extends TestCase {

	/**
	 * A manifest's exact bytes, as build-manifest.php writes them.
	 */
	private const MANIFEST = "{\n    \"version\": \"1.2.0\",\n    \"zip\": \"blueline-1.2.0.zip\",\n    \"sha256\": \"" . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' . "\"\n}\n";

	/**
	 * Reset the in-memory stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * A fresh keypair: array( secret key, base64 public key ).
	 *
	 * @return array{0: string, 1: string}
	 */
	private function keypair(): array {
		$pair = sodium_crypto_sign_keypair();

		return array( sodium_crypto_sign_secretkey( $pair ), base64_encode( sodium_crypto_sign_publickey( $pair ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the key format the theme ships.
	}

	/**
	 * A base64 detached signature, the content of manifest.json.sig.
	 *
	 * @param string $message Signed bytes.
	 * @param string $secret  Secret key.
	 * @return string
	 */
	private function sign( string $message, string $secret ): string {
		return base64_encode( sodium_crypto_sign_detached( $message, $secret ) ) . "\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the signature file format.
	}

	/**
	 * A valid signature by a listed key is accepted (trailing newline included).
	 */
	public function test_valid_signature_is_verified(): void {
		list( $secret, $public ) = $this->keypair();

		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $this->sign( self::MANIFEST, $secret ), array( $public ) ) );
	}

	/**
	 * One changed byte in the manifest (a swapped sha256) breaks the signature.
	 */
	public function test_tampered_manifest_is_rejected(): void {
		list( $secret, $public ) = $this->keypair();
		$signature               = $this->sign( self::MANIFEST, $secret );
		$tampered                = str_replace( 'aaaa', 'bbbb', self::MANIFEST );

		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( $tampered, $signature, array( $public ) ) );
	}

	/**
	 * A signature by a key the theme does not list is refused.
	 */
	public function test_signature_by_an_unlisted_key_is_rejected(): void {
		list( $attacker ) = $this->keypair();
		list( , $public ) = $this->keypair();

		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( self::MANIFEST, $this->sign( self::MANIFEST, $attacker ), array( $public ) ) );
	}

	/**
	 * With keys configured, no signature at all is never good enough.
	 */
	public function test_missing_signature_with_keys_is_rejected(): void {
		list( , $public ) = $this->keypair();

		$this->assertSame( 'missing', blueline_updater_check_manifest_signature( self::MANIFEST, null, array( $public ) ) );
	}

	/**
	 * With no keys, nothing is checked: the integrity-only behaviour.
	 */
	public function test_no_keys_means_not_required(): void {
		$this->assertSame( 'not_required', blueline_updater_check_manifest_signature( self::MANIFEST, null, array() ) );
		$this->assertSame( 'not_required', blueline_updater_check_manifest_signature( self::MANIFEST, 'garbage', array() ) );
	}

	/**
	 * Rotation: a release signed by either listed key verifies.
	 */
	public function test_any_listed_key_verifies_for_rotation(): void {
		list( $old_secret, $old_public ) = $this->keypair();
		list( $new_secret, $new_public ) = $this->keypair();
		$keys                            = array( $old_public, $new_public );

		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $this->sign( self::MANIFEST, $old_secret ), $keys ) );
		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $this->sign( self::MANIFEST, $new_secret ), $keys ) );
		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( self::MANIFEST, $this->sign( self::MANIFEST, $old_secret ), array( $new_public ) ), 'a retired key no longer verifies' );
	}

	/**
	 * The signature covers the published bytes, not the data: the same JSON
	 * re-serialised (whitespace, key order) does not verify.
	 */
	public function test_signature_over_reserialised_json_is_rejected(): void {
		list( $secret, $public ) = $this->keypair();
		$signature               = $this->sign( self::MANIFEST, $secret );
		$compact                 = (string) wp_json_encode( json_decode( self::MANIFEST, true ) );

		$this->assertSame( json_decode( self::MANIFEST, true ), json_decode( $compact, true ), 'same data' );
		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( $compact, $signature, array( $public ) ) );
	}

	/**
	 * Malformed signature files.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_signatures(): array {
		return array(
			'empty'        => array( '' ),
			'not base64'   => array( str_repeat( '!', 88 ) ),
			'too short'    => array( base64_encode( str_repeat( 'x', 63 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
			'too long'     => array( base64_encode( str_repeat( 'x', 65 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
			'hex not b64'  => array( str_repeat( 'ab', 64 ) ),
			'huge'         => array( str_repeat( 'A', 100000 ) ),
			'all zero sig' => array( base64_encode( str_repeat( "\0", 64 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
		);
	}

	/**
	 * Anything that is not one valid signature is invalid, never an exception.
	 *
	 * @param string $signature Signature file contents.
	 */
	#[DataProvider( 'malformed_signatures' )]
	public function test_malformed_signature_is_invalid( string $signature ): void {
		list( , $public ) = $this->keypair();

		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( self::MANIFEST, $signature, array( $public ) ) );
	}

	/**
	 * Unusable key entries are skipped, but they still count as "signing is on":
	 * a list of only bad keys verifies nothing instead of letting everything through.
	 */
	public function test_unusable_keys_fail_closed(): void {
		list( $secret, $public ) = $this->keypair();
		$signature               = $this->sign( self::MANIFEST, $secret );
		$hex                     = bin2hex( (string) base64_decode( $public, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- building a wrong-format fixture.

		$this->assertSame( array(), blueline_updater_decode_public_keys( array( '', 'nope', $hex, 42, null ) ) );
		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( self::MANIFEST, $signature, array( $hex ) ), 'hex is not the documented format' );
		$this->assertSame( 'invalid', blueline_updater_check_manifest_signature( self::MANIFEST, $signature, array( '' ) ) );
		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $signature, array( 'nope', $public ) ), 'a bad entry does not hide a good one' );
		$this->assertCount( 1, blueline_updater_decode_public_keys( array( ' ' . $public . "\n" ) ), 'surrounding whitespace is tolerated' );
	}

	/**
	 * WordPress's bundled sodium_compat (used when the extension is missing)
	 * accepts the signatures the release pipeline makes.
	 */
	public function test_sodium_compat_pure_php_verifies_the_same_signature(): void {
		$autoload = __DIR__ . '/../vendor/johnpbloch/wordpress-core/wp-includes/sodium_compat/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			$this->markTestSkipped( 'WordPress core (dev dependency) is not installed.' );
		}
		require_once $autoload;

		list( $secret, $public ) = $this->keypair();
		$signature               = sodium_crypto_sign_detached( self::MANIFEST, $secret );
		$key                     = (string) base64_decode( $public, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixture.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- sodium_compat's own switch for forcing its pure-PHP code path.
		$previous = ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;

		ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		try {
			$this->assertTrue( ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, self::MANIFEST, $key ) );
			$this->assertFalse( ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, self::MANIFEST . ' ', $key ) );
		} finally {
			ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $previous;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Every key the theme ships is usable, so a typo cannot quietly turn
	 * every update into "signature invalid" on every site.
	 */
	public function test_every_shipped_public_key_is_a_valid_key(): void {
		$keys = blueline_updater_public_keys();

		$this->assertIsArray( BLUELINE_RELEASE_PUBLIC_KEYS, 'a list, so keys can be rotated' );
		$this->assertCount( count( $keys ), blueline_updater_decode_public_keys( $keys ) );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'no duplicate keys' );
	}

	/**
	 * The shipped source never carries a private key: a 64-byte base64 value
	 * (88 characters) in the key list would be one.
	 */
	public function test_the_key_list_holds_no_private_key(): void {
		$source = (string) file_get_contents( __DIR__ . '/../inc/updater-rules.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertDoesNotMatchRegularExpression( '#[A-Za-z0-9+/]{86}==#', $source );
	}

	/**
	 * The cache fingerprint is stable for a list and changes with it.
	 */
	public function test_fingerprint_changes_with_the_key_list(): void {
		list( , $a ) = $this->keypair();
		list( , $b ) = $this->keypair();

		$this->assertSame( '', blueline_updater_keys_fingerprint( array() ) );
		$this->assertNotSame( '', blueline_updater_keys_fingerprint( array( $a ) ) );
		$this->assertNotSame( blueline_updater_keys_fingerprint( array( $a ) ), blueline_updater_keys_fingerprint( array( $a, $b ) ) );
		$this->assertSame( blueline_updater_keys_fingerprint( array( $a, $b ) ), blueline_updater_keys_fingerprint( array( $a, $b ) ) );
	}

	/**
	 * Signature failures outrank other broken releases; otherwise newest wins.
	 */
	public function test_pick_rejected_prefers_signature_failures_then_newest(): void {
		$assets    = array(
			'version' => '1.3.0',
			'reason'  => 'assets',
		);
		$manifest  = array(
			'version' => '1.4.0',
			'reason'  => 'manifest',
		);
		$signature = array(
			'version' => '1.2.0',
			'reason'  => 'signature_invalid',
		);

		$this->assertSame( $assets, blueline_updater_pick_rejected( null, $assets ) );
		$this->assertSame( $manifest, blueline_updater_pick_rejected( $assets, $manifest ) );
		$this->assertSame( $manifest, blueline_updater_pick_rejected( $manifest, $assets ) );
		$this->assertSame( $signature, blueline_updater_pick_rejected( $manifest, $signature ) );
		$this->assertSame( $signature, blueline_updater_pick_rejected( $signature, $manifest ) );
	}

	/**
	 * No keys: a recommendation, not a failure, with the how-to.
	 */
	public function test_signing_health_recommends_enabling_when_no_keys(): void {
		$result = blueline_updater_signing_health_result( array(), true );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertSame( 'Release signing is not enabled', $result['label'] );
		$this->assertSame( 'blueline_release_signing', $result['test'] );
		$this->assertStringContainsString( 'docs/RELEASING.md', $result['description'] );
		$this->assertStringContainsString( 'BLUELINE_RELEASE_PUBLIC_KEYS', $result['description'] );
		$this->assertStringContainsString( 'Bitwarden or Doppler', $result['description'] );
	}

	/**
	 * Keys configured and usable: good.
	 */
	public function test_signing_health_good_with_usable_keys(): void {
		list( , $a ) = $this->keypair();
		list( , $b ) = $this->keypair();

		$one = blueline_updater_signing_health_result( array( $a ), true );
		$two = blueline_updater_signing_health_result( array( $a, $b ), true );

		$this->assertSame( 'good', $one['status'] );
		$this->assertStringContainsString( 'the 1 trusted release key', $one['description'] );
		$this->assertSame( 'good', $two['status'] );
		$this->assertStringContainsString( 'one of the 2 trusted release keys', $two['description'] );
	}

	/**
	 * Keys configured but unusable, or no crypto: critical, with the cause.
	 */
	public function test_signing_health_critical_when_nothing_can_verify(): void {
		list( , $a ) = $this->keypair();

		$bad_keys  = blueline_updater_signing_health_result( array( 'not-a-key' ), true );
		$no_crypto = blueline_updater_signing_health_result( array( $a ), false );
		$partial   = blueline_updater_signing_health_result( array( $a, 'not-a-key' ), true );

		$this->assertSame( 'critical', $bad_keys['status'] );
		$this->assertStringContainsString( 'none of the keys', $bad_keys['description'] );
		$this->assertSame( 'critical', $no_crypto['status'] );
		$this->assertStringContainsString( 'sodium', $no_crypto['description'] );
		$this->assertSame( 'recommended', $partial['status'] );
		$this->assertStringContainsString( '1 of the 2 keys', $partial['description'] );
	}

	/**
	 * The release-signing test is registered beside the updater test.
	 */
	public function test_signing_health_test_is_registered(): void {
		$tests = blueline_updater_register_health_test( array() );

		$this->assertSame( 'blueline_updater_run_signing_health_test', $tests['direct']['blueline_release_signing']['test'] );
		$this->assertSame( 'blueline_updater_run_health_test', $tests['direct']['blueline_updater']['test'] );
		$this->assertSame( 'recommended', blueline_updater_run_signing_health_test()['status'], 'the shipped theme has no key yet' );
	}

	/**
	 * Signature rejections.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function signature_rejections(): array {
		return array(
			'missing'     => array( 'signature_missing', 'without a release signature' ),
			'invalid'     => array( 'signature_invalid', 'does not match any release key' ),
			'unsupported' => array( 'signature_unsupported', 'cannot verify release signatures' ),
		);
	}

	/**
	 * A release refused over its signature is critical in the updater's own
	 * test, names the version, and says not to install it by hand.
	 *
	 * @param string $reason Rejection reason.
	 * @param string $phrase Expected wording.
	 */
	#[DataProvider( 'signature_rejections' )]
	public function test_updater_health_is_critical_for_signature_rejections( string $reason, string $phrase ): void {
		$state = array(
			'status'    => 'ok',
			'channel'   => 'stable',
			'installed' => '1.1.0',
			'signing'   => 'required',
			'candidate' => null,
			'rejected'  => array(
				'version' => '1.2.0',
				'reason'  => $reason,
			),
		);

		$result = blueline_updater_health_result( $state );

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertStringContainsString( '1.2.0', $result['description'] );
		$this->assertStringContainsString( $phrase, $result['description'] );

		$state['candidate'] = array( 'version' => '1.1.5' );
		$this->assertStringContainsString( 'Blueline 1.1.5 is correctly signed and still available', blueline_updater_health_result( $state )['description'] );
	}
}
