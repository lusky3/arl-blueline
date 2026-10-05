<?php
/**
 * Player photo upload handler and EXIF stripping, plus the legacy /profile-picture 301.
 * The pencil form and blueline_account_render_photo_notice() live in the theme.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

require_once BLUELINE_CORE_DIR . '/includes/shared/attachment-usage.php';

const BLUELINE_PLAYER_PHOTO_MAX_BYTES  = 2 * 1024 * 1024;
const BLUELINE_PLAYER_PHOTO_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

/**
 * Redirect to Player Profile and exit. A non-null status is carried in the query string for
 * blueline_account_render_photo_notice() to read back.
 *
 * Not wc_add_notice(): WooCommerce never initialises its session or cart on an admin_post_*
 * request (admin-post.php lives under /wp-admin/), so wc_add_notice() fatals there.
 *
 * @param string      $redirect_to Where to send the user back to.
 * @param string|null $status      A key blueline_account_render_photo_notice() recognises, or null for none.
 * @return never
 */
function blueline_redirect_with_photo_status_and_exit( string $redirect_to, ?string $status = null ): never {
	wp_safe_redirect( null === $status ? $redirect_to : esc_url_raw( add_query_arg( 'blueline_photo', $status, $redirect_to ) ) );
	exit;
}

add_action( 'admin_post_blueline_upload_player_photo', 'blueline_handle_player_photo_upload' );
/**
 * Handle the Player Profile pencil-icon's photo upload.
 *
 * The player is resolved server-side from the logged-in session, never from the request, so a
 * forged submission can only ever change the submitter's own player photo. The file is not
 * touched until the login, nonce, link and ownership checks have all passed.
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
		blueline_redirect_with_photo_status_and_exit( $redirect, 'unlinked' );
	}

	if ( ! blueline_user_is_verified_player_owner( get_current_user_id(), $player_id ) ) {
		blueline_redirect_with_photo_status_and_exit( $redirect, 'not_owner' );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is validated, and the file's real bytes inspected, by blueline_player_photo_file_status().
	$status = blueline_player_photo_file_status( $_FILES['player_photo'] ?? array() );

	if ( 'ok' === $status ) {
		$status = blueline_store_uploaded_player_photo( $player_id ) ? 'updated' : 'error';
	}

	blueline_redirect_with_photo_status_and_exit( $redirect, 'none' === $status ? null : $status );
}

/**
 * Validate a submitted photo without touching WordPress state.
 *
 * Checks run in this order and the first failure wins: PHP's own upload error, the byte cap,
 * the real image bytes (type, dimensions, pixel cap), then the file name's agreement with them.
 *
 * @param array $file The $_FILES['player_photo'] entry (empty when absent).
 * @return string 'ok', 'none' (nothing was submitted), or the refusal: 'too_large', 'invalid' or 'error'.
 */
function blueline_player_photo_file_status( array $file ): string {
	// PHP reports an upload it refused (over upload_max_filesize, partial, no temp dir...) through
	// ['error'] with tmp_name empty.
	$status = blueline_player_photo_upload_error_status( isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK );
	if ( 'ok' !== $status ) {
		return $status;
	}

	if ( empty( $file['tmp_name'] ) ) {
		return 'none';
	}

	if ( isset( $file['size'] ) && $file['size'] > BLUELINE_PLAYER_PHOTO_MAX_BYTES ) {
		return 'too_large';
	}

	$filename = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';

	return blueline_player_photo_image_status( (string) $file['tmp_name'], $filename );
}

/**
 * Map a PHP UPLOAD_ERR_* code to a photo status.
 *
 * @param int $code The upload error code.
 * @return string 'ok', 'none', 'too_large' or 'error'.
 */
function blueline_player_photo_upload_error_status( int $code ): string {
	return match ( $code ) {
		UPLOAD_ERR_OK => 'ok',
		UPLOAD_ERR_NO_FILE => 'none',
		UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
		default => 'error',
	};
}

/**
 * Judge an upload by its real bytes, never the browser-supplied MIME type.
 *
 * @param string $tmp_name Server-generated temp file path from PHP's upload handling.
 * @param string $filename Sanitised client file name.
 * @return string 'ok', 'invalid' or 'too_large'.
 */
