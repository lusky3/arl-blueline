<?php
/**
 * A player's stats section (SportsPress player-statistics.php, one
 * player-statistics-league.php per league plus Career Total) shows ONE empty
 * state when nothing is recorded anywhere, instead of a "not recorded yet"
 * block per table (inc/sportspress/player-stats.php).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class PlayerStatsEmptyStateTest extends TestCase {

	/**
	 * Reset the fake WordPress state and close any collector a test left open.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_sp_player_stats_collect( 'close' );
	}

	/**
	 * Render the league template for one degenerate (empty) table, as
	 * sp_get_template() would.
	 *
	 * @param string $caption Table caption.
	 * @return string
	 */
	private function render_empty_table( string $caption ): string {
		$data       = array(
			0  => array( 'name' => 'Season' ),
			-1 => array( 'name' => 'Total' ),
		);
		$scrollable = true;

		ob_start();
		include dirname( __DIR__ ) . '/sportspress/player-statistics-league.php';
		return (string) ob_get_clean();
	}

	/**
	 * Run one whole stats section: the callbacks this theme hangs on
	 * SportsPress's before/after template actions, around the given body.
	 * Called directly, since other callbacks on those actions enqueue assets.
	 *
	 * @param callable $body Prints the section's tables.
	 * @return string
	 */
	private function render_section( callable $body ): string {
		ob_start();
		blueline_sp_player_stats_open( 'player-statistics.php' );
		echo $body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test markup.
		blueline_sp_player_stats_close( 'player-statistics.php' );
		return (string) ob_get_clean();
	}

	/**
	 * Nothing recorded in Division 2 or Career Total: one empty state, not two.
	 */
	public function test_a_player_with_no_stats_gets_one_empty_state(): void {
		$html = $this->render_section(
			fn() => $this->render_empty_table( 'Division 2' ) . $this->render_empty_table( 'Career Total' )
		);

		$this->assertSame( 1, substr_count( $html, 'class="bl-sp-empty"' ) );
		$this->assertStringContainsString( 'No stats yet. They appear here after the first scored game.', $html );
		$this->assertStringNotContainsString( 'not recorded yet', $html );
		$this->assertStringContainsString( 'bl-sp-empty__mark', $html );
	}

	/**
	 * Stats in one table: the empty one before it keeps its own line, printed
	 * ahead of the real table, and no whole-section state is added.
	 */
	public function test_an_empty_table_before_a_real_one_keeps_its_line(): void {
		blueline_sp_player_stats_collect( 'open' );

		$this->assertSame(
			array(
				'print' => array(),
				'none'  => false,
			),
			blueline_sp_player_stats_collect( 'empty', 'Division 2' )
		);
		$this->assertSame( array( 'Division 2' ), blueline_sp_player_stats_collect( 'table' )['print'] );
		$this->assertSame( array( 'Career Total' ), blueline_sp_player_stats_collect( 'empty', 'Career Total' )['print'], 'after a real table an empty one prints straight away' );
		$this->assertFalse( blueline_sp_player_stats_collect( 'close' )['none'] );
	}

	/**
	 * The league template used on its own (no player-statistics.php around
	 * it) prints its line straight away, as it always did.
	 */
	public function test_outside_a_stats_section_the_line_prints_at_once(): void {
		$html = $this->render_empty_table( 'Career Total' );

		$this->assertStringContainsString( 'Career Total not recorded yet.', $html );
		$this->assertFalse( blueline_sp_player_stats_collect( 'close' )['none'] );
	}

	/**
	 * Another template's hooks never open or close the collector, and the
	 * callbacks are on SportsPress's template actions.
	 */
	public function test_other_templates_do_not_touch_the_collector(): void {
		blueline_sp_player_stats_open( 'player-list.php' );

		$this->assertSame( array( 'Division 1' ), blueline_sp_player_stats_collect( 'empty', 'Division 1' )['print'] );
		$this->assertNotEmpty( $GLOBALS['bl_test_hooks']['sportspress_before_template'] ?? array() );
		$this->assertNotEmpty( $GLOBALS['bl_test_hooks']['sportspress_after_template'] ?? array() );
	}
}
