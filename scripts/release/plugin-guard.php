<?php
/**
 * Plugin release guard CLI: refuse to package or publish a blueline-core tree
 * whose version sources disagree.
 *
 *   php scripts/release/plugin-guard.php [expected-version] [plugin-dir]
 *
 * Checks the Version header == BLUELINE_CORE_VERSION == composer.json
 * "version" (if present) == README/readme.txt "Stable tag" (if present), and
 * that composer.json's require.php floor matches the Requires PHP header.
 * `expected-version` (no leading v) additionally pins the header to a value.
 *
 * Exit 0 = consistent, 1 = something disagrees, 2 = could not tell (a gate
 * that cannot see must block, never pass).
 *
 * @package blueline-core
 */

require_once __DIR__ . '/plugin-lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$expected = (string) ( $argv[1] ?? '' );
	$plugin   = rtrim( (string) ( $argv[2] ?? dirname( __DIR__, 2 ) . '/plugins/blueline-core' ), '/' ) . '/';

	$main = is_readable( $plugin . 'blueline-core.php' ) ? file_get_contents( $plugin . 'blueline-core.php' ) : false;
	if ( false === $main ) {
		fwrite( STDERR, "plugin guard: cannot read {$plugin}blueline-core.php\n" );
		exit( 2 );
	}

	$composer = null;
	if ( is_file( $plugin . 'composer.json' ) ) {
		$composer = is_readable( $plugin . 'composer.json' ) ? file_get_contents( $plugin . 'composer.json' ) : false;
		if ( false === $composer ) {
			fwrite( STDERR, "plugin guard: cannot read {$plugin}composer.json\n" );
			exit( 2 );
		}
	}

	$readmes = array();
	foreach ( array( 'README.md', 'readme.txt' ) as $name ) {
		if ( is_file( $plugin . $name ) ) {
			$contents = is_readable( $plugin . $name ) ? file_get_contents( $plugin . $name ) : false;
			if ( false === $contents ) {
				fwrite( STDERR, "plugin guard: cannot read $plugin$name\n" );
				exit( 2 );
			}
			$readmes[ $name ] = $contents;
		}
	}

	$errors = blueline_plugin_release_guard( $main, $composer, $readmes, ltrim( $expected, 'v' ) );
	foreach ( $errors as $error ) {
		fwrite( STDERR, "plugin guard: $error\n" );
	}

	if ( array() === $errors ) {
		echo 'plugin guard: blueline-core ' . blueline_plugin_release_header( $main, 'Version' ) . " is consistent\n";
	}
	exit( array() === $errors ? 0 : 1 );
}