function blueline_player_photo_image_status( string $tmp_name, string $filename ): string {
	$image_info = getimagesize( $tmp_name );
	if ( false === $image_info || ! in_array( $image_info['mime'], BLUELINE_PLAYER_PHOTO_MIME_TYPES, true ) ) {
		return 'invalid';
	}

	// A small file can still declare a huge bitmap (a decompression bomb): decoding allocates
	// width x height x 4 bytes, so refuse oversized dimensions before anything decodes the image.
	if ( (int) $image_info[0] <= 0 || (int) $image_info[1] <= 0 ) {
		return 'invalid';
	}

	if ( (int) $image_info[0] * (int) $image_info[1] > blueline_player_photo_max_pixels( $image_info ) ) {
		return 'too_large';
	}

	// wp_check_filetype_and_ext() re-reads the file and must agree with the file name's extension.
	$checked = wp_check_filetype_and_ext( $tmp_name, $filename );

	return ! empty( $checked['type'] ) && in_array( $checked['type'], BLUELINE_PLAYER_PHOTO_MIME_TYPES, true ) ? 'ok' : 'invalid';
}

/**
 * Hand a validated upload to WordPress (EXIF stripped on the way in) and make it the player's photo.
 *
 * @param int $player_id sp_player post ID.
 * @return bool Whether the new photo is now the player's thumbnail.
 */
function blueline_store_uploaded_player_photo( int $player_id ): bool {
	// admin-post.php already loads these; the guard only matters for another caller.
	if ( ! function_exists( 'media_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	add_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' );
	$attachment_id = media_handle_upload( 'player_photo', $player_id );
	remove_filter( 'wp_handle_upload', 'blueline_strip_uploaded_photo_metadata' );

	return ! is_wp_error( $attachment_id ) && blueline_set_player_photo( $player_id, $attachment_id );
}

/**
 * The largest bitmap (width x height, in pixels) a player photo may declare.
 *
 * Decoding allocates about width x height x 4 bytes, and the byte cap alone does not bound
 * that: a tiny, highly compressed PNG can declare tens of thousands of pixels per side. The
 * default of 40,000,000 px (about 6300 x 6300, 160MB decoded) is far above any real photo that
 * fits in 2MB, yet low enough to keep the decode inside a PHP worker.
 *
 * @param array $image_info The getimagesize() result for the upload.
 * @return int Pixel cap, at least 1.
 */
function blueline_player_photo_max_pixels( array $image_info = array() ): int {
	/**
	 * Filters the maximum pixel count (width x height) accepted for a player photo upload.
	 *
	 * @param int   $max_pixels Default 40000000.
	 * @param array $image_info The getimagesize() result for the upload.
	 */
	$max_pixels = (int) apply_filters( 'blueline_core_player_photo_max_pixels', 40000000, $image_info );

	return max( 1, $max_pixels );
}

/**
 * Make a freshly uploaded attachment the player's photo, flag it as ours,
 * then delete the photo it replaced when that one is safe to delete.
 *
 * If the thumbnail cannot be set (or does not read back as the new
 * attachment) the new attachment is deleted and the previous photo is left
 * untouched, so the profile never ends up with no photo.
 *
 * @param int $player_id     sp_player post ID.
 * @param int $attachment_id The new attachment, already parented to $player_id.
 * @return bool Whether the new photo is now the player's thumbnail.
 */
function blueline_set_player_photo( int $player_id, int $attachment_id ): bool {
	$previous_id = (int) get_post_thumbnail_id( $player_id );

	if ( ! set_post_thumbnail( $player_id, $attachment_id ) || (int) get_post_thumbnail_id( $player_id ) !== $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );

		return false;
	}

	update_post_meta( $attachment_id, BLUELINE_PLAYER_PHOTO_FLAG_META, 1 );

	if ( blueline_replaced_player_photo_is_deletable( $previous_id, $attachment_id, $player_id ) ) {
		wp_delete_attachment( $previous_id, true );
	}

	return true;
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

	// GD first (see blueline_prefer_gd_image_editor()); WordPress still falls back to Imagick when GD cannot do the job.
	add_filter( 'wp_image_editors', 'blueline_prefer_gd_image_editor', PHP_INT_MAX );
	$editor = wp_get_image_editor( $path );
	remove_filter( 'wp_image_editors', 'blueline_prefer_gd_image_editor', PHP_INT_MAX );
	if ( is_wp_error( $editor ) ) {
		return false;
	}

	$editor->maybe_exif_rotate();

	// Strip on the editor's own Imagick handle so there is one decode and one lossy encode, not a second pass over the file.
	$stripped_in_editor = false;
	if ( $editor instanceof WP_Image_Editor_Imagick ) {
		$image = blueline_imagick_editor_handle( $editor );
		if ( null !== $image ) {
			try {
				blueline_remove_imagick_profiles( $image );
				$stripped_in_editor = true;
			} catch ( Exception $e ) {
				return false;
			}
		}
	}

	$saved = $editor->save( $path, $mime );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || $saved['path'] !== $path ) {
		return false;
	}

	// The editor's handle was not reachable: strip by re-reading the saved file (a second pass, but still fail-closed).
	if ( $editor instanceof WP_Image_Editor_Imagick && ! $stripped_in_editor ) {
		return blueline_strip_imagick_profiles( $path );
	}

	return true;
}

