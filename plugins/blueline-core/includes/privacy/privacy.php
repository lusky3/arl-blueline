<?php
/**
 * Member privacy: stop core from publishing the site's user list.
 *
 * Members author their own sp_player posts, so core's REST users endpoint,
 * the users sitemap, author archives and oEmbed author fields would
 * otherwise list every member's real name and email-derived slug to
 * anonymous visitors.
 *
 * Moved from themes/blueline/inc/privacy.php. This hardening only applies
 * while the blueline-core plugin is active: deactivating the plugin
 * re-exposes the user list.
 *
 * The second half of the file registers WordPress personal-data exporters
 * and erasers (Tools > Export / Erase Personal Data) for the data this
 * plugin owns about a user: the custom avatar (user meta
 * `blueline_avatar_id` and its media file) and the player link (post meta
 * `sp_user` on the member's sp_player post, plus a flagged player photo).
 * Core's own export and erase know nothing about either.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current user may browse other users: anyone who can edit
 * posts (the block editor's author picker needs the users endpoint) or
 * list users.
 *
 * @return bool
 */
function blueline_can_view_user_directory(): bool {
	return current_user_can( 'list_users' ) || current_user_can( 'edit_posts' );
}

add_filter( 'rest_endpoints', 'blueline_restrict_user_rest_endpoints' );
/**
 * Remove the users collection and single-user REST routes for visitors who
 * may not browse users. /wp/v2/users/me (the caller's own record) is kept.
 *
 * @param array $endpoints Registered REST routes.
 * @return array
 */
function blueline_restrict_user_rest_endpoints( $endpoints ) {
	if ( ! is_array( $endpoints ) || blueline_can_view_user_directory() ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}

add_filter( 'wp_sitemaps_add_provider', 'blueline_remove_users_sitemap_provider', 10, 2 );
/**
 * Drop core's users sitemap (one /author/<slug> URL per member).
 *
 * @param WP_Sitemaps_Provider|false $provider Sitemap provider instance.
 * @param string                     $name     Provider name.
 * @return WP_Sitemaps_Provider|false
 */
function blueline_remove_users_sitemap_provider( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}

add_action( 'template_redirect', 'blueline_block_author_archives', 1 );
/**
 * 404 author archives (/author/<slug> and ?author=N) for visitors who may
 * not browse users. Priority 1 runs before redirect_canonical() would turn
 * ?author=N into the slug URL.
 *
 * @return void
 */
function blueline_block_author_archives(): void {
	global $wp_query;

	if ( ! is_author() || blueline_can_view_user_directory() ) {
		return;
	}

	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}

add_filter( 'oembed_response_data', 'blueline_strip_oembed_author' );
/**
 * Remove the author's name and archive URL from oEmbed responses, which
 * third parties fetch anonymously for any post.
 *
 * @param array $data oEmbed response data.
 * @return array
 */
function blueline_strip_oembed_author( $data ) {
	unset( $data['author_name'], $data['author_url'] );

	return $data;
}

/**
 * User meta key of the custom avatar pointer (avatars module). Resolved at
 * call time with a literal fallback, because the avatars module can be
 * switched off through the `blueline_core_modules` filter while this one
 * stays on, and the pointer would still need exporting and erasing.
 *
 * @return string
 */
function blueline_privacy_avatar_meta_key(): string {
	return defined( 'BLUELINE_AVATAR_META_KEY' ) ? (string) constant( 'BLUELINE_AVATAR_META_KEY' ) : 'blueline_avatar_id';
}

/**
 * Post meta key linking an sp_player post to its user (player-link module).
 *
 * @return string
 */
function blueline_privacy_player_user_meta_key(): string {
	return defined( 'BLUELINE_PLAYER_USER_META' ) ? (string) constant( 'BLUELINE_PLAYER_USER_META' ) : 'sp_user';
}

/**
 * Attachment meta key flagging a photo uploaded through the player-photo
 * handler (player-photo module).
 *
 * @return string
 */
function blueline_privacy_player_photo_flag_key(): string {
	return defined( 'BLUELINE_PLAYER_PHOTO_FLAG_META' ) ? (string) constant( 'BLUELINE_PLAYER_PHOTO_FLAG_META' ) : '_blueline_player_photo';
}

/**
 * The user ID behind an email address in a privacy request.
 *
 * @param mixed $email_address Email address from the request.
 * @return int User ID, or 0 for an invalid or unknown address.
 */
function blueline_privacy_user_id_for_email( $email_address ): int {
	if ( ! is_string( $email_address ) || ! is_email( $email_address ) ) {
		return 0;
	}

	$user = get_user_by( 'email', $email_address );

	return $user ? absint( $user->ID ) : 0;
}

/**
 * The attachment a user's avatar pointer references, when it really is one.
 *
 * @param int $user_id User ID.
 * @return int Attachment ID, or 0 for no pointer, a stale pointer or a pointer at a non-attachment.
 */
function blueline_privacy_avatar_attachment_id( int $user_id ): int {
	$attachment_id = absint( get_user_meta( $user_id, blueline_privacy_avatar_meta_key(), true ) );

	return ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) ? $attachment_id : 0;
}

