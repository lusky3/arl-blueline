<?php
/**
 * BootTest fixture: a sentinel defined outside the plugin's includes/, as a legacy theme would.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stand-in for a legacy theme's blueline_get_linked_player_id().
 *
 * @return int
 */
function blueline_core_test_legacy_sentinel(): int {
	return 0;
}
