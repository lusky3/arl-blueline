<?php
/**
 * GitHub release updater: HTTP, caching and verified download. The decisions
 * (which URL gets the token, which release wins) are pure and live in
 * updater-rules.php.
 *
 * The token comes from wp-config.php only. It is attached to requests for this
 * repo's API and nothing else, and never appears in an error message.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BLUELINE_UPDATER_CACHE_KEY' ) ) {
	define( 'BLUELINE_UPDATER_CACHE_KEY', 'blueline_updater_state' );
}

/**
 * The configured GitHub token, or '' when absent, empty, blank or not a string.
 *
 * @return string
 */
function blueline_updater_token(): string {
	if ( ! defined( 'BLUELINE_GITHUB_TOKEN' ) || ! is_string( BLUELINE_GITHUB_TOKEN ) ) {
		return '';
	}

	return trim( BLUELINE_GITHUB_TOKEN );
}

/**
 * The configured update channel: `stable` (default) or `beta`.
 *
 * @return string
 */
function blueline_updater_channel(): string {
	return blueline_updater_resolve_channel( defined( 'BLUELINE_UPDATE_CHANNEL' ) ? BLUELINE_UPDATE_CHANNEL : 'stable' );
}

/**
 * One failure, with fixed text: no URL, no token, no response body.
 *
 * @param string $reason Short reason code.
 * @param int    $status HTTP status, 0 when none.
 * @param string $rate   The x-ratelimit-remaining header, '' when absent.
 * @return WP_Error
 */
function blueline_updater_error( string $reason, int $status = 0, string $rate = '' ): WP_Error {
	return new WP_Error(
		'blueline_updater_' . $reason,
		__( 'The Blueline update service could not be reached or refused the request.', 'blueline' ),
		array(
			'status' => $status,
			'rate'   => $rate,
		)
	);
}

/**
 * GET a URL, following redirects by hand so the token never leaves GitHub's API.
 *
 * Each hop is decided by blueline_updater_next_hop(); the Authorization header
 * is sent only while the current URL is on this repo's API. With `filename`,
 * every hop streams to that file (a redirect's body is empty and is overwritten
 * by the next hop), so the final hop's body is what remains.
 *
 * @param string               $url  URL to fetch.
 * @param array<string, mixed> $args `accept` (Accept header) and `filename` (stream target).
 * @return array<string, mixed>|WP_Error Final 2xx response, or a WP_Error.
 */
