<?php
/**
 * GitHub release updater: pure rules (no WordPress calls beyond wp_parse_url,
 * no network). Where the token may go, which redirects may be followed, how a
 * response is classified. The I/O lives in updater-client.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BLUELINE_UPDATER_REPO' ) ) {
	define( 'BLUELINE_UPDATER_REPO', 'lusky3/arl-blueline' );
}

// Redirect hops allowed per download (API -> signed storage is one).
if ( ! defined( 'BLUELINE_UPDATER_MAX_HOPS' ) ) {
	define( 'BLUELINE_UPDATER_MAX_HOPS', 3 );
}

/*
 * Exact hosts GitHub redirects release-asset downloads to. Observed with
 * scripts/verify-github-asset-redirect.sh against a public repo: the API
 * answers 302 and storage serves the file with NO Authorization header. We
 * follow every hop by hand (redirection => 0) so the token is never forwarded.
 * Private-repo behaviour is confirmed by the owner (docs/RELEASING.md, R3).
 */
if ( ! defined( 'BLUELINE_UPDATER_STORAGE_HOSTS' ) ) {
	define(
		'BLUELINE_UPDATER_STORAGE_HOSTS',
		array(
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
		)
	);
}

/*
 * Release signing keys: the ed25519 PUBLIC keys whose signature on a release's
 * manifest.json this theme accepts. Each entry is the 32-byte public key in
 * standard base64 (44 characters, ending in `=`), exactly as
 * scripts/release/generate-signing-key.php prints it.
 *
 * Empty: releases are checked for integrity only (the zip's sha256 from the
 * manifest), and Site Health recommends turning signing on. Not empty: every
 * release must carry manifest.json.sig, a valid signature by ANY key listed
 * here, or it is not offered at all. A list (not one key) so a new key can be
 * added before the old one is retired; see docs/RELEASING.md, "Release signing".
 * Never put a private key here.
 */
if ( ! defined( 'BLUELINE_RELEASE_PUBLIC_KEYS' ) ) {
	define(
		'BLUELINE_RELEASE_PUBLIC_KEYS',
		array(
			'l1Jb134ZYUSlzWXiyb8CAl0+lISJjREiz4sXhyo/+jk=', // Release key generated 2026-10-15; the private half is in the owner's password manager and the RELEASE_SIGNING_KEY Actions secret.
		)
	);
}

/**
 * Whether a URL is on this repo's GitHub API, the only place the token may go.
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function blueline_updater_is_repo_api_url( string $url ): bool {
	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
		return false;
	}
	if ( 'https' !== strtolower( $parts['scheme'] ) || 'api.github.com' !== strtolower( $parts['host'] ) ) {
		return false;
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
		return false;
	}

	$path = $parts['path'];

	// Traversal could walk out of the repo prefix once GitHub normalizes it.
	if ( false !== strpos( $path, '..' ) || false !== stripos( $path, '%2e' ) ) {
		return false;
	}

	return 0 === stripos( $path, '/repos/' . BLUELINE_UPDATER_REPO . '/' );
}

/**
 * Decide what to do with one HTTP response while downloading by hand.
 *
 * @param int    $status      Response status code.
 * @param string $location    Location header ('' when absent).
 * @param string $current_url URL that produced the response.
 * @param int    $hop         Redirects followed so far.
 * @return array{action: string, url?: string, send_auth?: bool, code?: string}
 */
function blueline_updater_next_hop( int $status, string $location, string $current_url, int $hop ): array {
	unset( $current_url ); // Reserved: relative Locations are refused, never resolved.

	if ( $status >= 200 && $status < 300 ) {
		return array( 'action' => 'done' );
	}
	if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
		return array(
			'action' => 'fail',
			'code'   => 'bad_status',
		);
	}
	if ( $hop >= BLUELINE_UPDATER_MAX_HOPS ) {
		return array(
			'action' => 'fail',
			'code'   => 'too_many_redirects',
		);
	}
	if ( '' === $location ) {
		return array(
			'action' => 'fail',
			'code'   => 'missing_location',
		);
	}

	$parts = wp_parse_url( $location );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return array(
			'action' => 'fail',
			'code'   => 'insecure_redirect',
		);
	}

	if ( in_array( strtolower( $parts['host'] ), BLUELINE_UPDATER_STORAGE_HOSTS, true ) && ! isset( $parts['user'] ) && ! isset( $parts['port'] ) ) {
		return array(
			'action'    => 'follow',
			'url'       => $location,
			'send_auth' => false,
		);
	}
	if ( blueline_updater_is_repo_api_url( $location ) ) {
		return array(
			'action'    => 'follow',
			'url'       => $location,
			'send_auth' => true,
		);
	}

	return array(
		'action' => 'fail',
		'code'   => 'untrusted_host',
	);
}

