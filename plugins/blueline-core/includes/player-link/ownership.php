<?php
/**
 * Ownership classification helpers for `wp blueline-core ownership`. A verified
 * owner (photo, personal details) needs the Player role AND post_author on the
 * linked sp_player; registrations made before the registration plugin set
 * post_author leave Player-role members linked by sp_user only.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Linked user holds the Player role and is the post_author.
const BLUELINE_CORE_OWNERSHIP_VERIFIED = 'verified';

// Linked user holds the Player role but is not the post_author: the case `apply` fixes.
const BLUELINE_CORE_OWNERSHIP_ROLE_NOT_AUTHOR = 'role_not_author';

// Linked user is the post_author but lacks the Player role.
const BLUELINE_CORE_OWNERSHIP_AUTHOR_NOT_ROLE = 'author_not_role';

// Linked user has neither: a name-claimed, view-only link.
const BLUELINE_CORE_OWNERSHIP_NAME_CLAIM = 'name_claim';

// sp_user points at a user that does not exist.
const BLUELINE_CORE_OWNERSHIP_MISSING_USER = 'missing_user';

// Post meta `apply` writes the replaced post_author to, so a change can be undone.
const BLUELINE_CORE_OWNERSHIP_PREV_AUTHOR_META = '_blueline_prev_author';

// Report flag: the linked user already owns (is post_author of) a DIFFERENT sp_player.
const BLUELINE_CORE_OWNERSHIP_FLAG_OWNS_OTHER = 'owns_other_player';

// Report flag: the current post_author is an existing user who holds the Player role.
const BLUELINE_CORE_OWNERSHIP_FLAG_AUTHOR_IS_PLAYER = 'author_is_player';

/**
 * Classify one linked player's ownership.
 *
 * @param int  $sp_user     Linked user ID (sp_user meta).
 * @param int  $post_author The player's post_author.
 * @param bool $user_exists Whether the linked user exists.
 * @param bool $has_role    Whether the linked user holds the Player role.
 * @return string One of the BLUELINE_CORE_OWNERSHIP_* values.
 */
function blueline_core_ownership_category( int $sp_user, int $post_author, bool $user_exists, bool $has_role ): string {
	if ( ! $user_exists ) {
		return BLUELINE_CORE_OWNERSHIP_MISSING_USER;
	}

	$is_author = $sp_user === $post_author;

	if ( $has_role ) {
		return $is_author ? BLUELINE_CORE_OWNERSHIP_VERIFIED : BLUELINE_CORE_OWNERSHIP_ROLE_NOT_AUTHOR;
	}

	return $is_author ? BLUELINE_CORE_OWNERSHIP_AUTHOR_NOT_ROLE : BLUELINE_CORE_OWNERSHIP_NAME_CLAIM;
}

/**
 * The id of another sp_player (not $player_id) that $user_id is post_author of,
 * or 0 when there is none.
 *
 * @param int $user_id   User ID.
 * @param int $player_id The player being considered.
 * @return int
 */
