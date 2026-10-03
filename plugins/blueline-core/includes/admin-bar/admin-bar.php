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
 * Hide the WordPress/SportsPress admin toolbar for anyone without
 * `manage_options` -- the same capability every admin surface this theme
 * ships (inc/settings/page.php's own settings page, its AJAX/POST
 * handlers, etc.) is already gated behind.
 *
 * Live-site review: a plain player-role account signed in to /account saw
 * the full wp-admin toolbar, including a direct "ARL Settings" link
 * (/wp-admin/admin.php?page=sportspress) and an "Admin Notices" item --
 * neither of which a player can do anything useful with, and both of which
 * make the account look more privileged than it is (and, for the settings
 * link, invite a curious click into an admin screen that will simply
 * refuse them once inc/settings/page.php's own `current_user_can(
 * 'manage_options' )` check runs). Removing the toolbar link is a UI fix,
 * not the security boundary -- that boundary is (and must remain) the
 * server-side capability check on the settings page/handlers themselves,
 * which this filter does not touch and does not need to: a hidden link to
 * a still-guarded page is simply tidier, not less safe than a visible one.
 *
 * Registered directly at file scope, the same way this file's other
 * WordPress-core hooks are (e.g. the widgets_init registration above):
 * core applies the `show_admin_bar` filter in is_admin_bar_showing(), after
 * the current user is resolved, so the current_user_can() check inside
 * this callback runs late enough and registering it early costs nothing.
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
