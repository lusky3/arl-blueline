<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Covers a live-review finding with zero prior test coverage: "Division 5
 * Playoffs" showed every team at position 0 with a 0-0-0-0 record and no
 * explanatory text -- reading as broken rather than "playoffs haven't
 * started yet." blueline_sp_zero_games_note() is the pure decision
 * sportspress/league-table.php now calls to decide whether, and what, to
 * say about an all-zero table.
 */
final class ZeroGamesNoteTest extends TestCase {

	/**
	 * THE EXACT BUG: every team at zero, in a table whose caption names a
	 * playoff round, must produce the playoffs-specific copy.
	 */
	public function test_all_zero_playoff_table_gets_playoffs_copy(): void {
		$note = blueline_sp_zero_games_note( true, array( 0, 0, 0, 0 ), true, true );

		$this->assertSame( 'Playoffs have not started yet.', $note );
	}

	/**
	 * The same all-zero situation for a REGULAR SEASON table (not a
	 * playoff round) gets the generic season copy instead.
	 */
	public function test_all_zero_regular_season_table_gets_generic_copy(): void {
		$note = blueline_sp_zero_games_note( true, array( 0, 0, 0, 0 ), true, false );

		$this->assertSame( 'No games have been played yet this season.', $note );
	}

	/**
	 * At least one team with a real, non-zero figure means the table is
	 * genuinely in progress -- no note at all, regardless of how many
	 * other teams still read zero.
	 */
	public function test_any_nonzero_figure_suppresses_the_note(): void {
		$note = blueline_sp_zero_games_note( true, array( 0, 0, 5, 0 ), true, true );

		$this->assertSame( '', $note );
	}

	/**
	 * No progress signal at all (the table has neither a 'gp' column nor
	 * enough record columns to infer from) must never be treated as
	 * "confirmed zero" -- that is "cannot tell," and showing the note
	 * anyway would be a false positive on a table that may well be full of
	 * real games.
	 */
	public function test_no_progress_signal_suppresses_the_note_even_with_all_zero_figures(): void {
		$note = blueline_sp_zero_games_note( false, array( 0, 0, 0, 0 ), true, true );

		$this->assertSame( '', $note );
	}

	/**
	 * No teams listed at all is a different, unrelated empty state (an
	 * unconfigured or genuinely empty table) -- not this finding's "teams
	 * exist but none have played" case, so no note here either.
	 */
	public function test_no_teams_at_all_suppresses_the_note(): void {
		$note = blueline_sp_zero_games_note( true, array(), false, true );

		$this->assertSame( '', $note );
	}

	/**
	 * An empty $progress_figures list (teams exist, but somehow no figures
	 * were extracted) must not throw, and must be treated the same as "no
	 * evidence of any game played" -- vacuously true, matching an all-zero
	 * result rather than erroring.
	 */
	public function test_empty_progress_figures_with_teams_present_is_treated_as_all_zero(): void {
		$note = blueline_sp_zero_games_note( true, array(), true, false );

		$this->assertSame( 'No games have been played yet this season.', $note );
	}
}
