<?php
/**
 * Collects Content-Security-Policy violation reports: `POST /wp-json/blueline/v1/csp-report`.
 *
 * Browsers post reports without a nonce (and usually without cookies), so the route is public. What keeps
 * it cheap and harmless: only the three report content types are accepted, the body is size-capped, each
 * visitor is rate-limited, and every report is validated and reduced before anything is stored.
 *
 * What is stored: one non-autoloaded option of counts per (directive, blocked origin or keyword, page path),
 * capped at BLUELINE_CORE_CSP_REPORT_MAX_ENTRIES, least-seen evicted first. Never a full URL, a query
 * string, an IP address or a user agent. The rate limiter keys a short-lived transient on a keyed hash of
 * address + user agent + today's date, so the key cannot be reversed or linked across days.
 *
 * Read the result with `wp blueline-core csp report`, or the top five in Tools > Site Health.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_CORE_CSP_REPORT_OPTION = 'blueline_core_csp_violations';

// A Reporting API batch carries the whole policy in every report (about 1.5 KB on checkout), so the cap
// leaves room for a handful of reports per request while still refusing anything bulky.
const BLUELINE_CORE_CSP_REPORT_MAX_BYTES     = 16384;
const BLUELINE_CORE_CSP_REPORT_MAX_PER_BATCH = 20;
const BLUELINE_CORE_CSP_REPORT_MAX_ENTRIES   = 200;
const BLUELINE_CORE_CSP_REPORT_RATE_LIMIT    = 30;
const BLUELINE_CORE_CSP_REPORT_RATE_WINDOW   = 600;

/**
 * The directives a report may name. Anything else is not a CSP report.
 */
const BLUELINE_CORE_CSP_REPORT_DIRECTIVES = array(
	'default-src',
	'script-src',
	'script-src-elem',
	'script-src-attr',
	'style-src',
	'style-src-elem',
	'style-src-attr',
	'img-src',
	'font-src',
	'connect-src',
	'media-src',
	'object-src',
	'frame-src',
	'child-src',
	'worker-src',
	'manifest-src',
	'prefetch-src',
	'fenced-frame-src',
	'form-action',
	'frame-ancestors',
	'base-uri',
	'navigate-to',
	'require-trusted-types-for',
	'trusted-types',
	'webrtc',
);

add_action( 'rest_api_init', 'blueline_core_csp_register_report_route' );
/**
 * Register the public report route.
 *
 * @return void
 */
