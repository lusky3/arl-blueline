<?php
/**
 * Theme updates from GitHub releases: the WordPress surface.
 *
 * Always active: the repository is public, so no credentials are needed.
 * BLUELINE_GITHUB_TOKEN in wp-config.php is optional (a higher rate limit, or a
 * private fork) and BLUELINE_UPDATE_CHANNEL picks `stable` or `beta`. The
 * style.css `Update URI` header routes WordPress's update check to
 * `update_themes_github.com`, which also keeps wordpress.org from being asked
 * about this theme. Rules live in updater-rules.php, HTTP/cache/download in
 * updater-client.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'blueline_updater_boot' );
add_filter( 'site_status_tests', 'blueline_updater_register_health_test' );
/**
 * Register the updater's hooks.
 *
 * @return void
 */
function blueline_updater_boot(): void {
	add_filter( 'update_themes_github.com', 'blueline_updater_filter_update', 10, 4 );
	add_filter( 'upgrader_pre_download', 'blueline_updater_filter_pre_download', 10, 4 );
	add_action( 'upgrader_process_complete', 'blueline_updater_on_upgrade', 10, 2 );
	add_action( 'load-update-core.php', 'blueline_updater_on_force_check' );
}

/**
 * The update array WordPress expects, from a cached candidate.
 *
 * @param array<string, mixed> $candidate blueline_updater_state()['candidate'].
 * @return array<string, string>
 */
function blueline_updater_build_update( array $candidate ): array {
	$manifest = $candidate['manifest'];
	$update   = array(
		'theme'        => 'blueline',
		'version'      => $candidate['version'],
		'url'          => $candidate['release_url'],
		'package'      => $candidate['package'],
		'requires'     => $manifest['requires_wp'],
		'requires_php' => $manifest['requires_php'],
	);

	return array_filter(
		$update,
		static function ( $value ) {
			return '' !== $value;
		}
	);
}

/**
 * `update_themes_github.com`: offer the newest eligible release for Blueline.
 *
 * @param mixed                $update     Update offered so far.
 * @param array<string, mixed> $theme_data Theme headers.
 * @param string               $stylesheet Theme directory name.
 * @param string[]             $locales    Installed locales.
 * @return mixed
 */
function blueline_updater_filter_update( $update, $theme_data = array(), $stylesheet = '', $locales = array() ) {
	unset( $theme_data, $locales );

	if ( 'blueline' !== $stylesheet ) {
		return $update;
	}

	$candidate = blueline_updater_state()['candidate'] ?? null;

	return is_array( $candidate ) ? blueline_updater_build_update( $candidate ) : $update;
}

/**
 * `upgrader_pre_download`: fetch our own release asset (a configured token
 * must not reach WordPress's generic downloader) and verify its sha256.
 *
 * @param mixed  $reply      Short-circuit value so far.
 * @param string $package    Package URL.
 * @param mixed  $upgrader   WP_Upgrader instance.
 * @param mixed  $hook_extra Extra upgrade args.
 * @return mixed Temp file path or WP_Error for our packages, `$reply` otherwise.
 */
function blueline_updater_filter_pre_download( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
	unset( $upgrader, $hook_extra );

	if ( ! is_string( $package ) || ! blueline_updater_is_repo_api_url( $package ) ) {
		return $reply;
	}

	return blueline_updater_download( $package );
}

/**
 * `upgrader_process_complete`: drop the cached state after a theme update.
 *
 * @param mixed                $upgrader   WP_Upgrader instance.
 * @param array<string, mixed> $hook_extra What was upgraded.
 * @return void
 */
function blueline_updater_on_upgrade( $upgrader, $hook_extra = array() ): void {
	unset( $upgrader );

	if ( is_array( $hook_extra ) && 'theme' === ( $hook_extra['type'] ?? '' ) ) {
		blueline_updater_flush();
	}
}

/**
 * Dashboard -> Updates -> "Check again" (?force-check=1): refresh our cache too.
 *
 * @return void
 */
