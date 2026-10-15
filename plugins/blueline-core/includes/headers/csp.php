<?php
/**
 * Content-Security-Policy, sent in report-only mode.
 *
 * The starter policy is built from an inventory of what the site's pages really load (docs/OPERATIONS.md,
 * "Content Security Policy"): the site itself, OneSignal push notifications, the self-hosted Google Analytics
 * tag, Cloudflare's analytics and Turnstile, Google Maps embeds in page content, and, on cart, checkout and
 * account pages only, PayPal, Google Pay, Apple Pay and the Google Maps address lookup. Inline scripts and
 * styles stay allowed because WordPress core and several plugins print them; the reports collected by
 * csp-report.php show what else breaks the policy before anything is enforced.
 *
 * Filters: `blueline_core_csp_mode` ('report-only' by default, 'enforce' or 'off') and
 * `blueline_core_csp_policy` (the directive map, per page type).
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Reporting-Endpoints name the report-to directive points at.
 */
const BLUELINE_CORE_CSP_ENDPOINT_NAME = 'blueline-csp';

/**
 * The policy's modes.
 */
const BLUELINE_CORE_CSP_MODES = array( 'report-only', 'enforce', 'off' );

/**
 * The CSP mode after the `blueline_core_csp_mode` filter. Anything unrecognised means report-only, so a
 * typo can never switch enforcement on (or the reports off) by accident.
 *
 * @return string 'report-only', 'enforce' or 'off'.
 */
function blueline_core_csp_mode(): string {
	/**
	 * Filters the Content-Security-Policy mode.
	 *
	 * @param string $mode 'report-only' (default: violations are reported, nothing is blocked), 'enforce'
	 *                     (violations are blocked and reported) or 'off' (no policy is sent).
	 */
	$mode = apply_filters( 'blueline_core_csp_mode', 'report-only' );

	return in_array( $mode, BLUELINE_CORE_CSP_MODES, true ) ? $mode : 'report-only';
}

/**
 * The policy's directives for a page type, before the filter. See the inventory in docs/OPERATIONS.md for
 * which page loads what.
 *
 * @param bool $commerce Whether this is a cart, checkout or account page (the only pages that take payments).
 * @return array<string, string[]> Directive => sources.
 */
function blueline_core_csp_directives( bool $commerce ): array {
	$self = "'self'";

	$directives = array(
		'default-src'     => array( $self ),
		'base-uri'        => array( $self ),
		'object-src'      => array( "'none'" ),
		'frame-ancestors' => array( $self ),
		'form-action'     => array( $self ),
		// OneSignal's loader pulls its SDK from cdn.onesignal.com and a JSONP sync script from api.onesignal.com.
		'script-src'      => array( $self, "'unsafe-inline'", 'https://cdn.onesignal.com', 'https://api.onesignal.com', 'https://onesignal.com', 'https://static.cloudflareinsights.com', 'https://challenges.cloudflare.com' ),
		'style-src'       => array( $self, "'unsafe-inline'", 'https://onesignal.com' ),
		// Sponsor logos, map tiles and avatars come from many hosts; images cannot run code.
		'img-src'         => array( $self, 'data:', 'https:' ),
		'font-src'        => array( $self, 'data:' ),
		// The self-hosted gtag.js still reports to Google Analytics; Cloudflare's beacon reports to cloudflareinsights.com.
		'connect-src'     => array( $self, 'https://*.onesignal.com', 'https://onesignal.com', 'https://cloudflareinsights.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com' ),
		// Turnstile on forms, and Google Maps embeds in page content (Arena Maps).
		'frame-src'       => array( $self, 'https://challenges.cloudflare.com', 'https://maps.google.com', 'https://www.google.com' ),
		// WordPress's emoji loader runs its feature test in a blob: worker.
		'worker-src'      => array( $self, 'blob:' ),
	);

	if ( $commerce ) {
		// PayPal (all its hosts), Google Pay, Apple Pay, and the Google Places address lookup and jQuery UI
		// stylesheet the checkout field editor loads on checkout and account forms.
		$extra = array(
			'form-action' => array( 'https://*.paypal.com' ),
			'script-src'  => array( 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://pay.google.com', 'https://applepay.cdn-apple.com', 'https://maps.googleapis.com' ),
			'style-src'   => array( 'https://ajax.googleapis.com' ),
			'connect-src' => array( 'https://*.paypal.com', 'https://pay.google.com', 'https://google.com/pay', 'https://maps.googleapis.com', 'https://places.googleapis.com' ),
			'frame-src'   => array( 'https://*.paypal.com', 'https://pay.google.com' ),
		);

		foreach ( $extra as $directive => $sources ) {
			$directives[ $directive ] = array_merge( $directives[ $directive ], $sources );
		}
	}

	return $directives;
}

/**
 * The policy as a header value: the directives plus the two report directives (report-uri for browsers
 * without the Reporting API, report-to for the rest), after the `blueline_core_csp_policy` filter. A
 * directive name that is not a lowercase token, or a source with whitespace, a semicolon or a comma, is
 * dropped, so a filter can never inject a directive or split the header.
 *
 * @param bool   $commerce   Whether this is a cart, checkout or account page.
 * @param string $report_url Absolute URL of the report endpoint.
 * @return string The policy, or '' when the filter returned no directives.
 */
function blueline_core_csp_policy( bool $commerce, string $report_url ): string {
	$directives = blueline_core_csp_directives( $commerce );

	if ( '' !== $report_url ) {
		$directives['report-uri'] = array( $report_url );
		$directives['report-to']  = array( BLUELINE_CORE_CSP_ENDPOINT_NAME );
	}

	/**
	 * Filters the Content-Security-Policy directives.
	 *
	 * @param array<string, string[]> $directives Directive => sources (report-uri and report-to included).
	 * @param array{commerce: bool}   $context    `commerce`: a cart, checkout or account page.
	 */
	$directives = apply_filters( 'blueline_core_csp_policy', $directives, array( 'commerce' => $commerce ) );

	if ( ! is_array( $directives ) ) {
		return '';
	}

	$parts = array();
	foreach ( $directives as $name => $sources ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) || ! is_array( $sources ) ) {
			continue;
		}

		$clean = array();
		foreach ( $sources as $source ) {
			if ( is_string( $source ) && preg_match( '/^[^\s;,]+$/', $source ) ) {
				$clean[] = $source;
			}
		}

		$parts[] = trim( $name . ' ' . implode( ' ', array_unique( $clean ) ) );
	}

	return implode( '; ', $parts );
}