/**
 * Classify a GitHub API response for caching and Site Health.
 *
 * GitHub limits requests in two ways. The primary limit reports itself with
 * `x-ratelimit-remaining: 0`; the secondary (abuse) limit answers 403 or 429
 * with a `retry-after` header while quota is still left. Both are "try again
 * later", never a revoked token, so neither may reach the red "refused" state.
 * A 404 on the repository means it is gone, renamed or private; that is its own
 * state because the fix (and who can make it) differs from a bad token.
 *
 * @param int                   $code    HTTP status code.
 * @param array<string, string> $headers Response headers, lower-cased keys.
 * @return string One of ok, unauthorized, rate_limited, not_found, error.
 */
function blueline_updater_classify_status( int $code, array $headers ): string {
	if ( $code >= 200 && $code < 300 ) {
		return 'ok';
	}
	if ( 429 === $code ) {
		return 'rate_limited';
	}
	if ( 403 === $code && ( '0' === (string) ( $headers['x-ratelimit-remaining'] ?? '' ) || '' !== trim( (string) ( $headers['retry-after'] ?? '' ) ) ) ) {
		return 'rate_limited';
	}
	if ( 401 === $code || 403 === $code ) {
		return 'unauthorized';
	}
	if ( 404 === $code ) {
		return 'not_found';
	}

	return 'error';
}

/**
 * The version a release tag names, or null when the tag is not ours.
 *
 * Accepts `v1.2.3`, `v1.2.3-rc.1` and `v1.2.3-beta.1`. Anything else after the
 * version is refused, on purpose: PHP's version_compare() ranks a `-p1`,
 * `-patch.1` or `-pl1` suffix ABOVE the final release, so a hotfix tagged that
 * way would be offered to the beta channel as newer than the version it patches
 * and then never reach stable, where a suffix means "prerelease". A hotfix is a
 * new patch number. Also rejects a missing or uppercase `v`, build metadata
 * (`+x`), a dangling `-`, and leading zeros.
 *
 * @param string $tag Tag name.
 * @return string|null Version without the `v`.
 */
function blueline_updater_parse_tag( string $tag ): ?string {
	$num = '(?:0|[1-9]\d*)';

	if ( 1 !== preg_match( '/^v(' . $num . '\.' . $num . '\.' . $num . '(?:-(?:rc|beta)\.' . $num . ')?)$/', $tag, $m ) ) {
		return null;
	}

	return $m[1];
}

/**
 * The update channel for a configured value: `beta` only on an exact opt-in.
 *
 * @param mixed $configured BLUELINE_UPDATE_CHANNEL (or anything).
 * @return string `stable` or `beta`.
 */
function blueline_updater_resolve_channel( $configured ): string {
	return ( is_string( $configured ) && 'beta' === strtolower( trim( $configured ) ) ) ? 'beta' : 'stable';
}

/**
 * The asset names a release must carry. Bound to the version, never read from
 * the manifest, so a manifest cannot choose which file gets installed.
 *
 * @param string $version Version without the `v`.
 * @return array{zip: string, manifest: string, signature: string}
 */
function blueline_updater_asset_names( string $version ): array {
	return array(
		'zip'       => 'blueline-' . $version . '.zip',
		'manifest'  => 'manifest.json',
		'signature' => 'manifest.json.sig',
	);
}

/**
 * Find a release asset by exact name, requiring a URL on this repo's API.
 *
 * @param array<int, mixed> $assets Release assets.
 * @param string            $name   Asset file name.
 * @return array<string, mixed>|null
 */
function blueline_updater_find_asset( array $assets, string $name ): ?array {
	foreach ( $assets as $asset ) {
		if ( is_array( $asset ) && ( $asset['name'] ?? null ) === $name ) {
			return ( is_string( $asset['url'] ?? null ) && blueline_updater_is_repo_api_url( $asset['url'] ) ) ? $asset : null;
		}
	}

	return null;
}

/**
 * Releases newer than the installed version that this channel is allowed to see,
 * newest first, whatever their assets.
 *
 * Skips drafts, tags that are not ours, and anything not newer than the
 * installed version. `stable` also skips any prerelease -- by flag OR by a `-`
 * suffix, because the flag is set by hand and must not be the only thing keeping
 * an rc off a live site.
 *
 * @param array<int, mixed> $releases  Decoded GitHub releases list.
 * @param string            $installed Installed theme version.
 * @param string            $channel   `stable` or `beta`.
 * @return array<int, array{version: string, release: array<string, mixed>}>
 */
