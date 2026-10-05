<?php
/**
 * Custom avatars from user meta `blueline_avatar_id`, replacing yith-woocommerce-customize-myaccount-page's.
 *
 * Filters the data-only `pre_get_avatar_data` and sets just `$args['url']`, so core builds the
 * <img> markup (class, size, loading attributes) exactly as it does for a Gravatar; one filter
 * covers get_avatar() and get_avatar_url(). `$args['alt']` is the caller's job and is left alone.
 *
 * The pointer is written by `wp blueline-core migrate-yith-avatars --apply`, which copies from
 * YITH's `yith-wcmap-avatar` user meta and never writes or deletes YITH's records. The avatar
 * is personal data: includes/privacy/privacy.php exports and erases it.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

require_once BLUELINE_CORE_DIR . '/includes/shared/attachment-usage.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-blueline-core-migrate-yith-avatars-command.php';
}

add_filter( 'pre_get_avatar_data', 'blueline_pre_get_avatar_data', 10, 2 );
/**
 * Substitute a user's custom avatar for the Gravatar, when one is set and its attachment still exists.
 *
 * Setting `$args['url']` short-circuits the rest of get_avatar_data() (no Gravatar hash is
 * computed). Every early return leaves `$args` unchanged, so a user with no override, or whose
 * attachment was deleted, falls back to Gravatar like anyone else.
 *
 * @param array $args        Arguments passed to get_avatar_data(), after processing.
 * @param mixed $id_or_email The avatar to retrieve. Accepts a user ID, email,
 *                            Gravatar hash, WP_User, WP_Post, or WP_Comment object.
 * @return array
 */
function blueline_pre_get_avatar_data( array $args, $id_or_email ): array {
	// Something with an earlier priority already resolved a URL: keep that decision.
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
 * Resolve the user ID behind get_avatar_data()'s polymorphic $id_or_email parameter.
 *
 * A logged-out commenter has no account to own an avatar, so that resolves to 0 and falls
 * through to Gravatar.
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
