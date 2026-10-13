<?php
/**
 * Unit tests for includes/privacy/privacy.php (user enumeration).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/privacy/privacy.php';

/**
 * Covers the REST, sitemap, author-archive and oEmbed filters.
 */
final class PrivacyTest extends TestCase {

	/**
	 * Fresh state, anonymous visitor.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_is_author']     = false;
		$GLOBALS['bl_test_status_header'] = null;
		$GLOBALS['wp_query']              = new class() {
			/**
			 * Whether set_404() was called.
			 *
			 * @var bool
			 */
			public bool $is_404 = false;

			/**
			 * Record the 404.
			 */
			public function set_404(): void {
				$this->is_404 = true;
			}
		};
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_is_author'], $GLOBALS['bl_test_status_header'], $GLOBALS['wp_query'] );
	}

	/**
	 * Grant a capability to the current (test) user.
	 *
	 * @param string $cap Capability.
	 */
	private function grant( string $cap ): void {
		$state                 = &blueline_test_state();
		$state['caps'][ $cap ] = true;
	}

	/**
	 * A route table shaped like core's users routes plus one unrelated route.
	 *
	 * @return array
	 */
	private function routes(): array {
		return array(
			'/wp/v2/users'               => array( 'list' ),
			'/wp/v2/users/(?P<id>[\d]+)' => array( 'single' ),
			'/wp/v2/users/me'            => array( 'me' ),
			'/wp/v2/posts'               => array( 'posts' ),
		);
	}

	/**
	 * Anonymous visitors lose the users list and single-user routes only.
	 */
	public function test_anonymous_visitor_loses_user_routes(): void {
		$routes = blueline_restrict_user_rest_endpoints( $this->routes() );

		$this->assertArrayNotHasKey( '/wp/v2/users', $routes );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wp/v2/users/me', $routes );
		$this->assertArrayHasKey( '/wp/v2/posts', $routes );
	}

	/**
	 * Every capability SportsPress's Player role holds on the production database.
	 *
	 * @return string[]
	 */
	private function sp_player_caps(): array {
		return array( 'read', 'level_0', 'edit_posts', 'upload_files', 'delete_posts', 'edit_sp_players', 'edit_published_sp_players', 'edit_sp_events', 'edit_published_sp_events', 'edit_sp_teams', 'edit_published_sp_teams' );
	}

	/**
	 * A REST request for $route with optional params.
	 *
	 * @param string $route  Route path.
	 * @param array  $params Request params.
	 * @return WP_REST_Request
	 */
	private function request( string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Admins (list_users) keep every route, and editors (edit_others_posts) keep them registered
	 * so the block editor's author picker can reach them; the dispatch gate narrows editors.
	 */
	public function test_editors_and_admins_keep_user_routes(): void {
		foreach ( array( 'edit_others_posts', 'list_users' ) as $cap ) {
			blueline_test_reset_state();
			$this->grant( $cap );

			$this->assertSame( $this->routes(), blueline_restrict_user_rest_endpoints( $this->routes() ), $cap );
		}
	}

	/**
	 * A Player-role account holds edit_posts, upload_files and the sp_* edit caps, and must still
	 * fail the gate: none of those is a reason to read the member list.
	 */
	public function test_a_user_with_only_the_sp_player_caps_fails_the_gate(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;
		foreach ( $this->sp_player_caps() as $cap ) {
			$this->grant( $cap );
		}

		$this->assertFalse( blueline_can_view_user_directory() );
		$this->assertFalse( blueline_can_see_post_authors() );

		$routes = blueline_restrict_user_rest_endpoints( $this->routes() );
		$this->assertArrayNotHasKey( '/wp/v2/users', $routes );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wp/v2/users/me', $routes );

		$GLOBALS['bl_test_is_author'] = true;
		blueline_block_author_archives();
		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
	}

	/**
	 * An editor without list_users reaches the users collection only for the block editor's
	 * author picker (?who=authors); every other users read is answered as if the route did not
	 * exist. Their own record (/users/me) and unrelated routes are untouched.
	 */
	public function test_editor_dispatch_is_limited_to_the_author_picker(): void {
		$this->grant( 'edit_others_posts' );

		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/users', array( 'who' => 'authors' ) ) ) );
		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/users/me' ) ) );
		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/posts' ) ) );

		foreach ( array( '/wp/v2/users', '/wp/v2/users/', '/wp/v2/users/12', '/WP/V2/Users' ) as $route ) {
			$result = blueline_restrict_user_rest_dispatch( null, null, $this->request( $route ) );

			$this->assertInstanceOf( WP_Error::class, $result, $route );
			$this->assertSame( 'rest_no_route', $result->get_error_code(), $route );
			$this->assertSame( array( 'status' => 404 ), $result->get_error_data(), $route );
		}

		$this->assertInstanceOf(
			WP_Error::class,
			blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/users', array( 'who' => 'everyone' ) ) )
		);
	}

	/**
	 * Admins pass the dispatch gate for every users request, and an earlier short-circuit result
	 * is passed through untouched.
	 */
	public function test_dispatch_gate_passes_admins_and_earlier_results(): void {
		$this->grant( 'list_users' );
		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/users' ) ) );
		$this->assertNull( blueline_restrict_user_rest_dispatch( null, null, $this->request( '/wp/v2/users/12' ) ) );

		blueline_test_reset_state();
		$earlier = array( 'cached' => true );
		$this->assertSame( $earlier, blueline_restrict_user_rest_dispatch( $earlier, null, $this->request( '/wp/v2/users' ) ) );
	}

	/**
	 * A REST post response shaped like core's, with an author field and author link.
	 *
	 * @return WP_REST_Response
	 */
	private function post_response(): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'id'     => 7,
				'title'  => array( 'rendered' => 'Jane Doe' ),
				'author' => 2486,
			)
		);
		$response->add_link( 'self', 'https://example.test/wp-json/wp/v2/posts/7' );
		$response->add_link( 'author', 'https://example.test/wp-json/wp/v2/users/2486' );

		return $response;
	}

	/**
	 * Anonymous visitors and Player-role accounts get posts and players without the author ID or
	 * the author link; everything else in the response is kept.
	 */
	public function test_rest_post_output_loses_the_author_for_members_and_visitors(): void {
		foreach ( array( array(), $this->sp_player_caps() ) as $caps ) {
			blueline_test_reset_state();
			foreach ( $caps as $cap ) {
				$this->grant( $cap );
			}

			$response = blueline_strip_rest_post_author( $this->post_response() );

			$this->assertSame(
				array(
					'id'    => 7,
					'title' => array( 'rendered' => 'Jane Doe' ),
				),
				$response->get_data()
			);
			$this->assertSame( array( 'self' ), array_keys( $response->get_links() ) );
		}
	}

	/**
	 * Staff who can see the member list, or reassign other people's posts in the editor, keep the
	 * author.
	 */
	public function test_rest_post_output_keeps_the_author_for_staff(): void {
		foreach ( array( 'list_users', 'edit_others_posts' ) as $cap ) {
			blueline_test_reset_state();
			$this->grant( $cap );

			$response = blueline_strip_rest_post_author( $this->post_response() );

			$this->assertSame( 2486, $response->get_data()['author'], $cap );
			$this->assertArrayHasKey( 'author', $response->get_links(), $cap );
		}
	}

	/**
	 * Anything other than a REST response passes through untouched.
	 */
	public function test_rest_post_author_filter_ignores_non_responses(): void {
		$this->assertSame( 'x', blueline_strip_rest_post_author( 'x' ) );
	}

	/**
	 * The author/author_exclude collection filters cannot be used to probe ownership: they are
	 * dropped for visitors and members, kept for staff.
	 */
	public function test_rest_author_query_filters_are_dropped_for_members_and_visitors(): void {
		$args = array(
			'post_type'      => 'sp_player',
			'author__in'     => array( 9 ),
			'author__not_in' => array( 3 ),
		);

		$this->assertSame( array( 'post_type' => 'sp_player' ), blueline_strip_rest_author_query_args( $args ) );

		$this->grant( 'edit_others_posts' );
		$this->assertSame( $args, blueline_strip_rest_author_query_args( $args ) );
	}

	/**
	 * The author filters are registered for core posts and SportsPress players.
	 */
	public function test_author_filters_are_registered_for_posts_and_players(): void {
		foreach ( array( 'post', 'sp_player' ) as $post_type ) {
			$this->assertNotFalse( has_filter( "rest_prepare_{$post_type}", 'blueline_strip_rest_post_author' ), $post_type );
			$this->assertNotFalse( has_filter( "rest_{$post_type}_query", 'blueline_strip_rest_author_query_args' ), $post_type );
		}
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', 'blueline_restrict_user_rest_dispatch' ) );
	}

	/**
	 * A logged-in member without either capability is treated as anonymous.
	 */
	public function test_logged_in_member_without_caps_loses_user_routes(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;

		$this->assertArrayNotHasKey( '/wp/v2/users', blueline_restrict_user_rest_endpoints( $this->routes() ) );
	}

	/**
	 * The users sitemap provider is removed; others pass through.
	 */
	public function test_users_sitemap_provider_is_removed(): void {
		$provider = new stdClass();

		$this->assertFalse( blueline_remove_users_sitemap_provider( $provider, 'users' ) );
		$this->assertSame( $provider, blueline_remove_users_sitemap_provider( $provider, 'posts' ) );
		$this->assertSame( $provider, blueline_remove_users_sitemap_provider( $provider, 'taxonomies' ) );
	}

	/**
	 * Author archives 404 for anonymous visitors.
	 */
	public function test_author_archive_404s_for_anonymous_visitor(): void {
		$GLOBALS['bl_test_is_author'] = true;

		blueline_block_author_archives();

		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, $GLOBALS['bl_test_status_header'] );
	}

	/**
	 * Admins (list_users) can still open author archives.
	 */
	public function test_author_archive_resolves_for_admin(): void {
		$GLOBALS['bl_test_is_author'] = true;
		$this->grant( 'list_users' );

		blueline_block_author_archives();

		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertNull( $GLOBALS['bl_test_status_header'] );
	}

	/**
	 * Holding edit_posts alone (as the Player role does) no longer opens author archives.
	 */
	public function test_author_archive_404s_for_edit_posts_alone(): void {
		$GLOBALS['bl_test_is_author'] = true;
		$this->grant( 'edit_posts' );

		blueline_block_author_archives();

		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
	}

	/**
	 * Non-author requests are untouched.
	 */
	public function test_non_author_request_is_untouched(): void {
		blueline_block_author_archives();

		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
	}

	/**
	 * The oEmbed response loses the author's name and archive URL.
	 */
	public function test_oembed_author_fields_are_stripped(): void {
		$data = blueline_strip_oembed_author(
			array(
				'title'       => 'Jane Doe',
				'author_name' => 'jane doe',
				'author_url'  => 'https://example.test/author/janedoe-gmail-com/',
				'provider'    => 'ARL',
			)
		);

		$this->assertSame(
			array(
				'title'    => 'Jane Doe',
				'provider' => 'ARL',
			),
			$data
		);
	}
}
