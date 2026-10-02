<?php
/**
 * Theme updates from GitHub releases: the WordPress surface.
 *
 * Active only when BLUELINE_GITHUB_TOKEN is set in wp-config.php (and
 * BLUELINE_UPDATE_CHANNEL picks `stable` or `beta`). The style.css `Update URI`
 * header routes WordPress's update check to `update_themes_github.com`, which
 * also keeps wordpress.org from being asked about this theme. Rules live in
 * updater-rules.php, HTTP/cache/download in updater-client.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'blueline_updater_boot' );
// Registered even without a token: explaining why updates are off is the point.
add_filter( 'site_status_tests', 'blueline_updater_register_health_test' );
/**
 * Register the updater's hooks, or nothing at all without a token.
 *
 * @return void
 */
function blueline_updater_boot(): void {
	if ( '' === blueline_updater_token() ) {
		return;
	}

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
 * `upgrader_pre_download`: fetch our own release asset (the token must not
 * reach WordPress's generic downloader) and verify its sha256.
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
	$tests['direct']['blueline_updater'] = array(
		'label' => __( 'Blueline theme updates', 'blueline' ),
		'test'  => 'blueline_updater_run_health_test',
	);

	return $tests;
}

/**
 * Run the test against the cached update state (no network without a token).
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
	$color   = 'blue';
	$level   = 'good';

	switch ( $status ) {
		case 'no_token':
			$level   = 'recommended';
			$color   = 'orange';
			$message = __( 'Theme updates from GitHub are switched off. Add BLUELINE_GITHUB_TOKEN to wp-config.php to receive Blueline updates.', 'blueline' );
			break;
		case 'unauthorized':
			$level   = 'critical';
			$color   = 'red';
			$message = __( 'GitHub refused the update token. It may be missing, revoked, expired (fine-grained tokens expire) or lack access to the theme repository. Replace BLUELINE_GITHUB_TOKEN in wp-config.php.', 'blueline' );
			break;
		case 'rate_limited':
			$level   = 'recommended';
			$color   = 'orange';
			$message = __( 'GitHub is rate limiting update checks. Blueline will try again within the hour.', 'blueline' );
			break;
		case 'ok':
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
