<?php
/**
 * GitHub release updater: HTTP, caching and verified download. The decisions
 * (which URL gets the token, which release wins) are pure and live in
 * updater-rules.php.
 *
 * The repository is public, so no token is needed: requests go out anonymously
 * (GitHub allows 60 an hour per address; a successful check is cached for 12
 * hours and a failed one for 1, and "Check again" bypasses the cache). A token
 * is optional. When BLUELINE_GITHUB_TOKEN is defined in
 * wp-config.php it is attached to requests for this repo's API and nothing
 * else, which lifts that limit or reaches a private fork, and it never appears
 * in an error message.
 *
 * When BLUELINE_RELEASE_PUBLIC_KEYS (updater-rules.php) lists a key, a release
 * is offered only if its manifest.json.sig verifies against one of them; the
 * manifest, and so the zip's sha256, is not read before that.
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
 * @param string $reason      Short reason code.
 * @param int    $status      HTTP status, 0 when none.
 * @param string $rate        The x-ratelimit-remaining header, '' when absent.
 * @param string $retry_after The retry-after header, '' when absent (a secondary rate limit sends it).
 * @return WP_Error
 */
function blueline_updater_error( string $reason, int $status = 0, string $rate = '', string $retry_after = '' ): WP_Error {
	return new WP_Error(
		'blueline_updater_' . $reason,
		__( 'The Blueline update service could not be reached or refused the request.', 'blueline' ),
		array(
			'status'      => $status,
			'rate'        => $rate,
			'retry_after' => $retry_after,
		)
	);
}

/**
 * GET a URL, following redirects by hand so the token never leaves GitHub's API.
 *
 * Each hop is decided by blueline_updater_next_hop(); the Authorization header
 * is sent only while the current URL is on this repo's API, and only when a
 * token is configured. With `filename`,
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

		if ( '' !== $token && blueline_updater_is_repo_api_url( $current ) ) {
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
			return blueline_updater_error(
				$next['code'],
				$status,
				(string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ),
				(string) wp_remote_retrieve_header( $response, 'retry-after' )
			);
		}

		$current = $next['url'];
	}

	return blueline_updater_error( 'too_many_redirects' );
}

/**
 * Map a request failure onto the status Site Health and the cache understand.
 *
 * @param WP_Error $error Error from blueline_updater_request().
 * @return string unauthorized, rate_limited, not_found or error.
 */
function blueline_updater_status_from_error( WP_Error $error ): string {
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : array();

	return blueline_updater_classify_status(
		(int) ( $data['status'] ?? 0 ),
		array(
			'x-ratelimit-remaining' => (string) ( $data['rate'] ?? '' ),
			'retry-after'           => (string) ( $data['retry_after'] ?? '' ),
		)
	);
}

/**
 * A fresh state record.
 *
 * @param string                                      $status    ok, unauthorized, rate_limited, not_found or error.
 * @param array<string, mixed>|null                   $candidate The update to offer, if any.
 * @param array{version: string, reason: string}|null $rejected  A newer release that exists but cannot be installed
 *                                                               (reason `assets`, `manifest`, `signature_missing`,
 *                                                               `signature_invalid` or `signature_unsupported`), if any.
 * @return array<string, mixed>
 */
function blueline_updater_make_state( string $status, ?array $candidate = null, ?array $rejected = null ): array {
	$keys = blueline_updater_public_keys();

	return array(
		'status'    => $status,
		'channel'   => blueline_updater_channel(),
		'installed' => defined( 'BLUELINE_VERSION' ) ? BLUELINE_VERSION : '0',
		'signing'   => array() === $keys ? 'off' : 'required',
		'keys'      => blueline_updater_keys_fingerprint( $keys ),
		'checked'   => time(),
		'candidate' => $candidate,
		'rejected'  => $rejected,
	);
}

/**
 * The signature outcome for one release (see blueline_updater_check_manifest_signature()),
 * fetching manifest.json.sig only when keys are configured.
 *
 * @param array<string, mixed> $entry    An eligible release (blueline_updater_eligible_releases()).
 * @param string               $manifest The manifest's bytes as downloaded.
 * @param array<int, mixed>    $keys     blueline_updater_public_keys().
 * @return string|WP_Error An outcome, or the request error when the signature could not be fetched.
 */
