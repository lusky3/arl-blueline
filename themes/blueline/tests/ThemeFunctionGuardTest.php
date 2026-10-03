<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Stops `function_exists( 'blueline_*' )` guards creeping back onto the theme's
 * own helpers (audit CC-04). functions.php loads every inc/ file on every
 * request, so such a guard only turns a rename or deletion into a silent
 * fallback. A guard is allowed only for a name listed below with its reason.
 */
final class ThemeFunctionGuardTest extends TestCase {

	/**
	 * Functions the blueline-core plugin defines. The theme must keep working
	 * with the plugin inactive, so every call into one of these stays guarded.
	 *
	 * @var string[]
	 */
	private const PLUGIN_FUNCTIONS = array(
		'blueline_account_endpoints',
		'blueline_account_slug_query_var',
		'blueline_current_user_player_id',
		'blueline_current_user_team_ids',
		'blueline_find_player_candidates',
		'blueline_get_linked_player_id',
		'blueline_get_post_titles',
		'blueline_user_is_verified_player_owner',
	);

	/**
	 * Theme functions whose guard is deliberate, each with its reason.
	 *
	 * @var array<string,string>
	 */
	private const KEPT_THEME_GUARDS = array(
		'blueline_is_commerce_request' => 'Defined in inc/woocommerce.php after its no-WooCommerce early return.',
		'blueline_get_sidebar_setting' => 'Pluggable definition in inc/woocommerce.php (if ! function_exists), not a call guard.',
	);

	/**
	 * Directories under the theme root that are not theme runtime code.
	 *
	 * @var string[]
	 */
	private const SKIP_DIRS = array( 'vendor', 'node_modules', 'tests', 'tests-e2e', '.wp-core-oracle', 'assets' );

	/**
	 * Every guarded blueline_* name is a plugin function or a kept theme guard.
	 */
	public function test_no_guard_on_an_always_loaded_theme_function(): void {
		$violations = array();
		foreach ( $this->guards() as $guard ) {
			if ( ! in_array( $guard['name'], self::PLUGIN_FUNCTIONS, true ) && ! isset( self::KEPT_THEME_GUARDS[ $guard['name'] ] ) ) {
				$violations[] = $guard['where'] . ' ' . $guard['name'];
			}
		}

		$this->assertSame(
			array(),
			$violations,
			'Call theme helpers directly: functions.php always loads them. If the function lives in blueline-core, add it to PLUGIN_FUNCTIONS; otherwise justify it in KEPT_THEME_GUARDS.'
		);
	}

	/**
	 * Every allowlist entry is still guarded somewhere, so the lists cannot rot.
	 */
	public function test_every_allowlisted_name_is_still_guarded(): void {
		$guarded = array_unique( array_column( $this->guards(), 'name' ) );
		$allowed = array_merge( self::PLUGIN_FUNCTIONS, array_keys( self::KEPT_THEME_GUARDS ) );

		$this->assertSame( array(), array_values( array_diff( $allowed, $guarded ) ), 'Remove allowlist entries nothing guards any more.' );
	}

	/**
	 * A plugin function defined by the theme too would make its guard meaningless.
	 */
	public function test_theme_defines_none_of_the_plugin_functions(): void {
		$this->assertSame( array(), array_values( array_intersect( self::PLUGIN_FUNCTIONS, $this->definitions( $this->theme_files() ) ) ) );
	}

	/**
	 * Each listed plugin function really exists in blueline-core: a rename there
	 * would leave the theme's guard permanently false.
	 */
	public function test_plugin_functions_exist_in_blueline_core(): void {
		$includes = dirname( __DIR__, 3 ) . '/plugins/blueline-core/includes';
		if ( ! is_dir( $includes ) ) {
			$this->markTestSkipped( 'blueline-core source is not next to the theme in this checkout.' );
		}

		$defined = $this->definitions( $this->php_files( $includes, array() ) );

		$this->assertSame( array(), array_values( array_diff( self::PLUGIN_FUNCTIONS, $defined ) ) );
	}

	/**
	 * Every function_exists()/is_callable() guard on a blueline_* name in theme code.
	 *
	 * @return array<int, array{name:string, where:string}>
	 */
	private function guards(): array {
		$root   = dirname( __DIR__ );
		$guards = array();
		foreach ( $this->theme_files() as $file ) {
			$tokens = $this->code_tokens( $file );
			$count  = count( $tokens );
			for ( $i = 0; $i + 2 < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( strtolower( $tokens[ $i ][1] ), array( 'function_exists', 'is_callable' ), true ) ) {
					continue;
				}
				if ( '(' !== $tokens[ $i + 1 ] || ! is_array( $tokens[ $i + 2 ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i + 2 ][0] ) {
					continue;
				}
				$name = trim( $tokens[ $i + 2 ][1], '\'"' );
				if ( 0 === strpos( $name, 'blueline_' ) ) {
					$guards[] = array(
						'name'  => $name,
						'where' => substr( $file, strlen( $root ) + 1 ) . ':' . $tokens[ $i ][2],
					);
				}
			}
		}
		return $guards;
	}

	/**
	 * Names of all named, non-method functions declared in the given files.
	 *
	 * @param string[] $files Absolute paths.
	 * @return string[]
	 */
	private function definitions( array $files ): array {
		$names = array();
		foreach ( $files as $file ) {
			$tokens = $this->code_tokens( $file );
			$count  = count( $tokens );
			for ( $i = 0; $i + 1 < $count; $i++ ) {
				if ( is_array( $tokens[ $i ] ) && T_FUNCTION === $tokens[ $i ][0] && is_array( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
					$names[] = $tokens[ $i + 1 ][1];
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Theme runtime PHP files (templates and inc/), absolute paths.
	 *
	 * @return string[]
	 */
	private function theme_files(): array {
		return $this->php_files( dirname( __DIR__ ), self::SKIP_DIRS );
	}

	/**
	 * PHP files under a directory, skipping the named top-level directories.
	 *
	 * @param string   $dir  Absolute directory.
	 * @param string[] $skip Top-level directory names to leave out.
	 * @return string[]
	 */
	private function php_files( string $dir, array $skip ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( $dir ) + 1 );
			if ( 'php' === $file->getExtension() && ! in_array( strtok( $relative, '/' ), $skip, true ) ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * A file's tokens without whitespace or comments.
	 *
	 * @param string $file Absolute path.
	 * @return array<int, array|string>
	 */
	private function code_tokens( string $file ): array {
		$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		return array_values(
			array_filter(
				token_get_all( $source ),
				static fn( $token ) => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
			)
		);
	}
}
