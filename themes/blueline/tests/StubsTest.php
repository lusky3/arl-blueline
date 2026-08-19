<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Unit tests.
 */
final class StubsTest extends TestCase {
	/**
	 * Test case.
	 */
	public function test_harness_boots(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertSame( 'a&amp;b', esc_html( 'a&b' ) );
	}

	/**
	 * Reset the nav-menu-locations fixture the wp_nav_menu()/has_nav_menu()
	 * stubs below read, so one test's assigned location can't leak into the
	 * next.
	 */
	protected function setUp(): void {
		$GLOBALS['bl_test_nav_menu_locations'] = array();
	}

	/**
	 * Binds the wp_nav_menu() stub to core's own contract (fix round 1 on
	 * Task 4, P1b-panel-completion): fed no menu and no callable
	 * `fallback_cb` -- exactly what blueline_site_header() passes on both
	 * its calls -- it returns `false` and prints nothing, matching core's
	 * unconditional `return false;` branch (wp-includes/nav-menu-template.php)
	 * rather than the previous version of this stub, which always returned
	 * null and echoed nothing regardless of $args -- a stub no test actually
	 * depended on, so an arbitrarily wrong one would have passed identically.
	 */
	public function test_wp_nav_menu_returns_false_with_no_menu_and_no_fallback(): void {
		ob_start();
		$result  = wp_nav_menu(
			array(
				'theme_location' => 'utility',
				'fallback_cb'    => false,
			)
		);
		$printed = (string) ob_get_clean();

		$this->assertFalse( $result );
		$this->assertSame( '', $printed );
	}

	/**
	 * The companion `echo => false` case core's own contract also
	 * guarantees: with a menu assigned, the stub returns the markup as a
	 * string rather than echoing it.
	 */
	public function test_wp_nav_menu_honours_echo_false_when_a_menu_is_assigned(): void {
		$GLOBALS['bl_test_nav_menu_locations'] = array( 'utility' );

		ob_start();
		$result  = wp_nav_menu(
			array(
				'theme_location' => 'utility',
				'echo'           => false,
			)
		);
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed, 'echo => false must not print anything' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'utility', $result );
	}

	/**
	 * The default (`echo` omitted, i.e. true) case with a menu assigned:
	 * the stub prints the markup and returns null, exactly as core does.
	 */
	public function test_wp_nav_menu_echoes_by_default_when_a_menu_is_assigned(): void {
		$GLOBALS['bl_test_nav_menu_locations'] = array( 'utility' );

		ob_start();
		$result  = wp_nav_menu( array( 'theme_location' => 'utility' ) );
		$printed = (string) ob_get_clean();

		$this->assertNull( $result );
		$this->assertStringContainsString( 'utility', $printed );
	}
}