function blueline_updater_newer_releases( array $releases, string $installed, string $channel ): array {
	$newer = array();

	foreach ( $releases as $release ) {
		if ( ! is_array( $release ) || ! is_string( $release['tag_name'] ?? null ) || ! empty( $release['draft'] ) ) {
			continue;
		}

		$version = blueline_updater_parse_tag( $release['tag_name'] );
		if ( null === $version || ! version_compare( $version, $installed, '>' ) ) {
			continue;
		}
		if ( 'beta' !== $channel && ( ! empty( $release['prerelease'] ) || false !== strpos( $version, '-' ) ) ) {
			continue;
		}

		$newer[] = array(
			'version' => $version,
			'release' => $release,
		);
	}

	usort(
		$newer,
		static function ( array $a, array $b ): int {
			return version_compare( $b['version'], $a['version'] );
		}
	);

	return $newer;
}

/**
 * A release's zip, manifest and signature assets, or null for each one it lacks.
 *
 * @param array<string, mixed> $release Release.
 * @param string               $version Version the tag names.
 * @return array{zip: array<string, mixed>|null, manifest: array<string, mixed>|null, signature: array<string, mixed>|null}
 */
function blueline_updater_release_assets( array $release, string $version ): array {
	$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
	$names  = blueline_updater_asset_names( $version );

	return array(
		'zip'       => blueline_updater_find_asset( $assets, $names['zip'] ),
		'manifest'  => blueline_updater_find_asset( $assets, $names['manifest'] ),
		'signature' => blueline_updater_find_asset( $assets, $names['signature'] ),
	);
}

/**
 * Releases this site may be offered, newest first: the newer releases
 * (blueline_updater_newer_releases()) that carry both a zip and a manifest.
 * The signature asset rides along (null when absent); whether it is required
 * is the client's decision, made per site from BLUELINE_RELEASE_PUBLIC_KEYS.
 *
 * @param array<int, mixed> $releases  Decoded GitHub releases list.
 * @param string            $installed Installed theme version.
 * @param string            $channel   `stable` or `beta`.
 * @return array<int, array{version: string, release: array<string, mixed>, zip_asset: array<string, mixed>, manifest_asset: array<string, mixed>, signature_asset: array<string, mixed>|null}>
 */
function blueline_updater_eligible_releases( array $releases, string $installed, string $channel ): array {
	$eligible = array();

	foreach ( blueline_updater_newer_releases( $releases, $installed, $channel ) as $entry ) {
		$assets = blueline_updater_release_assets( $entry['release'], $entry['version'] );

		if ( null === $assets['zip'] || null === $assets['manifest'] ) {
			continue;
		}

		$eligible[] = array(
			'version'         => $entry['version'],
			'release'         => $entry['release'],
			'zip_asset'       => $assets['zip'],
			'manifest_asset'  => $assets['manifest'],
			'signature_asset' => $assets['signature'],
		);
	}

	return $eligible;
}

/**
 * Releases this channel should be offering but cannot, because the zip or the
 * manifest is missing (a botched upload), newest first.
 *
 * Without this the check would skip such a release in silence and report "up to
 * date" while production never receives it.
 *
 * @param array<int, mixed> $releases  Decoded GitHub releases list.
 * @param string            $installed Installed theme version.
 * @param string            $channel   `stable` or `beta`.
 * @return array<int, array{version: string, release: array<string, mixed>}>
 */
function blueline_updater_incomplete_releases( array $releases, string $installed, string $channel ): array {
	$incomplete = array();

	foreach ( blueline_updater_newer_releases( $releases, $installed, $channel ) as $entry ) {
		$assets = blueline_updater_release_assets( $entry['release'], $entry['version'] );

		if ( null === $assets['zip'] || null === $assets['manifest'] ) {
			$incomplete[] = $entry;
		}
	}

	return $incomplete;
}

/**
 * Validate a decoded manifest against the release it came from.
 *
 * @param mixed  $manifest Decoded manifest.
 * @param string $version  Version the release tag names.
 * @return array<string, string>|null Normalized manifest, or null when invalid.
 */
function blueline_updater_validate_manifest( $manifest, string $version ): ?array {
	if ( ! is_array( $manifest ) ) {
		return null;
	}
	foreach ( array( 'version', 'zip', 'sha256' ) as $key ) {
		if ( ! is_string( $manifest[ $key ] ?? null ) ) {
			return null;
		}
	}

	$names = blueline_updater_asset_names( $version );
	if ( $manifest['version'] !== $version || $manifest['zip'] !== $names['zip'] || 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $manifest['sha256'] ) ) {
		return null;
	}

	$clean = array(
		'version' => $version,
		'zip'     => $names['zip'],
		'sha256'  => strtolower( $manifest['sha256'] ),
	);
	foreach ( array( 'requires_wp', 'requires_php', 'tested_wp', 'changelog' ) as $key ) {
		$clean[ $key ] = is_string( $manifest[ $key ] ?? null ) ? $manifest[ $key ] : '';
	}

	return $clean;
}

