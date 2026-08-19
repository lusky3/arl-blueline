<?php
/**
 * Plugin Name: RH Royal MCP /register un-hijack
 * Description: Gives GET /register back to the Register page. Added 2026-08-06.
 *
 * WHY: royal-mcp registers its OAuth endpoints on generic root paths at the TOP of the
 * rewrite stack (royal-mcp.php, register_oauth_rewrites):
 *
 *     add_rewrite_rule( 'register/?$', 'index.php?royal_mcp_oauth=register', 'top' );
 *
 * It lands at position 26 of ~615 rules, so it shadows the page rules and the site's own
 * Register page (id 11113) becomes unreachable by ANY url -- ?page_id=11113 canonically
 * 301s to /register, which then hits the RFC 7591 Dynamic Client Registration handler and
 * 405s with {"error":"invalid_request","error_description":"POST method required."}.
 * That was ~30-77 real visitors a day arriving from /schedule and /arl-league-info, and
 * 1,702 logged "oauth:register" errors in the plugin's own activity log.
 *
 * Upstream is not going to save us: 1.4.39 ships the identical rule and exposes no filter
 * to relocate or disable it, and 1.4.22 hardened the pattern to catch the trailing-slash
 * variant too. The plugin cannot simply be turned off either -- MCP is in active use.
 *
 * SAFETY: Dynamic Client Registration is POST-only and the plugin itself 405s every
 * non-POST, so withholding the rule from GET/HEAD costs no OAuth behaviour at all. POST
 * still reaches the DCR handler and OPTIONS still gets the plugin's 204 CORS preflight;
 * only page-style reads fall through to WordPress. The match is checked against the
 * royal_mcp_oauth target so we can never drop some other plugin's rule that happens to
 * use the same key.
 *
 * Filters the READ side (option_rewrite_rules) and deliberately NOT rewrite_rules_array:
 * that one feeds the update_option() inside a rules flush, so filtering it would let a
 * flush that happened to run during a GET persist the removal and permanently break
 * POST /register. The stored option always keeps the full rule set.
 *
 * REVERT: delete this file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'option_rewrite_rules',
	function ( $rules ) {
		if ( ! is_array( $rules ) ) {
			return $rules;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: 'GET';

		// POST is OAuth DCR, OPTIONS is the CORS preflight. Both stay with the plugin.
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $rules;
		}

		if ( isset( $rules['register/?$'] )
			&& false !== strpos( $rules['register/?$'], 'royal_mcp_oauth=register' ) ) {
			unset( $rules['register/?$'] );
		}

		return $rules;
	}
);
