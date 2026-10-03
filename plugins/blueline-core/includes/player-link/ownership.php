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
 * Enrich raw linked-player rows with the linked user and a category.
 *
 * @param object[] $linked Rows with ID, post_title, post_author, sp_user.
 * @return array<int, array{player_id:int, player:string, sp_user:int, user_login:string, post_author:int, category:string}>
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

		$rows[] = array(
			'player_id'   => (int) $row->ID,
			'player'      => (string) $row->post_title,
			'sp_user'     => $sp_user,
			'user_login'  => $user ? (string) ( $user->user_login ?? '' ) : '',
			'post_author' => $post_author,
			'category'    => blueline_core_ownership_category( $sp_user, $post_author, (bool) $user, $user && blueline_user_has_player_role( $sp_user ) ),
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
	} else {
		$plan['ok'] = true;
	}

	return $plan;
}

/**
 * One CSV line (RFC 4180 quoting).
 *
 * @param array<int|string, scalar> $cells Cell values.
 * @return string
 */
function blueline_core_ownership_csv_line( array $cells ): string {
	$quoted = array();
	foreach ( $cells as $cell ) {
		$quoted[] = '"' . str_replace( '"', '""', (string) $cell ) . '"';
	}

	return implode( ',', $quoted );
}
