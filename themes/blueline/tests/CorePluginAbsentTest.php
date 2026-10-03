<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/floating-next-game.php';

/**
 * The theme without the blueline-core plugin: call sites of the moved player
 * resolver degrade to "no player" instead of fataling.
 */
final class CorePluginAbsentTest extends TestCase {

	/**
	 * Reset the fake-WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * The theme no longer defines the plugin's boot sentinel or the moved resolvers.
	 */
	public function test_theme_defines_none_of_the_moved_player_functions(): void {
		$this->assertFalse( function_exists( 'blueline_get_linked_player_id' ), 'blueline-core boot sentinel must live only in the plugin.' );
		$this->assertFalse( function_exists( 'blueline_current_user_player_id' ) );
		$this->assertFalse( function_exists( 'blueline_current_user_team_ids' ) );
		$this->assertFalse( function_exists( 'blueline_handle_player_photo_upload' ) );
		$this->assertFalse( function_exists( 'blueline_pre_get_avatar_data' ) );
	}

	/**
	 * A logged-in visitor gets no floating next-game widget, and no fatal, without the plugin.
	 */
	public function test_floating_next_game_renders_nothing_without_the_plugin(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 42;

		ob_start();
		blueline_render_floating_next_game();
		$this->assertSame( '', ob_get_clean() );
	}
}
