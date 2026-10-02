<?php
/**
 * Member privacy: stop core from publishing the site's user list.
 *
 * Members author their own sp_player posts, so core's REST users endpoint,
 * the users sitemap, author archives and oEmbed author fields would
 * otherwise list every member's real name and email-derived slug to
 * anonymous visitors.
 *
 * @package blueline
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
