<?php
/**
 * Prove a blueline-core zip is the one a signed release manifest names.
 *
 *   php scripts/release/verify-plugin.php manifest.json blueline-core-X.Y.Z.zip
 *
 * Checks <manifest>.sig with the theme's own function and keys
 * (blueline_updater_check_manifest_signature() and BLUELINE_RELEASE_PUBLIC_KEYS
 * in themes/blueline/inc/updater-rules.php), then that the manifest's
 * plugin_zip / plugin_sha256 name this zip and its bytes. The plugin has no
 * updater, so this is the check before installing it by hand (docs/RELEASING.md,
 * "Plugin"); the release workflow runs it before publishing. Policy:
 * blueline_release_plugin_verdict() in lib.php, consistent with
 * verify-manifest.php: while the theme trusts no key, a zip matching the
 * manifest's sha256 passes with a warning. Prints one GitHub Actions
 * annotation (::notice::, ::warning:: or ::error::).
 *
 * Exit 0 = genuine, 1 = do not install, 2 = could not tell (usage, unreadable file).
 *
 * @package blueline
 */

require_once __DIR__ . '/lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$manifest_path = $argv[1] ?? '';
	$zip_path      = $argv[2] ?? '';
	$manifest      = '' !== $manifest_path && is_file( $manifest_path ) && is_readable( $manifest_path ) ? file_get_contents( $manifest_path ) : false;
	$zip_sha256    = '' !== $zip_path && is_file( $zip_path ) && is_readable( $zip_path ) ? hash_file( 'sha256', $zip_path ) : false;

	if ( 3 !== count( $argv ) || false === $manifest || false === $zip_sha256 ) {
		fwrite( STDERR, "usage: php verify-plugin.php <manifest.json> <blueline-core-X.Y.Z.zip>\n(manifest.json.sig must sit beside manifest.json)\n" );
		exit( 2 );
	}

	// The theme file only refuses to load outside WordPress; it has no other side effects.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	}
	require_once dirname( __DIR__, 2 ) . '/themes/blueline/inc/updater-rules.php';

	if ( ! function_exists( 'blueline_updater_check_manifest_signature' ) || ! function_exists( 'blueline_updater_public_keys' ) ) {
		fwrite( STDERR, "verify-plugin: could not load the theme's signature check\n" );
		exit( 2 );
	}

	$sig_path  = $manifest_path . '.sig';
	$signature = is_file( $sig_path ) ? file_get_contents( $sig_path ) : null;
	if ( false === $signature ) {
		fwrite( STDERR, "verify-plugin: cannot read $sig_path\n" );
		exit( 2 );
	}

	$outcome = blueline_updater_check_manifest_signature( $manifest, $signature, blueline_updater_public_keys() );
	$verdict = blueline_release_plugin_verdict( $outcome, $manifest, basename( $zip_path ), $zip_sha256 );

	echo '::' . $verdict['level'] . '::' . $verdict['message'] . "\n";
	exit( $verdict['ok'] ? 0 : 1 );
}
