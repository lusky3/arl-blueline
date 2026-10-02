<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Runs scripts/release/package.sh against the working tree and inspects the zip.
 */
final class ReleasePackageTest extends TestCase {

	/**
	 * Output directory for the current test.
	 *
	 * @var string
	 */
	private string $out = '';

	/**
	 * Remove the output directory.
	 */
	protected function tearDown(): void {
		if ( '' !== $this->out && is_dir( $this->out ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->out ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test cleanup of a temp dir.
		}
	}

	/**
	 * Whether a command exists.
	 *
	 * @param string $name Command name.
	 * @return bool
	 */
	private function has( string $name ): bool {
		exec( 'command -v ' . escapeshellarg( $name ), $unused, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- probing for a tool in a test.

		return 0 === $status;
	}

	/**
	 * The zip has one blueline/ root, ships runtime files and nothing else.
	 */
	public function test_package_builds_a_clean_zip_rooted_at_blueline(): void {
		foreach ( array( 'rsync', 'zip', 'unzip', 'bash' ) as $tool ) {
			if ( ! $this->has( $tool ) ) {
				$this->markTestSkipped( "$tool is not installed." );
			}
		}

		$version = '1.0.1';
		if ( 1 === preg_match( '/^Version:\s*(\S+)/m', (string) file_get_contents( __DIR__ . '/../style.css' ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
			$version = $m[1];
		}

		$this->out = sys_get_temp_dir() . '/bl-package-' . getmypid();
		exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running the packaging script under test.
			sprintf( 'bash %s v%s %s 2>&1', escapeshellarg( dirname( __DIR__, 3 ) . '/scripts/release/package.sh' ), escapeshellarg( $version ), escapeshellarg( $this->out ) ),
			$output,
			$status
		);
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$zip = $this->out . '/blueline-' . $version . '.zip';
		$this->assertFileExists( $zip );

		exec( 'unzip -Z1 ' . escapeshellarg( $zip ), $entries ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- listing the zip under test.
		$this->assertNotEmpty( $entries );
		foreach ( $entries as $entry ) {
			$this->assertStringStartsWith( 'blueline/', $entry );
		}

		$joined = "\n" . implode( "\n", $entries ) . "\n";
		foreach ( array( 'blueline/style.css', 'blueline/assets/dist/index.js', 'blueline/tools/contrast-rules.json', 'blueline/inc/updater-rules.php' ) as $present ) {
			$this->assertStringContainsString( "\n" . $present . "\n", $joined );
		}
		$this->assertStringContainsString( "\nblueline/assets/src/js/", $joined );
		foreach ( array( 'blueline/tests/', 'blueline/node_modules/', 'blueline/vendor/', 'blueline/composer.json', 'blueline/package.json', 'blueline/assets/src/css/' ) as $absent ) {
			$this->assertStringNotContainsString( "\n" . $absent, $joined );
		}
	}
}
