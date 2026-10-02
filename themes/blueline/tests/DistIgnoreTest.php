<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Runs the real rsync with .distignore, so the shipped file set is proven
 * under the same semantics scripts/deploy-theme.sh and the release zip use.
 */
final class DistIgnoreTest extends TestCase {

	/**
	 * Staging directory for the current test.
	 *
	 * @var string
	 */
	private string $out = '';

	/**
	 * Remove the staging directory.
	 */
	protected function tearDown(): void {
		if ( '' !== $this->out && is_dir( $this->out ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->out ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test cleanup of a temp dir; no WordPress runtime here.
		}
	}

	/**
	 * Every rule line is a whole-line comment or a plain pattern.
	 */
	public function test_distignore_has_no_inline_comments(): void {
		$lines = (array) file( __DIR__ . '/../.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$rules = array_filter(
			$lines,
			static function ( $line ) {
				return '#' !== substr( ltrim( $line ), 0, 1 );
			}
		);

		$this->assertNotEmpty( $rules, 'premise: rules found' );
		foreach ( $rules as $rule ) {
			$this->assertStringNotContainsString( ' #', $rule );
			$this->assertSame( trim( $rule ), $rule, 'no stray whitespace' );
		}
	}

	/**
	 * What ships, and what does not.
	 */
	public function test_rsync_with_distignore_ships_runtime_files_only(): void {
		exec( 'command -v rsync', $unused, $found ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- probing for rsync in a test.
		if ( 0 !== $found ) {
			$this->markTestSkipped( 'rsync is not installed.' );
		}

		$this->out = sys_get_temp_dir() . '/bl-distignore-' . getmypid();
		$root      = dirname( __DIR__ );
		exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running rsync in a test.
			sprintf(
				'rsync -a --exclude-from=%s %s %s 2>&1',
				escapeshellarg( $root . '/.distignore' ),
				escapeshellarg( $root . '/' ),
				escapeshellarg( $this->out . '/' )
			),
			$output,
			$status
		);
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		foreach ( array( 'style.css', 'functions.php', 'CHANGELOG.md', 'assets/dist/index.js', 'tools/contrast-rules.json', 'inc/updater-rules.php' ) as $present ) {
			$this->assertFileExists( $this->out . '/' . $present );
		}
		$this->assertNotEmpty( glob( $this->out . '/assets/src/js/*.js' ), 'assets/src/js ships' );

		$absent = array(
			'tests',
			'tests-e2e',
			'tests-browser',
			'composer.json',
			'composer.lock',
			'package.json',
			'package-lock.json',
			'phpunit.xml',
			'playwright.config.js',
			'webpack.config.js',
			'assets/src/css',
			'.distignore',
			'.gitignore',
			'node_modules',
			'vendor',
		);
		foreach ( $absent as $path ) {
			$this->assertFileDoesNotExist( $this->out . '/' . $path );
		}
	}
}
