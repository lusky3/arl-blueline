<?php
/**
 * Access module: keeps member accounts (players, customers, subscribers) out of wp-admin and
 * strips the editing capabilities their roles carry, without touching the stored role definitions.
 * login-messages.php makes the login and lost-password answers generic.
 *
 * SportsPress's `sp_player` role holds edit_posts, upload_files, delete_posts and the edit caps of
 * players, events and teams, so without this every registered player could open wp-admin, the
 * block editor and the media library, and create posts through the REST API. Members do everything
 * they need on the front end: the account pages (WooCommerce), the photo upload and player claim
 * (admin-post.php handlers that check ownership, not editing caps) and the theme toggle
 * (admin-ajax.php).
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/login-messages.php';

/**
 * The roles that make up a member account. A user is restricted only when EVERY role they hold
 * is on this list, so any other role (administrator, ARL Board, a SportsPress staff or manager
 * role, shop manager, ...) lifts the restriction.
 *
 * @return string[]
 */
function blueline_core_wp_admin_blocked_roles(): array {
	$default = array( 'sp_player', 'customer', 'subscriber' );

	/**
	 * Filters the roles that are kept out of wp-admin and lose their editing caps. A user is
	 * restricted only when every role they hold is in this list.
	 *
	 * @param string[] $roles Role slugs. Default sp_player, customer, subscriber.
	 */
	$roles = apply_filters( 'blueline_core_wp_admin_blocked_roles', $default );

	return is_array( $roles ) ? array_values( array_map( 'strval', $roles ) ) : $default;
}

/**
 * Capabilities that mark a user as staff whatever their roles say (a cap granted to the user
 * directly, for example). Holding any of them lifts the restriction.
 *
 * @return string[]
 */
function blueline_core_wp_admin_exempt_caps(): array {
	return array( 'edit_others_posts', 'manage_options', 'manage_woocommerce', 'manage_sportspress', 'edit_others_sp_players', 'edit_others_sp_events' );
}

/**
 * Whether $user is a member account: logged in, holding at least one role, every role on the
 * blocked list, and none of the staff caps.
 *
 * @param mixed                    $user    The user (a WP_User; anything else is not restricted).
 * @param array<string, bool>|null $allcaps The user's caps before any filtering; read from the user
 *                                          when null. user_has_cap passes its own, which avoids
 *                                          asking has_cap() from inside that filter.
 * @return bool
 */
