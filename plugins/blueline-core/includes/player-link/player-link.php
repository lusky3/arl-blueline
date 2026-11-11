<?php
/**
 * Player-link module: links a WordPress user to a SportsPress player (sp_user post meta for
 * registration and league links, BLUELINE_PLAYER_CLAIM_META for self-service name claims).
 *
 * Entry point only. It declares the constants every file shares and loads the module's files in
 * dependency order; each file's header says what it owns.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_PLAYER_USER_META = 'sp_user';
const BLUELINE_MATCH_THRESHOLD  = 0.85;
const BLUELINE_PLAYER_ROLE      = 'sp_player';

/*
 * A self-service name claim's own post meta key. sp_user is shared: other league plugins treat
 * it as "this account is this player" (game availability, discipline notices, registration's
 * identity check), so a self-asserted name claim must never be written there. Only blueline-core
 * and the theme (through blueline_get_linked_player_id()) read this key.
 */
const BLUELINE_PLAYER_CLAIM_META = '_blueline_name_claim_user';

require_once __DIR__ . '/request-cache.php';
require_once __DIR__ . '/name-match.php';
require_once __DIR__ . '/claim-pool.php';
require_once __DIR__ . '/eligibility.php';
require_once __DIR__ . '/candidates.php';
require_once __DIR__ . '/link.php';
require_once __DIR__ . '/claim-handler.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// __DIR__, not BLUELINE_CORE_DIR: the sp_user backfill script may load this file without the plugin booted.
	require_once __DIR__ . '/ownership.php';
	require_once __DIR__ . '/class-blueline-core-ownership-command.php';
}
