<?php
/**
 * Request-scoped memo for the player-link module, kept on the WordPress object cache.
 *
 * Entries live in a NON-persistent group. A persistent cache (Redis drop-in) would keep an
 * answer past the request and serve it stale after a link write, so nothing here may be
 * persisted. Tests reset it with wp_cache_flush().
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_PLAYER_LINK_CACHE_GROUP = 'blueline_player_link';

/**
 * The object-cache group for this module's request-scoped entries, registered as
 * non-persistent on first use.
 *
 * @return string Cache group name.
 */
function blueline_player_link_cache_group(): string {
	static $registered = false;

	if ( ! $registered ) {
		wp_cache_add_non_persistent_groups( BLUELINE_PLAYER_LINK_CACHE_GROUP );
		$registered = true;
	}

	return BLUELINE_PLAYER_LINK_CACHE_GROUP;
}
