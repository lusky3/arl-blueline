<?php
/**
 * The admin_post handler for a logged-in user's "Is this you?" name claim, and its redirect.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_blueline_claim_player', 'blueline_handle_claim_player_submission' );
/**
 * Handle a logged-in user's "Is this you?" claim submission.
 *
 * The nonce ties the submission to blueline_claim_player, and the submitted player_id is only
 * honoured if it appears in that user's OWN blueline_find_player_candidates() list, so a tampered
 * request cannot claim a player who is not a genuine name match for the logged-in account, nor one
 * already claimed by someone else.
 */
function blueline_handle_claim_player_submission(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You must be logged in to do this.', 'blueline-core' ), 403 );
	}

	check_admin_referer( 'blueline_claim_player' );

	$user_id   = get_current_user_id();
	$player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
	$redirect  = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}

	$candidate_ids = wp_list_pluck( blueline_find_player_candidates( $user_id ), 'player_id' );

	if ( ! $player_id || ! in_array( $player_id, $candidate_ids, true ) ) {
		blueline_redirect_after_claim( $redirect, 'invalid' );
	}

	$result = blueline_link_player_to_user( $player_id, $user_id );

	blueline_redirect_after_claim( $redirect, is_wp_error( $result ) ? $result->get_error_code() : 'linked' );
}

/**
 * Redirect back to $redirect_to with the claim outcome in a query var, and exit.
 *
 * @param string $redirect_to Where to send the user back to.
 * @param string $status      One of 'linked', 'invalid', or a blueline_link_player_to_user() WP_Error code.
 */
function blueline_redirect_after_claim( string $redirect_to, string $status ): void {
	wp_safe_redirect( esc_url_raw( add_query_arg( 'blueline_claim', $status, $redirect_to ) ) );
	exit;
}
