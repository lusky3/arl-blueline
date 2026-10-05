<?php
/**
 * Account predicates for the player-link module: who holds the Player role, who owns a player
 * record, and who may self-link by typing a name.
 *
 * No dependency on the link store, so the candidate finder can use it without a cycle.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether $user_id holds SportsPress' Player role, which sportspress-player-registration
 * assigns at checkout.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_has_player_role( int $user_id ): bool {
	$user = get_userdata( $user_id );

	return $user && in_array( BLUELINE_PLAYER_ROLE, (array) ( $user->roles ?? array() ), true );
}

/**
 * Whether $user_id owns $player_id: is its post_author, the ownership
 * sportspress-player-registration writes alongside sp_user. A name claim writes only sp_user, so
 * it never confers ownership.
 *
 * @param int $user_id   WordPress user ID.
 * @param int $player_id sp_player post ID.
 * @return bool
 */
function blueline_user_owns_player( int $user_id, int $player_id ): bool {
	return $user_id > 0 && $player_id > 0 && (int) get_post_field( 'post_author', $player_id ) === $user_id;
}

/**
 * Whether $user_id owns any sp_player record at all.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_owns_any_player( int $user_id ): bool {
	if ( $user_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		return false;
	}

	return (bool) get_posts(
		array(
			'post_type'      => 'sp_player',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
}

/**
 * Whether $user_id may self-link a player by name match. Registered players (Player role) and
 * owners of a player record are linked by registration or by the league, never by typing a name,
 * which the account holder controls.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_can_claim_by_name( int $user_id ): bool {
	return ! blueline_user_has_player_role( $user_id ) && ! blueline_user_owns_any_player( $user_id );
}
