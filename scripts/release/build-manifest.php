<?php
/**
 * Write the update manifest and release notes for a built zip.
 *
 *   php scripts/release/build-manifest.php <tag> <zip> <outdir> [plugin-zip]
 *
 * Writes <outdir>/manifest.json and <outdir>/notes.md (the version's CHANGELOG
 * section, used as the GitHub release body). With [plugin-zip] (the
 * blueline-core zip package-plugin.sh built), the manifest also records that
 * zip's name and sha256, so the manifest's signature covers the plugin as well
 * (scripts/release/verify-plugin.php checks it).
 *
 * @package blueline
 */

require_once __DIR__ . '/lib.php';

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	list( , $tag, $zip, $out, $plugin_zip ) = array_pad( $argv, 5, '' );

	$version = blueline_release_parse_tag( (string) $tag );
	$theme   = dirname( __DIR__, 2 ) . '/themes/blueline/';
	$style   = is_readable( $theme . 'style.css' ) ? file_get_contents( $theme . 'style.css' ) : false;
	$log     = is_readable( $theme . 'CHANGELOG.md' ) ? file_get_contents( $theme . 'CHANGELOG.md' ) : false;

	if ( null === $version || ! is_file( (string) $zip ) || ! is_dir( (string) $out ) || false === $style || false === $log || ( '' !== $plugin_zip && ! is_file( $plugin_zip ) ) ) {
		fwrite( STDERR, "usage: build-manifest.php <tag> <zip> <outdir> [plugin-zip] (valid tag, existing zips and dir)\n" );
		exit( 2 );
	}

	$section = blueline_release_changelog_section( $log, $version );
	if ( null === $section ) {
		fwrite( STDERR, "build-manifest: no CHANGELOG section for $version\n" );
		exit( 1 );
	}

	$manifest = blueline_release_manifest( $version, $style, basename( $zip ), hash_file( 'sha256', $zip ), $section );
	if ( '' !== $plugin_zip ) {
		$manifest = blueline_release_manifest_with_plugin( $manifest, basename( $plugin_zip ), hash_file( 'sha256', $plugin_zip ) );
	}

	file_put_contents( $out . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	file_put_contents( $out . '/notes.md', $section . "\n" );
	echo "wrote $out/manifest.json and $out/notes.md\n";
}
