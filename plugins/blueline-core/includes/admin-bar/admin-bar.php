<?php
/**
 * Hide the admin toolbar from anyone without `manage_options`. Moved from
 * themes/blueline/inc/setup.php.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'show_admin_bar', 'blueline_hide_admin_bar_for_players' );
/**
 * Hide the WordPress/SportsPress admin toolbar for every user who lacks
 * `manage_options`.
 *
 * Despite the name, this is NOT limited to the player role: it hides the
 * toolbar from every user without `manage_options`, which includes editors,
 * shop managers and any other non-administrator role. That is the intended
 * behaviour. (The function name is kept as is, since other code may refer
 * to it.)
 *
 * Live-site review: a plain player-role account signed in to /account saw
 * the full wp-admin toolbar, including a direct "ARL Settings" link
 * (/wp-admin/admin.php?page=sportspress) and an "Admin Notices" item --
 * neither of which a player can do anything useful with, and both of which
 * make the account look more privileged than it is. Removing the toolbar
 * link is a UI fix, not the security boundary -- that boundary is (and must
 * remain) the server-side capability check on each admin screen and handler,
 * which this filter does not touch: a hidden link to a still-guarded page is
 * simply tidier, not less safe than a visible one.
 *
 * Core applies the `show_admin_bar` filter in is_admin_bar_showing(), after
 * the current user is resolved, so the current_user_can() check inside this
 * callback runs late enough for the hook to be registered at file scope.
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
