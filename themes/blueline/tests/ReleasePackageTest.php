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
	 * Skip unless every tool the packaging script needs is installed.
	 */
	private function require_tools(): void {
		foreach ( array( 'git', 'rsync', 'tar', 'zip', 'unzip', 'bash' ) as $tool ) {
			if ( ! $this->has( $tool ) ) {
				$this->markTestSkipped( "$tool is not installed." );
			}
		}
	}

	/**
	 * The theme's style.css Version.
	 *
	 * @return string
	 */
	private function theme_version(): string {
		$version = '1.0.1';
		if ( 1 === preg_match( '/^Version:\s*(\S+)/m', (string) file_get_contents( __DIR__ . '/../style.css' ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
			$version = $m[1];
		}

		return $version;
	}

	/**
	 * Run package.sh.
	 *
	 * @param string $tag      Tag argument.
	 * @param string $out      Output directory.
	 * @param bool   $worktree Package the working tree instead of the tag.
	 * @return array{0: int, 1: string} Exit status and combined output.
	 */
	private function package( string $tag, string $out, bool $worktree = true ): array {
		exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running the packaging script under test.
			sprintf(
				'%sSOURCE_DATE_EPOCH=1767225600 bash %s %s %s 2>&1',
				$worktree ? 'BLUELINE_PACKAGE_FROM_WORKTREE=1 ' : 'BLUELINE_PACKAGE_FROM_WORKTREE= ',
				escapeshellarg( dirname( __DIR__, 3 ) . '/scripts/release/package.sh' ),
				escapeshellarg( $tag ),
				escapeshellarg( $out )
			),
			$output,
			$status
		);

		return array( $status, implode( "\n", $output ) );
	}

	/**
	 * The zip has one blueline/ root, ships runtime files and nothing else.
	 */
	public function test_package_builds_a_clean_zip_rooted_at_blueline(): void {
		$this->require_tools();
		$version   = $this->theme_version();
		$this->out = sys_get_temp_dir() . '/bl-package-' . getmypid();

		list( $status, $output ) = $this->package( 'v' . $version, $this->out );
		$this->assertSame( 0, $status, $output );

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
		$this->assertStringContainsString( "\nblueline/assets/src/js/settings-occasions.js\n", $joined );
		foreach ( array( 'blueline/tests/', 'blueline/node_modules/', 'blueline/vendor/', 'blueline/composer.json', 'blueline/package.json', 'blueline/assets/src/css/', 'blueline/tools/lib/', 'blueline/assets/src/js/index.js' ) as $absent ) {
			$this->assertStringNotContainsString( "\n" . $absent, $joined );
		}
	}

	/**
	 * The same tree and SOURCE_DATE_EPOCH give byte-identical zips.
	 */
	public function test_package_is_reproducible(): void {
		$this->require_tools();
		$version   = $this->theme_version();
		$this->out = sys_get_temp_dir() . '/bl-package-repro-' . getmypid();

		list( $status, $output ) = $this->package( 'v' . $version, $this->out . '/a' );
		$this->assertSame( 0, $status, $output );
		list( $status, $output ) = $this->package( 'v' . $version, $this->out . '/b' );
		$this->assertSame( 0, $status, $output );

		$this->assertSame(
			hash_file( 'sha256', $this->out . '/a/blueline-' . $version . '.zip' ),
			hash_file( 'sha256', $this->out . '/b/blueline-' . $version . '.zip' )
		);
	}

	/**
	 * A release packages a tag; a tag that does not exist is an error, not a silent working-tree build.
	 */
	public function test_package_refuses_an_unknown_tag(): void {
		$this->require_tools();
		$this->out = sys_get_temp_dir() . '/bl-package-notag-' . getmypid();

		list( $status, $output ) = $this->package( 'v0.0.0-no-such-tag', $this->out, false );

		$this->assertNotSame( 0, $status );
		$this->assertStringContainsString( 'tag v0.0.0-no-such-tag not found', $output );
	}
}