function blueline_core_user_is_restricted( $user, ?array $allcaps = null ): bool {
	if ( ! $user instanceof WP_User || (int) $user->ID <= 0 ) {
		return false;
	}

	$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
	if ( ! $roles || array_diff( $roles, blueline_core_wp_admin_blocked_roles() ) ) {
		return false;
	}

	$allcaps = $allcaps ?? ( isset( $user->allcaps ) && is_array( $user->allcaps ) ? $user->allcaps : array() );
	foreach ( blueline_core_wp_admin_exempt_caps() as $cap ) {
		if ( ! empty( $allcaps[ $cap ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Where a member is sent instead of wp-admin: the WooCommerce account page.
 *
 * @return string
 */
function blueline_core_account_url(): string {
	$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

	return '' !== $url ? $url : home_url( '/account/' );
}

add_action( 'admin_init', 'blueline_core_block_wp_admin', 1 );
/**
 * Send a member who opens any wp-admin screen to the account page.
 *
 * The admin_init hook also fires for admin-post.php and admin-ajax.php, which carry the front-end forms
 * members use (photo upload, player claim, theme toggle), so those are let through; so are REST,
 * cron and WP-CLI requests, which are not screens.
 *
 * @return void
 */
function blueline_core_block_wp_admin(): void {
	if ( ! is_admin() || blueline_core_is_non_screen_request() ) {
		return;
	}

	if ( ! blueline_core_user_is_restricted( wp_get_current_user() ) ) {
		return;
	}

	wp_safe_redirect( blueline_core_account_url() );
	exit;
}

/**
 * Whether this request is a handler endpoint or a non-screen context rather than a wp-admin page.
 *
 * @return bool
 */
function blueline_core_is_non_screen_request(): bool {
	if ( wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return true;
	}

	return in_array( $GLOBALS['pagenow'] ?? '', array( 'admin-post.php', 'admin-ajax.php' ), true );
}

add_filter( 'login_redirect', 'blueline_core_member_login_redirect', 99, 3 );
/**
 * After logging in through wp-login.php, send a member headed for wp-admin (core's default
 * destination) to the account page instead. A front-end page they asked to return to is kept.
 *
 * @param string           $redirect_to           The destination.
 * @param string           $requested_redirect_to The requested destination (unused).
 * @param WP_User|WP_Error $user                  The user, or the login error.
 * @return string
 */
function blueline_core_member_login_redirect( $redirect_to, $requested_redirect_to, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- core passes three arguments; only the first and last matter.
	if ( ! blueline_core_user_is_restricted( $user ) || ! blueline_core_is_wp_admin_url( (string) $redirect_to ) ) {
		return $redirect_to;
	}

	return blueline_core_account_url();
}

/**
 * Whether $url points into wp-admin. An empty destination counts, because core then falls back
 * to admin_url().
 *
 * @param string $url URL or path.
 * @return bool
 */
function blueline_core_is_wp_admin_url( string $url ): bool {
	if ( '' === trim( $url ) ) {
		return true;
	}

	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	return false !== strpos( '/' . ltrim( $path, '/' ) . '/', '/wp-admin/' );
}

/**
 * Every capability a member loses: creating, editing, publishing and deleting posts and pages,
 * uploading files, moderating comments, and every editing, publishing, deleting and term cap of
 * the SportsPress post types. Reading caps are kept.
 *
 * @return string[]
 */
function blueline_core_member_trimmed_caps(): array {
	static $caps = null;

	if ( null !== $caps ) {
		return $caps;
	}

	$caps = array( 'upload_files', 'moderate_comments' );

	foreach ( array( 'post', 'page' ) as $type ) {
		foreach ( array( 'edit', 'edit_published', 'edit_private', 'edit_others', 'publish', 'delete', 'delete_published', 'delete_private', 'delete_others' ) as $action ) {
			$caps[] = "{$action}_{$type}s";
		}
	}

	foreach ( array( 'sp_player', 'sp_event', 'sp_team', 'sp_staff', 'sp_list', 'sp_table', 'sp_calendar' ) as $type ) {
		// SportsPress also stores the singular names as primitive caps on its roles.
		$caps[] = "edit_{$type}";
		$caps[] = "delete_{$type}";

		foreach ( array( 'edit', 'edit_published', 'edit_private', 'edit_others', 'publish', 'delete', 'delete_published', 'delete_private', 'delete_others' ) as $action ) {
			$caps[] = "{$action}_{$type}s";
		}

		foreach ( array( 'manage', 'edit', 'delete', 'assign' ) as $action ) {
			$caps[] = "{$action}_{$type}_terms";
		}
	}

	return $caps;
}

add_filter( 'user_has_cap', 'blueline_core_trim_member_caps', 999, 4 );
/**
 * Deny a member's editing caps at check time. The role definitions in the database are left
 * alone, so switching the module off restores SportsPress's defaults, and a member who is given
 * a staff role gets every cap back at once.
 *
 * Runs late so another plugin's user_has_cap grant cannot hand one of these back.
 *
 * @param array<string, bool> $allcaps The user's caps.
 * @param string[]            $caps    Primitive caps being checked (unused).
 * @param array               $args    The has_cap() arguments (unused).
 * @param WP_User             $user    The user.
 * @return array<string, bool>
 */
function blueline_core_trim_member_caps( $allcaps, $caps, $args, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- core passes four arguments; the middle two are not needed.
	if ( ! is_array( $allcaps ) || ! blueline_core_user_is_restricted( $user, $allcaps ) ) {
		return $allcaps;
	}

	foreach ( blueline_core_member_trimmed_caps() as $cap ) {
		if ( isset( $allcaps[ $cap ] ) ) {
			$allcaps[ $cap ] = false;
		}
	}

	return $allcaps;
}
