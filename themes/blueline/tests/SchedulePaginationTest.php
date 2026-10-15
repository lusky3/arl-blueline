<?php
/**
 * The full-season schedule's paging (inc/sportspress/core.php).
 *
 * The schedule template (sportspress/event-list.php) used to switch paging off outright, because
 * SportsPress's client-side pager cannot cope with the date-heading rows, so
 * a calendar of 58 games printed all 58 oldest-first with the next game
 * three screens down. The template now pages on the server, in whole date
 * groups, and opens on the page holding the next game. The decisions are in
 * pure helpers so they can be exercised here; the template itself cannot be
 * rendered by this suite (see EventListPendingResultTest.php).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class SchedulePaginationTest extends TestCase {

	/**
	 * Date keys for $per_day games on each of $days consecutive days.
	 *
	 * @param int $days    Number of game days.
	 * @param int $per_day Games per day.
	 * @return string[]
	 */
	private function dates( int $days, int $per_day ): array {
		$keys = array();

		for ( $d = 1; $d <= $days; $d++ ) {
			for ( $g = 0; $g < $per_day; $g++ ) {
				$keys[] = sprintf( '2026-10-%02d', $d );
			}
		}

		return $keys;
	}

	/**
	 * A list that fits the rows setting is one page.
	 */
	public function test_a_short_list_is_a_single_page(): void {
		$this->assertSame( array( array( 0, 6 ) ), blueline_sp_schedule_page_ranges( $this->dates( 3, 2 ), 20 ) );
		$this->assertSame( array(), blueline_sp_schedule_page_ranges( array(), 20 ) );
	}

	/**
	 * Pages are filled with whole date groups: a day is never split, so no
	 * date heading is repeated across a page break.
	 */
	public function test_pages_hold_whole_date_groups(): void {
		// 10 days x 4 games, 10 rows: two days (8 rows) fit, a third (12) would not.
		$ranges = blueline_sp_schedule_page_ranges( $this->dates( 10, 4 ), 10 );

		$this->assertCount( 5, $ranges );
		$this->assertSame( array( 0, 8 ), $ranges[0] );
		$this->assertSame( array( 32, 8 ), $ranges[4] );
	}

	/**
	 * A single day bigger than the rows setting still gets a page of its own.
	 */
	public function test_an_oversized_day_is_kept_whole(): void {
		$keys   = array_merge( $this->dates( 1, 12 ), array( '2026-11-01' ) );
		$ranges = blueline_sp_schedule_page_ranges( $keys, 5 );

		$this->assertSame( array( array( 0, 12 ), array( 12, 1 ) ), $ranges );
	}

	/**
	 * A rows setting of zero or less means no paging.
	 */
	public function test_no_rows_setting_means_one_page(): void {
		$this->assertSame( array( array( 0, 8 ) ), blueline_sp_schedule_page_ranges( $this->dates( 4, 2 ), 0 ) );
		$this->assertSame( array( array( 0, 8 ) ), blueline_sp_schedule_page_ranges( $this->dates( 4, 2 ), -1 ) );
	}

	/**
	 * Every event lands on exactly one page, in order.
	 */
	public function test_ranges_cover_every_event_once(): void {
		$keys   = $this->dates( 23, 3 );
		$ranges = blueline_sp_schedule_page_ranges( $keys, 20 );
		$next   = 0;

		foreach ( $ranges as $range ) {
			$this->assertSame( $next, $range[0] );
			$next += $range[1];
		}

		$this->assertSame( count( $keys ), $next );
	}

	/**
	 * The page holding an event index.
	 */
	public function test_page_for_index(): void {
		$ranges = array( array( 0, 8 ), array( 8, 8 ), array( 16, 4 ) );

		$this->assertSame( 1, blueline_sp_schedule_page_for_index( $ranges, 0 ) );
		$this->assertSame( 1, blueline_sp_schedule_page_for_index( $ranges, 7 ) );
		$this->assertSame( 2, blueline_sp_schedule_page_for_index( $ranges, 8 ) );
		$this->assertSame( 3, blueline_sp_schedule_page_for_index( $ranges, 19 ) );
		$this->assertSame( 3, blueline_sp_schedule_page_for_index( $ranges, 99 ), 'past the end is the last page' );
	}

	/**
	 * With no page asked for, an oldest-first list opens on the page holding
	 * the next game, a newest-first list on its first page, and a list with
	 * nothing upcoming on its last page (the most recent games).
	 */
	public function test_the_default_page(): void {
		$ranges = array( array( 0, 8 ), array( 8, 8 ), array( 16, 4 ) );

		$this->assertSame( 2, blueline_sp_schedule_default_page( $ranges, 11, true ) );
		$this->assertSame( 1, blueline_sp_schedule_default_page( $ranges, 11, false ) );
		$this->assertSame( 3, blueline_sp_schedule_default_page( $ranges, null, true ) );
		$this->assertSame( 1, blueline_sp_schedule_default_page( array(), null, true ) );
	}

	/**
	 * A requested page is clamped to what exists; an absent or junk value
	 * falls back to the default.
	 */
	public function test_requested_page_is_clamped_or_defaulted(): void {
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( '2', 3, 1 ) );
		$this->assertSame( 3, blueline_sp_schedule_resolve_page( '99', 3, 1 ) );
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( '-4', 3, 2 ), 'below 1 is junk, so the default' );
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( '0', 3, 2 ), 'page 0 does not exist' );
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( null, 3, 2 ) );
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( 'abc', 3, 2 ) );
		$this->assertSame( 2, blueline_sp_schedule_resolve_page( array( '5' ), 3, 2 ) );
	}

	/**
	 * Each paged list on a page needs its own query argument.
	 */
	public function test_each_list_has_its_own_query_argument(): void {
		$this->assertSame( 'sched_page', blueline_sp_schedule_page_param( 1 ) );
		$this->assertSame( 'sched_page_2', blueline_sp_schedule_page_param( 2 ) );
		$this->assertSame( 'sched_page_3', blueline_sp_schedule_page_param( 3 ) );
	}

	/**
	 * Each paged list printed on a request is numbered in turn.
	 */
	public function test_lists_are_numbered_in_print_order(): void {
		$first  = blueline_sp_schedule_next_list_position();
		$second = blueline_sp_schedule_next_list_position();

		$this->assertSame( $first + 1, $second );
	}

	/**
	 * One page: no pager at all.
	 */
	public function test_pager_is_empty_for_a_single_page(): void {
		$this->assertSame( '', blueline_sp_schedule_pager_html( 1, 1, 'https://example.test/calendar', 'sched_page', 'bl-sched-1', true ) );
	}

	/**
	 * Middle page: both directions, as links that keep the reader on the
	 * table (anchor) and say what they lead to.
	 */
	public function test_pager_links_both_ways_on_a_middle_page(): void {
		$html = blueline_sp_schedule_pager_html( 2, 3, 'https://example.test/calendar', 'sched_page', 'bl-sched-1', true );

		$this->assertStringContainsString( '<nav class="bl-sp-pager" aria-label="Schedule pages">', $html );
		$this->assertStringContainsString( 'href="https://example.test/calendar?sched_page=1#bl-sched-1"', $html );
		$this->assertStringContainsString( 'href="https://example.test/calendar?sched_page=3#bl-sched-1"', $html );
		$this->assertStringContainsString( 'rel="prev"', $html );
		$this->assertStringContainsString( 'rel="next"', $html );
		$this->assertStringContainsString( 'Earlier games', $html );
		$this->assertStringContainsString( 'Later games', $html );
		$this->assertStringContainsString( 'Page 2 of 3', $html );
	}

	/**
	 * The first page has nothing before it, the last nothing after.
	 */
	public function test_pager_omits_the_missing_direction(): void {
		$first = blueline_sp_schedule_pager_html( 1, 3, 'https://example.test/c', 'sched_page', 'a', true );
		$last  = blueline_sp_schedule_pager_html( 3, 3, 'https://example.test/c', 'sched_page', 'a', true );

		$this->assertStringNotContainsString( 'Earlier games', $first );
		$this->assertStringContainsString( 'Later games', $first );
		$this->assertStringContainsString( 'Earlier games', $last );
		$this->assertStringNotContainsString( 'Later games', $last );
	}

	/**
	 * A newest-first list reads the other way round: "next" is older.
	 */
	public function test_pager_labels_follow_the_list_order(): void {
		$html = blueline_sp_schedule_pager_html( 2, 3, 'https://example.test/c', 'sched_page', 'a', false );

		$this->assertMatchesRegularExpression( '#rel="prev"[^>]*>.*Later games#s', $html );
		$this->assertMatchesRegularExpression( '#rel="next"[^>]*>.*Earlier games#s', $html );
	}

	/**
	 * The template pages the rows, keeps the client-side pager out of it, and
	 * only pages a list that is not already capped by a row limit.
	 */
	public function test_template_wires_the_pager(): void {
		$src = (string) file_get_contents( __DIR__ . '/../sportspress/event-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( 'blueline_sp_schedule_page_ranges(', $src );
		$this->assertStringContainsString( 'blueline_sp_schedule_pager_html(', $src );
		$this->assertMatchesRegularExpression( '/\$paginated\s*= false;/', $src, 'SportsPress\'s own DataTables pager must stay off: it cannot cope with date-heading rows' );
		$this->assertStringContainsString( 'array_slice( $data,', $src );
	}
}
