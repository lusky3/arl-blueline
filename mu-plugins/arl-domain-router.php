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
 *    points rel=canonical and og:url at the primary host, and sends admin, login, account, cart and checkout
 *    to the primary host (so payments, webhooks and logins live in one place). Its sitemaps redirect to the
 *    primary host's and its robots.txt names only the primary sitemap, so crawlers are never handed a list of
 *    URLs whose canonical is somewhere else. Legacy aliases (rookiehockey.com) 301 to the same path on the
 *    legacy canonical host.
 *  - an ALIAS host (old brand names, www variants): 301 to the same path on the primary host.
 *
 * On the primary and legacy hosts, /new (the old preview address of the new site) 301s to the primary home
 * page. /new/<path> 301s to <path> on the primary host when WordPress can tell that <path> exists; otherwise
 * it is a plain 404, without WordPress's "closest matching post" guess.
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
		'primary'                => 'arlhockey.ca',
		'primary_theme'          => array( 'blueline', 'blueline' ),
		'legacy_hosts'           => array( 'rookiehockey.ca', 'www.rookiehockey.ca', 'rookiehockey.com', 'www.rookiehockey.com' ),
		'legacy_canonical'       => 'www.rookiehockey.ca',
		// Legacy hosts that only forward: a 301 to the same path and query on legacy_canonical. They stay in
		// legacy_hosts so their URLs in stored content are still normalised.
		'legacy_aliases'         => array( 'rookiehockey.com', 'www.rookiehockey.com' ),
		// Set false to leave the legacy hosts completely untouched (they are still recognised for URL rewriting on
		// the primary face). Used to stage the primary face behind a preview before the old domains change.
		'legacy_enabled'         => true,
		'legacy_theme'           => array( 'rookie', 'rookie-child' ),
		'alias_hosts'            => array( 'www.arlhockey.ca', 'arlhockey.com', 'www.arlhockey.com', 'adultrecreationalleague.ca', 'www.adultrecreationalleague.ca', 'coedhockey.ca', 'www.coedhockey.ca', 'beginnerhockey.ca', 'www.beginnerhockey.ca' ),
		// Hosts that appear inside stored content or options and should be normalised too (staging uses one).
		'stored_hosts'           => array(),
		// Legacy requests under these paths are sent to the primary host. /wc-api is deliberately NOT here: payment
		// webhooks are registered at the old address and a redirect would turn their POST into a GET.
		'legacy_redirect'        => array( '/wp-admin', '/wp-login.php', '/account', '/checkout', '/cart' ),
		// ...except these (the legacy theme's front end still needs them).
		'legacy_redirect_ok'     => array( '/wp-admin/admin-ajax.php' ),
		// Options and page templates that differ ONLY on the primary face. They are global in the database, so
		// changing them there would change the legacy site too; here they are applied per request instead.
		'primary_options'        => array(
			'sportspress_team_template_order' => array( 'calendar', 'schedule', 'logo', 'excerpt', 'content', 'link', 'details', 'staff', 'lists', 'tables', 'events' ),
		),
		// Page slug => template file ('default' = no template). Top-level pages only.
		'primary_page_templates' => array(
			'home' => 'template-homepage.php',
			'faqs' => 'default',
		),
		// Links that cross between the two faces. They survive URL normalising because they are same-host paths:
		// <primary>/classic/<path> -> <legacy>/<path>, and <legacy>/new/<path> -> <primary>/<path>.
		'to_legacy_prefix'       => '/classic',
		'to_primary_prefix'      => '/new',
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
 * Redirect status that keeps the request method for non-GET requests (307/308 instead of 302/301).
 *
 * @param int    $status Base status (301 or 302).
 * @param string $method HTTP method.
 * @return int
 */
function arl_dr_redirect_status( int $status, string $method ): int {
	if ( in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
		return $status;
	}
	return 301 === $status ? 308 : 307;
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
		'new'     => arl_dr_strip_prefix( $uri, (string) $cfg['to_primary_prefix'] ),
	);
	// /new itself always means "the new site's home page", wherever it is asked for. A deeper /new/<path> is
	// decided later by arl_dr_new_path_rest(), once WordPress can say whether <path> exists.
	if ( null !== $rest['new'] && '/' === arl_dr_uri_path( $rest['new'] ) && in_array( $family, array( 'primary', 'legacy' ), true ) ) {
		return array( $primary . $rest['new'], 301 );
	}
	if ( 'primary' === $family && null !== $rest['primary'] ) {
		return array( 'https://' . arl_dr_normalise_host( (string) $cfg['legacy_canonical'] ) . $rest['primary'], 302 );
	}
	if ( 'legacy' === $family ) {
		if ( arl_dr_is_sitemap_path( arl_dr_uri_path( $uri ) ) ) {
			return array( $primary . $uri, 301 );
		}
		if ( arl_dr_path_goes_to_primary( arl_dr_uri_path( $uri ), $cfg ) ) {
			return array( $primary . $uri, 302 );
		}
		if ( arl_dr_host_in( $host, (array) ( $cfg['legacy_aliases'] ?? array() ) ) ) {
			return array( 'https://' . arl_dr_normalise_host( (string) $cfg['legacy_canonical'] ) . $uri, 301 );
		}
	}
	return null;
}

