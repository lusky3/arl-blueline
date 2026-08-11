<?php
/**
 * Theme-owned custom avatars.
 *
 * Replaces yith-woocommerce-customize-myaccount-page's custom-avatar feature
 * (spec §6.6, Task 13). YITH's actual per-user link -- confirmed by reading
 * its own source (`includes/class-yith-wcmap-avatar.php`) and staging's real
 * data, not assumed -- is user meta `yith-wcmap-avatar` (an attachment ID),
 * read via `get_user_avatar_id()` and substituted with a hand-built <img>
 * tag on the low-level `pre_get_avatar` filter. The `yith_wcmap_users_avatar_ids`
 * option is a separate, internal bookkeeping list (every attachment ID the
 * plugin has ever used as *someone's* avatar, for cleanup/media-library
 * filtering) -- it is not keyed by user ID, and can go stale relative to the
 * real per-user links (see the migration script for the staging evidence).
 *
 * This file filters the later, data-only `pre_get_avatar_data` hook instead
 * of `pre_get_avatar` -- setting only `$args['url']` and letting core's own
 * get_avatar_data()/get_avatar() build the final <img> markup (class,
 * height, width, alt, loading/fetchpriority/decoding attributes) exactly as
 * it would for a Gravatar, rather than re-implementing that markup by hand
 * the way YITH's `get_avatar()` callback does. `get_avatar_url()` is itself
 * a thin wrapper around `get_avatar_data()` (see
 * wp-includes/link-template.php), so this one filter covers both core entry
 * points the task interface names.
 *
 * The migrated data lives in user meta `blueline_avatar_id`, written by
 * scripts/one-off/2026-08-11-migrate-yith-avatars.php. Neither
 * `yith-wcmap-avatar` nor `yith_wcmap_users_avatar_ids` is read, written, or
 * deleted here or by the migration script -- YITH's own records are never
 * at risk, and the migration is a copy, not a move.
 *
 * `$args['alt']` is intentionally left untouched: it is already populated
 * from whatever the get_avatar()/get_avatar_url() caller passed in (core
 * merges the `$alt` parameter into `$args` before `get_avatar_data()` -- and
 * therefore this filter -- ever runs), so a meaningful alt is a call site
 * concern, not this filter's.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * User meta key holding a user's theme-owned custom avatar attachment ID.
 *
 * @var string
 */
const BLUELINE_AVATAR_META_KEY = 'blueline_avatar_id';

add_filter( 'pre_get_avatar_data', 'blueline_pre_get_avatar_data', 10, 2 );
/**
 * Substitute a user's migrated custom avatar for the default Gravatar
 * resolution, when one is set and its attachment still exists.
 *
 * Setting `$args['url']` here short-circuits the rest of
 * `get_avatar_data()` (see its `pre_get_avatar_data` filter docs in
 * wp-includes/link-template.php) -- no Gravatar hash or network round-trip
 * is computed, and `get_avatar()`/`get_avatar_url()` take the URL from
 * here. Returning `$args` unchanged (every early-return branch below) falls
 * through to WordPress's normal Gravatar-or-default-image resolution, so a
 * user with no override, or one whose attachment has since been deleted,
 * degrades exactly like any other user -- never a broken image, never a
 * fatal.
 *
 * @param array $args        Arguments passed to get_avatar_data(), after processing.
 * @param mixed $id_or_email The avatar to retrieve. Accepts a user ID, email,
 *                            Gravatar hash, WP_User, WP_Post, or WP_Comment object.
 * @return array
 */
function blueline_pre_get_avatar_data( array $args, $id_or_email ): array {
	// Something with earlier/higher priority already resolved a URL --
	// don't override a more specific decision (mirrors the short-circuit
	// contract get_avatar_data() itself documents for this filter).
	if ( isset( $args['url'] ) ) {
		return $args;
	}

	$user_id = blueline_resolve_avatar_user_id( $id_or_email );
	if ( ! $user_id ) {
		return $args;
	}

	$attachment_id = absint( get_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, true ) );
	if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
		return $args;
	}

	$size = ! empty( $args['size'] ) ? absint( $args['size'] ) : 96;
	$src  = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
	if ( ! $src ) {
		return $args;
	}

	$args['url']          = $src[0];
	$args['found_avatar'] = true;

	return $args;
}

/**
 * Resolve the WordPress user ID behind get_avatar_data()'s polymorphic
 * $id_or_email parameter, for the shapes that can plausibly own a
 * theme-owned custom avatar.
 *
 * A comment left by a logged-out visitor has no WP user account for a
 * custom avatar to be attached to, so it correctly resolves to 0 here and
 * falls through to Gravatar/default -- not an error case.
 *
 * @param mixed $id_or_email The avatar to retrieve. Accepts a user ID,
 *                            Gravatar hash, email, WP_User, WP_Post, or
 *                            WP_Comment object.
 * @return int User ID, or 0 if none could be resolved.
 */
function blueline_resolve_avatar_user_id( $id_or_email ): int {
	if ( is_numeric( $id_or_email ) ) {
		return absint( $id_or_email );
	}

	if ( $id_or_email instanceof WP_User ) {
		return absint( $id_or_email->ID );
	}

	if ( $id_or_email instanceof WP_Post ) {
		return absint( $id_or_email->post_author );
	}

	if ( $id_or_email instanceof WP_Comment ) {
		return absint( $id_or_email->user_id );
	}

	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? absint( $user->ID ) : 0;
	}

	return 0;
}
