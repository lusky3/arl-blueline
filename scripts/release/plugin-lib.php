<?php
/**
 * Plugin release helpers: pure functions shared by plugin-guard.php, no git,
 * no WordPress, no network. Mirrors scripts/release/lib.php (the theme's).
 *
 * The plugin is versioned on its own line (0.x.y today), independent of the
 * theme's tag. docs/RELEASING.md explains how the two relate.
 *
 * @package blueline-core
 */

/**
 * Plain semver: 1.2.3 or 1.2.3-rc.1 (no leading zeros, no +build).
 *
 * @param string $version Candidate version.
 * @return bool
 */
function blueline_plugin_release_valid_version( string $version ): bool {
	$num = '(?:0|[1-9]\d*)';

	return 1 === preg_match( '/^' . $num . '\.' . $num . '\.' . $num . '(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/', $version );
}

/**
 * One header from the main plugin file's leading docblock ('' when absent).
 *
 * @param string $php Contents of blueline-core.php.
 * @param string $key Header name, e.g. `Version` or `Requires PHP`.
 * @return string
 */
function blueline_plugin_release_header( string $php, string $key ): string {
	return 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':[ \t]*(.+?)[ \t]*$/mi', $php, $m ) ? $m[1] : '';
}

/**
 * The BLUELINE_CORE_VERSION constant's value ('' when absent).
 *
 * @param string $php Contents of blueline-core.php.
 * @return string
 */
function blueline_plugin_release_constant( string $php ): string {
	return 1 === preg_match( "/define\\(\\s*'BLUELINE_CORE_VERSION',\\s*'([^']*)'/", $php, $m ) ? $m[1] : '';
}

/**
 * The `Stable tag:` value of a readme ('' when absent), in either the
 * readme.txt (`Stable tag: 1.2.3`) or README.md (`**Stable tag:** 1.2.3`) style.
 *
 * @param string $readme Contents of README.md or readme.txt.
 * @return string
 */
function blueline_plugin_release_stable_tag( string $readme ): string {
	return 1 === preg_match( '/^[ \t*_>-]*Stable tag:[ \t*_]*([^\s*_]+)/mi', $readme, $m ) ? $m[1] : '';
}

/**
 * The minimum PHP version a composer constraint like `>=8.3` or `^8.3` demands.
 *
 * @param string $constraint composer.json `require.php`.
 * @return string Dotted lower bound without patch, '' when it cannot be read.
 */
function blueline_plugin_release_php_floor( string $constraint ): string {
	return 1 === preg_match( '/^\s*(?:>=|\^|~)?\s*(\d+\.\d+)/', $constraint, $m ) ? $m[1] : '';
}

/**
 * Everything wrong with releasing this plugin tree; an empty list means safe to ship.
 *
 * @param string               $main_php      Contents of blueline-core.php.
 * @param string|null          $composer_json Contents of composer.json (null when absent).
 * @param array<string,string> $readmes       Readme file name => contents (only the ones that exist).
 * @param string               $expected      Version the caller insists on ('' = no expectation).
 * @return string[]
 */
function blueline_plugin_release_guard( string $main_php, ?string $composer_json, array $readmes, string $expected = '' ): array {
	$errors  = array();
	$version = blueline_plugin_release_header( $main_php, 'Version' );

	if ( ! blueline_plugin_release_valid_version( $version ) ) {
		return array( "blueline-core.php Version header is '$version', not MAJOR.MINOR.PATCH[-suffix]." );
	}

	if ( '' !== $expected && $expected !== $version ) {
		$errors[] = "blueline-core.php Version is '$version' but '$expected' was expected.";
	}

	$constant = blueline_plugin_release_constant( $main_php );
	if ( $constant !== $version ) {
		$errors[] = "BLUELINE_CORE_VERSION is '$constant' but the Version header is '$version'.";
	}

	foreach ( $readmes as $name => $contents ) {
		$stable = blueline_plugin_release_stable_tag( $contents );
		if ( '' !== $stable && $stable !== $version ) {
			$errors[] = "$name Stable tag is '$stable' but the Version header is '$version'.";
		}
	}

	if ( null !== $composer_json ) {
		$composer = json_decode( $composer_json, true );
		if ( ! is_array( $composer ) ) {
			$errors[] = 'composer.json is not valid JSON.';
		} else {
			if ( isset( $composer['version'] ) && (string) $composer['version'] !== $version ) {
				$errors[] = "composer.json version is '{$composer['version']}' but the Version header is '$version'.";
			}

			$header_php = blueline_plugin_release_header( $main_php, 'Requires PHP' );
			$floor      = isset( $composer['require']['php'] ) ? blueline_plugin_release_php_floor( (string) $composer['require']['php'] ) : '';
			if ( '' === $floor ) {
				$errors[] = "composer.json has no readable require.php (the Requires PHP header says '$header_php').";
			} elseif ( $floor !== $header_php ) {
				$errors[] = "composer.json require.php floor is '$floor' but the Requires PHP header is '$header_php'.";
			}
		}
	}

	return $errors;
}
