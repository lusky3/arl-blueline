<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release/lib.php';
require_once __DIR__ . '/../inc/updater-rules.php';

/**
 * Covers release signing: scripts/release/{lib,sign-manifest,verify-manifest,generate-signing-key}.php.
 * Every key here is a throwaway generated for the test run.
 */
final class ReleaseSigningTest extends TestCase {

	private const MANIFEST = "{\n    \"version\": \"1.2.0\",\n    \"zip\": \"blueline-1.2.0.zip\"\n}\n";

	/**
	 * Scratch directory for the current test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * A fresh scratch directory.
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bl-signing-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test scratch directory.
	}

	/**
	 * Remove the scratch directory.
	 */
	protected function tearDown(): void {
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test cleanup of a temp dir.
		}
	}

	/**
	 * A throwaway keypair: base64 secret key and base64 public key.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function keypair(): array {
		$pair = sodium_crypto_sign_keypair();

		return array(
			base64_encode( sodium_crypto_sign_secretkey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the key format the scripts use.
			base64_encode( sodium_crypto_sign_publickey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the key format the theme ships.
		);
	}

	/**
	 * Run a release script with an explicit environment (nothing inherited but PATH).
	 *
	 * @param string                $script Script file name in scripts/release.
	 * @param string[]              $args   Arguments.
	 * @param array<string, string> $env    Extra environment.
	 * @return array{code: int, out: string, err: string}
	 */
	private function run_script( string $script, array $args = array(), array $env = array() ): array {
		$command = array_merge( array( PHP_BINARY, dirname( __DIR__, 3 ) . '/scripts/release/' . $script ), $args );
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- running the release script under test.
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->dir,
			array_merge( array( 'PATH' => (string) getenv( 'PATH' ) ), $env )
		);
		$this->assertIsResource( $process );

		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- process pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- process pipe.

		return array(
			'code' => proc_close( $process ),
			'out'  => $out,
			'err'  => $err,
		);
	}

	/**
	 * Write the test manifest into the scratch directory.
	 *
	 * @return string Its path.
	 */
	private function write_manifest(): string {
		$path = $this->dir . '/manifest.json';
		file_put_contents( $path, self::MANIFEST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		return $path;
	}

	/**
	 * Producer and consumer agree on the signature asset's name.
	 */
	public function test_signature_asset_name_matches_the_theme_updater(): void {
		$this->assertSame( blueline_updater_asset_names( '1.2.0' )['signature'], blueline_release_signature_asset() );
	}

	/**
	 * What the release scripts sign, the theme verifies.
	 */
	public function test_sign_round_trips_through_the_theme_check(): void {
		list( $secret, $public ) = $this->keypair();

		$signed = blueline_release_sign( self::MANIFEST, $secret . "\n" );

		$this->assertIsArray( $signed );
		$this->assertSame( $public, $signed['public_key'] );
		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $signed['signature'] . "\n", array( $public ) ) );
	}

	/**
	 * Keys the signer must refuse.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_secret_keys(): array {
		$pair = sodium_crypto_sign_keypair();

		return array(
			'empty'             => array( '' ),
			'not base64'        => array( '!!!not-a-key!!!' ),
			'public key only'   => array( base64_encode( sodium_crypto_sign_publickey( $pair ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
			'32-byte seed'      => array( base64_encode( random_bytes( 32 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
			'mismatched halves' => array( base64_encode( random_bytes( 64 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
			'truncated'         => array( substr( base64_encode( sodium_crypto_sign_secretkey( $pair ) ), 0, 80 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.
		);
	}

	/**
	 * An unusable key signs nothing.
	 *
	 * @param string $key Secret key candidate.
	 */
	#[DataProvider( 'unusable_secret_keys' )]
	public function test_sign_refuses_unusable_keys( string $key ): void {
		$this->assertNull( blueline_release_sign( self::MANIFEST, $key ) );
	}

	/**
	 * The workflow's publish/no-publish decision.
	 */
	public function test_signature_verdict_policy(): void {
		list( , $public ) = $this->keypair();

		$unsigned_no_keys = blueline_release_signature_verdict( array(), false, 'not_required' );
		$signed_no_keys   = blueline_release_signature_verdict( array(), true, 'not_required' );
		$unsigned_keys    = blueline_release_signature_verdict( array( $public ), false, 'missing' );
		$verified         = blueline_release_signature_verdict( array( $public ), true, 'verified' );
		$wrong_key        = blueline_release_signature_verdict( array( $public ), true, 'invalid' );

		$this->assertTrue( $unsigned_no_keys['ok'] );
		$this->assertSame( 'warning', $unsigned_no_keys['level'] );
		$this->assertStringContainsString( 'UNSIGNED', $unsigned_no_keys['message'] );
		$this->assertTrue( $signed_no_keys['ok'] );
		$this->assertSame( 'warning', $signed_no_keys['level'] );
		$this->assertFalse( $unsigned_keys['ok'], 'never fall back to unsigned once the theme trusts a key' );
		$this->assertSame( 'error', $unsigned_keys['level'] );
		$this->assertTrue( $verified['ok'] );
		$this->assertSame( 'notice', $verified['level'] );
		$this->assertFalse( $wrong_key['ok'] );
		$this->assertSame( 'error', $wrong_key['level'] );
	}

	/**
	 * The signing script takes the key from the environment, writes .sig
	 * beside the manifest and prints only the public key.
	 */
	public function test_sign_manifest_cli_writes_a_verifying_signature(): void {
		list( $secret, $public ) = $this->keypair();
		$manifest                = $this->write_manifest();

		$run = $this->run_script( 'sign-manifest.php', array( $manifest ), array( 'RELEASE_SIGNING_KEY' => $secret . "\n" ) );

		$this->assertSame( 0, $run['code'], $run['err'] );
		$this->assertStringContainsString( $public, $run['out'] );
		$this->assertStringNotContainsString( $secret, $run['out'] . $run['err'] );
		$this->assertFileExists( $manifest . '.sig' );
		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, (string) file_get_contents( $manifest . '.sig' ), array( $public ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the script's output file.
	}

	/**
	 * A bad key fails without signing and without echoing what it was given.
	 */
	public function test_sign_manifest_cli_rejects_a_bad_key_quietly(): void {
		$manifest = $this->write_manifest();
		$bad      = base64_encode( random_bytes( 64 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixture.

		$run = $this->run_script( 'sign-manifest.php', array( $manifest ), array( 'RELEASE_SIGNING_KEY' => $bad ) );

		$this->assertSame( 1, $run['code'] );
		$this->assertFileDoesNotExist( $manifest . '.sig' );
		$this->assertStringNotContainsString( $bad, $run['out'] . $run['err'] );
		$this->assertStringContainsString( 'not a usable ed25519 secret key', $run['err'] );
	}

	/**
	 * No key in the environment, or anything extra on the command line (where
	 * a pasted key would leak into the process list), is a usage error.
	 */
	public function test_sign_manifest_cli_takes_the_key_from_the_environment_only(): void {
		list( $secret ) = $this->keypair();
		$manifest       = $this->write_manifest();

		$no_env = $this->run_script( 'sign-manifest.php', array( $manifest ) );
		$argv   = $this->run_script( 'sign-manifest.php', array( $manifest, $secret ), array( 'RELEASE_SIGNING_KEY' => $secret ) );

		$this->assertSame( 2, $no_env['code'] );
		$this->assertStringContainsString( 'RELEASE_SIGNING_KEY is not set', $no_env['err'] );
		$this->assertSame( 2, $argv['code'] );
		$this->assertFileDoesNotExist( $manifest . '.sig' );
		$this->assertStringNotContainsString( $secret, $argv['out'] . $argv['err'] );
	}

	/**
	 * The verify script against the shipped theme (no keys yet) publishes
	 * either way, with a warning annotation.
	 */
	public function test_verify_manifest_cli_warns_while_the_theme_trusts_no_key(): void {
		if ( array() !== blueline_updater_public_keys() ) {
			$this->markTestSkipped( 'The theme now ships a release key; the keyed policy is covered by test_signature_verdict_policy.' );
		}
		list( $secret ) = $this->keypair();
		$manifest       = $this->write_manifest();

		$unsigned = $this->run_script( 'verify-manifest.php', array( $manifest ) );
		$this->run_script( 'sign-manifest.php', array( $manifest ), array( 'RELEASE_SIGNING_KEY' => $secret ) );
		$signed = $this->run_script( 'verify-manifest.php', array( $manifest ) );

		$this->assertSame( 0, $unsigned['code'], $unsigned['err'] );
		$this->assertStringStartsWith( '::warning::Publishing an UNSIGNED release', $unsigned['out'] );
		$this->assertSame( 0, $signed['code'], $signed['err'] );
		$this->assertStringStartsWith( '::warning::manifest.json is signed, but the theme trusts no release key yet', $signed['out'] );
	}

	/**
	 * The verify script cannot be fooled into passing by a missing manifest.
	 */
	public function test_verify_manifest_cli_refuses_without_a_manifest(): void {
		$run = $this->run_script( 'verify-manifest.php', array( $this->dir . '/nope.json' ) );

		$this->assertSame( 2, $run['code'] );
	}

	/**
	 * The key ceremony: stdout carries only the public key, the private key
	 * goes to a 0600 file, and the pair works end to end with the signer and
	 * the theme's check.
	 */
	public function test_generate_signing_key_writes_private_key_to_a_0600_file(): void {
		$file = $this->dir . '/signing-key';

		$run = $this->run_script( 'generate-signing-key.php', array( $file ) );

		$this->assertSame( 0, $run['code'], $run['err'] );
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		$private = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the script's output file.

		$this->assertSame( 1, preg_match_all( '#[A-Za-z0-9+/]{43}=#', $run['out'], $keys ), 'exactly one public key on stdout' );
		$this->assertStringContainsString( 'put this in themes/blueline/inc/updater-rules.php', $run['out'] );
		$this->assertStringNotContainsString( $private, $run['out'] . $run['err'] );
		$this->assertStringContainsString( 'Bitwarden or Doppler', $run['err'] );
		$this->assertStringContainsString( 'gh secret set RELEASE_SIGNING_KEY', $run['err'] );

		$signed = blueline_release_sign( self::MANIFEST, $private );
		$this->assertIsArray( $signed );
		$this->assertSame( $keys[0][0], $signed['public_key'] );
		$this->assertSame( 'verified', blueline_updater_check_manifest_signature( self::MANIFEST, $signed['signature'], array( $keys[0][0] ) ) );
	}

	/**
	 * An existing key file is never overwritten, and nothing is printed then.
	 */
	public function test_generate_signing_key_refuses_to_overwrite(): void {
		$file = $this->dir . '/signing-key';
		file_put_contents( $file, "KEEP\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$run = $this->run_script( 'generate-signing-key.php', array( $file ) );

		$this->assertSame( 1, $run['code'] );
		$this->assertSame( "KEEP\n", file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the fixture back.
		$this->assertSame( '', $run['out'] );
		$this->assertSame( 0, preg_match( '#[A-Za-z0-9+/]{43}=#', $run['err'] ), 'no key material printed' );
	}

	/**
	 * Without a file the private key goes to stderr once, never to stdout.
	 */
	public function test_generate_signing_key_prints_private_key_to_stderr_only(): void {
		$run = $this->run_script( 'generate-signing-key.php' );

		$this->assertSame( 0, $run['code'] );
		$this->assertSame( 1, preg_match_all( '#[A-Za-z0-9+/]{86}==#', $run['err'], $private ), 'the private key, once' );
		$this->assertStringNotContainsString( $private[0][0], $run['out'] );
		$this->assertSame( 1, preg_match_all( '#[A-Za-z0-9+/]{43}=#', $run['out'], $public ) );
		$this->assertSame( $public[0][0], blueline_release_sign( self::MANIFEST, $private[0][0] )['public_key'] ?? null );
	}
}
