<?php
/**
 * Meta keys and the featured-image check shared by the player-photo and privacy modules.
 *
 * Both modules can be switched off independently (`blueline_core_modules`), so each
 * requires this file itself rather than relying on the other being loaded.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Guarded so the file may load before or after any module that also declares them.
defined( 'BLUELINE_AVATAR_META_KEY' ) || define( 'BLUELINE_AVATAR_META_KEY', 'blueline_avatar_id' ); // User meta: custom avatar attachment ID.
defined( 'BLUELINE_PLAYER_PHOTO_FLAG_META' ) || define( 'BLUELINE_PLAYER_PHOTO_FLAG_META', '_blueline_player_photo' ); // Attachment meta: the upload handler created it, so it may delete it once replaced.

/**
 * Post meta key linking an sp_player post to its user. The player-link module owns the
 * BLUELINE_PLAYER_USER_META constant, which is absent when that module is switched off.
 *
 * @return string
 */
function blueline_player_user_meta_key(): string {
	return defined( 'BLUELINE_PLAYER_USER_META' ) ? (string) constant( 'BLUELINE_PLAYER_USER_META' ) : 'sp_user';
}

/**
 * Whether any post other than $ignore_post_id uses $attachment_id as its featured image.
 *
 * Gates a permanent attachment delete, so it fails closed: a failed lookup (or no
 * database object at all) counts as "in use".
 *
 * @param int $attachment_id  Attachment ID.
 * @param int $ignore_post_id Post whose use of the attachment does not count.
 * @return bool
 */
function blueline_attachment_is_thumbnail_elsewhere( int $attachment_id, int $ignore_post_id ): bool {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- covers every post type and status, which get_posts() cannot. wp_postmeta has no index on meta_value, so this scans the _thumbnail_id rows: fine because it runs only on a photo replacement or an erase request, never on a page view. Must not be cached: it gates a delete.
	$other_post = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id != %d LIMIT 1",
			(string) $attachment_id,
			$ignore_post_id
		)
	);

	return null !== $other_post || '' !== (string) $wpdb->last_error;
}