/**
 * The absolute URL of the report endpoint (csp-report.php).
 *
 * @return string
 */
function blueline_core_csp_report_url(): string {
	return (string) rest_url( 'blueline/v1/csp-report' );
}

/**
 * The request flags that mean "not a front-end HTML page". The policy is only sent when none is set.
 *
 * @return string[]
 */
function blueline_core_csp_skip_flags(): array {
	return array( 'admin', 'ajax', 'cron', 'rest', 'feed', 'robots', 'favicon', 'trackback', 'embed', 'sitemap', 'wc_ajax' );
}

/**
 * What the current request is, for the policy: the skip flags (blueline_core_csp_skip_flags()) and
 * `commerce` (cart, checkout or any account page, or every page when the theme's
 * `blueline_keep_payment_scripts` filter keeps the payment scripts everywhere). oEmbed embeds are skipped
 * because other sites frame them, which frame-ancestors would report (or, enforced, block).
 *
 * @return array<string, bool>
 */
function blueline_core_csp_request_flags(): array {
	$flags = array(
		'admin'     => is_admin(),
		'ajax'      => wp_doing_ajax(),
		'cron'      => wp_doing_cron(),
		'rest'      => function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request(),
		'feed'      => is_feed(),
		'robots'    => is_robots(),
		'favicon'   => function_exists( 'is_favicon' ) && is_favicon(),
		'trackback' => is_trackback(),
		'embed'     => is_embed(),
		'sitemap'   => '' !== (string) get_query_var( 'sitemap', '' ),
		'wc_ajax'   => '' !== (string) get_query_var( 'wc-ajax', '' ),
	);

	$flags['commerce'] = ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() )
		|| (bool) apply_filters( 'blueline_keep_payment_scripts', false );

	return array_map( 'boolval', $flags );
}

/**
 * The CSP headers for a request. Pure: the mode, flags and URL are passed in.
 *
 * @param string              $mode       blueline_core_csp_mode().
 * @param array<string, bool> $flags      blueline_core_csp_request_flags().
 * @param string              $report_url Report endpoint URL.
 * @return array<string, string> Header name => value; empty when nothing is to be sent.
 */
function blueline_core_csp_headers_for( string $mode, array $flags, string $report_url ): array {
	if ( 'off' === $mode ) {
		return array();
	}

	foreach ( blueline_core_csp_skip_flags() as $flag ) {
		if ( ! empty( $flags[ $flag ] ) ) {
			return array();
		}
	}

	$policy = blueline_core_csp_policy( ! empty( $flags['commerce'] ), $report_url );
	if ( '' === $policy ) {
		return array();
	}

	$headers = array(
		( 'enforce' === $mode ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only' ) => $policy,
	);

	if ( '' !== $report_url && ! preg_match( '/["\s]/', $report_url ) ) {
		$headers['Reporting-Endpoints'] = BLUELINE_CORE_CSP_ENDPOINT_NAME . '="' . $report_url . '"';
	}

	return $headers;
}

/**
 * Work out and emit the CSP headers for the current request.
 *
 * Neither header replaces an earlier one of the same name: WooCommerce sends its own
 * `Content-Security-Policy: frame-ancestors 'self'` on account pages, and a browser applies every policy
 * it receives, so ours is added beside it. Likewise another plugin's Reporting-Endpoints entry survives.
 *
 * @param callable(string, bool): void $send Sender, header() in production.
 * @return void
 */
function blueline_core_emit_csp_headers( callable $send ): void {
	$headers = blueline_core_csp_headers_for( blueline_core_csp_mode(), blueline_core_csp_request_flags(), blueline_core_csp_report_url() );

	foreach ( $headers as $name => $value ) {
		$send( $name . ': ' . $value, false );
	}
}

// template_redirect, not send_headers: the page-type conditionals (is_checkout(), is_feed()) need the main
// query, which WordPress runs after send_headers. Priority 0 so pages that other callbacks render and exit
// on later (at the default priority) still carry it.
add_action( 'template_redirect', 'blueline_core_send_csp_headers', 0 );
/**
 * Send the CSP headers with a front-end page.
 *
 * @return void
 */
function blueline_core_send_csp_headers(): void {
	if ( headers_sent() ) {
		return;
	}

	blueline_core_emit_csp_headers(
		static function ( string $line, bool $replace ): void {
			header( $line, $replace );
		}
	);
}
