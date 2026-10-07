<?php
/**
 * Plugin Name: ARL Domain Router
 * Description: One WordPress install, two faces. The primary domain (arlhockey.ca) runs the Blueline theme; the legacy domains (rookiehockey.ca / .com) keep the classic Rookie theme. Alias domains redirect to the primary.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * Must-use plugin: copy this single file into wp-content/mu-plugins/. It is inert for any host it does not
 * know (staging, WP-CLI, cron), so installing it changes nothing until a request arrives on a configured host.
 *
 * What it does for a request on:
 *  - a PRIMARY host: serves the primary theme, builds URLs on that host, rewrites legacy-host URLs found in
 *    stored content to the primary host, and silences the legacy-only Simple CSS plugin output.
 *  - a LEGACY host: serves the legacy theme, builds URLs on that host, rewrites primary-host URLs back to it,
 *    points rel=canonical at the primary host, and sends admin, login, account, cart and checkout to the
 *    primary host (so payments, webhooks and logins live in one place).
 *  - an ALIAS host (old brand names, www variants): 301 to the same path on the primary host.
 *
 * Override anything with define( 'ARL_DOMAIN_ROUTER', array( ... ) ) or the 'arl_domain_router_config' filter.
 *
 * @package arl-domain-router
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default configuration.
 *
 * @return array<string, mixed>
 */
function arl_dr_defaults(): array {
	return array(
		'primary'            => 'arlhockey.ca',
		'primary_theme'      => array( 'blueline', 'blueline' ),
		'legacy_hosts'       => array( 'rookiehockey.ca', 'www.rookiehockey.ca', 'rookiehockey.com', 'www.rookiehockey.com' ),
		'legacy_canonical'   => 'www.rookiehockey.ca',
		'legacy_theme'       => array( 'rookie', 'rookie-child' ),
		'alias_hosts'        => array( 'www.arlhockey.ca', 'arlhockey.com', 'www.arlhockey.com', 'adultrecreationalleague.ca', 'www.adultrecreationalleague.ca', 'coedhockey.ca', 'www.coedhockey.ca' ),
		// Hosts that appear inside stored content or options and should be normalised too (staging uses one).
		'stored_hosts'       => array(),
		// Legacy requests under these paths are sent to the primary host.
		'legacy_redirect'    => array( '/wp-admin', '/wp-login.php', '/account', '/checkout', '/cart', '/wc-api' ),
		// ...except these (the legacy theme's front end still needs them).
		'legacy_redirect_ok' => array( '/wp-admin/admin-ajax.php' ),
		// Links that cross between the two faces. They survive URL normalising because they are same-host paths:
		// <primary>/classic/<path> -> <legacy>/<path>, and <legacy>/new/<path> -> <primary>/<path>.
		'to_legacy_prefix'   => '/classic',
		'to_primary_prefix'  => '/new',
	);
}

/**
 * Effective configuration.
 *
 * @return array<string, mixed>
 */
function arl_dr_config(): array {
	$cfg = arl_dr_defaults();
	if ( defined( 'ARL_DOMAIN_ROUTER' ) && is_array( ARL_DOMAIN_ROUTER ) ) {
		$cfg = array_merge( $cfg, ARL_DOMAIN_ROUTER );
	}
	return (array) apply_filters( 'arl_domain_router_config', $cfg );
}

/**
 * Lower-case a host and drop any port and trailing dot.
 *
 * @param string $host Raw host header.
 * @return string
 */