/**
 * Whether a host is one of a list of hosts.
 *
 * @param string            $host  Host header.
 * @param array<int, mixed> $hosts Hosts to compare with.
 * @return bool
 */
function arl_dr_host_in( string $host, array $hosts ): bool {
	return in_array( arl_dr_normalise_host( $host ), array_map( 'arl_dr_normalise_host', array_map( 'strval', $hosts ) ), true );
}

/**
 * Whether a path is a sitemap address: core's index, provider pages and stylesheets, or the common
 * sitemap.xml / sitemap_index.xml aliases.
 *
 * @param string $path Request path (no query).
 * @return bool
 */
function arl_dr_is_sitemap_path( string $path ): bool {
	return 1 === preg_match( '~^/(?:wp-sitemap(?:-[a-z0-9_-]+)?\.(?:xml|xsl)|sitemap(?:_index)?\.xml)$~i', $path );
}

/**
 * The rest of a /new/<path> request that must wait for WordPress (rewrite rules, posts, terms) to say
 * whether <path> exists on the primary host.
 *
 * @param string               $host Host header.
 * @param string               $uri  Request URI including any query string.
 * @param array<string, mixed> $cfg  Configuration.
 * @return string|null The URI after the prefix, query included, or null when this request is not one.
 */
function arl_dr_new_path_rest( string $host, string $uri, array $cfg ): ?string {
	$family = arl_dr_family( $host, $cfg );
	if ( 'primary' !== $family && 'legacy' !== $family ) {
		return null;
	}
	if ( null !== arl_dr_redirect_for( $host, $uri, $cfg ) ) {
		return null;
	}
	$rest = arl_dr_strip_prefix( $uri, (string) $cfg['to_primary_prefix'] );
	return ( null === $rest || '/' === arl_dr_uri_path( $rest ) ) ? null : $rest;
}

/**
 * Redirect for a deferred /new/<path> request: permanent when WordPress found <path>, none otherwise.
 *
 * @param string               $rest     URI after the /new prefix, query included.
 * @param bool                 $resolves Whether <path> exists (arl_dr_path_resolves()).
 * @param array<string, mixed> $cfg      Configuration.
 * @return array{0: string, 1: int}|null
 */
function arl_dr_new_path_redirect( string $rest, bool $resolves, array $cfg ): ?array {
	if ( ! $resolves ) {
		return null;
	}
	return array( 'https://' . arl_dr_normalise_host( (string) $cfg['primary'] ) . $rest, 301 );
}

/**
 * Whether WordPress has something at a path: the home page, a single post, page or other post type
 * (url_to_postid()), a published page that lists other posts (the posts page, the shop), or a public
 * taxonomy's term archive (/<taxonomy base>/<term slug>), each with or without a /page/N suffix. Both faces share
 * one database, so a path that exists here exists on the primary host too. Needs rewrite rules and
 * registered taxonomies, so call it on wp_loaded or later.
 *
 * @param string $path Request path (no query).
 * @return bool
 */
