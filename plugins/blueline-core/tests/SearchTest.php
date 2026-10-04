<?php
/**
 * Unit tests for the search-relevance ORDER BY builder.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/search/search.php';

/**
 * Covers blueline_search_title_match_first() in includes/search/search.php.
 */
final class SearchTest extends TestCase {

	/**
	 * The $wpdb that was global before the test.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Install the stub $wpdb and start from the front end.
	 */
	protected function setUp(): void {
		blueline_test_reset_hooks();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new Blueline_Core_Test_Search_Wpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double for $wpdb.
		unset( $GLOBALS['bl_test_is_admin'] );
	}

	/**
	 * Restore the globals.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring $wpdb.
		unset( $GLOBALS['bl_test_is_admin'] );
	}

	/**
	 * The filter is registered on posts_orderby with both arguments.
	 */
	public function test_it_is_registered_on_posts_orderby(): void {
		$this->assertNotFalse( has_filter( 'posts_orderby', 'blueline_search_title_match_first' ) );
	}

	/**
	 * Admin requests are never touched.
	 */
	public function test_admin_requests_return_the_orderby_unchanged(): void {
		$GLOBALS['bl_test_is_admin'] = true;

		$query = new WP_Query( true, true, array( 's' => 'Lumberjacks' ) );

		$this->assertSame( 'wp_posts.post_date DESC', blueline_search_title_match_first( 'wp_posts.post_date DESC', $query ) );
	}

	/**
	 * A query that is not a search is not touched.
	 */
	public function test_non_search_queries_return_the_orderby_unchanged(): void {
		$query = new WP_Query( false, true, array( 's' => 'Lumberjacks' ) );

		$this->assertSame( 'x DESC', blueline_search_title_match_first( 'x DESC', $query ) );
	}

	/**
	 * A secondary (non-main) search query is not touched.
	 */
	public function test_non_main_queries_return_the_orderby_unchanged(): void {
		$query = new WP_Query( true, false, array( 's' => 'Lumberjacks' ) );

		$this->assertSame( 'x DESC', blueline_search_title_match_first( 'x DESC', $query ) );
	}

	/**
	 * An empty or whitespace-only term is not touched.
	 */
	public function test_an_empty_search_term_returns_the_orderby_unchanged(): void {
		foreach ( array( '', '   ', "\t\n" ) as $i => $term ) {
			$query = new WP_Query( true, true, array( 's' => $term ) );

			$this->assertSame( 'x DESC', blueline_search_title_match_first( 'x DESC', $query ), 'term #' . $i );
		}

		$this->assertSame( 'x DESC', blueline_search_title_match_first( 'x DESC', new WP_Query( true, true ) ) );
	}

	/**
	 * The CASE tier is an exact match first, a leading match second, everything else last, and the
	 * term is trimmed.
	 */
	public function test_the_tier_expression_ranks_exact_then_leading_then_the_rest(): void {
		$query = new WP_Query( true, true, array( 's' => '  Lumberjacks ' ) );

		$result = blueline_search_title_match_first( '', $query );

		$this->assertSame(
			"CASE WHEN wp_posts.post_title LIKE 'Lumberjacks' THEN 0 WHEN wp_posts.post_title LIKE 'Lumberjacks%' THEN 1 ELSE 2 END ASC",
			$result
		);
		$this->assertSame( array( 'Lumberjacks', 'Lumberjacks%' ), $GLOBALS['wpdb']->prepared_args );
	}

	/**
	 * An existing ORDER BY is kept, after the new tier and a comma separator.
	 */
	public function test_an_existing_orderby_is_appended_after_the_tier(): void {
		$query = new WP_Query( true, true, array( 's' => 'Whalers' ) );

		$result = blueline_search_title_match_first( 'wp_posts.post_date DESC', $query );

		$this->assertStringStartsWith( 'CASE WHEN wp_posts.post_title LIKE', $result );
		$this->assertStringEndsWith( 'ELSE 2 END ASC, wp_posts.post_date DESC', $result );
	}

	/**
	 * LIKE wildcards in the term are escaped (so `%` and `_` match literally), a quote is
	 * escaped by prepare(), and the leading-match wildcard is appended after escaping.
	 */
	public function test_wildcards_and_quotes_in_the_term_are_escaped(): void {
		$query = new WP_Query( true, true, array( 's' => "50%_off's" ) );

		$result = blueline_search_title_match_first( '', $query );

		// esc_like() backslash-escapes % and _; the trailing % of the leading tier is NOT escaped.
		$this->assertSame( array( "50\\%\\_off's", "50\\%\\_off's%" ), $GLOBALS['wpdb']->prepared_args );
		// prepare() slash-escapes the backslashes and the quote for the SQL string literal.
		$this->assertStringContainsString( "LIKE '50\\\\%\\\\_off\\'s' THEN 0", $result );
		$this->assertStringContainsString( "LIKE '50\\\\%\\\\_off\\'s%' THEN 1", $result );
	}
}
