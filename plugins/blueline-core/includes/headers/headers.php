<?php
/**
 * Response headers for front-end pages.
 *
 * Only headers that cannot break the site belong here. The site never asks for the camera, the
 * microphone or the visitor's location, so a Permissions-Policy that switches those off costs nothing
 * and stops an injected or third-party script from asking. Strict-Transport-Security is deliberately
 * NOT sent from PHP: it binds the whole domain for every browser that has seen it for a year, so it is
 * set where the domain is configured, at Cloudflare (see docs/OPERATIONS.md).
 *
 * The Content-Security-Policy (csp.php) is sent in report-only mode, which reports and blocks nothing,
 * and its reports are collected by csp-report.php.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/csp.php';
require_once __DIR__ . '/csp-report.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-blueline-core-csp-command.php';
}

/**
 * The headers to send, name => value, after the `blueline_core_security_headers` filter. Entries whose
 * name is not a header token or whose value is empty or spans lines are dropped, so a filter can never
 * split the response.
 *
 * @return array<string, string>
 */
function blueline_core_security_headers(): array {
	/**
	 * Filters the security headers sent with front-end pages.
	 *
	 * @param array<string, string> $headers Header name => value.
	 */
	$headers = apply_filters(
		'blueline_core_security_headers',
		array(
			'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
		)
	);

	if ( ! is_array( $headers ) ) {
		return array();
	}

	$clean = array();
	foreach ( $headers as $name => $value ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) || ! is_scalar( $value ) ) {
			continue;
		}
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/[\r\n]/', $value ) ) {
			continue;
		}
		$clean[ $name ] = $value;
	}

	return $clean;
}

/**
 * Emit header lines through a sender, each replacing any earlier header of the same name.
 *
 * @param array<string, string>        $headers Header name => value.
 * @param callable(string, bool): void $send    Sender, header() in production.
 * @return void
 */
function blueline_core_emit_headers( array $headers, callable $send ): void {
	foreach ( $headers as $name => $value ) {
		$send( $name . ': ' . $value, true );
	}
}

add_action( 'send_headers', 'blueline_core_send_security_headers' );
/**
 * Send the security headers with WordPress's own front-end headers.
 *
 * @return void
 */
function blueline_core_send_security_headers(): void {
	if ( headers_sent() ) {
		return;
	}

	blueline_core_emit_headers(
		blueline_core_security_headers(),
		static function ( string $line, bool $replace ): void {
			header( $line, $replace );
		}
	);
}
