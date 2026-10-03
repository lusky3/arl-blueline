<?php
/**
 * Player photo upload handler, EXIF stripping and the legacy /profile-picture 301.
 * Moved from themes/blueline/inc/account/player-profile.php; the pencil form
 * and blueline_account_render_photo_notice() stay in the theme.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Attachment meta flag: this upload handler created the attachment, so it may delete it once replaced.
const BLUELINE_PLAYER_PHOTO_FLAG_META = '_blueline_player_photo';

/**
 * Redirect to Player Profile carrying an outcome status
 * (blueline_account_render_photo_notice() reads it back), and exit.
 *
 * NOT wc_add_notice(): confirmed live (staging, 2026-09-04) that
 * WC()->session/WC()->cart -- what wc_add_notice() actually writes into --
 * are never initialised on an admin_post_* request, because admin-post.php
 * lives under /wp-admin/ and WooCommerce's own frontend bootstrap skips
 * everywhere is_admin() is true, admin-post.php included even though it is
 * how a front-end form is meant to reach PHP. Calling wc_add_notice() here
 * threw "Call to undefined function" and fataled the whole request
 * (500, every upload). blueline_handle_claim_player_submission() (the
 * OTHER admin_post_* form handler, blueline-core's player-link module)
 * already solved this the same way: a status in the redirect's query
 * string, read back by a small dedicated notice renderer instead of
 * WooCommerce's session-backed one.
 *
 * @param string $redirect_to Where to send the user back to.
 * @param string $status      A key blueline_account_render_photo_notice() recognises.
 * @return void
 */
function blueline_redirect_after_photo_upload( string $redirect_to, string $status ): void {
	wp_safe_redirect( esc_url_raw( add_query_arg( 'blueline_photo', $status, $redirect_to ) ) );
	exit;
}

add_action( 'admin_post_blueline_upload_player_photo', 'blueline_handle_player_photo_upload' );
/**
 * Handle the Player Profile pencil-icon's photo upload.
 *
 * $player_id is resolved server-side from the logged-in session
 * (blueline_current_user_player_id(), never trusted from the request), so a
 * tampered submission can only ever change the SUBMITTER's own player photo
 * -- there is no player_id field in the form for a forged request to alter.
 *
 * File validation mirrors sportspress-player-tools' own upload handler
 * (2MB cap, real image bytes checked via getimagesize() rather than the
 * browser-supplied MIME type, wp_check_filetype_and_ext() against the
 * filename): same threat model, same answer, just reachable for a real
 * player this time.
 *
 * @return void
 */
function blueline_handle_player_photo_upload(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You must be logged in to do this.', 'blueline-core' ), 403 );
	}

	check_admin_referer( 'blueline_upload_player_photo' );

	$redirect = function_exists( 'wc_get_account_endpoint_url' )
		? wc_get_account_endpoint_url( 'player-profile' )
		: home_url( '/' );

	$player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;

	if ( ! $player_id ) {
		blueline_redirect_after_photo_upload( $redirect, 'unlinked' );
	}

	if ( ! blueline_user_is_verified_player_owner( get_current_user_id(), $player_id ) ) {
		blueline_redirect_after_photo_upload( $redirect, 'not_owner' );
	}

	if ( empty( $_FILES['player_photo']['tmp_name'] ) ) {
		wp_safe_redirect( $redirect );
		exit;
	}

	$max_size = 2 * 1024 * 1024;
	if ( isset( $_FILES['player_photo']['size'] ) && $_FILES['player_photo']['size'] > $max_size ) {
		blueline_redirect_after_photo_upload( $redirect, 'too_large' );
	}

	$allowed_mime_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated temp file path from PHP's own upload handling, never attacker-supplied content; getimagesize() below reads and validates the file's real bytes, which is the actual security check here.
	$image_info = getimagesize( $_FILES['player_photo']['tmp_name'] );
	if ( false === $image_info || ! in_array( $image_info['mime'], $allowed_mime_types, true ) ) {
		blueline_redirect_after_photo_upload( $redirect, 'invalid' );
	}

	$filename = isset( $_FILES['player_photo']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['player_photo']['name'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same tmp_name as above; wp_check_filetype_and_ext() itself re-reads the file's real bytes against $filename, it does not trust either as a bare string.
	$checked       = wp_check_filetype_and_ext( $_FILES['player_photo']['tmp_name'], $filename );
	$resolved_mime = ! empty( $checked['type'] ) ? $checked['type'] : '';

	if ( ! $resolved_mime || ! in_array( $resolved_mime, $allowed_mime_types, true ) ) {
		blueline_redirect_after_photo_upload( $redirect, 'invalid' );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	add_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' );
	$attachment_id = media_handle_upload( 'player_photo', $player_id );
	remove_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' );

	if ( is_wp_error( $attachment_id ) ) {
		blueline_redirect_after_photo_upload( $redirect, 'error' );
	}

	blueline_set_player_photo( $player_id, $attachment_id );
	blueline_redirect_after_photo_upload( $redirect, 'updated' );
}

/**
 * Make a freshly uploaded attachment the player's photo, flag it as ours,
 * then delete the photo it replaced when that one is safe to delete.
 *
 * @param int $player_id     sp_player post ID.
 * @param int $attachment_id The new attachment, already parented to $player_id.
 * @return void
 */
function blueline_set_player_photo( int $player_id, int $attachment_id ): void {
	$previous_id = (int) get_post_thumbnail_id( $player_id );

	set_post_thumbnail( $player_id, $attachment_id );
	update_post_meta( $attachment_id, BLUELINE_PLAYER_PHOTO_FLAG_META, 1 );

	if ( blueline_replaced_player_photo_is_deletable( $previous_id, $attachment_id, $player_id ) ) {
		wp_delete_attachment( $previous_id, true );
	}
}

/**
 * Whether the photo a new upload replaced may be deleted: only an attachment
 * this handler created for this same player, now unused as any thumbnail.
 * Legacy (unflagged) attachments are never deleted.
 *
 * @param int $previous_id The replaced thumbnail's attachment ID (0 if none).
 * @param int $new_id      The attachment that replaced it.
 * @param int $player_id   sp_player post ID.
 * @return bool
 */
function blueline_replaced_player_photo_is_deletable( int $previous_id, int $new_id, int $player_id ): bool {
	if ( $previous_id <= 0 || $previous_id === $new_id ) {
		return false;
	}

	if ( 'attachment' !== get_post_type( $previous_id ) ) {
		return false;
	}

	if ( '1' !== (string) get_post_meta( $previous_id, BLUELINE_PLAYER_PHOTO_FLAG_META, true ) ) {
		return false;
	}

	if ( (int) get_post_field( 'post_parent', $previous_id ) !== $player_id ) {
		return false;
	}

	return ! blueline_attachment_is_thumbnail_elsewhere( $previous_id, $player_id );
}

/**
 * Whether any post other than $player_id uses $attachment_id as its featured image.
 *
 * @param int $attachment_id Attachment ID.
 * @param int $player_id     The player post to ignore.
 * @return bool
 */
function blueline_attachment_is_thumbnail_elsewhere( int $attachment_id, int $player_id ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed postmeta lookup across every post type and status (get_posts() cannot cover both); must not be cached because it gates a delete.
	$other_post = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id != %d LIMIT 1",
			(string) $attachment_id,
			$player_id
		)
	);

	// Fail closed: a failed lookup counts as "still in use".
	return null !== $other_post || '' !== (string) $wpdb->last_error;
}

