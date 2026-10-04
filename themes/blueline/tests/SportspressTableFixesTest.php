<?php
/**
 * QA fx4 table fixes: the event-list column count (C-16/B-05), the "score
 * coming soon" cut-off (C-24), empty states (B-14) and the roster sort key
 * (B-12). The templates themselves need a live SP_Calendar, so their wiring
 * is source-scanned and the decisions are unit-tested through the helpers.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class SportspressTableFixesTest extends TestCase {

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
	 * /schedule and team pages: Time, Home, Result, Away, Arena.
	 */
	public function test_column_count_includes_the_result_column(): void {
		$this->assertSame( 5, blueline_sp_event_list_column_count( array( 'event', 'teams', 'time', 'venue' ) ) );
	}

	/**
	 * Calendars add League and Season (and sometimes Article).
	 */
	public function test_column_count_for_calendar_columns(): void {
		$this->assertSame( 7, blueline_sp_event_list_column_count( array( 'event', 'time', 'league', 'season', 'venue' ) ) );
		$this->assertSame( 8, blueline_sp_event_list_column_count( array( 'event', 'time', 'league', 'season', 'venue', 'article' ) ) );
	}

	/**
	 * Arena is always printed (hidden when inactive), so it always counts.
	 */
	public function test_column_count_counts_a_hidden_arena_column(): void {
		$this->assertSame( 5, blueline_sp_event_list_column_count( array( 'event', 'time' ) ) );
	}

	/**
	 * No column list means every column, as sp_column_active() treats it.
	 */
	public function test_column_count_with_no_column_list_counts_everything(): void {
		$this->assertSame( 9, blueline_sp_event_list_column_count( null ) );
		$this->assertSame( 9, blueline_sp_event_list_column_count( array() ) );
	}

	/**
	 * The template's colspan comes from the helper, not a hand count.
	 */
	public function test_template_uses_the_column_count_helper(): void {
		$this->assertStringContainsString( 'blueline_sp_event_list_column_count( $usecolumns )', $this->source( 'sportspress/event-list.php' ) );
	}

	/**
	 * A game from last night still expects a score.
	 */
	public function test_recent_unscored_game_still_expects_a_result(): void {
		$now = 1_700_000_000;
		$this->assertTrue( blueline_sp_result_still_expected( $now - DAY_IN_SECONDS, $now ) );
		$this->assertTrue( blueline_sp_result_still_expected( $now - 14 * DAY_IN_SECONDS, $now ) );
	}

	/**
	 * A game weeks or years old does not.
	 */
	public function test_old_unscored_game_no_longer_expects_a_result(): void {
		$now = 1_700_000_000;
		$this->assertFalse( blueline_sp_result_still_expected( $now - 15 * DAY_IN_SECONDS, $now ) );
		$this->assertFalse( blueline_sp_result_still_expected( $now - 3650 * DAY_IN_SECONDS, $now ) );
		$this->assertFalse( blueline_sp_result_still_expected( false, $now ) );
	}

	/**
	 * The pending copy is gated on the cut-off.
	 */
	public function test_template_gates_the_pending_copy_on_the_cut_off(): void {
		$this->assertMatchesRegularExpression(
			"/'pending' === \\\$bl_event_state && blueline_sp_result_still_expected\( \\\$bl_start_ts \)/",
			$this->source( 'sportspress/event-list.php' )
		);
	}

	/**
	 * An event list with no rows prints the empty state, not a bare header.
	 */
	public function test_event_list_has_an_empty_state(): void {
		$src = $this->source( 'sportspress/event-list.php' );

		$this->assertMatchesRegularExpression( '/if \( empty\( \$data \) \) : \?>.*bl-sp-empty.*<\?php else : \?>.*<table/s', $src );
	}

	/**
	 * A stats table of blanks and dashes has nothing to show.
	 */
	public function test_stat_rows_of_blanks_and_dashes_have_no_values(): void {
		$rows = array(
			-1 => array(
				'name' => 'Total',
				'team' => '-',
				'g'    => '',
				'a'    => ' ',
				'pim'  => '-',
				'p'    => '&mdash;',
			),
		);

		$this->assertFalse( blueline_sp_stat_rows_have_values( $rows, array( 'g', 'a', 'pim', 'p' ) ) );
	}

	/**
	 * A zero is a real value.
	 */
	public function test_a_zero_stat_is_a_value(): void {
		$rows = array(
			12 => array(
				'g' => '',
				'a' => '0',
			),
		);

		$this->assertTrue( blueline_sp_stat_rows_have_values( $rows, array( 'g', 'a' ) ) );
	}

	/**
	 * The player statistics template uses the check.
	 */
	public function test_player_statistics_template_uses_the_values_check(): void {
		$this->assertStringContainsString( 'blueline_sp_stat_rows_have_values( $data, $stat_keys )', $this->source( 'sportspress/player-statistics-league.php' ) );
	}

	/**
	 * Both roster loops give DataTables a plain-name sort key.
	 */
	public function test_roster_name_cells_carry_a_sort_key(): void {
		$src = $this->source( 'sportspress/team-lists.php' );

		$this->assertSame( 2, substr_count( $src, '<td class="bl-sp-roster__name-cell" data-order="' ) );
		$this->assertStringNotContainsString( '<td class="bl-sp-roster__name-cell">', $src );
	}
}