function arl_dr_path_resolves( string $path ): bool {
	// Page 2+ of a list exists when page 1 does (an empty page is WordPress's own 404 to give).
	$path = trim( (string) preg_replace( '~/page/\d+/?$~i', '', '/' . trim( $path, '/' ) ), '/' );
	if ( '' === $path ) {
		return true;
	}
	if ( function_exists( 'url_to_postid' ) && url_to_postid( home_url( '/' . $path ) ) > 0 ) {
		return true;
	}
	// The posts page and the shop page are pages that url_to_postid() reports as 0 (they list other posts).
	if ( function_exists( 'get_page_by_path' ) ) {
		$page = get_page_by_path( rawurldecode( $path ) );
		if ( is_object( $page ) && 'publish' === ( $page->post_status ?? '' ) ) {
			return true;
		}
	}
	$segments = explode( '/', $path );
	if ( 2 !== count( $segments ) || ! function_exists( 'get_taxonomies' ) || ! function_exists( 'term_exists' ) ) {
		return false;
	}
	foreach ( (array) get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
		$rewrite = is_object( $taxonomy ) && isset( $taxonomy->rewrite ) && is_array( $taxonomy->rewrite ) ? $taxonomy->rewrite : array();
		$base    = trim( (string) ( $rewrite['slug'] ?? '' ), '/' );
		if ( '' !== $base && strtolower( $base ) === strtolower( rawurldecode( $segments[0] ) ) && term_exists( rawurldecode( $segments[1] ), (string) $taxonomy->name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The legacy hosts' robots.txt: every rule as WordPress and plugins wrote it, but the only sitemap named is
 * the primary host's (core's own Sitemap line would name the legacy host). A site that asks not to be indexed
 * names no sitemap at all, as core does.
 *
 * @param string               $output    robots.txt body.
 * @param bool                 $is_public Whether the site is public (blog_public).
 * @param array<string, mixed> $cfg       Configuration.
 * @return string
 */
function arl_dr_legacy_robots_txt( string $output, bool $is_public, array $cfg ): string {
	$lines = preg_split( '/\r\n|\n|\r/', $output );
	$kept  = array();
	foreach ( is_array( $lines ) ? $lines : array() as $line ) {
		if ( 0 !== stripos( ltrim( $line ), 'sitemap:' ) ) {
			$kept[] = $line;
		}
	}
	$body = rtrim( implode( "\n", $kept ) ) . "\n";
	if ( ! $is_public ) {
		return $body;
	}
	return $body . "\nSitemap: https://" . arl_dr_normalise_host( (string) $cfg['primary'] ) . "/wp-sitemap.xml\n";
}

/**
 * Point an Open Graph og:url tag at a URL (the primary host's canonical), so a share from a legacy page
 * names the same address as its rel=canonical.
 *
 * @param string $body HTML.
 * @param string $url  URL to use.
 * @return string
 */
function arl_dr_point_og_url( string $body, string $url ): string {
	$out = preg_replace_callback(
		'~(<meta\s+property=(["\'])og:url\2\s+content=(["\']))[^"\']*(\3)~i',
		static function ( array $m ) use ( $url ): string {
			return $m[1] . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . $m[4];
		},
		$body
	);
	return is_string( $out ) ? $out : $body;
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
	if ( 'legacy' === arl_dr_family( $target_host, $cfg ) ) {
		$body = arl_dr_point_og_url( $body, $canonical );
	}
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
 * Template to force for a page on the primary face, by page slug.
 *
 * @param string               $slug Page slug.
 * @param array<string, mixed> $cfg  Configuration.
 * @return string|null Template file, 'default', or null for no override.
 */
function arl_dr_page_template_for( string $slug, array $cfg ): ?string {
	$map = (array) $cfg['primary_page_templates'];
	return isset( $map[ $slug ] ) ? (string) $map[ $slug ] : null;
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
 * Send a redirect and stop (never returns). Non-GET requests keep their method (arl_dr_redirect_status()).
 *
 * @param array{0: string, 1: int} $redirect Target URL and base status.
 * @return void
 */
function arl_dr_send_redirect( array $redirect ): void {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a fixed list in arl_dr_redirect_status().
	header( 'Location: ' . $redirect[0], true, arl_dr_redirect_status( $redirect[1], $method ) );
	exit;
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
	if ( 'other' === $family || ( 'legacy' === $family && empty( $cfg['legacy_enabled'] ) ) ) {
		return;
	}

	$redirect = arl_dr_redirect_for( $host, arl_dr_request_uri(), $cfg );
	if ( null !== $redirect ) {
		arl_dr_send_redirect( $redirect );
	}

	$new_rest = arl_dr_new_path_rest( $host, arl_dr_request_uri(), $cfg );
	if ( null !== $new_rest ) {
		add_action(
			'wp_loaded',
			static function () use ( $new_rest, $cfg ) {
				$redirect = arl_dr_new_path_redirect( $new_rest, arl_dr_path_resolves( arl_dr_uri_path( $new_rest ) ), $cfg );
				if ( null !== $redirect ) {
					arl_dr_send_redirect( $redirect );
				}
				// Nothing here: answer with a 404 rather than WordPress's guess at a similarly named post.
				add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
			}
		);
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
		foreach ( (array) $cfg['primary_options'] as $name => $value ) {
			add_filter(
				'pre_option_' . $name,
				static function () use ( $value ) {
					return $value;
				}
			);
		}
		add_filter(
			'get_post_metadata',
			static function ( $value, $post_id, $meta_key, $single ) use ( $cfg ) {
				if ( '_wp_page_template' !== $meta_key || 'page' !== get_post_type( (int) $post_id ) ) {
					return $value;
				}
				$post = get_post( (int) $post_id );
				if ( ! $post || 0 !== (int) $post->post_parent ) {
					return $value;
				}
				$template = arl_dr_page_template_for( (string) $post->post_name, $cfg );
				if ( null === $template ) {
					return $value;
				}
				return $single ? $template : array( $template );
			},
			10,
			4
		);

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
				// blueline-core's archive canonical would name this host; the tag below names the primary one.
				remove_action( 'wp_head', 'blueline_render_archive_canonical', 1 );
			}
		);
		// Sitemap requests are redirected to the primary host above; switching core's off here as well stops
		// it adding a legacy-host Sitemap line to robots.txt.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter(
			'robots_txt',
			static function ( $output, $is_public ) use ( $cfg ) {
				return arl_dr_legacy_robots_txt( (string) $output, (bool) $is_public, $cfg );
			},
			100,
			2
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
