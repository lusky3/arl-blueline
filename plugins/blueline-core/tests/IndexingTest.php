<?php
/**
 * Unit tests for includes/indexing/indexing.php: the noindex flag, sitemap exclusions,
 * robots.txt and the editor checkbox.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/indexing/indexing.php';

/**
 * Covers the indexing module.
 */
final class IndexingTest extends TestCase {

	/**
	 * The get_posts() model saved in setUp() and restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_model;

	/**
	 * Fresh state and a get_posts() model for the sitemap exclusion queries.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		unset( $GLOBALS['bl_core_test_wc_page_ids'], $GLOBALS['bl_core_test_meta_boxes'], $GLOBALS['bl_core_test_revision_ids'], $GLOBALS['bl_core_test_sitemaps_enabled'] );
		$_POST = array();

		$this->previous_model               = $GLOBALS['bl_test_get_posts_model'] ?? null;
		$GLOBALS['bl_test_get_posts_model'] = array( $this, 'model_get_posts' );

		$GLOBALS['wpdb']               = new Blueline_Core_Test_Wpdb();
		$GLOBALS['wpdb']->col_callback = 'blueline_core_test_flagged_post_ids';
	}

	/**
	 * Restore globals.
	 */
	protected function tearDown(): void {
		$GLOBALS['bl_test_get_posts_model'] = $this->previous_model;
		unset( $GLOBALS['bl_core_test_wc_page_ids'], $GLOBALS['bl_core_test_meta_boxes'], $GLOBALS['bl_core_test_revision_ids'], $GLOBALS['bl_core_test_sitemaps_enabled'], $GLOBALS['wpdb'] );
		$_POST = array();
	}

	/**
	 * Model of the module's one get_posts() shape over the registered-posts store: the utility
	 * pages' post_name__in lookup. The flag lookup must not come through here: a meta_query that
	 * ORs two postmeta joins took over 5 s on the league's 1.2 million meta rows.
	 *
	 * @param array $args get_posts() args.
	 * @return int[]
	 */
	public function model_get_posts( array $args ): array {
		$this->assertArrayNotHasKey( 'meta_query', $args, 'The flag lookup is one direct postmeta query, not a meta_query.' );

		$state = &blueline_test_state();
		$found = array();

		foreach ( $state['posts'] as $post_id => $post ) {
			if ( ( $post['type'] ?? '' ) !== $args['post_type'] || ( $post['status'] ?? '' ) !== $args['post_status'] ) {
				continue;
			}

			if ( isset( $args['post_name__in'] ) && ! in_array( $post['post_name'] ?? '', $args['post_name__in'], true ) ) {
				continue;
			}

			$found[] = (int) $post_id;
		}

		sort( $found );

		return $found;
	}