/**
 * `wp_handle_upload` filter, added only around the player-photo upload:
 * strip EXIF/GPS from the stored original before WordPress builds its
 * sub-sizes from it. Fails closed -- an unstrippable file is deleted and
 * the upload reported as an error.
 *
 * @param array $upload {file, url, type} from _wp_handle_upload().
 * @return array
 */
function blueline_strip_uploaded_photo_metadata( $upload ) {
	if ( ! is_array( $upload ) || isset( $upload['error'] ) || empty( $upload['file'] ) ) {
		return $upload;
	}

	if ( blueline_strip_image_metadata( (string) $upload['file'], (string) ( $upload['type'] ?? '' ) ) ) {
		return $upload;
	}

	wp_delete_file( $upload['file'] );

	return array( 'error' => __( 'The photo could not be processed.', 'blueline-core' ) );
}

/**
 * Re-encode an image in place without its metadata, baking any EXIF
 * orientation into the pixels first.
 *
 * GD never writes metadata on save. WordPress's Imagick editor keeps the
 * EXIF/IPTC/XMP profiles (even its own image_strip_meta preserves them),
 * so those are removed explicitly. GIFs are left alone: they carry no
 * EXIF, and a re-save would flatten an animation.
 *
 * @param string $path Absolute path to the image.
 * @param string $mime Its MIME type.
 * @return bool Whether the file is now metadata-free (or needed nothing).
 */
function blueline_strip_image_metadata( string $path, string $mime ): bool {
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return 'image/gif' === $mime;
	}

	$editor = wp_get_image_editor( $path );
	if ( is_wp_error( $editor ) ) {
		return false;
	}

	$editor->maybe_exif_rotate();

	$saved = $editor->save( $path, $mime );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || $saved['path'] !== $path ) {
		return false;
	}

	if ( $editor instanceof WP_Image_Editor_Imagick ) {
		return blueline_strip_imagick_profiles( $path );
	}

	return true;
}

/**
 * Remove every Imagick profile except the colour profile (icc/icm).
 *
 * @param string $path Absolute path to the image.
 * @return bool
 */
function blueline_strip_imagick_profiles( string $path ): bool {
	try {
		$image = new Imagick( $path );
		foreach ( array_keys( $image->getImageProfiles( '*', true ) ) as $profile ) {
			if ( ! in_array( $profile, array( 'icc', 'icm' ), true ) ) {
				$image->removeImageProfile( $profile );
			}
		}
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
		$written = $image->writeImage( $path );
		$image->clear();

		return (bool) $written;
	} catch ( Exception $e ) {
		return false;
	}
}

add_action( 'template_redirect', 'blueline_redirect_profile_picture_endpoint' );
/**
 * 301 sportspress-player-tools' own /account/profile-picture/ endpoint to
 * /account/player-profile/, which now carries the same capability (see
 * blueline_render_player_profile_bio_section()'s own docblock for why that
 * plugin's page never actually worked for a real player) plus everything
 * else Player Profile already shows.
 *
 * This is a THIRD-PARTY plugin's rewrite endpoint, not a WooCommerce
 * built-in -- deliberately its own small check, not folded into
 * blueline_account_endpoints()/blueline_account_legacy_redirect_map()
 * (inc/account/endpoints.php), which exist specifically to rename
 * WooCommerce's OWN default slugs without breaking WC_Query's internal
 * query-var resolution. 'profile-picture' has no such internal meaning to
 * preserve; it only needs to stop resolving to the plugin's broken page.
 *
 * @return void
 */
function blueline_redirect_profile_picture_endpoint(): void {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	global $wp;

	if ( ! isset( $wp->query_vars['profile-picture'] ) ) {
		return;
	}

	if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return;
	}

	wp_safe_redirect( wc_get_account_endpoint_url( 'player-profile' ), 301 );
	exit;
}
