<?php
/**
 * Release helpers: pure functions shared by guard.php and build-manifest.php,
 * unit tested by themes/blueline/tests/ReleaseGuardTest.php. No git, no
 * WordPress, no network.
 *
 * @package blueline
 */

/**
 * Tag grammar: v1.2.3 or v1.2.3-rc.1 (no uppercase V, no +build, no leading zeros).
 * A literal copy of blueline_updater_parse_tag(): scripts cannot load theme code.
 *
 * @param string $tag Tag name.
 * @return string|null Version without the `v`.
 */
function blueline_release_parse_tag( string $tag ): ?string {
	$num = '(?:0|[1-9]\d*)';

	if ( 1 !== preg_match( '/^v(' . $num . '\.' . $num . '\.' . $num . '(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?)$/', $tag, $m ) ) {
		return null;
	}

	return $m[1];
}

/**
 * One header from style.css's leading comment ('' when absent).
 *
 * @param string $css Contents of style.css.
 * @param string $key Header name, e.g. `Version` or `Requires at least`.
 * @return string
 */
function blueline_release_parse_style_header( string $css, string $key ): string {
	return 1 === preg_match( '/^' . preg_quote( $key, '/' ) . ':[ \t]*(.+?)[ \t]*$/mi', $css, $m ) ? $m[1] : '';
}

/**
 * The BLUELINE_VERSION constant's value from functions.php ('' when absent).
 *
 * @param string $php Contents of functions.php.
 * @return string
 */
function blueline_release_parse_version_constant( string $php ): string {
	return 1 === preg_match( "/define\\(\\s*'BLUELINE_VERSION',\\s*'([^']*)'/", $php, $m ) ? $m[1] : '';
}

/**
 * The body of a version's CHANGELOG section, or null when missing or empty.
 * Matches `## X`, `## [X]`, and either followed by ` - date`.
 *
 * @param string $md      Contents of CHANGELOG.md.
 * @param string $version Version, e.g. `1.2.0-rc.1`.
 * @return string|null
 */
function blueline_release_changelog_section( string $md, string $version ): ?string {
	$lines = preg_split( '/\r\n|\r|\n/', $md );
	$body  = array();
	$found = false;

	foreach ( (array) $lines as $line ) {
		if ( 1 === preg_match( '/^##(?!#)\s*(.*)$/', $line, $m ) ) {
			if ( $found ) {
				break;
			}
			$found = 1 === preg_match( '/^\[?' . preg_quote( $version, '/' ) . '\]?(?:\s+-\s+.*)?$/', trim( $m[1] ) );
			continue;
		}
		if ( $found ) {
			$body[] = $line;
		}
	}

	$text = trim( implode( "\n", $body ) );

	return ( $found && '' !== $text ) ? $text : null;
}

/**
 * Everything wrong with publishing this tag; an empty list means safe to ship.
 *
 * @param string $tag          Tag name (`v1.2.0`).
 * @param string $style_css    Contents of style.css.
 * @param string $functions_php Contents of functions.php.
 * @param string $changelog_md Contents of CHANGELOG.md.
 * @return string[]
 */
function blueline_release_guard( string $tag, string $style_css, string $functions_php, string $changelog_md ): array {
	$version = blueline_release_parse_tag( $tag );
	if ( null === $version ) {
		return array( "Tag '$tag' is not vMAJOR.MINOR.PATCH or vMAJOR.MINOR.PATCH-suffix." );
	}

	$errors = array();
	$style  = blueline_release_parse_style_header( $style_css, 'Version' );
	if ( $style !== $version ) {
		$errors[] = "style.css Version is '$style' but the tag is '$version'.";
	}

	$constant = blueline_release_parse_version_constant( $functions_php );
	if ( $constant !== $style ) {
		$errors[] = "BLUELINE_VERSION is '$constant' but style.css Version is '$style'.";
	}

	if ( null === blueline_release_changelog_section( $changelog_md, $version ) ) {
		$errors[] = "CHANGELOG.md has no non-empty '## $version' section.";
	}

	return $errors;
}

/**
 * The update manifest the theme's updater reads. Requirements come from the
 * style.css headers, the single source of truth.
 *
 * @param string $version   Version without the `v`.
 * @param string $style_css Contents of style.css.
 * @param string $zip_name  Zip file name.
 * @param string $sha256    Lower-case hex digest of the zip.
 * @param string $changelog This version's CHANGELOG section.
 * @return array<string, string>
 */
function blueline_release_manifest( string $version, string $style_css, string $zip_name, string $sha256, string $changelog ): array {
	return array(
		'version'      => $version,
		'requires_wp'  => blueline_release_parse_style_header( $style_css, 'Requires at least' ),
		'requires_php' => blueline_release_parse_style_header( $style_css, 'Requires PHP' ),
		'tested_wp'    => blueline_release_parse_style_header( $style_css, 'Tested up to' ),
		'zip'          => $zip_name,
		'sha256'       => $sha256,
		'changelog'    => $changelog,
	);
}
