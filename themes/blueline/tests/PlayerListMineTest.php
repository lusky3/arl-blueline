<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress/player-list.php';

/**
 * The signed-in viewer's own row in a player list gets the "You" badge.
 */
final class PlayerListMineTest extends TestCase {

	/**
	 * A stock-shaped list: row 0 is the labels, the rest are keyed by player ID.
	 *
	 * @return array
	 */
	private function list_data(): array {
		return array(
			0  => array(
				'number' => '#',
				'name'   => 'Player',
			),
			11 => array(
				'number' => 4,
				'name'   => 'Aaron Cooper',
			),
			12 => array(
				'number' => 9,
				'name'   => 'Bea Singh',
			),
		);
	}

	/**
	 * Without blueline-core there is no viewer-to-player link, so nothing changes.
	 */
	public function test_without_the_plugin_the_data_is_untouched(): void {
		$this->assertFalse( function_exists( 'blueline_current_user_player_id' ) );
		$this->assertSame( $this->list_data(), blueline_player_list_mark_current_user( $this->list_data() ) );
	}

	/**
	 * Unexpected filter input is passed straight back, never fatals.
	 */
	public function test_non_array_data_is_returned_as_is(): void {
		$this->assertSame( 'x', blueline_player_list_mark_current_user( 'x' ) );
	}

	/**
	 * Only the viewer's own row is badged.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_only_the_viewers_own_row_is_badged(): void {
		$GLOBALS['bl_test_me'] = 12;
		eval( 'function blueline_current_user_player_id() { return $GLOBALS["bl_test_me"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stands in for the blueline-core function, which must stay undefined in the shared process (CorePluginAbsentTest).

		$data = blueline_player_list_mark_current_user( $this->list_data() );

		$this->assertSame( 'Aaron Cooper', $data[11]['name'] );
		$this->assertSame( 'Bea Singh <span class="bl-sp-roster__you">You</span>', $data[12]['name'] );
		$this->assertSame( 'Player', $data[0]['name'] );
	}

	/**
	 * A logged-out or unclaimed viewer (no linked player) changes nothing.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_viewer_with_no_linked_player_changes_nothing(): void {
		eval( 'function blueline_current_user_player_id() { return null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- see above.

		$this->assertSame( $this->list_data(), blueline_player_list_mark_current_user( $this->list_data() ) );
	}

	/**
	 * A viewer whose player is not in this list (another division) changes nothing.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_player_absent_from_this_list_changes_nothing(): void {
		eval( 'function blueline_current_user_player_id() { return 999; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- see above.

		$this->assertSame( $this->list_data(), blueline_player_list_mark_current_user( $this->list_data() ) );
	}
}
