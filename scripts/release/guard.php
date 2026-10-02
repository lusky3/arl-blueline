<?php
/**
 * Release guard CLI: refuse to publish a tag that disagrees with the theme.
 *
 *   php scripts/release/guard.php <tag>
 *
 * Exit 0 = safe to publish, 1 = something is mislabelled, 2 = could not tell
 * (a gate that cannot see must block, never pass).
 *
 * @package blueline
 */

require_once __DIR__ . '/lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$tag   = $argv[1] ?? '';
	$theme = dirname( __DIR__, 2 ) . '/themes/blueline/';
	$files = array();

	foreach ( array( 'style.css', 'functions.php', 'CHANGELOG.md' ) as $name ) {
		$contents = is_readable( $theme . $name ) ? file_get_contents( $theme . $name ) : false;
		if ( false === $contents ) {
			fwrite( STDERR, "release guard: cannot read $name\n" );
			exit( 2 );
		}
		$files[ $name ] = $contents;
	}

	$errors = blueline_release_guard( $tag, $files['style.css'], $files['functions.php'], $files['CHANGELOG.md'] );
	foreach ( $errors as $error ) {
		fwrite( STDERR, "release guard: $error\n" );
	}

	if ( array() === $errors ) {
		echo "release guard: $tag is consistent\n";
	}
	exit( array() === $errors ? 0 : 1 );
}
