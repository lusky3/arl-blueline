<?php
/**
 * Search-engine indexing: a per-post "hide from search engines" flag, a core XML sitemap that
 * lists only canonical, indexable pages (and, through archival.php, current-season content),
 * and the sitemap's address in robots.txt.
 *
 * Every filter here adds to what core and other plugins already set; nothing turns a page
 * someone else marked noindex back into an indexable one.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Archival SportsPress pages: indexable, but left out of the sitemap (see the file's header).
require_once __DIR__ . '/archival.php';

/**
 * Post meta flag: '1' means noindex, nofollow and out of the XML sitemap. Protected (leading
 * underscore), so it is set through the editor checkbox below or WP-CLI, never Custom Fields.
 */
const BLUELINE_NOINDEX_META = '_blueline_noindex';

/**
 * Page Links To's target-URL meta. A post carrying it has its permalink rewritten to that
 * target ("#", a relative path, or another site), which is never a valid sitemap URL.
 */
const BLUELINE_LINKS_TO_META = '_links_to';

/**
 * Whether a post carries the noindex flag.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function blueline_post_is_noindexed( int $post_id ): bool {
	return $post_id > 0 && '1' === (string) get_post_meta( $post_id, BLUELINE_NOINDEX_META, true );
}

/**
 * Slugs of pages that are tools, not content (account flows, vendor back office, duplicates).
 * They are noindexed and left out of the sitemap.
 *
 * @return string[]
 */
function blueline_indexing_utility_page_slugs(): array {
	/**
	 * Filters the slugs of utility pages kept out of search results and the XML sitemap.
	 *
	 * @param string[] $slugs Page slugs (post_name).
	 */
	$slugs = apply_filters(
		'blueline_indexing_utility_page_slugs',
		array( 'logout', 'lostpassword', 'store-manager', 'vendor-register', 'vendor-membership', 'league-dashboard', 'standings-new' )
	);

	return array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) ) );
}

/**
 * Whether a post is one of the utility pages.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function blueline_is_utility_page( int $post_id ): bool {
	return $post_id > 0
		&& 'page' === get_post_type( $post_id )
		&& in_array( (string) get_post_field( 'post_name', $post_id ), blueline_indexing_utility_page_slugs(), true );
}

add_filter( 'wp_robots', 'blueline_indexing_wp_robots', 20 );
/**
 * Add noindex, nofollow to a flagged post or a utility page. Runs after core's own directives
 * (priority 10) so a `follow` core added for a public site is replaced, not contradicted.
 *
 * @param mixed $robots Robots directives, name => value.
 * @return mixed
 */
function blueline_indexing_wp_robots( $robots ) {
	if ( ! is_array( $robots ) || ! is_singular() ) {
		return $robots;
	}

	$post_id = (int) get_queried_object_id();

	if ( ! blueline_post_is_noindexed( $post_id ) && ! blueline_is_utility_page( $post_id ) ) {
		return $robots;
	}

	unset( $robots['follow'] );
	$robots['noindex']  = true;
	$robots['nofollow'] = true;

	return $robots;
}

/**
 * The WooCommerce cart, checkout and account pages. WooCommerce noindexes them itself, so
 * listing them in the sitemap contradicts that.
 *
 * @return int[]
 */
function blueline_indexing_woocommerce_page_ids(): array {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return array();
	}

	$ids = array();
	foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
		$id = (int) wc_get_page_id( $page );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * Published posts of $post_type flagged noindex ('1') or carrying a Page Links To target (any
 * value).
 *
 * One pass over the postmeta meta_key index, which holds a few dozen rows for these two keys. The
 * equivalent WP_Query meta_query (an OR across two postmeta joins) makes the database pair every
 * meta row of every post of the type: on the league's 1.2 million meta rows it took over 5 s for
 * the event sitemap, on every sitemap request. This takes under a millisecond, so it is not cached
 * and a flag change shows in the next sitemap at once.
 *
 * @param string $post_type Post type.
 * @return int[] Post IDs, ascending.
 */
function blueline_indexing_flagged_post_ids( string $post_type ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the docblock: the meta_query form is the slow path; this is fast enough to run uncached.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = %s AND p.post_status = %s
				AND pm.meta_key IN ( %s, %s )
				AND ( pm.meta_key = %s OR pm.meta_value = %s )
			ORDER BY pm.post_id",
			$post_type,
			'publish',
			BLUELINE_NOINDEX_META,
			BLUELINE_LINKS_TO_META,
			BLUELINE_LINKS_TO_META,
			'1'
		)
	);

	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Published posts of $post_type that must not be in the sitemap: flagged noindex, redirected by
 * Page Links To, and (for pages) the WooCommerce and utility pages.
 *
 * @param string $post_type Post type of the sitemap being built.
 * @return int[] Post IDs, ascending.
 */
