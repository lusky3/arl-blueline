<?php
/**
 * Hide the admin toolbar from anyone without `manage_options`.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'show_admin_bar', 'blueline_hide_admin_bar_for_players' );
/**
 * Hide the toolbar from every user who lacks `manage_options` (players, but also editors,
 * shop managers and any other non-administrator role).
 *
 * A UI fix, not the security boundary: that is the server-side capability check on each admin
 * screen and handler, which this filter does not touch. A hidden link to a still-guarded page
 * is simply tidier. Core applies `show_admin_bar` after the current user is resolved, so
 * registering at file scope is early enough.
 *
 * @param bool $show Core's own default answer.
 * @return bool
 */
function blueline_hide_admin_bar_for_players( $show ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	return $show;
}
