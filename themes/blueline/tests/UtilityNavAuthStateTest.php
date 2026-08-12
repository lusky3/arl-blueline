<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers P3 finding 3: the header's utility nav (account links) is a
 * static, admin-managed wp_nav_menu -- on this site "My ARL Account" and
 * a "Log Out" link with a baked-in _wpnonce, both rendered unconditionally
 * regardless of login state. Verified live: a cookie-less visitor to
 * /account saw the page's own login form AND a live "Log Out" link in the
 * header at the same time. blueline_utility_nav_auth_state() (the
 * 'wp_nav_menu_objects' filter added in inc/template-tags.php) is what
 * fixes that; these tests call it directly, the same pattern
 * NavWalkerDedupTest.php uses for the walker.
 */
final class UtilityNavAuthStateTest extends TestCase {

	/**
	 * Resets the stub environment's login state before every test so none
	 * of these leak into each other.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Builds a fake nav menu item as wp_nav_menu_objects would deliver it.
	 *
	 * @param string $url   Item URL.
	 * @param string $title Item title.
	 * @return object
	 */
	private function menu_item( string $url, string $title ) {
		return (object) array(
			'ID'    => 1,
			'title' => $title,
			'url'   => $url,
		);
	}

	/**
	 * A call for a location OTHER than 'utility' must pass its items
	 * through completely untouched, regardless of login state.
	 */
	public function test_non_utility_location_is_left_untouched(): void {
		$items = array( $this->menu_item( 'https://example.test/wp-login.php?action=logout', 'Log Out' ) );
		$args  = (object) array( 'theme_location' => 'primary' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		$this->assertSame( $items, $result );
	}

	/**
	 * A logged-IN visitor's utility menu must render exactly as configured
	 * -- "Log Out" belongs in front of someone who can actually use it.
	 */
	public function test_logged_in_visitor_menu_is_untouched(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;

		$items = array(
			$this->menu_item( 'https://example.test/account', 'My ARL Account' ),
			$this->menu_item( 'https://example.test/wp-login.php?action=logout&_wpnonce=abc', 'Log Out' ),
		);
		$args  = (object) array( 'theme_location' => 'utility' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		$this->assertSame( $items, $result );
	}

	/**
	 * The exact bug: a logged-OUT visitor must never be offered a live
	 * logout link -- there is nothing to log out of.
	 */
	public function test_logged_out_visitor_never_sees_a_logout_link(): void {
		$items = array(
			$this->menu_item( 'https://example.test/account', 'My ARL Account' ),
			$this->menu_item( 'https://example.test/wp-login.php?action=logout&_wpnonce=abc', 'Log Out' ),
		);
		$args  = (object) array( 'theme_location' => 'utility' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		foreach ( $result as $item ) {
			$this->assertStringNotContainsString( 'action=logout', $item->url );
		}
	}

	/**
	 * A logged-out visitor must be given a way IN -- a "Log In" item
	 * appended when the admin-configured menu doesn't already have one.
	 */
	public function test_logged_out_visitor_gets_a_login_link_when_menu_has_none(): void {
		$items = array(
			$this->menu_item( 'https://example.test/account', 'My ARL Account' ),
			$this->menu_item( 'https://example.test/wp-login.php?action=logout&_wpnonce=abc', 'Log Out' ),
		);
		$args  = (object) array( 'theme_location' => 'utility' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		$titles = array_map(
			static function ( $item ) {
				return $item->title;
			},
			$result
		);

		$this->assertContains( 'Log In', $titles );
	}

	/**
	 * A menu that already carries its own login-ish item (by TITLE, e.g. an
	 * admin-authored "Sign In" link that doesn't happen to resolve to the
	 * same URL blueline_utility_login_url() would use) must not get a
	 * second, duplicate one appended.
	 */
	public function test_existing_login_item_is_not_duplicated(): void {
		$items = array(
			$this->menu_item( 'https://example.test/members', 'Sign In' ),
		);
		$args  = (object) array( 'theme_location' => 'utility' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Sign In', $result[0]->title );
	}

	/**
	 * The exact live bug this filter's first version shipped with: an
	 * existing item that already points at the SAME destination the
	 * fabricated login link would use (on this site, "My ARL Account" and
	 * the login link both resolve to /account) must be relabelled in
	 * place, not left alone with a second, redundant "Log In" appended
	 * next to it -- confirmed live as "My ARL Account | Log In" rendering
	 * side by side, both going to the identical URL. This stub environment
	 * has no wc_get_page_permalink(), so blueline_utility_login_url()
	 * falls back to wp_login_url() -- the test uses that same URL as the
	 * existing item's destination to exercise the match.
	 */
	public function test_item_matching_the_login_destination_is_relabelled_not_duplicated(): void {
		$items = array(
			$this->menu_item( wp_login_url(), 'Member Login' ),
		);
		$args  = (object) array( 'theme_location' => 'utility' );

		$result = blueline_utility_nav_auth_state( $items, $args );

		$this->assertCount( 1, $result, 'must relabel the existing item in place, not append a second one' );
		$this->assertSame( 'Log In', $result[0]->title );
	}

	/**
	 * The fabricated "Log In" item must carry every property a normal nav
	 * item has (ID, url, classes, etc.) so nothing downstream -- the
	 * walker, or a plugin's own nav_menu_* filter -- hits a missing
	 * property.
	 */
	public function test_fabricated_login_item_has_a_complete_shape(): void {
		$item = blueline_utility_login_menu_item( 'https://example.test/account' );

		foreach ( array( 'ID', 'title', 'url', 'classes', 'target', 'attr_title', 'xfn', 'menu_item_parent', 'object', 'type' ) as $property ) {
			$this->assertObjectHasProperty( $property, $item );
		}
		$this->assertIsArray( $item->classes );
		$this->assertSame( 'https://example.test/account', $item->url );
	}

	/**
	 * Login URL falls back to wp_login_url() when WooCommerce's
	 * wc_get_page_permalink() isn't available -- exactly this stub
	 * environment's state, and a real, guarded fallback for a site that
	 * somehow has this theme without WooCommerce active.
	 */
	public function test_login_url_falls_back_to_wp_login_url_without_woocommerce(): void {
		$this->assertSame( wp_login_url(), blueline_utility_login_url() );
	}
}
