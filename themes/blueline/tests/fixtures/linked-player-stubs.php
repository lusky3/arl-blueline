<?php
/**
 * A stand-in for blueline-core's blueline_get_linked_player_id(), for theme
 * tests of the My Account tabs as a player who IS linked.
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests: the theme branches on
 * function_exists( 'blueline_get_linked_player_id' ), so defining it in the
 * shared process would turn every other test into a "plugin active" test.
 *
 * @package blueline
 */

// The account renderers also need its WP_Query and WP_Term stand-ins.
require_once __DIR__ . '/player-schedule-stubs.php';

/**
 * The player a user is linked to, from $GLOBALS['bl_test_linked_players'].
 *
 * @param int $user_id WordPress user ID.
 * @return int|null
 */
function blueline_get_linked_player_id( int $user_id ): ?int {
	return $GLOBALS['bl_test_linked_players'][ $user_id ] ?? null;
}
