<?php
/**
 * Per-member scope for the next-game scripts' localStorage keys.
 *
 * The floating next-game widget (floating-next-game.js), the My Account
 * next-game card (account-next-game.js) and the Preferences "Show next game
 * widget again" button (preferences-widget-reset.js) remember dismissed and
 * seen states in localStorage, which belongs to the browser, not to the
 * member. On a shared device one member's dismissal would hide the widget for
 * the next member to sign in there (FE-01). Each of those elements carries
 * this scope in `data-bl-member`, and the scripts add it to their keys.
 *
 * The scope is a truncated wp_hash() of the user id: stable per member and
 * salted, so it does not reveal who the member is. No email, name or raw id
 * ever goes into storage.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The storage scope for one member, or '' for a guest.
 *
 * @param int $user_id WordPress user id.
 * @return string 16 hex characters, or ''.
 */
function blueline_next_game_member_scope( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return '';
	}

	return substr( wp_hash( 'blueline-next-game:' . $user_id ), 0, 16 );
}

/**
 * The `data-bl-member` attribute for the signed-in member, already escaped.
 *
 * @return string
 */
function blueline_next_game_member_attribute(): string {
	return 'data-bl-member="' . esc_attr( blueline_next_game_member_scope( get_current_user_id() ) ) . '"';
}
