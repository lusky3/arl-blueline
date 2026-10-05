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
	 * Editors (edit_posts) and admins (list_users) keep every route.
	 */
	public function test_editors_and_admins_keep_user_routes(): void {
		foreach ( array( 'edit_posts', 'list_users' ) as $cap ) {
			blueline_test_reset_state();
			$this->grant( $cap );

			$this->assertSame( $this->routes(), blueline_restrict_user_rest_endpoints( $this->routes() ), $cap );
		}
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
	 * Editors can still open author archives.
	 */
	public function test_author_archive_resolves_for_editor(): void {
		$GLOBALS['bl_test_is_author'] = true;
		$this->grant( 'edit_posts' );

		blueline_block_author_archives();

		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertNull( $GLOBALS['bl_test_status_header'] );
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
