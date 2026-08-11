<?php
/**
 * League-first My Account dashboard (Task 12). Replaces the Task 9 port of
 * production's shop-account dashboard wholesale, per plan: league content
 * -- next game, team, season stats -- leads; billing is demoted to a
 * linked group at the bottom.
 *
 * For the ~88% of current-season players with no sp_user link (Task 11),
 * the claim card is shown IN PLACE of the next-game/team/season modules,
 * not alongside three empty versions of them -- it is the primary
 * experience here, not a fallback.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

blueline_account_render_claim_notice();

$blueline_dashboard_user_id   = get_current_user_id();
$blueline_dashboard_player_id = function_exists( 'blueline_get_linked_player_id' )
	? blueline_get_linked_player_id( $blueline_dashboard_user_id )
	: null;

if ( $blueline_dashboard_player_id ) {
	blueline_account_render_next_game( $blueline_dashboard_player_id );
	blueline_account_render_my_team( $blueline_dashboard_player_id );
	blueline_account_render_season_stats( $blueline_dashboard_player_id );
} else {
	blueline_account_render_claim_card( $blueline_dashboard_user_id );
}

blueline_account_render_registration( $blueline_dashboard_user_id );
blueline_account_render_billing_group();

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
