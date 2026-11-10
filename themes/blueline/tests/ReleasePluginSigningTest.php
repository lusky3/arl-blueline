<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release/lib.php';
require_once __DIR__ . '/stubs/no-release-keys.php';
require_once __DIR__ . '/../inc/updater-rules.php';

/**
 * Covers the blueline-core zip's place in the signed manifest:
 * blueline_release_manifest_with_plugin(), blueline_release_plugin_verdict(),
 * build-manifest.php's plugin argument and scripts/release/verify-plugin.php.
 * Every key here is a throwaway generated for the test run.
 */
final class ReleasePluginSigningTest extends TestCase {

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
		$this->dir = sys_get_temp_dir() . '/bl-plugin-signing-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
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
	 * @param string   $script Script file name in scripts/release.
	 * @param string[] $args   Arguments.
	 * @return array{code: int, out: string, err: string}
	 */
	private function run_script( string $script, array $args = array() ): array {
		$command = array_merge( array( PHP_BINARY, dirname( __DIR__, 3 ) . '/scripts/release/' . $script ), $args );
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- running the release script under test.
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->dir,
			array( 'PATH' => (string) getenv( 'PATH' ) )
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
	 * Write a file into the scratch directory.
	 *
	 * @param string $name  File name.
	 * @param string $bytes Contents.
	 * @return string Its path.
	 */
	private function write( string $name, string $bytes ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		return $path;
	}

	/**
	 * Manifest bytes as build-manifest.php writes them, covering a plugin zip.
	 *
	 * @param string $plugin_zip    Plugin zip name.
	 * @param string $plugin_sha256 Plugin zip sha256.
	 * @return string
	 */
	private function manifest_json( string $plugin_zip, string $plugin_sha256 ): string {
		$manifest = blueline_release_manifest( '1.2.0', "Version: 1.2.0\n", 'blueline-1.2.0.zip', str_repeat( 'a', 64 ), '- x' );
		$manifest = blueline_release_manifest_with_plugin( $manifest, $plugin_zip, $plugin_sha256 );

		return json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- mirrors build-manifest.php, which has no WordPress.
	}

	/**
	 * The plugin fields ride inside the manifest the theme already accepts,
	 * so sites on the current updater keep validating it.
	 */
	public function test_manifest_with_plugin_adds_fields_the_theme_still_accepts(): void {
		$manifest = blueline_release_manifest( '1.2.0', "Version: 1.2.0\n", 'blueline-1.2.0.zip', str_repeat( 'a', 64 ), '- x' );
		$with     = blueline_release_manifest_with_plugin( $manifest, 'blueline-core-0.2.0.zip', str_repeat( 'B', 64 ) );

		$this->assertSame( 'blueline-core-0.2.0.zip', $with['plugin_zip'] );
		$this->assertSame( str_repeat( 'b', 64 ), $with['plugin_sha256'], 'stored lower-case' );
		$this->assertSame( $manifest, array_diff_key( $with, array_flip( array( 'plugin_zip', 'plugin_sha256' ) ) ), 'theme fields untouched' );
		$this->assertNotNull( blueline_updater_validate_manifest( json_decode( (string) json_encode( $with ), true ), '1.2.0' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- mirrors build-manifest.php.
	}

	/**
	 * A verified manifest naming this exact zip and hash passes.
	 */
	public function test_verdict_accepts_a_verified_manifest_that_names_the_zip(): void {
		$sha = hash( 'sha256', 'plugin bytes' );

		$verdict = blueline_release_plugin_verdict( 'verified', $this->manifest_json( 'blueline-core-0.2.0.zip', $sha ), 'blueline-core-0.2.0.zip', $sha );

		$this->assertTrue( $verdict['ok'], $verdict['message'] );
	}

	/**
	 * Without a verified signature the manifest proves nothing, whatever it says.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unverified_outcomes(): array {
		return array(
			'invalid'      => array( 'invalid' ),
			'missing'      => array( 'missing' ),
			'not_required' => array( 'not_required' ),
			'empty'        => array( '' ),
		);
	}

	/**
	 * Refuses anything but a verified signature.
	 *
	 * @param string $outcome Signature outcome.
	 */
	#[DataProvider( 'unverified_outcomes' )]
	public function test_verdict_refuses_an_unverified_manifest( string $outcome ): void {
		$sha = hash( 'sha256', 'plugin bytes' );

		$verdict = blueline_release_plugin_verdict( $outcome, $this->manifest_json( 'blueline-core-0.2.0.zip', $sha ), 'blueline-core-0.2.0.zip', $sha );

		$this->assertFalse( $verdict['ok'] );
	}

	/**
	 * A swapped zip (different bytes), a different file name, or a manifest
	 * that never covered a plugin all fail.
	 */
	public function test_verdict_refuses_a_zip_the_manifest_does_not_cover(): void {
		$sha   = hash( 'sha256', 'plugin bytes' );
		$other = hash( 'sha256', 'tampered bytes' );
		$plain = json_encode( blueline_release_manifest( '1.2.0', "Version: 1.2.0\n", 'blueline-1.2.0.zip', $sha, '- x' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fixture.

		$swapped  = blueline_release_plugin_verdict( 'verified', $this->manifest_json( 'blueline-core-0.2.0.zip', $sha ), 'blueline-core-0.2.0.zip', $other );
		$renamed  = blueline_release_plugin_verdict( 'verified', $this->manifest_json( 'blueline-core-0.2.0.zip', $sha ), 'blueline-core-0.1.0.zip', $sha );
		$uncover  = blueline_release_plugin_verdict( 'verified', (string) $plain, 'blueline-core-0.2.0.zip', $sha );
		$garbage  = blueline_release_plugin_verdict( 'verified', 'not json', 'blueline-core-0.2.0.zip', $sha );
		$bad_hash = blueline_release_plugin_verdict( 'verified', $this->manifest_json( 'blueline-core-0.2.0.zip', 'zz' ), 'blueline-core-0.2.0.zip', 'zz' );

		$this->assertFalse( $swapped['ok'] );
		$this->assertStringContainsString( 'sha256', $swapped['message'] );
		$this->assertFalse( $renamed['ok'] );
		$this->assertFalse( $uncover['ok'] );
		$this->assertFalse( $garbage['ok'] );
		$this->assertFalse( $bad_hash['ok'] );
	}

	/**
	 * End to end with a real signature: sign the manifest, check it the way
	 * a site does, then the plugin verdict holds only for the untouched zip.
	 */
	public function test_signed_manifest_binds_the_plugin_zip(): void {
		list( $secret, $public ) = $this->keypair();
		$zip                     = 'PK fake plugin zip bytes';
		$manifest                = $this->manifest_json( 'blueline-core-0.2.0.zip', hash( 'sha256', $zip ) );
		$signed                  = blueline_release_sign( $manifest, $secret );
		$this->assertNotNull( $signed );

		$outcome = blueline_updater_check_manifest_signature( $manifest, $signed['signature'], array( $public ) );
		$good    = blueline_release_plugin_verdict( $outcome, $manifest, 'blueline-core-0.2.0.zip', hash( 'sha256', $zip ) );
		$bad     = blueline_release_plugin_verdict( $outcome, $manifest, 'blueline-core-0.2.0.zip', hash( 'sha256', $zip . 'x' ) );

		// The manifest itself cannot be edited to match a swapped zip without breaking the signature.
		$edited  = $this->manifest_json( 'blueline-core-0.2.0.zip', hash( 'sha256', $zip . 'x' ) );
		$re_sign = blueline_updater_check_manifest_signature( $edited, $signed['signature'], array( $public ) );

		$this->assertSame( 'verified', $outcome );
		$this->assertTrue( $good['ok'], $good['message'] );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 'invalid', $re_sign );
	}

	/**
	 * The build-manifest.php CLI records the plugin zip when given one, and leaves
	 * the manifest as before when not.
	 */
	public function test_build_manifest_cli_records_the_plugin_zip(): void {
		$style   = (string) file_get_contents( __DIR__ . '/../style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$version = blueline_release_parse_style_header( $style, 'Version' );
		$theme   = $this->write( "blueline-$version.zip", 'theme zip' );
		$plugin  = $this->write( 'blueline-core-9.9.9.zip', 'plugin zip' );
		mkdir( $this->dir . '/with', 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test scratch directory.
		mkdir( $this->dir . '/without', 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test scratch directory.

		$with    = $this->run_script( 'build-manifest.php', array( "v$version", $theme, $this->dir . '/with', $plugin ) );
		$without = $this->run_script( 'build-manifest.php', array( "v$version", $theme, $this->dir . '/without' ) );
		$missing = $this->run_script( 'build-manifest.php', array( "v$version", $theme, $this->dir . '/without', $this->dir . '/nope.zip' ) );

		$this->assertSame( 0, $with['code'], $with['err'] );
		$manifest = json_decode( (string) file_get_contents( $this->dir . '/with/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the script's output file.
		$this->assertSame( 'blueline-core-9.9.9.zip', $manifest['plugin_zip'] );
		$this->assertSame( hash( 'sha256', 'plugin zip' ), $manifest['plugin_sha256'] );
		$this->assertSame( hash( 'sha256', 'theme zip' ), $manifest['sha256'] );

		$this->assertSame( 0, $without['code'], $without['err'] );
		$plain = json_decode( (string) file_get_contents( $this->dir . '/without/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the script's output file.
		$this->assertArrayNotHasKey( 'plugin_zip', $plain );

		$this->assertSame( 2, $missing['code'] );
	}

	/**
	 * The verify-plugin.php CLI refuses a manifest no trusted key signed, and a zip
	 * the manifest does not cover. (It runs against the shipped theme's keys,
	 * which a test cannot sign for; the accepting path is covered by
	 * test_signed_manifest_binds_the_plugin_zip.)
	 */
	public function test_verify_plugin_cli_refuses_unsigned_and_foreign_signatures(): void {
		list( $secret ) = $this->keypair();
		$zip            = $this->write( 'blueline-core-0.2.0.zip', 'plugin zip' );
		$manifest       = $this->write( 'manifest.json', $this->manifest_json( 'blueline-core-0.2.0.zip', hash( 'sha256', 'plugin zip' ) ) );

		$unsigned = $this->run_script( 'verify-plugin.php', array( $manifest, $zip ) );

		$signed = blueline_release_sign( (string) file_get_contents( $manifest ), $secret ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixture.
		$this->write( 'manifest.json.sig', $signed['signature'] . "\n" );
		$foreign = $this->run_script( 'verify-plugin.php', array( $manifest, $zip ) );

		$this->assertSame( 1, $unsigned['code'], $unsigned['out'] . $unsigned['err'] );
		$this->assertStringStartsWith( '::error::', $unsigned['out'] );
		$this->assertSame( 1, $foreign['code'], $foreign['out'] . $foreign['err'] );
		$this->assertStringStartsWith( '::error::', $foreign['out'] );
	}

	/**
	 * Missing files are a usage error, never a pass.
	 */
	public function test_verify_plugin_cli_usage_errors(): void {
		$manifest = $this->write( 'manifest.json', '{}' );

		$no_args = $this->run_script( 'verify-plugin.php' );
		$no_zip  = $this->run_script( 'verify-plugin.php', array( $manifest, $this->dir . '/nope.zip' ) );
		$no_man  = $this->run_script( 'verify-plugin.php', array( $this->dir . '/nope.json', $manifest ) );

		$this->assertSame( 2, $no_args['code'] );
		$this->assertSame( 2, $no_zip['code'] );
		$this->assertSame( 2, $no_man['code'] );
	}
}
