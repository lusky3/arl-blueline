<?php
/**
 * Sign a release manifest: writes <manifest>.sig (base64 ed25519 detached
 * signature over the manifest's exact bytes) next to it.
 *
 *   RELEASE_SIGNING_KEY=... php scripts/release/sign-manifest.php dist/manifest.json
 *
 * The secret key is read from the RELEASE_SIGNING_KEY environment variable and
 * nowhere else: never from the command line (it would land in the process list
 * and shell history), never printed, and wiped from memory after use. Only the
 * public key that signed is printed, so it can be compared with the theme's
 * BLUELINE_RELEASE_PUBLIC_KEYS. See docs/RELEASING.md, "Release signing".
 *
 * Exit 0 = signed, 1 = the key is unusable, 2 = usage (no key, no manifest).
 *
 * @package blueline
 */

require_once __DIR__ . '/lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$key = getenv( 'RELEASE_SIGNING_KEY' );

	// Out of the environment as early as possible, so nothing this process starts inherits it.
	putenv( 'RELEASE_SIGNING_KEY' );
	unset( $_ENV['RELEASE_SIGNING_KEY'], $_SERVER['RELEASE_SIGNING_KEY'] );

	if ( 2 !== count( $argv ) ) {
		fwrite( STDERR, "usage: RELEASE_SIGNING_KEY=<base64 secret key> php sign-manifest.php <manifest.json>\n(the key is never accepted as an argument)\n" );
		exit( 2 );
	}

	$manifest_path = $argv[1];
	$manifest      = is_file( $manifest_path ) && is_readable( $manifest_path ) ? file_get_contents( $manifest_path ) : false;

	if ( false === $manifest ) {
		fwrite( STDERR, "sign-manifest: cannot read $manifest_path\n" );
		exit( 2 );
	}
	if ( ! is_string( $key ) || '' === trim( $key ) ) {
		fwrite( STDERR, "sign-manifest: RELEASE_SIGNING_KEY is not set\n" );
		exit( 2 );
	}

	$signed = blueline_release_sign( $manifest, $key );
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $key );
	}

	if ( null === $signed ) {
		fwrite( STDERR, "sign-manifest: RELEASE_SIGNING_KEY is not a usable ed25519 secret key (expected 64 bytes in base64, as generate-signing-key.php writes it). Nothing was signed.\n" );
		exit( 1 );
	}

	$sig_path = $manifest_path . '.sig';
	if ( false === file_put_contents( $sig_path, $signed['signature'] . "\n" ) ) {
		fwrite( STDERR, "sign-manifest: cannot write $sig_path\n" );
		exit( 2 );
	}

	echo "sign-manifest: wrote $sig_path, signed by public key {$signed['public_key']}\n";
	exit( 0 );
}
