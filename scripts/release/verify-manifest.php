<?php
/**
 * Prove a release's manifest and signature verify, with the theme's own code
 * and keys, before anything is published.
 *
 *   php scripts/release/verify-manifest.php dist/manifest.json
 *
 * Reads <manifest>.sig when it exists and checks it with the very function
 * every site runs (blueline_updater_check_manifest_signature() in
 * themes/blueline/inc/updater-rules.php) against that file's
 * BLUELINE_RELEASE_PUBLIC_KEYS. Prints one GitHub Actions annotation
 * (::notice::, ::warning:: or ::error::); the policy is
 * blueline_release_signature_verdict() in lib.php.
 *
 * Exit 0 = safe to publish, 1 = must not publish, 2 = could not tell.
 *
 * @package blueline
 */

require_once __DIR__ . '/lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$manifest_path = $argv[1] ?? '';
	$manifest      = '' !== $manifest_path && is_file( $manifest_path ) && is_readable( $manifest_path ) ? file_get_contents( $manifest_path ) : false;

	if ( false === $manifest ) {
		fwrite( STDERR, "usage: php verify-manifest.php <manifest.json>\n" );
		exit( 2 );
	}

	// The theme file only refuses to load outside WordPress; it has no other side effects.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	}
	require_once dirname( __DIR__, 2 ) . '/themes/blueline/inc/updater-rules.php';

	if ( ! function_exists( 'blueline_updater_check_manifest_signature' ) || ! function_exists( 'blueline_updater_public_keys' ) ) {
		fwrite( STDERR, "verify-manifest: could not load the theme's signature check\n" );
		exit( 2 );
	}

	$sig_path  = $manifest_path . '.sig';
	$signature = is_file( $sig_path ) ? file_get_contents( $sig_path ) : null;
	if ( false === $signature ) {
		fwrite( STDERR, "verify-manifest: cannot read $sig_path\n" );
		exit( 2 );
	}

	$keys    = blueline_updater_public_keys();
	$outcome = blueline_updater_check_manifest_signature( $manifest, $signature, $keys );
	$verdict = blueline_release_signature_verdict( $keys, null !== $signature, $outcome );

	echo '::' . $verdict['level'] . '::' . $verdict['message'] . "\n";
	exit( $verdict['ok'] ? 0 : 1 );
}