/**
 * The sp_player posts linked to a user through sp_user. Normally one; every
 * link is returned so a duplicated link is exported and erased too.
 *
 * Not blueline_get_linked_player_id(): that returns one ID from a request
 * cache, which is wrong for a request that has to find and then remove all
 * of them.
 *
 * @param int $user_id User ID.
 * @return int[] Player post IDs, ascending.
 */
function blueline_privacy_linked_player_ids( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'      => 'sp_player',
			'post_status'    => 'any',
			'meta_key'       => blueline_privacy_player_user_meta_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a rare, admin-triggered privacy request, not a request-time query.
			'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Whether a post other than $ignore_post_id uses $attachment_id as its
 * featured image. Fails closed: a failed lookup counts as "in use".
 *
 * @param int $attachment_id  Attachment ID.
 * @param int $ignore_post_id Post whose use of the attachment does not count.
 * @return bool
 */
function blueline_privacy_attachment_is_thumbnail_elsewhere( int $attachment_id, int $ignore_post_id ): bool {
	global $wpdb;

	if ( ! is_object( $wpdb ) ) {
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed postmeta lookup across every post type and status (get_posts() cannot cover both); must not be cached because it gates a delete.
	$other_post = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id != %d LIMIT 1",
			(string) $attachment_id,
			$ignore_post_id
		)
	);

	return null !== $other_post || '' !== (string) $wpdb->last_error;
}

/**
 * Whether another user's avatar pointer (ours or YITH's) references the attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @param int $user_id       The requesting user, excluded from the check.
 * @return bool
 */
