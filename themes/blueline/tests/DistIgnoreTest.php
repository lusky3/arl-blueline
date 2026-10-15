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
			$this->assertDoesNotMatchRegularExpression( '/^[+-]\S/', $rule, 'an include/exclude prefix needs one space after it' );
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

		$present = array(
			'style.css',
			'functions.php',
			'CHANGELOG.md',
			'assets/dist/index.js',
			'assets/dist/index.css',
			'assets/dist/account.css',
			'assets/dist/account.js',
			'assets/dist/account.asset.php',
			'assets/dist/sportspress.css',
			'assets/dist/sportspress.js',
			'assets/dist/sponsors.js',
			'assets/dist/fonts/inter-variable.woff2',
			'assets/fonts/OFL-Inter.txt',
			'assets/fonts/OFL-BarlowCondensed.txt',
			'tools/contrast-rules.json',
			'tools/tokens.json',
			'inc/updater-rules.php',
			'assets/src/js/settings-photos.js',
			'assets/src/js/settings-brand-colors.js',
			'assets/src/js/settings-occasions.js',
		);
		foreach ( $present as $path ) {
			$this->assertFileExists( $this->out . '/' . $path );
		}

		$absent = array(
			'tests',
			'tests-e2e',
			'tests-browser',
			'composer.json',
			'composer.lock',
			'package.json',
			'package-lock.json',
			'phpunit.xml',
			'phpcs.xml.dist',
			'playwright.config.js',
			'webpack.config.js',
			'assets/src/css',
			'.distignore',
			'.gitignore',
			'node_modules',
			'vendor',
			'tools/lib',
			'tools/check-contrast.mjs',
			'assets/src/js/index.js',
			'assets/src/js/navigation.js',
			'assets/fonts/inter-variable.woff2',
			'assets/dist/editor.js',
			'assets/dist/sponsors-page.js',
		);
		foreach ( $absent as $path ) {
			$this->assertFileDoesNotExist( $this->out . '/' . $path );
		}
		$this->assertSame( array(), glob( $this->out . '/assets/fonts/*.woff2' ), 'font sources stay out; assets/dist/fonts/ ships' );
	}

	/**
	 * Every assets/src/js file the package leaves out must already be inside a bundle.
	 *
	 * A script enqueued straight from source but missing a "+" line would
	 * otherwise vanish from the release without an error.
	 */
	public function test_every_excluded_source_script_is_bundled(): void {
		$src      = dirname( __DIR__ ) . '/assets/src/js/';
		$shipped  = array( 'settings-photos.js', 'settings-brand-colors.js', 'settings-occasions.js' );
		$bundled  = array();
		$frontier = self::webpack_entry_scripts();
		while ( $frontier ) {
			$file = array_pop( $frontier );
			if ( isset( $bundled[ $file ] ) || ! is_file( $src . $file ) ) {
				continue;
			}
			$bundled[ $file ] = true;
			preg_match_all( "/(?:from\s+|import\s+|require\(\s*)'\.\/([\w.-]+\.js)'/", (string) file_get_contents( $src . $file ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a unit test.
			array_push( $frontier, ...$m[1] );
		}

		$sources = array_map( 'basename', (array) glob( $src . '*.js' ) );
		$this->assertNotEmpty( $sources, 'premise: source scripts found' );
		foreach ( $sources as $file ) {
			if ( in_array( $file, $shipped, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $file, $bundled, "assets/src/js/$file is excluded from the package but not reachable from a webpack entry: give it a + line in .distignore or bundle it" );
		}
	}

	/**
	 * The assets/src/js files webpack.config.js names as entries (or parts of one).
	 *
	 * @return string[] Basenames.
	 */
	private static function webpack_entry_scripts(): array {
		$config = (string) file_get_contents( dirname( __DIR__ ) . '/webpack.config.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a unit test.
		preg_match_all( "/js\(\s*'([\w.-]+)'\s*\)|assets\/src\/js\/([\w.-]+\.js)/", $config, $m );

		$files = array();
		foreach ( $m[1] as $i => $short ) {
			$files[] = '' !== $short ? $short . '.js' : $m[2][ $i ];
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * The assets/dist/*.js files .distignore drops are the empty stubs of CSS-only entries.
	 */
	public function test_excluded_dist_scripts_are_empty_stubs(): void {
		$lines   = (array) file( __DIR__ . '/../.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$dropped = preg_grep( '#^/assets/dist/[\w-]+\.js$#', $lines );

		$this->assertNotEmpty( $dropped, 'premise: dist stubs listed' );
		foreach ( $dropped as $rule ) {
			$path = dirname( __DIR__ ) . $rule;
			$this->assertFileExists( $path );
			$this->assertSame( 0, filesize( $path ), "$rule now has content: ship it (remove its .distignore line)" );
		}
	}
}
