<?php
/**
 * Release helpers: pure functions shared by guard.php and build-manifest.php,
 * unit tested by themes/blueline/tests/ReleaseGuardTest.php. No git, no
 * WordPress, no network.
 *
 * @package blueline
 */

/**
 * Tag grammar: v1.2.3, v1.2.3-rc.1 or v1.2.3-beta.1 (no uppercase V, no +build, no leading
 * zeros, no other suffix: PHP's version_compare() ranks `-p1` / `-patch.1` above the final
 * release). A literal copy of blueline_updater_parse_tag(): scripts cannot load theme code.
 *
 * @param string $tag Tag name.
 * @return string|null Version without the `v`.
 */
function blueline_release_parse_tag( string $tag ): ?string {
	$num = '(?:0|[1-9]\d*)';

	if ( 1 !== preg_match( '/^v(' . $num . '\.' . $num . '\.' . $num . '(?:-(?:rc|beta)\.' . $num . ')?)$/', $tag, $m ) ) {
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
 * @param string      $changelog_md Contents of CHANGELOG.md.
 * @param string|null $package_json Contents of package.json; null skips that check.
 * @return string[]
 */
function blueline_release_guard( string $tag, string $style_css, string $functions_php, string $changelog_md, ?string $package_json = null ): array {
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

	if ( null !== $package_json ) {
		$package = json_decode( $package_json, true );
		$pkg_ver = is_array( $package ) && isset( $package['version'] ) ? (string) $package['version'] : '';
		if ( $pkg_ver !== $style ) {
			$errors[] = "package.json version is '$pkg_ver' but style.css Version is '$style'.";
		}
	}

	if ( null === blueline_release_changelog_section( $changelog_md, $version ) ) {
		$errors[] = "CHANGELOG.md has no non-empty '## $version' section.";
	}

	return $errors;
}

/**
 * The release asset holding manifest.json's signature. Must equal the theme
 * updater's blueline_updater_asset_names()['signature'] (ReleaseSigningTest).
 *
 * @return string
 */
function blueline_release_signature_asset(): string {
	return 'manifest.json.sig';
}

/**
 * Sign a manifest's exact bytes with an ed25519 secret key.
 *
 * The key is libsodium's 64-byte secret key (seed followed by public key) in
 * standard base64, as generate-signing-key.php writes it. A key whose public
 * half does not belong to its seed (a truncated or mangled paste) is refused,
 * as is any signature that does not verify under the key's own public half.
 *
 * @param string $manifest   Bytes to sign.
 * @param string $secret_b64 Base64 secret key (surrounding whitespace ignored).
 * @return array{signature: string, public_key: string}|null Base64 signature and public key, or null when the key is unusable.
 */
function blueline_release_sign( string $manifest, string $secret_b64 ): ?array {
	if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
		return null;
	}

	$secret = base64_decode( trim( $secret_b64 ), true );
	if ( ! is_string( $secret ) || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
		return null;
	}

	try {
		$public  = sodium_crypto_sign_publickey_from_secretkey( $secret );
		$derived = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( substr( $secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );
		if ( ! hash_equals( $derived, $public ) ) {
			return null;
		}

		$signature = sodium_crypto_sign_detached( $manifest, $secret );
		if ( ! sodium_crypto_sign_verify_detached( $signature, $manifest, $public ) ) {
			return null;
		}
	} catch ( Throwable ) {
		return null;
	} finally {
		sodium_memzero( $secret );
	}

	return array(
		'signature'  => base64_encode( $signature ),
		'public_key' => base64_encode( $public ),
	);
}

/**
 * What the release workflow does with a manifest's signature, given the keys
 * the theme trusts (BLUELINE_RELEASE_PUBLIC_KEYS) and the theme's own verdict
 * on the signature (blueline_updater_check_manifest_signature()).
 *
 * No keys in the theme: publish either way, with a warning (sites would not
 * check a signature). Keys in the theme: publish only a manifest that one of
 * them verifies, because every site running that theme refuses anything else.
 *
 * @param array<int, mixed> $keys          The theme's configured keys.
 * @param bool              $has_signature Whether manifest.json.sig exists.
 * @param string            $outcome       The theme's verdict on the pair.
 * @return array{ok: bool, level: string, message: string} `level` is a GitHub Actions command: notice, warning or error.
 */
function blueline_release_signature_verdict( array $keys, bool $has_signature, string $outcome ): array {
	if ( array() === $keys ) {
		return array(
			'ok'      => true,
			'level'   => 'warning',
			'message' => $has_signature
				? 'manifest.json is signed, but the theme trusts no release key yet (BLUELINE_RELEASE_PUBLIC_KEYS in themes/blueline/inc/updater-rules.php is empty), so sites ignore the signature. Add the public key: docs/RELEASING.md, "Release signing".'
				: 'Publishing an UNSIGNED release: the RELEASE_SIGNING_KEY secret is not set. Sites accept it only because the theme trusts no release key yet. To sign releases see docs/RELEASING.md, "Release signing".',
		);
	}

	$count = count( $keys );

	if ( ! $has_signature ) {
		return array(
			'ok'      => false,
			'level'   => 'error',
			'message' => "The theme requires signed releases (BLUELINE_RELEASE_PUBLIC_KEYS lists $count key(s)) but no manifest.json.sig was made: the RELEASE_SIGNING_KEY secret is not set. Every site on this theme would refuse the release, so nothing is published.",
		);
	}

	if ( 'verified' === $outcome ) {
		return array(
			'ok'      => true,
			'level'   => 'notice',
			'message' => "manifest.json.sig verifies against a key in BLUELINE_RELEASE_PUBLIC_KEYS ($count key(s) trusted).",
		);
	}

	return array(
		'ok'      => false,
		'level'   => 'error',
		'message' => "manifest.json.sig does NOT verify against any key in BLUELINE_RELEASE_PUBLIC_KEYS (result: $outcome). The RELEASE_SIGNING_KEY secret does not match the theme's public keys, so nothing is published.",
	);
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