	/**
	 * Register a post.
	 *
	 * @param int    $id     Post ID.
	 * @param string $type   Post type.
	 * @param string $slug   post_name.
	 * @param string $status Post status.
	 */
	private function post( int $id, string $type, string $slug, string $status = 'publish' ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'type'      => $type,
			'status'    => $status,
			'post_name' => $slug,
		);
	}

	/**
	 * View $post_id as the current singular request.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Post type.
	 */
	private function view( int $post_id, string $type ): void {
		blueline_test_set_queried_post_type( $type );
		$state                      = &blueline_test_state();
		$state['queried_object_id'] = $post_id;
	}

	/**
	 * What core's own wp_robots callbacks produce on a public site.
	 *
	 * @return array
	 */
	private function core_robots(): array {
		return array(
			'follow'            => true,
			'max-image-preview' => 'large',
		);
	}

	/**
	 * A flagged page gets noindex, nofollow, and core's "follow" is replaced, not contradicted.
	 */
	public function test_flagged_page_is_noindex_nofollow(): void {
		$this->post( 10, 'page', 'hidden-returning-player-signup' );
		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );
		$this->view( 10, 'page' );

		$this->assertSame(
			array(
				'max-image-preview' => 'large',
				'noindex'           => true,
				'nofollow'          => true,
			),
			blueline_indexing_wp_robots( $this->core_robots() )
		);
	}

	/**
	 * An unflagged page, a flag of anything but '1', and non-singular views are left alone.
	 */
	public function test_unflagged_and_non_singular_views_are_untouched(): void {
		$this->post( 10, 'page', 'about' );
		$this->view( 10, 'page' );
		$this->assertSame( $this->core_robots(), blueline_indexing_wp_robots( $this->core_robots() ) );

		update_post_meta( 10, BLUELINE_NOINDEX_META, '0' );
		$this->assertSame( $this->core_robots(), blueline_indexing_wp_robots( $this->core_robots() ) );

		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );
		$this->view( 10, '' );
		$this->assertSame( $this->core_robots(), blueline_indexing_wp_robots( $this->core_robots() ) );
	}

	/**
	 * Utility pages are noindexed too, so the sitemap and the page agree; a post that happens to
	 * share a utility slug is not.
	 */
	public function test_utility_pages_are_noindexed(): void {
		$this->post( 20, 'page', 'lostpassword' );
		$this->view( 20, 'page' );
		$this->assertTrue( blueline_indexing_wp_robots( array() )['noindex'] );

		$this->post( 21, 'post', 'logout' );
		$this->view( 21, 'post' );
		$this->assertSame( array(), blueline_indexing_wp_robots( array() ) );
	}

	/**
	 * The utility slug list is filterable and sanitised.
	 */
	public function test_utility_slugs_are_filterable(): void {
		add_filter(
			'blueline_indexing_utility_page_slugs',
			static function ( $slugs ) {
				$slugs[] = 'Members Only';
				return $slugs;
			}
		);

		$this->assertContains( 'members-only', blueline_indexing_utility_page_slugs() );
		$this->assertContains( 'logout', blueline_indexing_utility_page_slugs() );
	}

	/**
	 * The page sitemap leaves out flagged pages, Page Links To pages, WooCommerce's cart, checkout
	 * and account pages, and utility pages, and keeps an exclusion another plugin already set.
	 */
	public function test_page_sitemap_excludes_noindexed_redirecting_shop_and_utility_pages(): void {
		$this->post( 10, 'page', 'hidden-returning-player-signup' );
		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );
		$this->post( 11, 'page', 'register' );
		update_post_meta( 11, BLUELINE_LINKS_TO_META, '/register' );
		$this->post( 12, 'page', 'photos' );
		update_post_meta( 12, BLUELINE_LINKS_TO_META, 'https://www.flickr.com/photos/arl#new_tab' );
		$this->post( 13, 'page', 'cart' );
		$this->post( 14, 'page', 'checkout' );
		$this->post( 15, 'page', 'account' );
		$this->post( 16, 'page', 'store-manager' );
		$this->post( 17, 'page', 'standings-new' );
		$this->post( 18, 'page', 'standings' );
		$this->post( 19, 'page', 'about' );
		update_post_meta( 19, BLUELINE_NOINDEX_META, '0' );
		$this->post( 30, 'page', 'old-draft', 'draft' );
		update_post_meta( 30, BLUELINE_NOINDEX_META, '1' );

		$GLOBALS['bl_core_test_wc_page_ids'] = array(
			'cart'      => 13,
			'checkout'  => 14,
			'myaccount' => 15,
			'shop'      => 99,
		);

		$args = blueline_indexing_sitemap_query_args(
			array(
				'post_type'    => 'page',
				'post__not_in' => array( 500 ),
			),
			'page'
		);

		$this->assertSame( array( 500, 10, 11, 12, 13, 14, 15, 16, 17 ), $args['post__not_in'] );
		$this->assertSame( 'page', $args['post_type'] );
	}

	/**
	 * Other post types only drop flagged and Page Links To posts; a page-only rule (WooCommerce,
	 * utility slugs) never reaches them, and with nothing to drop the args are unchanged.
	 */
	public function test_other_post_type_sitemaps_only_drop_flagged_and_redirecting_posts(): void {
		$this->post( 40, 'post', 'logout' );
		$this->post( 41, 'post', 'news' );
		update_post_meta( 41, BLUELINE_NOINDEX_META, '1' );
		$this->post( 42, 'post', 'elsewhere' );
		update_post_meta( 42, BLUELINE_LINKS_TO_META, '#' );
		$GLOBALS['bl_core_test_wc_page_ids'] = array( 'cart' => 40 );

		$this->assertSame( array( 41, 42 ), blueline_indexing_sitemap_query_args( array(), 'post' )['post__not_in'] );

		$this->assertSame( array( 'post_type' => 'sp_team' ), blueline_indexing_sitemap_query_args( array( 'post_type' => 'sp_team' ), 'sp_team' ) );
		$this->assertFalse( blueline_indexing_sitemap_query_args( false, 'page' ) );
	}

	/**
	 * Exactly the old meta_query's posts: a noindex flag counts only when it is '1', a Page Links To
	 * target counts whatever it holds (even empty), several meta rows never list a post twice, and
	 * drafts and other post types are not returned.
	 */
	public function test_flagged_ids_match_the_noindex_and_page_links_to_rules(): void {
		$this->post( 50, 'sp_event', 'game-1' );
		update_post_meta( 50, BLUELINE_NOINDEX_META, '1' );
		update_post_meta( 50, BLUELINE_LINKS_TO_META, '/schedule' );
		$this->post( 51, 'sp_event', 'game-2' );
		update_post_meta( 51, BLUELINE_LINKS_TO_META, '' );
		$this->post( 52, 'sp_event', 'game-3' );
		update_post_meta( 52, BLUELINE_NOINDEX_META, 'yes' );
		$this->post( 53, 'sp_event', 'game-4' );
		update_post_meta( 53, BLUELINE_NOINDEX_META, new Blueline_Test_Meta_Rows( array( '0', '1' ) ) );
		$this->post( 54, 'sp_event', 'game-5', 'draft' );
		update_post_meta( 54, BLUELINE_NOINDEX_META, '1' );
		$this->post( 55, 'sp_player', 'someone' );
		update_post_meta( 55, BLUELINE_NOINDEX_META, '1' );
		$this->post( 56, 'sp_event', 'game-6' );
		update_post_meta( 56, 'sp_status', '1' );

		$this->assertSame( array( 50, 51, 53 ), blueline_indexing_sitemap_excluded_ids( 'sp_event' ) );
		$this->assertSame( array( 'sp_event', 'publish', BLUELINE_NOINDEX_META, BLUELINE_LINKS_TO_META, BLUELINE_LINKS_TO_META, '1' ), $GLOBALS['wpdb']->prepared_args );
	}

	/**
	 * One query per sitemap build, never a cached answer: a flag set or cleared between two builds
	 * shows in the next one at once, so there is nothing to invalidate.
	 */
	public function test_each_build_runs_one_query_and_sees_flag_changes_at_once(): void {
		$this->post( 60, 'post', 'news-1' );
		$this->post( 61, 'post', 'news-2' );

		$this->assertSame( array(), blueline_indexing_sitemap_excluded_ids( 'post' ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->col_queries );

		update_post_meta( 61, BLUELINE_NOINDEX_META, '1' );
		$this->assertSame( array( 61 ), blueline_indexing_sitemap_query_args( array(), 'post' )['post__not_in'] );

		update_post_meta( 60, BLUELINE_LINKS_TO_META, 'https://example.org/' );
		$this->assertSame( array( 60, 61 ), blueline_indexing_sitemap_excluded_ids( 'post' ) );

		blueline_test_reset_state();
		$this->post( 60, 'post', 'news-1' );
		$this->assertSame( array(), blueline_indexing_sitemap_excluded_ids( 'post' ) );
		$this->assertCount( 4, $GLOBALS['wpdb']->col_queries );
	}

	/**
	 * The robots.txt body gains the sitemap line on a public site that lacks one.
	 */
	public function test_robots_txt_names_the_sitemap_on_a_public_site(): void {
		$body = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		$this->assertSame(
			"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.test/wp-sitemap.xml\n",
			blueline_indexing_robots_txt( $body, '1' )
		);
	}

	/**
	 * No line on a site that discourages search engines or has sitemaps off, and never a second
	 * line when core (or anyone) already added one.
	 */
	public function test_robots_txt_is_left_alone_when_private_disabled_or_already_listed(): void {
		$body = "User-agent: *\nDisallow: /wp-admin/\n";

		$this->assertSame( $body, blueline_indexing_robots_txt( $body, '0' ) );
		$this->assertSame( $body, blueline_indexing_robots_txt( $body, 0 ) );

		$listed = $body . "\nSitemap: https://example.test/wp-sitemap.xml\n";
		$this->assertSame( $listed, blueline_indexing_robots_txt( $listed, '1' ) );
		$this->assertSame( $body . "sitemap:https://example.test/other.xml\n", blueline_indexing_robots_txt( $body . "sitemap:https://example.test/other.xml\n", '1' ) );

		$GLOBALS['bl_core_test_sitemaps_enabled'] = false;
		$this->assertSame( $body, blueline_indexing_robots_txt( $body, '1' ) );
	}

	/**
	 * The checkbox is registered on pages and posts and reflects the stored flag.
	 */
	public function test_meta_box_is_registered_and_renders_the_flag(): void {
		blueline_indexing_add_meta_box();
		$this->assertSame( array( 'page', 'post' ), array_column( $GLOBALS['bl_core_test_meta_boxes'], 'screen' ) );
		$this->assertSame( 'side', $GLOBALS['bl_core_test_meta_boxes'][0]['context'] );

		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );

		ob_start();
		blueline_indexing_render_meta_box( (object) array( 'ID' => 10 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="blueline_noindex_nonce" value="' . wp_create_nonce( 'blueline_noindex_10' ) . '"', $html );
		$this->assertMatchesRegularExpression( '/name="blueline_noindex" value="1"\s*checked/', $html );

		ob_start();
		blueline_indexing_render_meta_box( (object) array( 'ID' => 11 ) );
		$this->assertStringNotContainsString( 'checked', (string) ob_get_clean() );
	}

	/**
	 * A valid save from someone who can edit the post sets and clears the flag.
	 */
	public function test_save_sets_and_clears_the_flag(): void {
		$this->grant_edit();
		$_POST = array(
			'blueline_noindex_nonce' => wp_create_nonce( 'blueline_noindex_10' ),
			'blueline_noindex'       => '1',
		);

		blueline_indexing_save_meta_box( 10 );
		$this->assertTrue( blueline_post_is_noindexed( 10 ) );

		unset( $_POST['blueline_noindex'] );
		blueline_indexing_save_meta_box( 10 );
		$this->assertFalse( blueline_post_is_noindexed( 10 ) );
	}

	/**
	 * No nonce (REST, quick edit), a nonce for another post, no edit rights or a revision: the
	 * flag is not touched.
	 */
	public function test_save_ignores_requests_it_should_not_trust(): void {
		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );

		$this->grant_edit();
		$_POST = array();
		blueline_indexing_save_meta_box( 10 );
		$this->assertTrue( blueline_post_is_noindexed( 10 ), 'no nonce' );

		$_POST = array( 'blueline_noindex_nonce' => wp_create_nonce( 'blueline_noindex_11' ) );
		blueline_indexing_save_meta_box( 10 );
		$this->assertTrue( blueline_post_is_noindexed( 10 ), 'nonce for another post' );

		$_POST                                = array( 'blueline_noindex_nonce' => wp_create_nonce( 'blueline_noindex_10' ) );
		$GLOBALS['bl_core_test_revision_ids'] = array( 10 );
		blueline_indexing_save_meta_box( 10 );
		$this->assertTrue( blueline_post_is_noindexed( 10 ), 'revision' );

		unset( $GLOBALS['bl_core_test_revision_ids'] );
		blueline_test_reset_state();
		update_post_meta( 10, BLUELINE_NOINDEX_META, '1' );
		blueline_indexing_save_meta_box( 10 );
		$this->assertTrue( blueline_post_is_noindexed( 10 ), 'no edit rights' );
	}

	/**
	 * The module's hooks are registered.
	 */
	public function test_hooks_are_registered(): void {
		$this->assertSame( 20, has_filter( 'wp_robots', 'blueline_indexing_wp_robots' ) );
		$this->assertSame( 10, has_filter( 'wp_sitemaps_posts_query_args', 'blueline_indexing_sitemap_query_args' ) );
		$this->assertSame( 100, has_filter( 'robots_txt', 'blueline_indexing_robots_txt' ) );
		$this->assertSame( 10, has_action( 'add_meta_boxes', 'blueline_indexing_add_meta_box' ) );
		$this->assertSame( 10, has_action( 'save_post', 'blueline_indexing_save_meta_box' ) );
	}

	/**
	 * Let the current user edit posts.
	 */
	private function grant_edit(): void {
		$state                      = &blueline_test_state();
		$state['caps']['edit_post'] = true;
	}
}
