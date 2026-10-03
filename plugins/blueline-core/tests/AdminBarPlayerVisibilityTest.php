<?php
/**
 * Unit tests.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/admin-bar/admin-bar.php';

/**
 * Live-site review: a plain player-role account signed in to /account saw
 * the full WordPress/SportsPress admin toolbar, including a direct
 * "ARL Settings" link (/wp-admin/admin.php?page=sportspress) and "Admin
 * Notices" -- covers blueline_hide_admin_bar_for_players(), the
 * `show_admin_bar` filter callback that hides it for anyone without
 * `manage_options`, the same capability inc/settings/page.php gates every
 * admin surface behind.
 */
final class AdminBarPlayerVisibilityTest extends TestCase {

	/**
	 * Reset the fake-WordPress state (including granted capabilities)
	 * before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A plain player account (no `manage_options`) never sees the bar,
	 * regardless of what core's own default answer was.
	 */
	public function test_a_player_without_manage_options_never_sees_the_bar(): void {
		$this->assertFalse( blueline_hide_admin_bar_for_players( true ) );
	}

	/**
	 * An actual site admin (`manage_options` granted) is unaffected --
	 * core's own default answer passes through untouched.
	 */
	public function test_an_admin_with_manage_options_keeps_core_default(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		$this->assertTrue( blueline_hide_admin_bar_for_players( true ) );
		$this->assertFalse( blueline_hide_admin_bar_for_players( false ) );
	}

	/**
	 * The callback is actually wired to the `show_admin_bar` filter, not
	 * merely defined and never hooked.
	 */
	public function test_the_callback_is_registered_on_the_show_admin_bar_filter(): void {
		$this->assertTrue( apply_filters( 'show_admin_bar', true ) === false );
	}
}
