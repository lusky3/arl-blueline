<?php
/**
 * Marks the signed-in viewer's own row in SportsPress player lists (the
 * division lists, past rosters and stats tables) with the same "You" badge
 * a team's own roster already carries (sportspress/team-lists.php).
 *
 * Hooked onto the stock template's `sportspress_player_list_data` filter
 * rather than overriding templates/player-list.php: that template prints raw
 * row strings with no per-row hook, so an override would mean forking ~290
 * lines of plugin markup to add one badge. The badge rides in the name cell;
 * the row tint is a `:has()` rule in sportspress.css, so no row markup is
 * touched.
 *
 * Loaded by inc/sportspress.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append the "You" badge to the viewer's own player in a player list's data.
 *
 * @param mixed $data List data: row 0 is the labels, the rest are rows keyed by player ID.
 * @return mixed The same data, with the viewer's own row's name badged.
 */
function blueline_player_list_mark_current_user( $data ) {
	if ( ! is_array( $data ) || ! function_exists( 'blueline_current_user_player_id' ) ) {
		return $data;
	}

	$mine = blueline_current_user_player_id();

	// Row 0 is the label row, never a player.
	if ( ! $mine || ! isset( $data[ $mine ] ) || ! is_array( $data[ $mine ] ) || empty( $data[ $mine ]['name'] ) ) {
		return $data;
	}

	$data[ $mine ]['name'] .= ' <span class="bl-sp-roster__you">' . esc_html__( 'You', 'blueline' ) . '</span>';

	return $data;
}
add_filter( 'sportspress_player_list_data', 'blueline_player_list_mark_current_user' );