function blueline_core_csp_register_report_route(): void {
	register_rest_route(
		'blueline/v1',
		'/csp-report',
		array(
			'methods'             => 'POST',
			'callback'            => 'blueline_core_csp_report_handle',
			// Public by design: browsers send reports without a nonce. Abuse is limited in the handler.
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handle one report request: content type, size, rate limit, then validation. 204 when at least one
 * report was counted.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function blueline_core_csp_report_handle( $request ) {
	$type = strtolower( trim( explode( ';', (string) $request->get_header( 'content_type' ) )[0] ) );
	if ( ! in_array( $type, array( 'application/csp-report', 'application/reports+json', 'application/json' ), true ) ) {
		return new WP_Error( 'blueline_csp_unsupported_type', __( 'Unsupported content type.', 'blueline-core' ), array( 'status' => 415 ) );
	}

	$body = (string) $request->get_body();
	if ( strlen( $body ) > BLUELINE_CORE_CSP_REPORT_MAX_BYTES || (int) $request->get_header( 'content_length' ) > BLUELINE_CORE_CSP_REPORT_MAX_BYTES ) {
		return new WP_Error( 'blueline_csp_too_large', __( 'Report too large.', 'blueline-core' ), array( 'status' => 413 ) );
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! blueline_core_csp_report_allow_visitor( $ip, (string) $request->get_header( 'user_agent' ) ) ) {
		return new WP_Error( 'blueline_csp_rate_limited', __( 'Too many reports.', 'blueline-core' ), array( 'status' => 429 ) );
	}

	$reports = blueline_core_csp_parse_reports( $body, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( ! $reports ) {
		return new WP_Error( 'blueline_csp_invalid_report', __( 'No valid CSP report.', 'blueline-core' ), array( 'status' => 400 ) );
	}

	blueline_core_csp_record( $reports );

	return new WP_REST_Response( null, 204 );
}

/**
 * Count one request against the visitor's allowance.
 *
 * @param string $ip         Remote address (never stored).
 * @param string $user_agent User agent (never stored).
 * @return bool False once the visitor has sent BLUELINE_CORE_CSP_REPORT_RATE_LIMIT requests in the window.
 */
function blueline_core_csp_report_allow_visitor( string $ip, string $user_agent ): bool {
	$key   = 'bl_csp_rl_' . substr( blueline_core_csp_visitor_key( $ip, $user_agent, gmdate( 'Y-m-d' ) ), 0, 24 );
	$count = (int) get_transient( $key );

	if ( $count >= BLUELINE_CORE_CSP_REPORT_RATE_LIMIT ) {
		return false;
	}

	set_transient( $key, $count + 1, BLUELINE_CORE_CSP_REPORT_RATE_WINDOW );

	return true;
}

/**
 * A visitor's rate-limit key: a hash keyed with the site's secret salt, over the address, user agent and
 * day, so it identifies the same browser for a day and cannot be reversed or linked to the next day.
 *
 * @param string $ip         Remote address.
 * @param string $user_agent User agent.
 * @param string $day        Y-m-d (UTC).
 * @return string
 */
function blueline_core_csp_visitor_key( string $ip, string $user_agent, string $day ): string {
	return wp_hash( 'blueline-csp|' . $day . '|' . $ip . '|' . $user_agent );
}

/**
 * The valid reports in a request body, reduced to what is stored.
 *
 * Accepts the report-uri shape (`{"csp-report": {...}}`) and the Reporting API shape (an array of
 * `{"type": "csp-violation", "body": {...}}`, other report types ignored), at most
 * BLUELINE_CORE_CSP_REPORT_MAX_PER_BATCH per request.
 *
 * @param string $body      Raw JSON body.
 * @param string $site_host This site's host name.
 * @return array<int, array{directive: string, blocked: string, path: string}>
 */
function blueline_core_csp_parse_reports( string $body, string $site_host ): array {
	$data = json_decode( $body, true, 8 );
	if ( ! is_array( $data ) ) {
		return array();
	}

	$items = array_is_list( $data ) ? $data : array( $data );
	$raw   = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		if ( isset( $item['csp-report'] ) && is_array( $item['csp-report'] ) ) {
			$raw[] = $item['csp-report'];
		} elseif ( 'csp-violation' === ( $item['type'] ?? '' ) && isset( $item['body'] ) && is_array( $item['body'] ) ) {
			$raw[] = $item['body'] + array( 'documentURL' => $item['url'] ?? '' );
		}
	}

	$reports = array();
	foreach ( array_slice( $raw, 0, BLUELINE_CORE_CSP_REPORT_MAX_PER_BATCH ) as $report ) {
		$normalized = blueline_core_csp_normalize_report( $report, $site_host );
		if ( null !== $normalized ) {
			$reports[] = $normalized;
		}
	}

	return $reports;
}

/**
 * One report reduced to (directive, blocked label, page path), or null when it does not validate.
 *
 * @param array<string, mixed> $report    A report body in either naming (kebab-case or camelCase).
 * @param string               $site_host This site's host name.
 * @return array{directive: string, blocked: string, path: string}|null
 */
function blueline_core_csp_normalize_report( array $report, string $site_host ): ?array {
	$field = static function ( string ...$names ) use ( $report ) {
		foreach ( $names as $name ) {
			if ( isset( $report[ $name ] ) && is_string( $report[ $name ] ) && '' !== $report[ $name ] ) {
				return $report[ $name ];
			}
		}
		return '';
	};

	$directive = $field( 'effective-directive', 'effectiveDirective' );
	if ( '' === $directive ) {
		// Older browsers only send the violated directive, with its sources: keep its name.
		$directive = strtok( $field( 'violated-directive', 'violatedDirective' ), ' ' );
	}

	if ( ! in_array( $directive, BLUELINE_CORE_CSP_REPORT_DIRECTIVES, true ) ) {
		return null;
	}

	$blocked = blueline_core_csp_blocked_label( $field( 'blocked-uri', 'blockedURL' ) );
	$path    = blueline_core_csp_document_path( $field( 'document-uri', 'documentURL' ), $site_host );

	if ( '' === $blocked || '' === $path ) {
		return null;
	}

	return array(
		'directive' => $directive,
		'blocked'   => $blocked,
		'path'      => $path,
	);
}

/**
 * What a report says was blocked, as stored: a keyword for inline code, eval and data/blob URLs, the
 * origin (scheme, host, non-default port) for a URL, the scheme alone for other schemes (browser
 * extensions). Never a path or query string.
 *
 * @param string $blocked Reported blocked-uri / blockedURL.
 * @return string The label, or '' when it is not a valid value.
 */
function blueline_core_csp_blocked_label( string $blocked ): string {
	$blocked = strtolower( trim( $blocked ) );

	$keywords = array(
		''                     => 'inline',
		'inline'               => 'inline',
		'eval'                 => 'eval',
		'wasm-eval'            => 'eval',
		'data'                 => 'data',
		'blob'                 => 'blob',
		'self'                 => 'self',
		'trusted-types-policy' => 'trusted-types-policy',
		'trusted-types-sink'   => 'trusted-types-sink',
	);
	if ( isset( $keywords[ $blocked ] ) ) {
		return $keywords[ $blocked ];
	}

	if ( ! preg_match( '#^([a-z][a-z0-9+.-]{0,30}):#', $blocked, $match ) ) {
		return '';
	}

	$scheme = $match[1];
	if ( in_array( $scheme, array( 'data', 'blob' ), true ) ) {
		return $scheme;
	}

	if ( ! in_array( $scheme, array( 'http', 'https', 'ws', 'wss' ), true ) ) {
		return $scheme;
	}

	$parts = wp_parse_url( $blocked );
	$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
	if ( '' === $host || strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
		return '';
	}

	$default_ports = array(
		'http'  => 80,
		'https' => 443,
		'ws'    => 80,
		'wss'   => 443,
	);
	$port          = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

	return $scheme . '://' . $host . ( $port && $port !== $default_ports[ $scheme ] ? ':' . $port : '' );
}

/**
 * The path of a reported page on this site (with or without `www.`), without query string or fragment,
 * at most 200 characters. Reports about any other site are not this site's to count.
 *
 * @param string $url       Reported document URL.
 * @param string $site_host This site's host name.
 * @return string The path, or '' when the URL is not an http(s) URL on this site.
 */
function blueline_core_csp_document_path( string $url, string $site_host ): string {
	$url   = (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $url );
	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
		return '';
	}

	$bare = static fn( string $host ): string => (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	if ( '' === $site_host || $bare( (string) ( $parts['host'] ?? '' ) ) !== $bare( $site_host ) ) {
		return '';
	}

	$path = (string) ( $parts['path'] ?? '' );

	return substr( '' === $path ? '/' : $path, 0, 200 );
}

/**
 * An empty store.
 *
 * @return array{since: string, total: int, entries: array<string, array>}
 */
function blueline_core_csp_empty_store(): array {
	return array(
		'since'   => '',
		'total'   => 0,
		'entries' => array(),
	);
}

/**
 * The stored counts, normalised (a damaged value reads as empty).
 *
 * @return array{since: string, total: int, entries: array<string, array{directive: string, blocked: string, path: string, count: int, last: string}>}
 */
function blueline_core_csp_store(): array {
	$stored = get_option( BLUELINE_CORE_CSP_REPORT_OPTION, array() );
	$store  = blueline_core_csp_empty_store();

	if ( ! is_array( $stored ) ) {
		return $store;
	}

	$store['since'] = is_string( $stored['since'] ?? null ) ? $stored['since'] : '';
	$store['total'] = max( 0, (int) ( $stored['total'] ?? 0 ) );

	foreach ( (array) ( $stored['entries'] ?? array() ) as $key => $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['directive'], $entry['blocked'], $entry['path'] ) ) {
			continue;
		}

		$store['entries'][ (string) $key ] = array(
			'directive' => (string) $entry['directive'],
			'blocked'   => (string) $entry['blocked'],
			'path'      => (string) $entry['path'],
			'count'     => max( 0, (int) ( $entry['count'] ?? 0 ) ),
			'last'      => (string) ( $entry['last'] ?? '' ),
		);
	}

	return $store;
}

/**
 * Add one report to a store. Pure. When the store is full, the least-seen entry (the oldest of equally
 * rare ones) makes room.
 *
 * @param array                                                   $store  blueline_core_csp_store() shape.
 * @param array{directive: string, blocked: string, path: string} $report One normalised report.
 * @param string                                                  $day    Y-m-d (UTC) of this report.
 * @param int                                                     $max    Maximum number of entries.
 * @return array The updated store.
 */
function blueline_core_csp_add_to_store( array $store, array $report, string $day, int $max ): array {
	$key = $report['directive'] . ' ' . $report['blocked'] . ' ' . $report['path'];

	if ( ! isset( $store['entries'][ $key ] ) ) {
		$excess = count( $store['entries'] ) - max( 1, $max ) + 1;
		for ( $i = 0; $i < $excess; $i++ ) {
			unset( $store['entries'][ blueline_core_csp_least_seen_key( $store['entries'] ) ] );
		}

		$store['entries'][ $key ] = $report + array(
			'count' => 0,
			'last'  => $day,
		);
	}

	++$store['entries'][ $key ]['count'];
	$store['entries'][ $key ]['last'] = $day;
	++$store['total'];

	return $store;
}

/**
 * The key of the least-seen entry: lowest count, then oldest last-seen day, then first stored.
 *
 * @param array<string, array{count: int, last: string}> $entries Store entries (not empty).
 * @return string
 */
function blueline_core_csp_least_seen_key( array $entries ): string {
	$evict = (string) array_key_first( $entries );

	foreach ( $entries as $key => $entry ) {
		$current = $entries[ $evict ];
		if ( $entry['count'] < $current['count'] || ( $entry['count'] === $current['count'] && strcmp( $entry['last'], $current['last'] ) < 0 ) ) {
			$evict = (string) $key;
		}
	}

	return $evict;
}

/**
 * Count reports in the stored option. Two simultaneous requests can each miss the other's count; for a
 * report of what is broken, that loss does not matter, and it avoids locking on a public endpoint.
 *
 * @param array<int, array{directive: string, blocked: string, path: string}> $reports Normalised reports.
 * @return void
 */
function blueline_core_csp_record( array $reports ): void {
	$store = blueline_core_csp_store();
	$now   = time();

	if ( '' === $store['since'] ) {
		$store['since'] = gmdate( 'Y-m-d H:i', $now );
	}

	foreach ( $reports as $report ) {
		$store = blueline_core_csp_add_to_store( $store, $report, gmdate( 'Y-m-d', $now ), BLUELINE_CORE_CSP_REPORT_MAX_ENTRIES );
	}

	update_option( BLUELINE_CORE_CSP_REPORT_OPTION, $store, false );
}

/**
 * Forget every collected report.
 *
 * @return void
 */
function blueline_core_csp_reset_store(): void {
	delete_option( BLUELINE_CORE_CSP_REPORT_OPTION );
}

/**
 * The entries, most reported first (most recently seen first among equals).
 *
 * @param array $store blueline_core_csp_store().
 * @param int   $limit At most this many; 0 for all.
 * @return array<int, array{directive: string, blocked: string, path: string, count: int, last: string}>
 */
function blueline_core_csp_top_entries( array $store, int $limit = 0 ): array {
	$entries = array_values( (array) ( $store['entries'] ?? array() ) );

	usort(
		$entries,
		static function ( array $a, array $b ): int {
			return array( $b['count'], $b['last'] ) <=> array( $a['count'], $a['last'] );
		}
	);

	return $limit > 0 ? array_slice( $entries, 0, $limit ) : $entries;
}

add_filter( 'site_status_tests', 'blueline_core_csp_register_site_health_test' );
/**
 * Register the informational "CSP reports" Site Health test.
 *
 * @param mixed $tests Site Health tests.
 * @return mixed
 */
function blueline_core_csp_register_site_health_test( $tests ) {
	if ( ! is_array( $tests ) ) {
		return $tests;
	}

	$tests['direct']['blueline_csp_reports'] = array(
		'label' => __( 'Content Security Policy reports', 'blueline-core' ),
		'test'  => 'blueline_core_csp_run_site_health_test',
	);

	return $tests;
}

/**
 * Run the Site Health test.
 *
 * @return array<string, mixed>
 */
function blueline_core_csp_run_site_health_test(): array {
	return blueline_core_csp_site_health_result( blueline_core_csp_store(), blueline_core_csp_mode() );
}

/**
 * The Site Health result: how many distinct violations were reported and the five most frequent.
 * Informational, so always "good": in report-only mode a violation breaks nothing.
 *
 * @param array  $store blueline_core_csp_store().
 * @param string $mode  blueline_core_csp_mode().
 * @return array<string, mixed>
 */
function blueline_core_csp_site_health_result( array $store, string $mode ): array {
	$distinct = count( (array) ( $store['entries'] ?? array() ) );

	if ( 'off' === $mode ) {
		$label = __( 'Content Security Policy is off', 'blueline-core' );
	} else {
		$label = sprintf(
			/* translators: %s: number of distinct violations. */
			'enforce' === $mode ? _n( 'CSP enforced: %s distinct violation', 'CSP enforced: %s distinct violations', $distinct, 'blueline-core' ) : _n( 'CSP report-only: %s distinct violation', 'CSP report-only: %s distinct violations', $distinct, 'blueline-core' ),
			number_format_i18n( $distinct )
		);
	}

	$description = '<p>' . esc_html__( 'Browsers report what the Content-Security-Policy would block. The counts are grouped by directive, blocked origin and page path; no addresses or full URLs are kept.', 'blueline-core' ) . '</p>';

	$top = blueline_core_csp_top_entries( $store, 5 );
	if ( $top ) {
		$description .= '<p>' . esc_html__( 'Most frequent:', 'blueline-core' ) . '</p><ul>';
		foreach ( $top as $entry ) {
			$description .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: report count, 2: CSP directive, 3: blocked origin or keyword, 4: page path. */
					__( '%1$s × %2$s blocked %3$s on %4$s', 'blueline-core' ),
					number_format_i18n( $entry['count'] ),
					$entry['directive'],
					$entry['blocked'],
					$entry['path']
				)
			) . '</li>';
		}
		$description .= '</ul>';
	}

	$description .= '<p>' . esc_html__( 'Full list: wp blueline-core csp report', 'blueline-core' ) . '</p>';

	return array(
		'label'       => $label,
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Blueline', 'blueline-core' ),
			'color' => 'blue',
		),
		'description' => $description,
		'actions'     => '',
		'test'        => 'blueline_csp_reports',
	);
}