function blueline_indexing_sitemap_excluded_ids( string $post_type ): array {
	$ids = blueline_indexing_flagged_post_ids( $post_type );

	if ( 'page' === $post_type ) {
		$ids = array_merge( $ids, blueline_indexing_woocommerce_page_ids() );

		$slugs = blueline_indexing_utility_page_slugs();
		if ( $slugs ) {
			$utility = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'post_name__in'  => $slugs,
				)
			);
			$ids     = array_merge( $ids, is_array( $utility ) ? $utility : array() );
		}
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	sort( $ids );

	return $ids;
}

add_filter( 'wp_sitemaps_posts_query_args', 'blueline_indexing_sitemap_query_args', 10, 2 );
/**
 * Keep excluded posts out of core's XML sitemap. Merges into any post__not_in another plugin
 * already set rather than replacing it.
 *
 * @param mixed  $args      WP_Query args for one post-type sitemap.
 * @param string $post_type Post type.
 * @return mixed
 */
function blueline_indexing_sitemap_query_args( $args, $post_type ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	$excluded = blueline_indexing_sitemap_excluded_ids( (string) $post_type );

	if ( ! $excluded ) {
		return $args;
	}

	$existing             = isset( $args['post__not_in'] ) ? array_map( 'intval', (array) $args['post__not_in'] ) : array();
	$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $excluded ) ) );

	return $args;
}

add_filter( 'robots_txt', 'blueline_indexing_robots_txt', 100, 2 );
/**
 * Make sure WordPress's virtual robots.txt names the XML sitemap when the site is public. Core
 * adds the line itself unless something removed it, so this only fills a gap and never adds a
 * second line. A static robots.txt file on the server bypasses WordPress entirely; see
 * docs/OPERATIONS.md.
 *
 * @param mixed      $output    robots.txt body.
 * @param string|int $is_public The blog_public option ('1' public, '0' discouraged).
 * @return string
 */
function blueline_indexing_robots_txt( $output, $is_public ): string {
	$output = (string) $output;

	if ( ! $is_public || ! function_exists( 'wp_sitemaps_get_server' ) || ! function_exists( 'get_sitemap_url' ) ) {
		return $output;
	}

	$server = wp_sitemaps_get_server();
	if ( ! $server || ! $server->sitemaps_enabled() ) {
		return $output;
	}

	if ( preg_match( '/^\s*sitemap\s*:/im', $output ) ) {
		return $output;
	}

	$url = get_sitemap_url( 'index' );
	if ( ! is_string( $url ) || '' === $url ) {
		return $output;
	}

	return rtrim( $output ) . "\n\nSitemap: " . esc_url_raw( $url ) . "\n";
}

/**
 * Post types that get the "Hide from search engines" checkbox.
 *
 * @return string[]
 */
function blueline_indexing_meta_box_post_types(): array {
	/**
	 * Filters the post types that show the "Hide from search engines" checkbox.
	 *
	 * @param string[] $post_types Post type names.
	 */
	return (array) apply_filters( 'blueline_indexing_meta_box_post_types', array( 'page', 'post' ) );
}

add_action( 'add_meta_boxes', 'blueline_indexing_add_meta_box' );
/**
 * Register the "Search engines" box in the editor sidebar.
 *
 * @return void
 */
function blueline_indexing_add_meta_box(): void {
	foreach ( blueline_indexing_meta_box_post_types() as $post_type ) {
		add_meta_box(
			'blueline-noindex',
			__( 'Search engines', 'blueline-core' ),
			'blueline_indexing_render_meta_box',
			$post_type,
			'side',
			'low'
		);
	}
}

/**
 * Render the checkbox.
 *
 * @param WP_Post|object $post The post being edited.
 * @return void
 */
function blueline_indexing_render_meta_box( $post ): void {
	$post_id = (int) ( $post->ID ?? 0 );

	wp_nonce_field( 'blueline_noindex_' . $post_id, 'blueline_noindex_nonce' );

	printf(
		'<label><input type="checkbox" name="blueline_noindex" value="1"%1$s /> %2$s</label><p class="description">%3$s</p>',
		checked( blueline_post_is_noindexed( $post_id ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute string.
		esc_html__( 'Hide from search engines', 'blueline-core' ),
		esc_html__( 'Tells search engines not to list this page and leaves it out of the sitemap. Anyone with the link can still open it.', 'blueline-core' )
	);
}

add_action( 'save_post', 'blueline_indexing_save_meta_box' );
/**
 * Save the checkbox. Only a request that carries the box's own nonce is handled, so REST
 * saves, quick edit and programmatic updates leave the flag alone.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function blueline_indexing_save_meta_box( $post_id ): void {
	$post_id = (int) $post_id;

	if ( ! isset( $_POST['blueline_noindex_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['blueline_noindex_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'blueline_noindex_' . $post_id ) ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! empty( $_POST['blueline_noindex'] ) ) {
		update_post_meta( $post_id, BLUELINE_NOINDEX_META, '1' );
	} else {
		delete_post_meta( $post_id, BLUELINE_NOINDEX_META );
	}
}