function arl_dr_normalise_host( string $host ): string {
	$host = strtolower( trim( $host ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host );
	return rtrim( $host, '.' );
}

/**
 * Which face a host belongs to.
 *
 * @param string               $host Host header.
 * @param array<string, mixed> $cfg  Configuration.
 * @return string 'primary', 'legacy', 'alias' or 'other'.
 */
function arl_dr_family( string $host, array $cfg ): string {
	$host = arl_dr_normalise_host( $host );
	if ( '' === $host ) {
		return 'other';
	}
	if ( arl_dr_normalise_host( (string) $cfg['primary'] ) === $host ) {
		return 'primary';
	}
	if ( in_array( $host, array_map( 'arl_dr_normalise_host', (array) $cfg['legacy_hosts'] ), true ) ) {
		return 'legacy';
	}
	if ( in_array( $host, array_map( 'arl_dr_normalise_host', (array) $cfg['alias_hosts'] ), true ) ) {
		return 'alias';
	}
	return 'other';
}

/**
 * Theme pair (template, stylesheet) for a family, or null to leave WordPress alone.
 *
 * @param string               $family Family from arl_dr_family().
 * @param array<string, mixed> $cfg    Configuration.
 * @return array{0: string, 1: string}|null
 */
function arl_dr_theme_for( string $family, array $cfg ): ?array {
	if ( 'primary' === $family ) {
		return array( (string) $cfg['primary_theme'][0], (string) $cfg['primary_theme'][1] );
	}
	if ( 'legacy' === $family ) {
		return array( (string) $cfg['legacy_theme'][0], (string) $cfg['legacy_theme'][1] );
	}
	return null;
}

/**
 * Whether a legacy-host path must move to the primary host.
 *
 * @param string               $path Request path (no query).
 * @param array<string, mixed> $cfg  Configuration.
 * @return bool
 */
function arl_dr_path_goes_to_primary( string $path, array $cfg ): bool {
	$path = '/' . ltrim( $path, '/' );
	foreach ( (array) $cfg['legacy_redirect_ok'] as $ok ) {
		if ( strtolower( $path ) === strtolower( (string) $ok ) ) {
			return false;
		}
	}
	foreach ( (array) $cfg['legacy_redirect'] as $prefix ) {
		$prefix = '/' . trim( (string) $prefix, '/' );
		$lower  = strtolower( $path );
		if ( strtolower( $prefix ) === $lower || 0 === strpos( $lower, strtolower( $prefix ) . '/' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Redirect decision for a request.
 *
 * @param string               $host Host header.
 * @param string               $uri  Request URI including any query string.
 * @param array<string, mixed> $cfg  Configuration.
 * @return array{0: string, 1: int}|null Target URL and status, or null for no redirect.
 */
function arl_dr_redirect_for( string $host, string $uri, array $cfg ): ?array {
	$family  = arl_dr_family( $host, $cfg );
	$uri     = '' === $uri ? '/' : $uri;
	$primary = 'https://' . arl_dr_normalise_host( (string) $cfg['primary'] );

	if ( 'alias' === $family ) {
		return array( $primary . $uri, 301 );
	}
	$rest = array(
		'primary' => arl_dr_strip_prefix( $uri, (string) $cfg['to_legacy_prefix'] ),
		'legacy'  => arl_dr_strip_prefix( $uri, (string) $cfg['to_primary_prefix'] ),
	);
	if ( 'primary' === $family && null !== $rest['primary'] ) {
		return array( 'https://' . arl_dr_normalise_host( (string) $cfg['legacy_canonical'] ) . $rest['primary'], 302 );
	}
	if ( 'legacy' === $family ) {
		if ( null !== $rest['legacy'] ) {
			return array( $primary . $rest['legacy'], 302 );
		}
		if ( arl_dr_path_goes_to_primary( arl_dr_uri_path( $uri ), $cfg ) ) {
			return array( $primary . $uri, 302 );
		}
	}
	return null;
}

/**
 * Remove a leading path prefix from a URI, keeping the rest and the query string.
 *
 * @param string $uri    Request URI.
 * @param string $prefix Path prefix such as '/classic'.
 * @return string|null The remaining URI (always starting with '/'), or null when the prefix does not match.
 */
function arl_dr_strip_prefix( string $uri, string $prefix ): ?string {
	$prefix = '/' . trim( $prefix, '/' );
	$path   = arl_dr_uri_path( $uri );
	$lower  = strtolower( $path );
	if ( strtolower( $prefix ) !== $lower && 0 !== strpos( $lower, strtolower( $prefix ) . '/' ) ) {
		return null;
	}
	$rest  = substr( $path, strlen( $prefix ) );
	$rest  = '' === $rest ? '/' : $rest;
	$query = (string) strstr( $uri, '?' );
	return $rest . $query;
}

/**
 * Path part of a URI (no WordPress dependency, so tests and early hooks can use it).
 *
 * @param string $uri Request URI.
 * @return string
 */
function arl_dr_uri_path( string $uri ): string {
	$parts = parse_url( $uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- must run before WordPress is fully loaded.
	return is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
}

/**
 * Every host (without a leading www.) whose URLs should be normalised in output.
 *
 * @param array<string, mixed> $cfg Configuration.
 * @return array<int, string>
 */
function arl_dr_known_bases( array $cfg ): array {
	$all   = array_merge(
		array( $cfg['primary'] ),
		(array) $cfg['legacy_hosts'],
		(array) $cfg['stored_hosts']
	);
	$bases = array();
	foreach ( $all as $host ) {
		$host = arl_dr_normalise_host( (string) $host );
		$host = (string) preg_replace( '/^www\./', '', $host );
		if ( '' !== $host ) {
			$bases[ $host ] = $host;
		}
	}
	return array_values( $bases );
}

/**
 * Point every known-host URL in a body at one host. Only URLs (after // or \/\/) are touched, so e-mail
 * addresses and sub-domains such as r2.rookiehockey.ca are left alone.
 *
 * @param string               $html        Response body.
 * @param string               $target_host Host to point URLs at.
 * @param array<string, mixed> $cfg         Configuration.
 * @return string
 */
function arl_dr_rewrite_urls( string $html, string $target_host, array $cfg ): string {
	$bases = array_map(
		static function ( string $base ): string {
			return preg_quote( $base, '~' );
		},
		arl_dr_known_bases( $cfg )
	);
	if ( array() === $bases ) {
		return $html;
	}
	$pattern = '~((?:https?:)?(?:\\\\?/){2})(?:www\.)?(?:' . implode( '|', $bases ) . ')(?![A-Za-z0-9\-]|\.[A-Za-z0-9])~i';
	$target  = arl_dr_normalise_host( $target_host );
	$out     = preg_replace_callback(
		$pattern,
		static function ( array $m ) use ( $target ): string {
			return preg_replace( '/^http:/i', 'https:', $m[1] ) . $target;
		},
		$html
	);
	return is_string( $out ) ? $out : $html;
}

/**
 * Placeholder written into the canonical tag and swapped for the real URL after URL rewriting, so the
 * rewriter cannot point the canonical back at the host being served.
 */
const ARL_DR_CANONICAL_TOKEN = '__ARL_DR_CANONICAL__';

/**
 * Final pass over a response body: normalise URLs, then fill in the canonical placeholder.
 *
 * @param string               $body        Response body.
 * @param string               $target_host Host being served.
 * @param string               $canonical   Canonical URL for this request.
 * @param array<string, mixed> $cfg         Configuration.
 * @return string
 */
function arl_dr_process_body( string $body, string $target_host, string $canonical, array $cfg ): string {
	$body = arl_dr_rewrite_urls( $body, $target_host, $cfg );
	return str_replace( ARL_DR_CANONICAL_TOKEN, $canonical, $body );
}

/**
 * Canonical URL for a request: always the primary host.
 *
 * @param string               $uri Request URI.
 * @param array<string, mixed> $cfg Configuration.
 * @return string
 */
function arl_dr_canonical_url( string $uri, array $cfg ): string {
	return 'https://' . arl_dr_normalise_host( (string) $cfg['primary'] ) . ( '' === $uri ? '/' : $uri );
}

/**
 * The (sanitised) host header of the current request, '' when there is none (WP-CLI, cron).
 *
 * @return string
 */
function arl_dr_request_host(): string {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to host characters below.
	return arl_dr_normalise_host( (string) preg_replace( '/[^A-Za-z0-9.\-:]/', '', $host ) );
}

/**
 * The request URI, reduced to URL-safe characters.
 *
 * @return string
 */
function arl_dr_request_uri(): string {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to URL characters below.
	return (string) preg_replace( '/[^\x21-\x7E]/', '', $uri );
}

/**
 * Wire everything for the current request. Runs once, after every mu-plugin has loaded so a config file
 * in any mu-plugin can still add the 'arl_domain_router_config' filter.
 *
 * @return void
 */
function arl_dr_boot(): void {
	$host = arl_dr_request_host();
	if ( '' === $host ) {
		return;
	}
	$cfg    = arl_dr_config();
	$family = arl_dr_family( $host, $cfg );
	if ( 'other' === $family ) {
		return;
	}

	$redirect = arl_dr_redirect_for( $host, arl_dr_request_uri(), $cfg );
	if ( null !== $redirect ) {
		header( 'Location: ' . $redirect[0], true, $redirect[1] );
		exit;
	}

	$theme = arl_dr_theme_for( $family, $cfg );
	if ( null !== $theme ) {
		add_filter(
			'pre_option_template',
			static function () use ( $theme ) {
				return $theme[0];
			}
		);
		add_filter(
			'pre_option_stylesheet',
			static function () use ( $theme ) {
				return $theme[1];
			}
		);
	}
	foreach ( array( 'pre_option_home', 'pre_option_siteurl' ) as $hook ) {
		add_filter(
			$hook,
			static function () use ( $host ) {
				return 'https://' . $host;
			}
		);
	}

	if ( 'primary' === $family ) {
		// The legacy-only Simple CSS plugin was written for the Rookie theme and would fight Blueline.
		add_filter(
			'option_simple_css',
			static function ( $value ) {
				if ( is_array( $value ) ) {
					$value['css'] = '';
				}
				return $value;
			}
		);
	}

	add_action(
		'template_redirect',
		static function () use ( $host, $cfg, $family ) {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			$canonical = arl_dr_canonical_url( arl_dr_request_uri(), $cfg );
			ob_start(
				static function ( string $body ) use ( $host, $cfg, $canonical ): string {
					foreach ( headers_list() as $header ) {
						if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) && false === stripos( $header, 'xml' ) ) {
							return $body;
						}
					}
					return arl_dr_process_body( $body, $host, $canonical, $cfg );
				}
			);
		},
		0
	);

	if ( 'legacy' === $family ) {
		add_action(
			'init',
			static function () {
				remove_action( 'wp_head', 'rel_canonical' );
			}
		);
		add_action(
			'wp_head',
			static function () {
				echo '<link rel="canonical" href="' . esc_attr( ARL_DR_CANONICAL_TOKEN ) . '" />' . "\n";
			},
			1
		);
	}
}
add_action( 'muplugins_loaded', 'arl_dr_boot', PHP_INT_MAX );
