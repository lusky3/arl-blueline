<?php
/**
 * League-first My Account dashboard (Task 12). Replaces the Task 9 port of
 * production's shop-account dashboard wholesale, per plan: league content
 * -- next game, team, season stats -- leads; billing is demoted to a
 * linked group at the bottom.
 *
 * For the ~16% of current-season players with no sp_user link (Task 16's
 * corrected figure -- 84% ARE linked; the "~88% unlinked" this file used to
 * claim came from the retracted, sticky sp_current_team denominator), the
 * claim card is shown IN PLACE of the next-game/team/season modules, not
 * alongside three empty versions of them.
 *
 * The three WooCommerce extension hooks at the bottom are NOT decoration:
 * `woocommerce_account_dashboard` is where WooCommerce Store Credit renders
 * its "you have available credit" block (via this theme's own
 * myaccount/dashboard-store-credit.php override -- see
 * woocommerce-store-credit/legacy/includes/class-wc-store-credit-my-account.php),
 * and dropping them silently deleted that block from every player's account
 * page. They are fired here in WooCommerce core's own order and at core's own
 * position (last in the template), so any extension that rendered on
 * production's dashboard still renders here.
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

/**
 * My Account dashboard.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_account_dashboard' );

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_before_my_account' );

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_after_my_account' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