function blueline_privacy_attachment_is_avatar_of_other_user( int $attachment_id, int $user_id ): bool {
	foreach ( array( blueline_privacy_avatar_meta_key(), 'yith-wcmap-avatar' ) as $meta_key ) {
		$others = get_users(
			array(
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a rare, admin-triggered privacy request, not a request-time query.
				'meta_value' => (string) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'exclude'    => array( $user_id ),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( ! empty( $others ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an attachment may be deleted for $user_id's erasure request: it
 * must exist, belong to that user (post_author) and not be used elsewhere.
 * Anything else is the league's or another member's data and stays.
 *
 * @param int $attachment_id  Attachment ID.
 * @param int $user_id        The requesting user.
 * @param int $ignore_post_id Post whose featured-image use does not count (the user's own player post, or 0).
 * @return bool
 */
function blueline_privacy_attachment_is_deletable( int $attachment_id, int $user_id, int $ignore_post_id ): bool {
	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		return false;
	}

	if ( (int) get_post_field( 'post_author', $attachment_id ) !== $user_id ) {
		return false;
	}

	return ! blueline_privacy_attachment_is_thumbnail_elsewhere( $attachment_id, $ignore_post_id )
		&& ! blueline_privacy_attachment_is_avatar_of_other_user( $attachment_id, $user_id );
}

/**
 * A fresh eraser response in the shape core expects.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function blueline_privacy_empty_erasure(): array {
	return array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

add_filter( 'wp_privacy_personal_data_exporters', 'blueline_register_privacy_exporters' );
/**
 * Register the avatar and player-link exporters.
 *
 * @param array $exporters Registered exporters.
 * @return array
 */
function blueline_register_privacy_exporters( $exporters ) {
	$exporters = is_array( $exporters ) ? $exporters : array();

	$exporters['blueline-core-avatar'] = array(
		'exporter_friendly_name' => __( 'Profile avatar', 'blueline-core' ),
		'callback'               => 'blueline_export_avatar_personal_data',
	);
	$exporters['blueline-core-player'] = array(
		'exporter_friendly_name' => __( 'Player profile link', 'blueline-core' ),
		'callback'               => 'blueline_export_player_personal_data',
	);

	return $exporters;
}

add_filter( 'wp_privacy_personal_data_erasers', 'blueline_register_privacy_erasers' );
/**
 * Register the avatar and player-link erasers.
 *
 * @param array $erasers Registered erasers.
 * @return array
 */
function blueline_register_privacy_erasers( $erasers ) {
	$erasers = is_array( $erasers ) ? $erasers : array();

	$erasers['blueline-core-avatar'] = array(
		'eraser_friendly_name' => __( 'Profile avatar', 'blueline-core' ),
		'callback'             => 'blueline_erase_avatar_personal_data',
	);
	$erasers['blueline-core-player'] = array(
		'eraser_friendly_name' => __( 'Player profile link', 'blueline-core' ),
		'callback'             => 'blueline_erase_player_personal_data',
	);

	return $erasers;
}

/**
 * Personal-data exporter: the user's custom avatar (pointer and image URL).
 * Single page.
 *
 * @param string $email_address Email address of the requester.
 * @param int    $page          Page number (unused: the data fits one page).
 * @return array{data: array, done: bool}
 */
function blueline_export_avatar_personal_data( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- exporter callback signature; single page.
	$export  = array(
		'data' => array(),
		'done' => true,
	);
	$user_id = blueline_privacy_user_id_for_email( $email_address );
	if ( ! $user_id ) {
		return $export;
	}

	$attachment_id = blueline_privacy_avatar_attachment_id( $user_id );
	if ( ! $attachment_id ) {
		return $export;
	}

	$fields = array(
		array(
			'name'  => __( 'Avatar attachment ID', 'blueline-core' ),
			'value' => (string) $attachment_id,
		),
	);
	$url    = wp_get_attachment_url( $attachment_id );
	if ( is_string( $url ) && '' !== $url ) {
		$fields[] = array(
			'name'  => __( 'Avatar image URL', 'blueline-core' ),
			'value' => $url,
		);
	}

	$export['data'][] = array(
		'group_id'          => 'blueline-avatar',
		'group_label'       => __( 'Profile avatar', 'blueline-core' ),
		'group_description' => __( 'The custom profile picture uploaded for this account.', 'blueline-core' ),
		'item_id'           => 'blueline-avatar-' . $user_id,
		'data'              => $fields,
	);

	return $export;
}

/**
 * Personal-data eraser: removes the avatar pointer and, when it is safe, the
 * image itself. The attachment is deleted only if the requesting user is its
 * author and nothing else (another post's featured image, another user's
 * avatar) uses it; otherwise it is reported as retained. Single page.
 *
 * @param string $email_address Email address of the requester.
 * @param int    $page          Page number (unused: the data fits one page).
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function blueline_erase_avatar_personal_data( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- eraser callback signature; single page.
	$response = blueline_privacy_empty_erasure();
	$user_id  = blueline_privacy_user_id_for_email( $email_address );
	if ( ! $user_id ) {
		return $response;
	}

	$meta_key = blueline_privacy_avatar_meta_key();
	$raw      = get_user_meta( $user_id, $meta_key, true );
	if ( '' === (string) $raw ) {
		return $response;
	}

	$attachment_id = absint( $raw );
	$post_type     = $attachment_id ? get_post_type( $attachment_id ) : false;

	if ( delete_user_meta( $user_id, $meta_key ) ) {
		$response['items_removed'] = true;
	} else {
		$response['items_retained'] = true;
		$response['messages'][]     = __( 'The link to the profile avatar could not be removed.', 'blueline-core' );
	}

	if ( 'attachment' === $post_type ) {
		// The pointer is already gone, so "another user's avatar" can no longer match this user.
		if ( blueline_privacy_attachment_is_deletable( $attachment_id, $user_id, 0 ) && wp_delete_attachment( $attachment_id, true ) ) {
			$response['items_removed'] = true;
		} else {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'The profile avatar image was kept because it was not uploaded by this user or is still used elsewhere on the site.', 'blueline-core' );
		}
	} elseif ( false !== $post_type ) {
		// Never delete a post that is not a media file, whatever the pointer says.
		$response['messages'][] = __( 'The profile avatar link pointed at something other than a media file; only the link was removed.', 'blueline-core' );
	}

	return $response;
}

/**
 * The photo of a player that this user uploaded through the player-photo
 * handler: the player's featured image, only when it carries the handler's
 * flag and its author is the user. League-supplied and legacy photos are not
 * the user's to export or erase through this request.
 *
 * @param int $player_id sp_player post ID.
 * @param int $user_id   The requesting user.
 * @return int Attachment ID, or 0.
 */
function blueline_privacy_player_photo_id( int $player_id, int $user_id ): int {
	$photo_id = (int) get_post_thumbnail_id( $player_id );

	if ( $photo_id <= 0 || 'attachment' !== get_post_type( $photo_id ) ) {
		return 0;
	}

	if ( '1' !== (string) get_post_meta( $photo_id, blueline_privacy_player_photo_flag_key(), true ) ) {
		return 0;
	}

	return (int) get_post_field( 'post_author', $photo_id ) === $user_id ? $photo_id : 0;
}

/**
 * Personal-data exporter: the player record linked to the user (sp_user) and
 * the player photo the user uploaded. Single page.
 *
 * @param string $email_address Email address of the requester.
 * @param int    $page          Page number (unused: the data fits one page).
 * @return array{data: array, done: bool}
 */
function blueline_export_player_personal_data( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- exporter callback signature; single page.
	$export  = array(
		'data' => array(),
		'done' => true,
	);
	$user_id = blueline_privacy_user_id_for_email( $email_address );
	if ( ! $user_id ) {
		return $export;
	}

	foreach ( blueline_privacy_linked_player_ids( $user_id ) as $player_id ) {
		$fields = array(
			array(
				'name'  => __( 'Player ID', 'blueline-core' ),
				'value' => (string) $player_id,
			),
			array(
				'name'  => __( 'Player name', 'blueline-core' ),
				'value' => (string) get_the_title( $player_id ),
			),
		);

		$permalink = get_permalink( $player_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$fields[] = array(
				'name'  => __( 'Player page', 'blueline-core' ),
				'value' => $permalink,
			);
		}

		$photo_id = blueline_privacy_player_photo_id( $player_id, $user_id );
		if ( $photo_id ) {
			$photo_url = wp_get_attachment_url( $photo_id );
			if ( is_string( $photo_url ) && '' !== $photo_url ) {
				$fields[] = array(
					'name'  => __( 'Player photo URL', 'blueline-core' ),
					'value' => $photo_url,
				);
			}
		}

		$export['data'][] = array(
			'group_id'          => 'blueline-player',
			'group_label'       => __( 'Player profile', 'blueline-core' ),
			'group_description' => __( 'The league player record linked to this account.', 'blueline-core' ),
			'item_id'           => 'blueline-player-' . $player_id,
			'data'              => $fields,
		);
	}

	return $export;
}

/**
 * Personal-data eraser for the player link. Deliberately NOT a deletion of
 * the league's player record: standings, game logs and statistics belong to
 * the league. It removes only the sp_user link between the account and the
 * player (and the user's own uploaded photo, when safe), and reports the
 * player record as retained. Single page.
 *
 * @param string $email_address Email address of the requester.
 * @param int    $page          Page number (unused: the data fits one page).
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function blueline_erase_player_personal_data( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- eraser callback signature; single page.
	$response = blueline_privacy_empty_erasure();
	$user_id  = blueline_privacy_user_id_for_email( $email_address );
	if ( ! $user_id ) {
		return $response;
	}

	$player_ids = blueline_privacy_linked_player_ids( $user_id );
	if ( ! $player_ids ) {
		return $response;
	}

	foreach ( $player_ids as $player_id ) {
		$title = (string) get_the_title( $player_id );

		$photo_id = blueline_privacy_player_photo_id( $player_id, $user_id );
		if ( $photo_id ) {
			if ( blueline_privacy_attachment_is_deletable( $photo_id, $user_id, $player_id ) && wp_delete_attachment( $photo_id, true ) ) {
				$response['items_removed'] = true;
			} else {
				$response['messages'][] = __( 'The player photo was kept because it is still used elsewhere on the site.', 'blueline-core' );
			}
		}

		if ( delete_post_meta( $player_id, blueline_privacy_player_user_meta_key(), (string) $user_id ) ) {
			$response['items_removed'] = true;
		} else {
			$response['messages'][] = sprintf(
				/* translators: %s: player name. */
				__( 'The link between this account and the player record "%s" could not be removed.', 'blueline-core' ),
				$title
			);
		}

		// The league's record and its statistics are kept either way.
		$response['items_retained'] = true;
		$response['messages'][]     = sprintf(
			/* translators: %s: player name. */
			__( 'The league player record "%s" and its statistics were kept, because game and standings history belong to the league. Only the link to this account was removed.', 'blueline-core' ),
			$title
		);
	}

	if ( function_exists( 'blueline_forget_linked_player_cache' ) ) {
		blueline_forget_linked_player_cache( $user_id );
	}

	return $response;
}