function blueline_updater_on_force_check(): void {
	// Read-only flag that only clears a cache; no nonce is needed.
	if ( isset( $_GET['force-check'] ) && current_user_can( 'update_themes' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		blueline_updater_flush();
	}
}

/**
 * Add the updater's Site Health test (Tools -> Site Health -> Status).
 *
 * @param array<string, mixed> $tests Registered tests.
 * @return array<string, mixed>
 */
function blueline_updater_register_health_test( $tests ) {
	$tests['direct']['blueline_updater']         = array(
		'label' => __( 'Blueline theme updates', 'blueline' ),
		'test'  => 'blueline_updater_run_health_test',
	);
	$tests['direct']['blueline_release_signing'] = array(
		'label' => __( 'Blueline release signing', 'blueline' ),
		'test'  => 'blueline_updater_run_signing_health_test',
	);

	return $tests;
}

/**
 * Run the release-signing test: configuration only, no network.
 *
 * @return array<string, mixed>
 */
function blueline_updater_run_signing_health_test(): array {
	return blueline_updater_signing_health_result( blueline_updater_public_keys(), function_exists( 'sodium_crypto_sign_verify_detached' ) );
}

/**
 * Whether theme updates are signature-checked, as a Site Health result. Pure.
 *
 * @param array<int, mixed> $keys             blueline_updater_public_keys().
 * @param bool              $crypto_available Whether ed25519 verification is available.
 * @return array<string, mixed>
 */
function blueline_updater_signing_health_result( array $keys, bool $crypto_available ): array {
	$usable = count( blueline_updater_decode_public_keys( $keys ) );
	$level  = 'good';
	$color  = 'blue';
	$label  = __( 'Blueline theme updates are signed and verified', 'blueline' );

	if ( array() === $keys ) {
		$level   = 'recommended';
		$color   = 'orange';
		$label   = __( 'Release signing is not enabled', 'blueline' );
		$message = __( 'Blueline theme updates are checked for integrity but not signed, so anyone able to publish a release in the theme\'s GitHub repository could ship an update that this site would offer. To turn signing on, follow "Release signing" in docs/RELEASING.md: generate a key, keep the private half in Bitwarden or Doppler and in GitHub Actions, and add the public key to BLUELINE_RELEASE_PUBLIC_KEYS in inc/updater-rules.php.', 'blueline' );
	} elseif ( ! $crypto_available ) {
		$level   = 'critical';
		$color   = 'red';
		$label   = __( 'Blueline cannot verify theme update signatures', 'blueline' );
		$message = __( 'This theme requires signed updates, but this server cannot check ed25519 signatures (neither the PHP sodium extension nor WordPress\'s sodium_compat is loaded), so no theme update will be offered. Ask your host to enable the sodium extension.', 'blueline' );
	} elseif ( 0 === $usable ) {
		$level   = 'critical';
		$color   = 'red';
		$label   = __( 'Blueline release signing keys are invalid', 'blueline' );
		$message = __( 'This theme requires signed updates, but none of the keys in BLUELINE_RELEASE_PUBLIC_KEYS is a valid ed25519 public key (44 characters of base64), so no theme update will be offered. Ask whoever maintains the theme releases to fix the key list.', 'blueline' );
	} elseif ( $usable < count( $keys ) ) {
		$level   = 'recommended';
		$color   = 'orange';
		$label   = __( 'A Blueline release signing key is invalid', 'blueline' );
		$message = sprintf(
			/* translators: 1: number of unusable keys, 2: number of configured keys. */
			__( '%1$d of the %2$d keys in BLUELINE_RELEASE_PUBLIC_KEYS is not a valid ed25519 public key and is ignored. Updates signed by the remaining keys are still verified and offered.', 'blueline' ),
			count( $keys ) - $usable,
			count( $keys )
		);
	} else {
		$message = sprintf(
			/* translators: %d: number of trusted release signing keys. */
			_n(
				'Theme updates are offered only when their manifest carries a valid signature by the %d trusted release key.',
				'Theme updates are offered only when their manifest carries a valid signature by one of the %d trusted release keys.',
				$usable,
				'blueline'
			),
			$usable
		);
	}

	return array(
		'label'       => $label,
		'status'      => $level,
		'badge'       => array(
			'label' => __( 'Security', 'blueline' ),
			'color' => $color,
		),
		'description' => '<p>' . esc_html( $message ) . '</p>',
		'actions'     => '',
		'test'        => 'blueline_release_signing',
	);
}

/**
 * The Site Health message for a release refused over its signature.
 *
 * @param string $reason `signature_missing`, `signature_invalid` or `signature_unsupported`.
 * @return string A sprintf template taking the release's version.
 */
function blueline_updater_signature_rejection_template( string $reason ): string {
	switch ( $reason ) {
		case 'signature_missing':
			/* translators: %s: version number of the release that is not offered. */
			return __( 'Blueline %s has been published without a release signature (manifest.json.sig), and this site only accepts signed updates, so it is not being offered. Do not install it by hand: ask whoever maintains the theme releases to confirm they published it and to re-run the release with the signing key.', 'blueline' );
		case 'signature_unsupported':
			/* translators: %s: version number of the release that is not offered. */
			return __( 'Blueline %s cannot be offered because this server cannot verify release signatures (no sodium extension and no sodium_compat). Ask your host to enable the PHP sodium extension.', 'blueline' );
		default:
			/* translators: %s: version number of the release that is not offered. */
			return __( 'The release signature of Blueline %s does not match any release key this theme trusts, so it is not being offered. The release may have been tampered with or signed with the wrong key. Do not install it by hand: tell whoever maintains the theme releases.', 'blueline' );
	}
}

/**
 * Run the test against the cached update state (one request only when the
 * cache is empty or stale).
 *
 * @return array<string, mixed>
 */
function blueline_updater_run_health_test(): array {
	return blueline_updater_health_result( blueline_updater_state() );
}

/**
 * Turn an update state into a Site Health result. Pure; the token is never in
 * the state, so it cannot appear here.
 *
 * @param array<string, mixed> $state blueline_updater_state().
 * @return array<string, mixed>
 */
function blueline_updater_health_result( array $state ): array {
	$status  = (string) ( $state['status'] ?? 'error' );
	$channel = (string) ( $state['channel'] ?? 'stable' );
	$latest  = is_array( $state['candidate'] ?? null ) ? (string) $state['candidate']['version'] : '';
	$reject  = is_array( $state['rejected'] ?? null ) ? $state['rejected'] : null;
	$color   = 'blue';
	$level   = 'good';

	switch ( $status ) {
		case 'unauthorized':
			$level   = 'critical';
			$color   = 'red';
			$message = __( 'GitHub refused the update request. If BLUELINE_GITHUB_TOKEN is set in wp-config.php it may be revoked, expired (fine-grained tokens expire) or lack access to the theme repository, so replace or remove it. Without a token, the repository may no longer be public.', 'blueline' );
			break;
		case 'rate_limited':
			$level   = 'recommended';
			$color   = 'orange';
			$message = __( 'GitHub is rate limiting update checks. Blueline will try again within the hour. Defining BLUELINE_GITHUB_TOKEN in wp-config.php raises the limit.', 'blueline' );
			break;
		case 'not_found':
			$level   = 'critical';
			$color   = 'red';
			$message = __( 'GitHub could not find the Blueline theme repository, so updates cannot be checked. It may have been renamed, deleted or made private. This is not a token problem; ask whoever maintains the theme releases. (A token that cannot see a private repository is reported this way too.)', 'blueline' );
			break;
		case 'ok':
			if ( null !== $reject && 0 === strpos( (string) ( $reject['reason'] ?? '' ), 'signature_' ) ) {
				$level   = 'critical';
				$color   = 'red';
				$message = sprintf( blueline_updater_signature_rejection_template( (string) $reject['reason'] ), (string) ( $reject['version'] ?? '' ) );
				if ( '' !== $latest ) {
					$message .= ' ' . sprintf(
						/* translators: %s: older version that is still available. */
						__( 'Blueline %s is correctly signed and still available from Dashboard > Updates.', 'blueline' ),
						$latest
					);
				}
				break;
			}
			if ( null !== $reject ) {
				$level = 'recommended';
				$color = 'orange';
				if ( 'manifest' === ( $reject['reason'] ?? '' ) ) {
					/* translators: %s: version number of the release that cannot be installed. */
					$template = __( 'Blueline %s has been published, but its update manifest is missing, unreadable or does not match the release, so it is not being offered. Ask whoever published it to fix the release files; sites will not receive it until then.', 'blueline' );
				} else {
					/* translators: %s: version number of the release that cannot be installed. */
					$template = __( 'Blueline %s has been published, but its theme zip or update manifest was not uploaded, so it is not being offered. Ask whoever published it to fix the release files; sites will not receive it until then.', 'blueline' );
				}
				$message = sprintf( $template, (string) ( $reject['version'] ?? '' ) );
				if ( '' !== $latest ) {
					$message .= ' ' . sprintf(
						/* translators: %s: older version that is still available. */
						__( 'Blueline %s is still available from Dashboard > Updates.', 'blueline' ),
						$latest
					);
				}
				break;
			}

			$message = '' === $latest
				? sprintf(
					/* translators: %s: update channel (stable or beta). */
					__( 'Blueline is up to date on the %s channel.', 'blueline' ),
					$channel
				)
				: sprintf(
					/* translators: 1: update channel (stable or beta), 2: latest available version. */
					__( 'Blueline %2$s is available on the %1$s channel. Update it from Dashboard > Updates.', 'blueline' ),
					$channel,
					$latest
				);
			break;
		default:
			$level   = 'recommended';
			$color   = 'orange';
			$message = __( 'The last check for Blueline updates failed (GitHub unreachable or answered unexpectedly). It will retry within the hour.', 'blueline' );
	}

	return array(
		'label'       => 'good' === $level ? __( 'Blueline theme updates are working', 'blueline' ) : __( 'Blueline theme updates need attention', 'blueline' ),
		'status'      => $level,
		'badge'       => array(
			'label' => __( 'Blueline updates', 'blueline' ),
			'color' => $color,
		),
		'description' => '<p>' . esc_html( $message ) . '</p>',
		'actions'     => '',
		'test'        => 'blueline_updater',
	);
}
