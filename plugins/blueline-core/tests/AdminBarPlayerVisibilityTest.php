<?php
/**
 * Unit tests for the admin-bar module.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/admin-bar/admin-bar.php';

/**
 * Covers blueline_hide_admin_bar_for_players(), the `show_admin_bar` callback that hides the
 * toolbar from anyone without `manage_options` (every such role, not only players).
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
	 * The filter keys on `manage_options` alone, so a non-administrator who holds other
	 * capabilities (an editor or shop manager) loses the toolbar too, by design.
	 */
	public function test_a_non_admin_with_other_capabilities_also_loses_the_bar(): void {
		$state                               = &blueline_test_state();
		$state['caps']['edit_posts']         = true;
		$state['caps']['edit_others_posts']  = true;
		$state['caps']['manage_woocommerce'] = true;

		$this->assertFalse( blueline_hide_admin_bar_for_players( true ) );
	}

	/**
	 * The callback is actually wired to the `show_admin_bar` filter, not
	 * merely defined and never hooked.
	 */
	public function test_the_callback_is_registered_on_the_show_admin_bar_filter(): void {
		$this->assertTrue( apply_filters( 'show_admin_bar', true ) === false );
	}
}