function blueline_updater_release_signature( array $entry, string $manifest, array $keys ) {
	// No keys: not_required, without a request. No asset: missing.
	if ( array() === $keys || ! is_array( $entry['signature_asset'] ?? null ) ) {
		return blueline_updater_check_manifest_signature( $manifest, null, $keys );
	}

	$response = blueline_updater_request( $entry['signature_asset']['url'], array( 'accept' => 'application/octet-stream' ) );
	if ( is_wp_error( $response ) ) {
		// A signature asset that is listed but gone (404) is a missing signature.
		return 'not_found' === blueline_updater_status_from_error( $response )
			? blueline_updater_check_manifest_signature( $manifest, null, $keys )
			: $response;
	}

	return blueline_updater_check_manifest_signature( $manifest, (string) wp_remote_retrieve_body( $response ), $keys );
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
	$rejected  = null;
	$keys      = blueline_updater_public_keys();

	// A newer release that is missing its zip or manifest is never offered, so say so
	// instead of letting the check report "up to date".
	$incomplete = blueline_updater_incomplete_releases( $releases, $installed, $channel );
	if ( $incomplete ) {
		$rejected = array(
			'version' => $incomplete[0]['version'],
			'reason'  => 'assets',
		);
	}

	// Newest first; a release whose manifest or signature is bad falls through to the next-older one.
	foreach ( blueline_updater_eligible_releases( $releases, $installed, $channel ) as $entry ) {
		$manifest_response = blueline_updater_request( $entry['manifest_asset']['url'], array( 'accept' => 'application/octet-stream' ) );
		$manifest          = null;
		$signature         = 'not_required';

		if ( is_wp_error( $manifest_response ) ) {
			$status = blueline_updater_status_from_error( $manifest_response );
			if ( 'unauthorized' === $status || 'rate_limited' === $status ) {
				return blueline_updater_make_state( $status );
			}
			// A manifest that is gone (404) is a broken release; anything else (a timeout,
			// a 5xx) says nothing about the release and is only a failed check.
			if ( 'not_found' !== $status ) {
				$had_error = true;
				continue;
			}
		} else {
			$body = (string) wp_remote_retrieve_body( $manifest_response );

			// The signature covers the bytes as published, so it is checked on the raw
			// body before json_decode() and before any field (sha256, version) is read.
			$signature = blueline_updater_release_signature( $entry, $body, $keys );
			if ( is_wp_error( $signature ) ) {
				$status = blueline_updater_status_from_error( $signature );
				if ( 'unauthorized' === $status || 'rate_limited' === $status ) {
					return blueline_updater_make_state( $status );
				}
				$had_error = true;
				continue;
			}
			if ( 'verified' !== $signature && 'not_required' !== $signature ) {
				$rejected = blueline_updater_pick_rejected(
					$rejected,
					array(
						'version' => $entry['version'],
						'reason'  => 'signature_' . $signature,
					)
				);
				continue;
			}

			$manifest = blueline_updater_validate_manifest( json_decode( $body, true ), $entry['version'] );
		}

		if ( null === $manifest ) {
			$rejected = blueline_updater_pick_rejected(
				$rejected,
				array(
					'version' => $entry['version'],
					'reason'  => 'manifest',
				)
			);
			continue;
		}

		// An older broken release is not news once a newer one is being offered.
		if ( null !== $rejected && ! version_compare( $rejected['version'], $entry['version'], '>' ) ) {
			$rejected = null;
		}

		return blueline_updater_make_state(
			'ok',
			array(
				'version'     => $entry['version'],
				'release_url' => is_string( $entry['release']['html_url'] ?? null ) ? $entry['release']['html_url'] : '',
				'package'     => $entry['zip_asset']['url'],
				'manifest'    => $manifest,
				'signature'   => $signature,
			),
			$rejected
		);
	}

	return blueline_updater_make_state( ( $had_error && null === $rejected ) ? 'error' : 'ok', null, $rejected );
}

/**
 * The cached update state; refreshed when absent, stale for this install, or forced.
 *
 * `ok` (including "nothing newer") is cached 12h, any failure 1h so a bad token
 * or a rate limit is not hammered. A newer release that cannot be installed
 * (see blueline_updater_make_state()) is also rechecked hourly, so a fixed
 * upload is noticed. Works without a token (see the file header). A cached
 * state made under a different release key list (or before keys existed) is
 * stale, so adding, rotating or removing a key takes effect on the next check.
 *
 * @param bool $force Skip the cache.
 * @return array<string, mixed>
 */
function blueline_updater_state( bool $force = false ): array {
	if ( ! $force ) {
		$cached = get_site_transient( BLUELINE_UPDATER_CACHE_KEY );
		$fresh  = blueline_updater_make_state( 'ok' );

		if (
			is_array( $cached )
			&& isset( $cached['status'] )
			&& ( $cached['channel'] ?? null ) === $fresh['channel']
			&& ( $cached['installed'] ?? null ) === $fresh['installed']
			&& ( $cached['keys'] ?? null ) === $fresh['keys']
		) {
			return $cached;
		}
	}

	$state = blueline_updater_fetch_state();
	set_site_transient( BLUELINE_UPDATER_CACHE_KEY, $state, ( 'ok' === $state['status'] && empty( $state['rejected'] ) ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

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
 * The sha256 comes from the candidate's manifest, which (with release keys
 * configured) was only accepted after its signature verified.
 *
 * @param string $package The package URL WordPress is about to download.
 * @return string|WP_Error Temp file path, or an error (the file is deleted on failure).
 */
function blueline_updater_download( string $package ) {
	$candidate = blueline_updater_state()['candidate'] ?? null;

	if ( ! blueline_updater_is_repo_api_url( $package ) || ! is_array( $candidate ) || ( $candidate['package'] ?? null ) !== $package ) {
		return new WP_Error( 'blueline_updater_unknown_package', __( 'That package is not the update Blueline offered. Check for updates again and retry.', 'blueline' ) );
	}

	// Belt and braces: never install a candidate whose signature was not verified while keys are configured.
	if ( array() !== blueline_updater_public_keys() && 'verified' !== ( $candidate['signature'] ?? null ) ) {
		return new WP_Error( 'blueline_updater_unsigned', __( 'This theme update was not verified against a Blueline release signing key, so it was not installed. Check for updates again; if it persists, see Tools > Site Health.', 'blueline' ) );
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
