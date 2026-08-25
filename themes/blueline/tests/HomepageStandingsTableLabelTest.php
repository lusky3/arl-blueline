<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * Regression coverage for blueline_homepage_standings_table_label(), the
 * tab-label extractor behind the homepage standings module's division tab
 * strip (blueline_homepage_standings_tabs()). Pins both naming conventions
 * this site's own sp_table archive actually uses -- "Division N" and the
 * older "Group X" -- plus the fallback for anything matching neither.
 */
final class HomepageStandingsTableLabelTest extends TestCase {

	/**
	 * The common case: a current-season division table.
	 */
	public function test_division_title_returns_just_the_number(): void {
		$this->assertSame( '1', blueline_homepage_standings_table_label( 'Division 1 | S2026' ) );
	}

	/**
	 * "Playoffs" lives between the season label and the pipe, so it must
	 * never leak into the tab label -- it's already implied by which
	 * season's tables are showing at all.
	 */
	public function test_playoffs_division_title_returns_just_the_number(): void {
		$this->assertSame( '4', blueline_homepage_standings_table_label( 'Division 4 | Playoffs S2026' ) );
	}

	/**
	 * Older winter seasons use lettered "Group X" instead of "Division N".
	 */
	public function test_group_title_returns_just_the_letter(): void {
		$this->assertSame( 'A', blueline_homepage_standings_table_label( 'Group A | Playoffs W2025-26' ) );
	}

	/**
	 * "4B" is the whole identifier, not "4" with a "B" dropped.
	 */
	public function test_lettered_subdivision_is_kept_whole(): void {
		$this->assertSame( '4B', blueline_homepage_standings_table_label( 'Division 4B | W2021-22' ) );
	}

	/**
	 * "1/2" (two divisions sharing one table) is kept whole, not truncated
	 * at the slash.
	 */
	public function test_combined_division_is_kept_whole(): void {
		$this->assertSame( '1/2', blueline_homepage_standings_table_label( 'Division 1/2 | S2022 Playoffs' ) );
	}

	/**
	 * A title naming neither convention (the archive's own "Open Division")
	 * falls back to the text before the pipe, unchanged -- never blank.
	 */
	public function test_a_title_matching_neither_convention_falls_back_to_the_text_before_the_pipe(): void {
		$this->assertSame( 'Open Division', blueline_homepage_standings_table_label( 'Open Division | S2019' ) );
	}

	/**
	 * The extractor works even without a "| <season>" suffix at all.
	 */
	public function test_a_division_title_with_no_pipe_at_all_still_extracts_just_the_number(): void {
		$this->assertSame( '4', blueline_homepage_standings_table_label( 'Division 4' ) );
	}

	/**
	 * Same no-pipe case, but for a title matching neither naming
	 * convention -- the whole trimmed title is the fallback.
	 */
	public function test_a_fallback_title_with_no_pipe_at_all_returns_the_whole_trimmed_title(): void {
		$this->assertSame( 'Championship Bracket', blueline_homepage_standings_table_label( 'Championship Bracket' ) );
	}
}
