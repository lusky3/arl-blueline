<?php
/**
 * The team roster keeps the captain's badge.
 *
 * The roster override prints its own markup and strips tags from each name,
 * so the badge the player-tools plugin adds to list data never reaches it.
 * The override asks the plugin's `spt_roster_captain_indicator` filter per
 * row and prints the answer as its own badge. The template needs a live
 * SP_Player_List, so its wiring is source-scanned and the decision is
 * unit-tested through the helper, with the plugin's filter stood in for.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class RosterCaptainBadgeTest extends TestCase {

	/**
	 * Start every test with no filters registered.
	 */
	protected function setUp(): void {
		unset( $GLOBALS['bl_test_hooks']['spt_roster_captain_indicator'] );
	}

	/**
	 * Leave no filters behind for other tests.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_hooks']['spt_roster_captain_indicator'] );
	}

	/**
	 * Read a theme source file.
	 *
	 * @param string $path Path relative to the theme root.
	 * @return string
	 */
	private function source( string $path ): string {
		return (string) file_get_contents( __DIR__ . '/../' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * Stand in for the plugin: player 9 is captain of list 117433, with this badge markup.
	 *
	 * @param string $badge Markup the plugin answers with for the captain.
	 */
	private function plugin_answers( string $badge ): void {
		add_filter(
			'spt_roster_captain_indicator',
			static function ( $html, $player_id, $list_id ) use ( $badge ) {
				return ( 9 === $player_id && 117433 === $list_id ) ? $html . $badge : $html;
			},
			10,
			3
		);
	}

	/**
	 * Without the plugin's filter nobody is the captain.
	 */
	public function test_no_answer_means_no_captain_mark(): void {
		$this->assertNull( blueline_sp_roster_captain_mark( 9, 117433 ) );
	}

	/**
	 * The captain gets the plugin's mark; everyone else, and the same player on another list, gets none.
	 */
	public function test_only_the_lists_captain_gets_the_mark(): void {
		$this->plugin_answers( ' <span class="spt-captain-indicator" title="Captain">C</span>' );

		$this->assertSame( 'C', blueline_sp_roster_captain_mark( 9, 117433 ) );
		$this->assertNull( blueline_sp_roster_captain_mark( 10, 117433 ) );
		$this->assertNull( blueline_sp_roster_captain_mark( 9, 115517 ) );
	}

	/**
	 * A site that changes the indicator text keeps its text, without the plugin's markup.
	 */
	public function test_a_custom_indicator_text_is_kept_as_plain_text(): void {
		$this->plugin_answers( '<span class="spt-captain-indicator">CAP</span>' );

		$this->assertSame( 'CAP', blueline_sp_roster_captain_mark( 9, 117433 ) );
	}

	/**
	 * An answer with no text still marks the captain.
	 */
	public function test_an_empty_indicator_falls_back_to_c(): void {
		$this->plugin_answers( '<span class="spt-captain-indicator"></span>' );

		$this->assertSame( 'C', blueline_sp_roster_captain_mark( 9, 117433 ) );
	}

	/**
	 * The roster template asks per player and per list, and prints the mark as its own badge.
	 */
	public function test_the_roster_template_asks_per_row_and_prints_the_mark(): void {
		$src = $this->source( 'sportspress/team-lists.php' );

		$this->assertStringContainsString( 'blueline_sp_roster_captain_mark( (int) $player_id, (int) $list_id )', $src );
		$this->assertStringContainsString( 'class="bl-sp-roster__captain"', $src );
		$this->assertStringContainsString( "wp_strip_all_tags( \$row['name'] )", $src );
	}
}