/**
 * The configured release public keys as a list (BLUELINE_RELEASE_PUBLIC_KEYS).
 *
 * A value that is not an array counts as one entry, so a mistyped constant
 * (a bare string, say) still demands a signature instead of switching signing off.
 *
 * @return array<int, mixed>
 */
function blueline_updater_public_keys(): array {
	$keys = BLUELINE_RELEASE_PUBLIC_KEYS;

	return is_array( $keys ) ? array_values( $keys ) : array( $keys );
}

/**
 * The usable keys among the configured entries, as 32-byte binary ed25519
 * public keys. An entry that is not exactly one base64-encoded public key
 * (44 characters) is skipped; it never makes the list count as empty.
 *
 * @param array<int, mixed> $keys blueline_updater_public_keys().
 * @return string[]
 */
function blueline_updater_decode_public_keys( array $keys ): array {
	$decoded = array();

	foreach ( $keys as $key ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '#^[A-Za-z0-9+/]{43}=$#', trim( $key ) ) ) {
			continue;
		}
		$decoded[] = (string) base64_decode( trim( $key ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a public key the theme ships, checked against the base64 alphabet above.
	}

	return $decoded;
}

/**
 * Check a release's manifest signature, before anything in the manifest is trusted.
 *
 * Outcomes:
 * - `not_required`: no keys configured; the integrity-only behaviour.
 * - `verified`: one of the configured keys signed these exact bytes.
 * - `missing`: keys are configured and the release has no manifest.json.sig.
 * - `invalid`: the signature is malformed or no configured key made it (also
 *   when none of the configured entries is a usable key).
 * - `unsupported`: this PHP cannot verify ed25519 signatures. WordPress loads
 *   sodium_compat when the sodium extension is missing, so this is unexpected.
 * Only `not_required` and `verified` let a release through.
 *
 * @param string            $manifest  The manifest's bytes exactly as downloaded (never re-encoded).
 * @param string|null       $signature The manifest.json.sig contents (base64), null when the release has none.
 * @param array<int, mixed> $keys      blueline_updater_public_keys().
 * @return string
 */
function blueline_updater_check_manifest_signature( string $manifest, ?string $signature, array $keys ): string {
	if ( array() === $keys ) {
		return 'not_required';
	}
	if ( null === $signature ) {
		return 'missing';
	}
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return 'unsupported';
	}

	$signature = trim( $signature );
	if ( 1 !== preg_match( '#^[A-Za-z0-9+/]{86}==$#', $signature ) ) {
		return 'invalid';
	}
	$raw = (string) base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a detached signature, checked against the base64 alphabet above.

	foreach ( blueline_updater_decode_public_keys( $keys ) as $key ) {
		try {
			if ( sodium_crypto_sign_verify_detached( $raw, $manifest, $key ) ) {
				return 'verified';
			}
		} catch ( Throwable ) {
			continue;
		}
	}

	return 'invalid';
}

/**
 * A short fingerprint of the configured keys, stored with the cached update
 * state so a changed key list is never answered from a check made under the
 * old one. '' when no keys are configured.
 *
 * @param array<int, mixed> $keys blueline_updater_public_keys().
 * @return string
 */
function blueline_updater_keys_fingerprint( array $keys ): string {
	if ( array() === $keys ) {
		return '';
	}

	$parts = array();
	foreach ( $keys as $key ) {
		$parts[] = is_scalar( $key ) ? (string) $key : gettype( $key );
	}

	return hash( 'sha256', implode( "\n", $parts ) );
}

/**
 * Which of two "newer release that cannot be installed" records to report.
 *
 * A signature failure outranks a missing file or a broken manifest whatever
 * the versions, because it can mean someone other than the maintainers
 * published the release; otherwise the newer release wins.
 *
 * @param array{version: string, reason: string}|null $current Reported so far.
 * @param array{version: string, reason: string}      $found   Just found.
 * @return array{version: string, reason: string}
 */
function blueline_updater_pick_rejected( ?array $current, array $found ): array {
	if ( null === $current ) {
		return $found;
	}

	$current_signature = 0 === strpos( $current['reason'], 'signature_' );
	$found_signature   = 0 === strpos( $found['reason'], 'signature_' );

	if ( $current_signature !== $found_signature ) {
		return $found_signature ? $found : $current;
	}

	return version_compare( $found['version'], $current['version'], '>' ) ? $found : $current;
}