function blueline_core_ownership_other_owned_player( int $user_id, int $player_id ): int {
	if ( $user_id <= 0 ) {
		return 0;
	}

	$owned = get_posts(
		array(
			'post_type'      => 'sp_player',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => 2, // Two is enough to find one that is not $player_id.
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( (array) $owned as $id ) {
		if ( (int) $id !== $player_id ) {
			return (int) $id;
		}
	}

	return 0;
}

/**
 * Enrich raw linked-player rows with the linked user, a category and, for the
 * rows `apply` could act on (role_not_author), the evidence an operator needs
 * to judge them: name-match score, post date and risk flags. The extra lookups
 * run only for those rows, not for every linked player.
 *
 * `name_score` is blueline_name_match_score() of the linked user's billing or
 * display name against the player title (0 when the user does not exist). A
 * low score on a row that would be handed ownership is the sign of a stranger's
 * name claim made before the claimant registered.
 *
 * @param object[] $linked Rows with ID, post_title, post_author, sp_user (and optionally post_date).
 * @return array<int, array{player_id:int, player:string, sp_user:int, user_login:string, post_author:int, category:string, post_date:string, name_score:float, flags:string}>
 */
function blueline_core_ownership_rows( array $linked ): array {
	$rows = array();

	foreach ( $linked as $row ) {
		$sp_user = (int) $row->sp_user;
		if ( $sp_user <= 0 ) {
			continue;
		}

		$user        = get_userdata( $sp_user );
		$post_author = (int) $row->post_author;
		$player_id   = (int) $row->ID;
		$category    = blueline_core_ownership_category( $sp_user, $post_author, (bool) $user, $user && blueline_user_has_player_role( $sp_user ) );
		$name_score  = 0.0;
		$flags       = array();

		if ( BLUELINE_CORE_OWNERSHIP_ROLE_NOT_AUTHOR === $category ) {
			$name_score = blueline_name_match_score( blueline_user_match_name( $sp_user ), (string) $row->post_title );

			if ( blueline_core_ownership_other_owned_player( $sp_user, $player_id ) > 0 ) {
				$flags[] = BLUELINE_CORE_OWNERSHIP_FLAG_OWNS_OTHER;
			}

			if ( blueline_user_has_player_role( $post_author ) ) {
				$flags[] = BLUELINE_CORE_OWNERSHIP_FLAG_AUTHOR_IS_PLAYER;
			}
		}

		$rows[] = array(
			'player_id'   => $player_id,
			'player'      => (string) $row->post_title,
			'sp_user'     => $sp_user,
			'user_login'  => $user ? (string) ( $user->user_login ?? '' ) : '',
			'post_author' => $post_author,
			'category'    => $category,
			'post_date'   => (string) ( $row->post_date ?? '' ),
			'name_score'  => $name_score,
			'flags'       => implode( ' ', $flags ),
		);
	}

	return $rows;
}

/**
 * Parse `--ids` into distinct positive player IDs.
 *
 * @param string $raw Comma-separated IDs.
 * @return int[]|WP_Error Empty input or any non-numeric token is an error.
 */
function blueline_core_ownership_parse_ids( string $raw ) {
	$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );

	if ( ! $tokens ) {
		return new WP_Error( 'no_ids', 'Pass the players to fix explicitly, e.g. --ids=101,102. There is no bulk mode.' );
	}

	$ids = array();
	foreach ( $tokens as $token ) {
		if ( ! ctype_digit( $token ) || (int) $token <= 0 ) {
			return new WP_Error( 'bad_id', sprintf( 'Not a player ID: "%s".', $token ) );
		}
		$ids[] = (int) $token;
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Decide whether `apply` may set $player_id's post_author to its linked user.
 *
 * Refuses, with a reason, when handing over ownership would look wrong:
 * the linked user already owns a different player (one account, one record), or
 * the current post_author is an existing user who holds the Player role (a real
 * registrant's record; not an unset legacy author such as an admin).
 *
 * @param int $player_id sp_player post ID.
 * @return array{ok:bool, reason:string, from:int, to:int}
 */
function blueline_core_ownership_plan( int $player_id ): array {
	$plan = array(
		'ok'     => false,
		'reason' => '',
		'from'   => (int) get_post_field( 'post_author', $player_id ),
		'to'     => (int) get_post_meta( $player_id, BLUELINE_PLAYER_USER_META, true ),
	);

	$other_owned = blueline_core_ownership_other_owned_player( $plan['to'], $player_id );

	if ( 'sp_player' !== get_post_type( $player_id ) ) {
		$plan['reason'] = 'not an sp_player post';
	} elseif ( $plan['to'] <= 0 ) {
		$plan['reason'] = 'no linked user (sp_user)';
	} elseif ( ! get_userdata( $plan['to'] ) ) {
		$plan['reason'] = sprintf( 'linked user %d does not exist', $plan['to'] );
	} elseif ( ! blueline_user_has_player_role( $plan['to'] ) ) {
		$plan['reason'] = sprintf( 'linked user %d lacks the Player role', $plan['to'] );
	} elseif ( $plan['from'] === $plan['to'] ) {
		$plan['reason'] = sprintf( 'already authored by user %d', $plan['to'] );
	} elseif ( $other_owned > 0 ) {
		$plan['reason'] = sprintf( 'linked user %1$d already owns a different player (%2$d)', $plan['to'], $other_owned );
	} elseif ( blueline_user_has_player_role( $plan['from'] ) ) {
		$plan['reason'] = sprintf( 'current author %d is an existing user with the Player role', $plan['from'] );
	} else {
		$plan['ok'] = true;
	}

	return $plan;
}

/**
 * Decide whether `unlink` may remove $player_id's sp_user row.
 *
 * Only a view-only name claim (linked user is neither post_author nor a Player
 * role holder) or a dangling link (user no longer exists) may be removed: a
 * verified owner, a Player-role member or the post_author is never unlinked by
 * this tool.
 *
 * @param int $player_id sp_player post ID.
 * @return array{ok:bool, reason:string, user:int, category:string}
 */
function blueline_core_ownership_unlink_plan( int $player_id ): array {
	$plan = array(
		'ok'       => false,
		'reason'   => '',
		'user'     => (int) get_post_meta( $player_id, BLUELINE_PLAYER_USER_META, true ),
		'category' => '',
	);

	if ( 'sp_player' !== get_post_type( $player_id ) ) {
		$plan['reason'] = 'not an sp_player post';
		return $plan;
	}

	if ( $plan['user'] <= 0 ) {
		$plan['reason'] = 'no linked user (sp_user)';
		return $plan;
	}

	$user_exists      = (bool) get_userdata( $plan['user'] );
	$plan['category'] = blueline_core_ownership_category(
		$plan['user'],
		(int) get_post_field( 'post_author', $player_id ),
		$user_exists,
		$user_exists && blueline_user_has_player_role( $plan['user'] )
	);

	if ( BLUELINE_CORE_OWNERSHIP_NAME_CLAIM !== $plan['category'] && BLUELINE_CORE_OWNERSHIP_MISSING_USER !== $plan['category'] ) {
		$plan['reason'] = sprintf( 'linked user %1$d is not a plain name claim (%2$s)', $plan['user'], $plan['category'] );
		return $plan;
	}

	$plan['ok'] = true;
	return $plan;
}

/**
 * One CSV line (RFC 4180 quoting).
 *
 * A cell that starts with = + - @ tab or CR would be evaluated as a formula by
 * spreadsheet software (player titles and logins are user-supplied), so it is
 * prefixed with a single quote, which spreadsheets treat as "text".
 *
 * @param array<int|string, scalar> $cells Cell values.
 * @return string
 */
function blueline_core_ownership_csv_line( array $cells ): string {
	$quoted = array();
	foreach ( $cells as $cell ) {
		$cell = (string) $cell;
		if ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
			$cell = "'" . $cell;
		}
		$quoted[] = '"' . str_replace( '"', '""', $cell ) . '"';
	}

	return implode( ',', $quoted );
}