function blueline_updater_request( string $url, array $args = array() ) {
	$token   = blueline_updater_token();
	$current = $url;

	for ( $hop = 0; $hop <= BLUELINE_UPDATER_MAX_HOPS; $hop++ ) {
		$headers = array(
			'Accept'     => $args['accept'] ?? 'application/vnd.github+json',
			'User-Agent' => 'Blueline/' . ( defined( 'BLUELINE_VERSION' ) ? BLUELINE_VERSION : '0' ),
		);

		if ( blueline_updater_is_repo_api_url( $current ) ) {
			if ( '' === $token ) {
				return blueline_updater_error( 'no_token' );
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$request = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( ! empty( $args['filename'] ) ) {
			$request['stream']   = true;
			$request['filename'] = $args['filename'];
		}

		$response = wp_remote_get( $current, $request );
		if ( is_wp_error( $response ) ) {
			return blueline_updater_error( 'transport' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$next   = blueline_updater_next_hop( $status, (string) wp_remote_retrieve_header( $response, 'location' ), $current, $hop );

		if ( 'done' === $next['action'] ) {
			return $response;
		}
		if ( 'fail' === $next['action'] ) {
			return blueline_updater_error( $next['code'], $status, (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) );
		}

		$current = $next['url'];
	}

	return blueline_updater_error( 'too_many_redirects' );
}

/**
 * Map a request failure onto the status Site Health and the cache understand.
 *
 * @param WP_Error $error Error from blueline_updater_request().
 * @return string unauthorized, rate_limited or error.
 */
function blueline_updater_status_from_error( WP_Error $error ): string {
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : array();

	return blueline_updater_classify_status(
		(int) ( $data['status'] ?? 0 ),
		array( 'x-ratelimit-remaining' => (string) ( $data['rate'] ?? '' ) )
	);
}

/**
 * A fresh state record.
 *
 * @param string                    $status    ok, unauthorized, rate_limited, error or no_token.
 * @param array<string, mixed>|null $candidate The update to offer, if any.
 * @return array<string, mixed>
 */
function blueline_updater_make_state( string $status, ?array $candidate = null ): array {
	return array(
		'status'    => $status,
		'channel'   => blueline_updater_channel(),
		'installed' => defined( 'BLUELINE_VERSION' ) ? BLUELINE_VERSION : '0',
		'checked'   => time(),
		'candidate' => $candidate,
	);
}

/**
 * Ask GitHub what is available, ignoring the cache.
 *
 * @return array<string, mixed>
 */
function blueline_updater_fetch_state(): array {
	$channel   = blueline_updater_channel();
	$installed = defined( 'BLUELINE_VERSION' ) ? BLUELINE_VERSION : '0';

	$response = blueline_updater_request( 'https://api.github.com/repos/' . BLUELINE_UPDATER_REPO . '/releases?per_page=10' );
	if ( is_wp_error( $response ) ) {
		return blueline_updater_make_state( blueline_updater_status_from_error( $response ) );
	}

	$releases = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $releases ) ) {
		return blueline_updater_make_state( 'error' );
	}

	$had_error = false;

	// Newest first; a release whose manifest is broken falls through to the next-older one.
	foreach ( blueline_updater_eligible_releases( $releases, $installed, $channel ) as $entry ) {
		$manifest_response = blueline_updater_request( $entry['manifest_asset']['url'], array( 'accept' => 'application/octet-stream' ) );
		if ( is_wp_error( $manifest_response ) ) {
			$status = blueline_updater_status_from_error( $manifest_response );
			if ( 'unauthorized' === $status || 'rate_limited' === $status ) {
				return blueline_updater_make_state( $status );
			}
			$had_error = true;
			continue;
		}

		$manifest = blueline_updater_validate_manifest( json_decode( wp_remote_retrieve_body( $manifest_response ), true ), $entry['version'] );
		if ( null === $manifest ) {
			continue;
		}

		return blueline_updater_make_state(
			'ok',
			array(
				'version'     => $entry['version'],
				'release_url' => is_string( $entry['release']['html_url'] ?? null ) ? $entry['release']['html_url'] : '',
				'package'     => $entry['zip_asset']['url'],
				'manifest'    => $manifest,
			)
		);
	}

	return blueline_updater_make_state( $had_error ? 'error' : 'ok' );
}

/**
 * The cached update state; refreshed when absent, stale for this install, or forced.
 *
 * `ok` (including "nothing newer") is cached 12h, any failure 1h so a bad token
 * or a rate limit is not hammered. No token means no state and no request.
 *
 * @param bool $force Skip the cache.
 * @return array<string, mixed>
 */
function blueline_updater_state( bool $force = false ): array {
	if ( '' === blueline_updater_token() ) {
		return blueline_updater_make_state( 'no_token' );
	}

	if ( ! $force ) {
		$cached = get_site_transient( BLUELINE_UPDATER_CACHE_KEY );
		$fresh  = blueline_updater_make_state( 'ok' );

		if ( is_array( $cached ) && ( $cached['channel'] ?? null ) === $fresh['channel'] && ( $cached['installed'] ?? null ) === $fresh['installed'] && isset( $cached['status'] ) ) {
			return $cached;
		}
	}

	$state = blueline_updater_fetch_state();
	set_site_transient( BLUELINE_UPDATER_CACHE_KEY, $state, 'ok' === $state['status'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $state;
}

/**
 * Forget the cached state.
 *
 * @return void
 */
function blueline_updater_flush(): void {
	delete_site_transient( BLUELINE_UPDATER_CACHE_KEY );
}

/**
 * Download the offered package to a temp file and verify its sha256.
 *
 * @param string $package The package URL WordPress is about to download.
 * @return string|WP_Error Temp file path, or an error (the file is deleted on failure).
 */
function blueline_updater_download( string $package ) {
	$candidate = blueline_updater_state()['candidate'] ?? null;

	if ( ! blueline_updater_is_repo_api_url( $package ) || ! is_array( $candidate ) || ( $candidate['package'] ?? null ) !== $package ) {
		return new WP_Error( 'blueline_updater_unknown_package', __( 'That package is not the update Blueline offered. Check for updates again and retry.', 'blueline' ) );
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$tmp = wp_tempnam( $candidate['manifest']['zip'] );
	if ( ! $tmp ) {
		return new WP_Error( 'blueline_updater_tempfile', __( 'Could not create a temporary file for the update.', 'blueline' ) );
	}

	$response = blueline_updater_request(
		$package,
		array(
			'accept'   => 'application/octet-stream',
			'filename' => $tmp,
		)
	);
	if ( is_wp_error( $response ) ) {
		wp_delete_file( $tmp );

		return $response;
	}

	if ( ! hash_equals( $candidate['manifest']['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
		wp_delete_file( $tmp );

		return new WP_Error( 'blueline_updater_checksum', __( 'The downloaded theme package failed its integrity check; the installed theme was not changed.', 'blueline' ) );
	}

	return $tmp;
}