/**
 * Put GD first in WordPress's image-editor list, whatever else is installed.
 *
 * GD never writes EXIF/IPTC/XMP on save, so a plain GD re-encode is already a
 * metadata strip, and its memory use is bounded by PHP's memory_limit (the
 * upload's pixel cap relies on that). Imagick allocates outside it and keeps
 * profiles unless removed by hand. Only editors WordPress reports usable are
 * in the list, so a host without GD simply keeps Imagick first.
 *
 * @param string[] $editors Editor class names, in preference order.
 * @return string[]
 */
function blueline_prefer_gd_image_editor( $editors ) {
	if ( ! is_array( $editors ) || ! in_array( 'WP_Image_Editor_GD', $editors, true ) ) {
		return $editors;
	}

	return array_values( array_unique( array_merge( array( 'WP_Image_Editor_GD' ), $editors ) ) );
}

/**
 * The Imagick object a WP_Image_Editor_Imagick has loaded, or null.
 *
 * WordPress exposes no getter for it (the property is protected), so it is
 * read by reflection; null makes the caller fall back to re-reading the file.
 *
 * @param WP_Image_Editor_Imagick $editor The editor.
 * @return Imagick|null
 */
function blueline_imagick_editor_handle( $editor ) {
	try {
		$property = new ReflectionProperty( $editor, 'image' );
		$property->setAccessible( true );
		$image = $property->getValue( $editor );
	} catch ( ReflectionException $e ) {
		return null;
	}

	return $image instanceof Imagick ? $image : null;
}

/**
 * Remove every profile except the colour profile (icc/icm) from an Imagick
 * image and reset its orientation (any EXIF rotation is already baked in).
 *
 * @param Imagick $image The image, modified in place.
 * @return void
 */
function blueline_remove_imagick_profiles( $image ): void {
	foreach ( array_keys( $image->getImageProfiles( '*', true ) ) as $profile ) {
		if ( ! in_array( $profile, array( 'icc', 'icm' ), true ) ) {
			$image->removeImageProfile( $profile );
		}
	}
	$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
}

/**
 * Fallback: re-read a saved file with Imagick and rewrite it without profiles.
 *
 * @param string $path Absolute path to the image.
 * @return bool
 */
function blueline_strip_imagick_profiles( string $path ): bool {
	try {
		$image = new Imagick( $path );
		blueline_remove_imagick_profiles( $image );
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
 * /account/player-profile/, which now carries the same capability.
 *
 * Deliberately separate from the legacy map in account-endpoints.php: that map only renames
 * WooCommerce's own slugs, and 'profile-picture' belongs to a third-party plugin.
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

add_filter( 'woocommerce_account_menu_items', 'blueline_remove_profile_picture_menu_item', 99 );
/**
 * Drop sportspress-player-tools' "Profile Picture" tab from the account menu: the redirect above
 * sends it to Player Profile, so it was a second link to the same page. Runs after the plugin
 * adds it (its filter uses the default priority).
 *
 * @param mixed $items Account menu items, endpoint => label.
 * @return mixed
 */
function blueline_remove_profile_picture_menu_item( $items ) {
	if ( is_array( $items ) ) {
		unset( $items['profile-picture'] );
	}

	return $items;
}
