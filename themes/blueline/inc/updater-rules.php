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
 * @return array{zip: string, manifest: string}
 */
function blueline_updater_asset_names( string $version ): array {
	return array(
		'zip'      => 'blueline-' . $version . '.zip',
		'manifest' => 'manifest.json',
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
 * A release's zip and manifest assets, or null for either one it lacks.
 *
 * @param array<string, mixed> $release Release.
 * @param string               $version Version the tag names.
 * @return array{zip: array<string, mixed>|null, manifest: array<string, mixed>|null}
 */
function blueline_updater_release_assets( array $release, string $version ): array {
	$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
	$names  = blueline_updater_asset_names( $version );

	return array(
		'zip'      => blueline_updater_find_asset( $assets, $names['zip'] ),
		'manifest' => blueline_updater_find_asset( $assets, $names['manifest'] ),
	);
}

/**
 * Releases this site may be offered, newest first: the newer releases
 * (blueline_updater_newer_releases()) that carry both assets.
 *
 * @param array<int, mixed> $releases  Decoded GitHub releases list.
 * @param string            $installed Installed theme version.
 * @param string            $channel   `stable` or `beta`.
 * @return array<int, array{version: string, release: array<string, mixed>, zip_asset: array<string, mixed>, manifest_asset: array<string, mixed>}>
 */
function blueline_updater_eligible_releases( array $releases, string $installed, string $channel ): array {
	$eligible = array();

	foreach ( blueline_updater_newer_releases( $releases, $installed, $channel ) as $entry ) {
		$assets = blueline_updater_release_assets( $entry['release'], $entry['version'] );

		if ( null === $assets['zip'] || null === $assets['manifest'] ) {
			continue;
		}

		$eligible[] = array(
			'version'        => $entry['version'],
			'release'        => $entry['release'],
			'zip_asset'      => $assets['zip'],
			'manifest_asset' => $assets['manifest'],
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
