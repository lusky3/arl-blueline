<?php
/**
 * The user-to-player link: the sp_user store and its request cache, the current-user resolvers,
 * and blueline_link_player_to_user(), the one function that writes a link.
 *
 * The sp_user post meta key is shared with sportspress-player-registration.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every sp_player post linked to $user_id through sp_user, oldest first, in any non-trashed
 * status. Uncached: callers needing a fresh answer (the post-write check in
 * blueline_link_player_to_user()) use this directly.
 *
 * Status 'any' covers publish, private, draft, pending and future (not trash or auto-draft). A
 * user linked to a draft or private player must still resolve to it, or the
 * `user_already_linked` guard would let them pick up a second one.
 *
 * Cost: wp_postmeta is indexed on meta_key and post_id, not meta_value, so this scans every row
 * carrying the sp_user key. It runs at most once per user per request (see
 * blueline_get_linked_player_id()).
 *
 * @param int $user_id WordPress user ID.
 * @return int[] Player post IDs, ascending; empty when unlinked or SportsPress is inactive.
 */
function blueline_query_linked_player_ids( int $user_id ): array {
	if ( $user_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'      => 'sp_player',
			'post_status'    => 'any',
			'meta_key'       => BLUELINE_PLAYER_USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- no user-to-player index exists, so resolving a link by value scans the sp_user rows; cached per request by the caller.
			'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 20, // A user is meant to have one; a handful covers legacy duplicates.
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * The sp_player post (if any) linked to $user_id.
 *
 * Deterministic when a user carries several sp_user rows (a legacy name claim next to a
 * registration-created player): the player the user is post_author of wins, else the lowest ID.
 * Published or not.
 *
 * Memoised for the request in the module's NON-persistent cache group (see request-cache.php): a
 * persistent entry would go stale the moment blueline_link_player_to_user() writes. That function
 * busts it through blueline_forget_linked_player_cache().
 *
 * @param int $user_id WordPress user ID.
 * @return int|null Player post ID, or null if unlinked or SportsPress is inactive.
 */
function blueline_get_linked_player_id( int $user_id ): ?int {
	$group  = blueline_player_link_cache_group();
	$cached = wp_cache_get( 'linked_player_' . $user_id, $group, false, $found );

	if ( $found ) {
		return $cached ? (int) $cached : null;
	}

	$ids = blueline_query_linked_player_ids( $user_id );

	$resolved = $ids ? $ids[0] : null;
	foreach ( $ids as $id ) {
		if ( blueline_user_owns_player( $user_id, $id ) ) {
			$resolved = $id;
			break;
		}
	}

	wp_cache_set( 'linked_player_' . $user_id, (int) $resolved, $group ); // 0 stands for "no link", so a null never has to round-trip the cache.
	return $resolved;
}

/**
 * Forget the cached link for $user_id so the next blueline_get_linked_player_id() re-queries.
 * Called after a successful blueline_link_player_to_user().
 *
 * @param int $user_id WordPress user ID.
 */
function blueline_forget_linked_player_cache( int $user_id ): void {
	wp_cache_delete( 'linked_player_' . $user_id, blueline_player_link_cache_group() );
}

/**
 * The sp_player linked to the CURRENT request's logged-in user, or null.
 *
 * Saves template code the get_current_user_id() contract: blueline_get_linked_player_id( 0 )
 * would run a real query for a user that can never match.
 *
 * @return int|null Player post ID, or null when logged out or unclaimed.
 */
function blueline_current_user_player_id(): ?int {
	$user_id = get_current_user_id();

	return $user_id ? blueline_get_linked_player_id( $user_id ) : null;
}

/**
 * Every current team id for the CURRENT request's logged-in, claimed player. Plural because a
 * player can carry several current teams; blueline_player_current_team_id() (singular) silently
 * drops all but the first published one.
 *
 * Teams come from the theme reader blueline_player_current_team_ids() (inc/account/player-data.php);
 * without it this returns no teams.
 *
 * @return int[] Positive team ids; empty when logged out, unclaimed, or the player has no current team.
 */
function blueline_current_user_team_ids(): array {
	$player_id = blueline_current_user_player_id();

	return $player_id && function_exists( 'blueline_player_current_team_ids' ) ? blueline_player_current_team_ids( $player_id ) : array();
}

/**
 * Whether $user_id is the verified owner of their linked player: holds the Player role AND owns
 * the record. Only a verified owner may change the player's photo or see personal registration
 * details; a name-claimed link is read-only and non-personal.
 *
 * @param int      $user_id   WordPress user ID.
 * @param int|null $player_id Linked player, or null to resolve it.
 * @return bool
 */
function blueline_user_is_verified_player_owner( int $user_id, ?int $player_id = null ): bool {
	$player_id = $player_id ?? blueline_get_linked_player_id( $user_id );

	return $player_id
		&& blueline_user_has_player_role( $user_id )
		&& blueline_user_owns_player( $user_id, $player_id );
}

/**
 * The distinct, positive user IDs currently stored in $player_id's sp_user rows. Placeholder rows
 * ('' or '0') are not owners and are skipped.
 *
 * @param int $player_id sp_player post ID.
 * @return int[]
 */
function blueline_player_linked_user_ids( int $player_id ): array {
	$ids = array();

	foreach ( (array) get_post_meta( $player_id, BLUELINE_PLAYER_USER_META, false ) as $value ) {
		$value = (int) $value;
		if ( $value > 0 ) {
			$ids[] = $value;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Undo this call's own sp_user write after the post-write check found the invariant broken, and
 * drop the cached link. Only the row holding $user_id is removed; a concurrent writer's row is
 * never touched.
 *
 * @param int $player_id sp_player post ID.
 * @param int $user_id   The user whose row was just written.
 */
function blueline_rollback_player_link( int $player_id, int $user_id ): void {
	delete_post_meta( $player_id, BLUELINE_PLAYER_USER_META, $user_id );
	blueline_forget_linked_player_cache( $user_id );
}

/**
 * Link $player_id to $user_id in sp_user, with the checks a linkage between a person and an
 * account demands:
 *
 * - $player_id must be an sp_player post (and, unless the caller can edit_users, a published one);
 * - a player already linked to a DIFFERENT user is never silently repointed;
 * - a user who already has a different player can't pick up a second one;
 * - only the user themselves, or someone who can edit_users, may write the link;
 * - without edit_users, the player must be in the user's own blueline_find_player_candidates()
 *   list, so this function can never be used to skip the name gate, whoever calls it.
 *
 * Concurrency: those checks are read-then-write, so two requests can pass them together. The write
 * is therefore a unique add_post_meta() (it fails when any sp_user row exists), and afterwards the
 * invariant is re-checked: exactly one sp_user user on the player and exactly one linked player
 * for the user. If either is violated the row this call wrote is removed and the matching
 * WP_Error is returned. When two writers collide both may be rolled back; the player is then left
 * unlinked and either user can retry. Failing closed.
 *
 * @param int $player_id sp_player post ID.
 * @param int $user_id   WordPress user ID.
 * @return true|WP_Error Error codes: forbidden, invalid, already_linked,
 *                       user_already_linked, not_eligible.
 */
function blueline_link_player_to_user( int $player_id, int $user_id ) {
	if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to link this player to this account.', 'blueline-core' )
		);
	}

	$is_admin = current_user_can( 'edit_users' );

	if ( 'sp_player' !== get_post_type( $player_id ) || ( ! $is_admin && 'publish' !== get_post_status( $player_id ) ) ) {
		return new WP_Error(
			'invalid',
			__( 'That is not a player that can be linked.', 'blueline-core' )
		);
	}

	$owners = blueline_player_linked_user_ids( $player_id );
	if ( array_diff( $owners, array( $user_id ) ) ) {
		return new WP_Error(
			'already_linked',
			__( 'This player is already linked to a different account.', 'blueline-core' )
		);
	}

	// A stale cached answer must not decide the "already has a player" question.
	blueline_forget_linked_player_cache( $user_id );
	$existing_player = blueline_get_linked_player_id( $user_id );
	if ( null !== $existing_player && $existing_player !== $player_id ) {
		return new WP_Error(
			'user_already_linked',
			__( 'This account is already linked to a different player.', 'blueline-core' )
		);
	}

	if ( ! $is_admin && ! blueline_user_can_claim_by_name( $user_id ) ) {
		return new WP_Error(
			'not_eligible',
			__( 'Registered players are linked by the league, not by name.', 'blueline-core' )
		);
	}

	if ( in_array( $user_id, $owners, true ) ) {
		return true; // Already linked to this user: nothing to write.
	}

	if ( ! $is_admin && ! in_array( $player_id, wp_list_pluck( blueline_find_player_candidates( $user_id ), 'player_id' ), true ) ) {
		return new WP_Error(
			'invalid',
			__( 'That player is not a match for this account.', 'blueline-core' )
		);
	}

	if ( ! add_post_meta( $player_id, BLUELINE_PLAYER_USER_META, $user_id, true ) ) {
		// A row already exists. Someone else may have just written it; a '' or '0' placeholder
		// row (the "unclaimed" shape the candidate pool also accepts) is taken over.
		$owners = blueline_player_linked_user_ids( $player_id );
		if ( array_diff( $owners, array( $user_id ) ) ) {
			return new WP_Error(
				'already_linked',
				__( 'This player is already linked to a different account.', 'blueline-core' )
			);
		}

		if ( ! $owners ) {
			update_post_meta( $player_id, BLUELINE_PLAYER_USER_META, $user_id, get_post_meta( $player_id, BLUELINE_PLAYER_USER_META, true ) );
		}
	}

	blueline_forget_linked_player_cache( $user_id );

	// Re-check what is actually stored now, not what was true before the write.
	if ( array( $user_id ) !== blueline_player_linked_user_ids( $player_id ) ) {
		blueline_rollback_player_link( $player_id, $user_id );

		return new WP_Error(
			'already_linked',
			__( 'This player is already linked to a different account.', 'blueline-core' )
		);
	}

	if ( array_diff( blueline_query_linked_player_ids( $user_id ), array( $player_id ) ) ) {
		blueline_rollback_player_link( $player_id, $user_id );

		return new WP_Error(
			'user_already_linked',
			__( 'This account is already linked to a different player.', 'blueline-core' )
		);
	}

	return true;
}
