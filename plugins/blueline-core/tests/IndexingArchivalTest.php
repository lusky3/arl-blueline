<?php
/**
 * Unit tests for includes/indexing/archival.php: which SportsPress pages are archival, the
 * cache behind that answer, and the sitemap exclusion.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/indexing/indexing.php';

/**
 * Covers the archival module.
 */
final class IndexingArchivalTest extends TestCase {

	/**
	 * The get_posts() model in force before this test.
	 *
	 * @var mixed
	 */
	private $previous_model;

	/**
	 * Calls the get_posts() model answered, to tell a cached read from a recomputation.
	 *
	 * @var int
	 */
	private int $queries = 0;

	/**
	 * Fresh world: no posts, no terms, the model and the fake $wpdb installed.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$this->queries                      = 0;
		$this->previous_model               = $GLOBALS['bl_test_get_posts_model'] ?? null;
		$GLOBALS['bl_test_get_posts_model'] = array( $this, 'model_get_posts' );
		$GLOBALS['wpdb']                    = new Blueline_Core_Test_Archival_Wpdb();
		$GLOBALS['wpdb']->col_callback      = 'blueline_core_test_flagged_post_ids'; // The noindex exclusion's query.

		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_event';
		$state['taxonomies'][] = 'sp_season';

		$this->allow_small_seasons();
	}

	/**
	 * Let a season of one game rank. The fixture league plays a game or two per season, far below
	 * the real default minimum; the minimum has tests of its own that remove this.
	 */
	private function allow_small_seasons(): void {
		add_filter( 'blueline_core_archival_min_season_events', array( $this, 'one_event' ) );
	}

	/**
	 * Filter callback: a minimum of one event.
	 *
	 * @return int
	 */
	public function one_event(): int {
		return 1;
	}

	/**
	 * Restore globals.
	 */
	protected function tearDown(): void {
		$GLOBALS['bl_test_get_posts_model'] = $this->previous_model;
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Model of the get_posts() shapes the module uses: post_type, post_status, one sp_season
	 * tax_query clause (IN or NOT EXISTS) and a post_date date_query.
	 *
	 * @param array $args get_posts() args.
	 * @return int[]
	 */
	public function model_get_posts( array $args ): array {
		++$this->queries;

		$state    = &blueline_test_state();
		$statuses = (array) ( $args['post_status'] ?? 'publish' );
		$found    = array();

		foreach ( $state['posts'] as $post_id => $post ) {
			if ( ( $post['type'] ?? '' ) !== $args['post_type'] || ! in_array( $post['status'] ?? '', $statuses, true ) ) {
				continue;
			}

			$post_terms = $state['post_terms'][ $post_id ]['sp_season'] ?? array();
			$clause     = $args['tax_query'][0] ?? null;

			if ( $clause && 'NOT EXISTS' === $clause['operator'] && $post_terms ) {
				continue;
			}

			if ( $clause && 'IN' === $clause['operator'] && ! array_intersect( $post_terms, $clause['terms'] ) ) {
				continue;
			}

			$after = $args['date_query'][0]['after'] ?? null;
			if ( null !== $after && (string) $post['date'] < $after ) {
				continue;
			}

			$found[] = (int) $post_id;
		}

		sort( $found );

		return $found;
	}

	/**
	 * Register an sp_season term with a real slug.
	 *
	 * @param int    $id     Term ID.
	 * @param string $slug   Slug.
	 * @param int    $parent_id Parent term ID.
	 */
	private function season( int $id, string $slug, int $parent_id = 0 ): void {
		blueline_test_register_term( $id, 'sp_season', $slug, $parent_id );

		$state                       = &blueline_test_state();
		$state['terms'][ $id ]->slug = $slug;
	}

	/**
	 * Register a published (or other status) post with season terms.
	 *
	 * @param int    $id      Post ID.
	 * @param string $type    Post type.
	 * @param int[]  $seasons sp_season term IDs.
	 * @param string $date    post_date.
	 * @param string $status  Post status.
	 */
	private function post( int $id, string $type, array $seasons = array(), string $date = '2020-01-01 12:00:00', string $status = 'publish' ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'type'   => $type,
			'status' => $status,
			'date'   => $date,
		);

		blueline_test_set_post_terms( $id, 'sp_season', $seasons );
	}

	/**
	 * Register an event with a line-up.
	 *
	 * @param int    $id      Event ID.
	 * @param int[]  $seasons sp_season term IDs.
	 * @param string $date    post_date.
	 * @param int[]  $players Player IDs (0 for an empty slot).
	 * @param int[]  $teams   Team IDs.
	 * @param int[]  $staff   Staff IDs.
	 * @param string $status  Post status.
	 */
	private function event( int $id, array $seasons, string $date, array $players = array(), array $teams = array(), array $staff = array(), string $status = 'publish' ): void {
		$this->post( $id, 'sp_event', $seasons, $date, $status );

		$state                                  = &blueline_test_state();
		$state['post_meta'][ $id ]['sp_player'] = new Blueline_Test_Meta_Rows( $players );
		$state['post_meta'][ $id ]['sp_team']   = new Blueline_Test_Meta_Rows( $teams );
		$state['post_meta'][ $id ]['sp_staff']  = new Blueline_Test_Meta_Rows( $staff );
	}

	/**
	 * A season calendar like the league's: two sessions a year, playoff terms as children, and
	 * next season's term created early (it has the highest term ID and no events).
	 *
	 * Recent with the default of two seasons: w2026-27 (6) and s2026 (5, whose playoffs run later
	 * than anything else but the new winter season).
	 */
	private function seed_league(): void {
		$this->season( 1, 'w2024-25' );
		$this->season( 2, 's2025' );
		$this->season( 3, 'w2025-26' );
		$this->season( 4, 'w2025-26-playoffs', 3 );
		$this->season( 5, 's2026' );
		$this->season( 6, 'w2026-27' );
		$this->season( 8, 's2026-playoffs', 5 );
		$this->season( 7, 'w2027-28' ); // Created early: no events, highest term ID among regular seasons.

		$this->event( 100, array( 1 ), '2025-02-10 20:00:00', array( 200, 0 ), array( 300 ), array( 0 ) );
		$this->event( 101, array( 2 ), '2025-08-10 20:00:00', array( 0 ), array( 0 ) );
		$this->event( 102, array( 3 ), '2026-03-01 20:00:00', array( 200, 205 ), array( 300 ), array( 400 ) );
		$this->event( 103, array( 4 ), '2026-03-29 20:00:00' ); // Playoff term only, of a season that is not recent.
		$this->event( 104, array( 5 ), '2026-07-20 20:00:00', array( 205 ), array( 301 ) );
		$this->event( 105, array( 8 ), '2026-08-28 20:00:00', array( 203 ) ); // Playoff term only, of a recent season.
		$this->event( 106, array( 6 ), '2026-10-18 20:00:00', array( 201 ), array( 301 ), array( 401 ), 'future' );
		$this->event( 107, array(), '2026-09-15 20:00:00' ); // No season, dated inside the recent seasons.
		$this->event( 108, array(), '2020-09-15 20:00:00' ); // No season, long ago.
		$this->event( 109, array( 1 ), '2025-02-11 20:00:00', array(), array(), array(), 'draft' ); // Not published: never listed.

		$this->post( 200, 'sp_player', array( 1, 3 ) ); // Only ever on past seasons.
		$this->post( 201, 'sp_player', array() ); // In a future event's line-up only.
		$this->post( 202, 'sp_player', array( 6 ) ); // New registrant: current season tag, no games yet.
		$this->post( 203, 'sp_player', array() ); // In a recent playoff event's line-up only.
		$this->post( 204, 'sp_player', array() ); // No events, no season.
		$this->post( 205, 'sp_player', array( 3 ) ); // Past tag, but plays in a recent event.
		$this->post( 206, 'sp_player', array( 3 ), '2020-01-01 12:00:00', 'draft' ); // A draft is never listed.

		$this->post( 300, 'sp_team', array( 1, 3 ) );
		$this->post( 301, 'sp_team', array( 5 ) );
		$this->post( 400, 'sp_staff', array( 3 ) );
		$this->post( 401, 'sp_staff', array( 6 ) );

		$this->post( 500, 'sp_table', array( 3 ) ); // Past season only.
		$this->post( 501, 'sp_table', array( 3, 5 ) ); // Spans a recent season.
		$this->post( 502, 'sp_table', array( 8 ) ); // A recent season's playoff term.
		$this->post( 503, 'sp_list', array() ); // No season: never archival.
		$this->post( 504, 'sp_calendar', array( 1 ) );
		$this->post( 505, 'sp_list', array( 4 ) ); // A past season's playoff term.

		$this->post( 600, 'page' );
		$this->post( 601, 'post', array( 1 ) );
	}

	// -- Season families and recency (pure) ------------------------------------------------------

	/**
	 * A playoff term belongs to its parent season; an orphan playoff term belongs to nothing.
	 */
	public function test_season_families_attach_playoff_terms_to_their_season(): void {
		$families = blueline_archival_season_families(
			array(
				array(
					'term_id' => 5,
					'slug'    => 's2026',
					'parent'  => 0,
				),
				array(
					'term_id' => 8,
					'slug'    => 's2026-playoffs',
					'parent'  => 5,
				),
				array(
					'term_id' => 9,
					'slug'    => 'w2099-playoffs',
					'parent'  => 0,
				),
				array(
					'term_id' => 10,
					'slug'    => 'x-playoffs',
					'parent'  => 8,
				),
			)
		);

		$this->assertSame(
			array(
				5 => 5,
				8 => 5,
			),
			$families
		);
	}

	/**
	 * Recency is the latest event date, not the term ID, a playoff term's dates count toward
	 * its season, and a season with no events does not rank.
	 */
	public function test_recent_seasons_are_ranked_by_latest_event_date(): void {
		$families = array(
			1 => 1,
			2 => 2,
			3 => 3,
			4 => 3,
			7 => 7,
		);
		$ranges   = array(
			1 => array(
				'first_date' => '2025-01-01 10:00:00',
				'last_date'  => '2025-03-01 10:00:00',
			),
			2 => array(
				'first_date' => '2025-06-01 10:00:00',
				'last_date'  => '2025-08-01 10:00:00',
			),
			3 => array(
				'first_date' => '2025-10-01 10:00:00',
				'last_date'  => '2026-02-01 10:00:00',
			),
			4 => array(
				'first_date' => '2026-03-01 10:00:00',
				'last_date'  => '2026-03-29 10:00:00',
			),
		);

		$this->assertSame(
			array(
				'terms' => array( 3, 2 ),
				'floor' => '2025-06-01 10:00:00',
			),
			blueline_archival_pick_recent( $families, $ranges, 2 )
		);

		$this->assertSame( array( 3 ), blueline_archival_pick_recent( $families, $ranges, 1 )['terms'] );
		$this->assertSame( array( 3, 2, 1 ), blueline_archival_pick_recent( $families, $ranges, 9 )['terms'], 'Season 7 has no events and never ranks.' );
		$this->assertSame(
			array(
				'terms' => array(),
				'floor' => '',
			),
			blueline_archival_pick_recent( $families, $ranges, 0 )
		);
	}

	/**
	 * A season with fewer events than the minimum never ranks, however late its games: a stray
	 * test season cannot push a real one out. A playoff term's events count toward its season.
	 */
	public function test_seasons_below_the_event_minimum_do_not_rank(): void {
		$families = array(
			5 => 5,
			8 => 5,
			6 => 6,
			9 => 9,
		);
		$ranges   = array(
			5 => array(
				'first_date' => '2026-04-24 18:00:00',
				'last_date'  => '2026-07-31 23:00:00',
				'events'     => 7,
			),
			8 => array(
				'first_date' => '2026-08-07 18:00:00',
				'last_date'  => '2026-08-28 23:00:00',
				'events'     => 4,
			),
			6 => array(
				'first_date' => '2026-09-25 18:45:00',
				'last_date'  => '2026-10-18 21:00:00',
				'events'     => 58,
			),
			9 => array(
				'first_date' => '2026-10-02 19:00:00',
				'last_date'  => '2026-10-29 19:00:00', // The latest games of all, but only two of them.
				'events'     => 2,
			),
		);

		$this->assertSame( array( 6, 5 ), blueline_archival_pick_recent( $families, $ranges, 2, 10 )['terms'], 'Season 5 has 7 + 4 events with its playoffs.' );
		$this->assertSame( array( 9, 6 ), blueline_archival_pick_recent( $families, $ranges, 2, 0 )['terms'], 'No minimum: ranked by date alone.' );
		$this->assertSame( array( 6 ), blueline_archival_pick_recent( $families, $ranges, 2, 12 )['terms'] );
		$this->assertSame( array(), blueline_archival_pick_recent( $families, $ranges, 2, 59 )['terms'] );
	}

	/**
	 * Through the real computation with the default minimum: a stray two-game test season dated
	 * after everything else is ignored and the real seasons stay current.
	 */
	public function test_a_stray_test_season_does_not_displace_a_real_one(): void {
		remove_filter( 'blueline_core_archival_min_season_events', array( $this, 'one_event' ) );
		$this->assertSame( 10, blueline_archival_min_season_events() );

		$this->season( 5, 's2026' );
		$this->season( 6, 'w2026-27' );
		$this->season( 9, 's2099' );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->event( 100 + $i, array( 5 ), sprintf( '2026-07-%02d 20:00:00', 10 + $i ) );
			$this->event( 200 + $i, array( 6 ), sprintf( '2026-10-%02d 20:00:00', 1 + $i ) );
		}
		$this->event( 300, array( 9 ), '2026-10-20 19:00:00' );
		$this->event( 301, array( 9 ), '2026-10-27 19:00:00' );

		$data = blueline_archival_compute();

		$this->assertSame( array( 6, 5 ), $data['recent_terms'] );
		$this->assertSame( 10, $data['min_events'] );
		$this->assertSame( array( 300, 301 ), $data['archival']['sp_event'], 'The stray season is the one left out.' );
	}

	/**
	 * The minimum is filterable and clamped at 0 (0 ranks every season with events).
	 */
	public function test_season_event_minimum_filter_is_clamped(): void {
		remove_filter( 'blueline_core_archival_min_season_events', array( $this, 'one_event' ) );
		add_filter(
			'blueline_core_archival_min_season_events',
			static function () {
				return -3;
			}
		);

		$this->assertSame( 0, blueline_archival_min_season_events() );
	}

	// -- Classification --------------------------------------------------------------------------

	/**
	 * The default two seasons: events, players (past-only, current, new registrant, playoff-only,
	 * no history), teams, staff and the tagged types are classified as the owner defined them.
	 */
	public function test_classification_with_the_default_two_seasons(): void {
		$this->seed_league();

		$data = blueline_archival_compute();

		$this->assertSame( array( 6, 5 ), $data['recent_terms'], 'The early-created w2027-28 term has no events and is not recent.' );
		$this->assertSame( array( 100, 101, 102, 103, 108 ), $data['archival']['sp_event'] );
		$this->assertSame( array( 200, 204 ), $data['archival']['sp_player'], 'A player only on past seasons is archival; 201, 202, 203 and 205 are current.' );
		$this->assertSame( array( 300 ), $data['archival']['sp_team'] );
		$this->assertSame( array( 400 ), $data['archival']['sp_staff'] );
		$this->assertSame( array( 500 ), $data['archival']['sp_table'] );
		$this->assertSame( array( 505 ), $data['archival']['sp_list'], 'A list with no season (503) is never archival.' );
		$this->assertSame( array( 504 ), $data['archival']['sp_calendar'] );
		$this->assertSame( 8, $data['totals']['sp_event'], 'Published events only: the scheduled 106 and the draft 109 are not counted.' );
		$this->assertSame( 6, $data['totals']['sp_player'], 'The draft player 206 is not counted.' );
	}

	/**
	 * A player on the current season's roster is not archival however long since a game: the
	 * season tag alone keeps them current.
	 */
	public function test_a_player_on_the_current_season_is_not_archival(): void {
		$this->seed_league();

		$this->assertNotContains( 202, blueline_archival_compute()['archival']['sp_player'] );
	}

	/**
	 * A playoff term's events do not rank as a season of their own and never make a past season
	 * recent: with three seasons the winter season (and its playoff events) is current.
	 */
	public function test_playoff_child_terms_follow_their_season(): void {
		$this->seed_league();

		$two   = blueline_archival_compute( 2 );
		$three = blueline_archival_compute( 3 );

		$this->assertContains( 103, $two['archival']['sp_event'], 'A past season\'s playoff-only event stays archival.' );
		$this->assertSame( array( 6, 5, 3 ), $three['recent_terms'] );
		$this->assertNotContains( 103, $three['archival']['sp_event'] );
		$this->assertNotContains( 505, $three['archival']['sp_list'] );
		$this->assertNotContains( 200, $three['archival']['sp_player'], 'Player 200 is tagged w2025-26, now recent.' );
	}

	/**
	 * Without any season that has events, nothing can be told apart as past, so nothing is
	 * archival.
	 */
	public function test_nothing_is_archival_without_dated_seasons(): void {
		$this->season( 1, 'w2026-27' );
		$this->post( 100, 'sp_player', array() );
		$this->post( 101, 'sp_event', array() );

		$data = blueline_archival_compute();

		$this->assertSame( array(), $data['recent_terms'] );
		$this->assertSame( array(), $data['archival']['sp_player'] );
		$this->assertSame( array(), $data['archival']['sp_event'] );
	}

	/**
	 * Venues, news posts, pages and anything else not listed are never archival.
	 */
	public function test_other_post_types_are_never_archival(): void {
		$this->seed_league();

		$data = blueline_archival_compute();

		$this->assertSame( array( 'sp_event', 'sp_player', 'sp_team', 'sp_staff', 'sp_table', 'sp_list', 'sp_calendar' ), $data['types'] );
		$this->assertArrayNotHasKey( 'page', $data['archival'] );
		$this->assertSame( array(), blueline_archival_ids( 'page' ) );
		$this->assertSame( array(), blueline_archival_ids( 'post' ) );
		$this->assertSame( array(), blueline_archival_ids( 'sp_venue' ) );
		$this->assertFalse(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 601,
					'post_type' => 'post',
				)
			)
		);
	}

	// -- Filters ---------------------------------------------------------------------------------

	/**
	 * The season count is filterable: 1 narrows what is current, 0 switches the exclusion off.
	 */
	public function test_recent_seasons_filter(): void {
		$this->seed_league();

		add_filter(
			'blueline_core_archival_recent_seasons',
			static function () {
				return 1;
			}
		);
		$one = blueline_archival_data();

		$this->assertSame( array( 6 ), $one['recent_terms'] );
		$this->assertContains( 104, $one['archival']['sp_event'], 's2026 is no longer recent.' );
		$this->assertNotContains( 106, $one['archival']['sp_event'] );
	}

	/**
	 * A count of 0 leaves everything out of the archival set and the sitemap query untouched.
	 */
	public function test_zero_recent_seasons_turns_the_exclusion_off(): void {
		$this->seed_league();

		add_filter(
			'blueline_core_archival_recent_seasons',
			static function () {
				return 0;
			}
		);

		$this->assertSame( array(), blueline_archival_ids( 'sp_event' ) );
		$this->assertSame( array( 'post_type' => 'sp_event' ), blueline_archival_sitemap_query_args( array( 'post_type' => 'sp_event' ), 'sp_event' ) );
	}

	/**
	 * A negative or non-numeric filter result is clamped, never an error.
	 */
	public function test_recent_seasons_filter_is_clamped(): void {
		add_filter(
			'blueline_core_archival_recent_seasons',
			static function () {
				return -4;
			}
		);

		$this->assertSame( 0, blueline_archival_recent_seasons() );
	}

	/**
	 * The per-post filter can mark a current post archival and rescue an archival one, for the
	 * single-post check and for the sitemap list alike.
	 */
	public function test_per_post_filter_overrides_the_computed_verdict(): void {
		$this->seed_league();

		add_filter(
			'blueline_core_is_archival_post',
			static function ( $archival, $post ) {
				if ( 205 === (int) $post->ID ) {
					return true; // Current player forced archival.
				}
				if ( 200 === (int) $post->ID ) {
					return false; // Archival player rescued.
				}
				return $archival;
			},
			10,
			2
		);

		$ids = blueline_archival_ids( 'sp_player' );

		$this->assertSame( array( 204, 205 ), $ids );
		$this->assertTrue(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 205,
					'post_type' => 'sp_player',
				)
			)
		);
		$this->assertFalse(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 200,
					'post_type' => 'sp_player',
				)
			)
		);
		$this->assertTrue(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 204,
					'post_type' => 'sp_player',
				)
			)
		);
	}

	/**
	 * Without a per-post filter the single-post check follows the computed set.
	 */
	public function test_is_archival_post_follows_the_computed_set(): void {
		$this->seed_league();

		$this->assertTrue(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 200,
					'post_type' => 'sp_player',
				)
			)
		);
		$this->assertFalse(
			blueline_is_archival_post(
				(object) array(
					'ID'        => 205,
					'post_type' => 'sp_player',
				)
			)
		);
		$this->assertFalse( blueline_is_archival_post( null ) );
	}

	/**
	 * The set of post types and their rules is filterable; an unknown rule is dropped.
	 */
	public function test_post_types_filter(): void {
		$this->seed_league();

		add_filter(
			'blueline_core_archival_post_types',
			static function ( $types ) {
				unset( $types['sp_player'] );
				$types['page'] = 'nonsense';
				return $types;
			}
		);

		$this->assertArrayNotHasKey( 'sp_player', blueline_archival_post_types() );
		$this->assertArrayNotHasKey( 'page', blueline_archival_post_types() );
		$this->assertSame( array(), blueline_archival_ids( 'sp_player' ) );
		$this->assertSame( array( 300 ), blueline_archival_ids( 'sp_team' ) );
	}

	// -- Cache -----------------------------------------------------------------------------------

	/**
	 * The result is computed once and then served from the transient, even after the request
	 * memo is gone.
	 */
	public function test_result_is_cached_in_a_transient(): void {
		$this->seed_league();

		blueline_archival_data();
		$after_first = $this->queries;
		$this->assertGreaterThan( 0, $after_first );
		$this->assertIsArray( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ) );

		blueline_archival_data();
		wp_cache_flush(); // A new request: the memo is gone, the transient stays.
		blueline_archival_data();

		$this->assertSame( $after_first, $this->queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->queries );
	}

	/**
	 * A cached copy computed under another season count, other post types or an older layout is
	 * not reused.
	 */
	public function test_cache_is_ignored_when_settings_or_layout_differ(): void {
		$this->seed_league();

		blueline_archival_data();
		$computed = $this->queries;

		add_filter(
			'blueline_core_archival_recent_seasons',
			static function () {
				return 3;
			}
		);
		wp_cache_flush();
		$this->assertSame( array( 6, 5, 3 ), blueline_archival_data()['recent_terms'] );
		$this->assertGreaterThan( $computed, $this->queries );

		// A changed event minimum is a changed setting too.
		$computed = $this->queries;
		add_filter(
			'blueline_core_archival_min_season_events',
			static function () {
				return 2;
			},
			20
		);
		wp_cache_flush();
		$this->assertSame( 2, blueline_archival_data()['min_events'] );
		$this->assertGreaterThan( $computed, $this->queries );

		blueline_test_reset_hooks();
		$this->allow_small_seasons();
		wp_cache_flush();
		set_transient(
			BLUELINE_ARCHIVAL_TRANSIENT,
			array(
				'version' => 0,
				'seasons' => 2,
				'types'   => array_keys( blueline_archival_post_types() ),
			)
		);
		$this->assertSame( array( 6, 5 ), blueline_archival_data()['recent_terms'] );
	}

	/**
	 * Saving, trashing or deleting an archival-capable post drops the cache; saving a page does
	 * not.
	 */
	public function test_saving_a_governed_post_invalidates_the_cache(): void {
		$this->seed_league();

		foreach ( array( 106, 202, 300, 401, 500 ) as $post_id ) {
			blueline_archival_data();
			$this->assertIsArray( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ) );

			do_action( 'save_post', $post_id );
			$this->assertFalse( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), 'save_post of ' . $post_id );
		}

		blueline_archival_data();
		do_action( 'before_delete_post', 200 );
		$this->assertFalse( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), 'delete' );

		blueline_archival_data();
		do_action( 'save_post', 600 );
		do_action( 'save_post', 99999 );
		$this->assertIsArray( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), 'A page or an unknown post leaves the cache alone.' );
	}

	/**
	 * A new event changes the answer as soon as it is saved: the next read recomputes.
	 */
	public function test_a_new_recent_event_updates_the_answer_after_invalidation(): void {
		$this->seed_league();

		$this->assertContains( 200, blueline_archival_ids( 'sp_player' ) );

		$this->event( 110, array( 6 ), '2026-10-25 20:00:00', array( 200 ), array(), array(), 'future' );
		$this->assertContains( 200, blueline_archival_ids( 'sp_player' ), 'Still cached.' );

		do_action( 'save_post', 110 );
		$this->assertNotContains( 200, blueline_archival_ids( 'sp_player' ) );
	}

	/**
	 * A status change (the cron publishing a scheduled event) drops the cache; an unchanged
	 * status or an ungoverned type does not.
	 */
	public function test_status_transitions_invalidate_the_cache(): void {
		$this->seed_league();

		blueline_archival_data();
		do_action( 'transition_post_status', 'publish', 'publish', (object) array( 'post_type' => 'sp_event' ) );
		do_action( 'transition_post_status', 'publish', 'future', (object) array( 'post_type' => 'page' ) );
		$this->assertIsArray( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ) );

		do_action( 'transition_post_status', 'publish', 'future', (object) array( 'post_type' => 'sp_event' ) );
		$this->assertFalse( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ) );
	}

	/**
	 * Season term changes drop the cache: an event or player re-tagged, a season created,
	 * renamed or deleted. Other taxonomies do not.
	 */
	public function test_season_term_changes_invalidate_the_cache(): void {
		$this->seed_league();

		blueline_archival_data();
		do_action( 'set_object_terms', 100, array( 1 ), array( 1 ), 'category', array(), array() );
		$this->assertIsArray( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), 'Another taxonomy.' );

		do_action( 'set_object_terms', 100, array( 6 ), array( 6 ), 'sp_season', array(), array() );
		$this->assertFalse( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), 'set_object_terms' );

		foreach ( array( 'created_sp_season', 'edited_sp_season', 'delete_sp_season' ) as $hook ) {
			blueline_archival_data();
			do_action( $hook, 7, 7 );
			$this->assertFalse( get_transient( BLUELINE_ARCHIVAL_TRANSIENT ), $hook );
		}
	}

	// -- Sitemap ---------------------------------------------------------------------------------

	/**
	 * Archival posts join post__not_in beside what other filters set; paging and every other
	 * argument pass through untouched, and types that are not governed are left alone.
	 */
	public function test_sitemap_query_args_exclude_archival_posts_and_keep_paging(): void {
		$this->seed_league();

		$args = blueline_archival_sitemap_query_args(
			array(
				'post_type'      => 'sp_player',
				'post__not_in'   => array( 9, 200 ),
				'posts_per_page' => 50,
				'paged'          => 3,
				'orderby'        => 'ID',
			),
			'sp_player'
		);

		$this->assertSame( array( 9, 200, 204 ), $args['post__not_in'] );
		$this->assertSame( 50, $args['posts_per_page'] );
		$this->assertSame( 3, $args['paged'] );
		$this->assertSame( 'ID', $args['orderby'] );

		$page = array( 'post_type' => 'page' );
		$this->assertSame( $page, blueline_archival_sitemap_query_args( $page, 'page' ) );
		$this->assertFalse( blueline_archival_sitemap_query_args( false, 'sp_event' ) );
	}

	/**
	 * Through the real hook, alongside the existing noindex exclusion: both lists apply, so the
	 * index entries (built from the same query) shrink with them.
	 */
	public function test_both_sitemap_filters_compose(): void {
		$this->seed_league();

		$this->assertSame( 11, has_filter( 'wp_sitemaps_posts_query_args', 'blueline_archival_sitemap_query_args' ) );

		update_post_meta( 301, BLUELINE_NOINDEX_META, '1' ); // Hidden by hand, and current, so only the flag drops it.

		$args = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'sp_team' ), 'sp_team' );

		$this->assertSame( array( 301, 300 ), $args['post__not_in'] );
	}

	/**
	 * When every post of a type is archival the type has nothing left to list (core then emits
	 * no sitemap entry for it, since it builds the entries from the result's page count).
	 */
	public function test_a_fully_archival_type_excludes_every_post(): void {
		$this->season( 1, 'w2026-27' );
		$this->season( 3, 's2026' );
		$this->season( 2, 'w2020-21' );
		$this->event( 100, array( 1 ), '2026-10-18 20:00:00', array( 500 ) );
		$this->event( 102, array( 3 ), '2026-07-18 20:00:00', array( 502 ) );
		$this->event( 101, array( 2 ), '2021-02-18 20:00:00', array( 501 ) );
		$this->post( 400, 'sp_staff', array( 2 ) );
		$this->post( 401, 'sp_staff', array( 2 ) );

		$args = blueline_archival_sitemap_query_args( array(), 'sp_staff' );

		$this->assertSame( array( 400, 401 ), $args['post__not_in'] );
	}

	// -- Site Health -----------------------------------------------------------------------------

	/**
	 * The Site Health test reports how many archival pages are left out and how many listed.
	 */
	public function test_site_health_reports_left_out_and_listed_counts(): void {
		$this->seed_league();

		$result = blueline_archival_site_health_result( blueline_archival_compute() );

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'blueline_archival_pages', $result['test'] );

		$data   = blueline_archival_compute();
		$left   = array_sum( array_map( 'count', $data['archival'] ) );
		$listed = array_sum( $data['totals'] ) - $left;

		$this->assertSame( sprintf( '%d archival pages are left out of the sitemap, %d listed', $left, $listed ), $result['label'] );
		$this->assertSame( 12, $left );
		$this->assertStringContainsString( 'stay indexable', $result['description'] );
	}

	/**
	 * With the exclusion off, or with nothing to compare against, the test says so instead of
	 * reporting zero.
	 */
	public function test_site_health_explains_the_off_and_empty_states(): void {
		$off = blueline_archival_site_health_result( blueline_archival_compute( 0 ) );
		$this->assertSame( 'Archival pages are not being left out of the sitemap', $off['label'] );

		$empty = blueline_archival_site_health_result( blueline_archival_compute( 2 ) );
		$this->assertSame( 'No archival pages are being left out of the sitemap', $empty['label'] );
	}

	/**
	 * The test is registered as a direct Site Health test.
	 */
	public function test_site_health_test_is_registered(): void {
		$this->assertSame( 10, has_filter( 'site_status_tests', 'blueline_archival_register_site_health_test' ) );

		$tests = blueline_archival_register_site_health_test( array( 'direct' => array() ) );

		$this->assertSame( 'blueline_archival_run_site_health_test', $tests['direct']['blueline_archival_pages']['test'] );
		$this->assertSame( 'not-an-array', blueline_archival_register_site_health_test( 'not-an-array' ) );
	}

	/**
	 * Every hook the module relies on is registered.
	 */
	public function test_hooks_are_registered(): void {
		$this->assertSame( 10, has_action( 'save_post', 'blueline_archival_invalidate_for_post' ) );
		$this->assertSame( 10, has_action( 'before_delete_post', 'blueline_archival_invalidate_for_post' ) );
		$this->assertSame( 10, has_action( 'transition_post_status', 'blueline_archival_invalidate_for_transition' ) );
		$this->assertSame( 10, has_action( 'set_object_terms', 'blueline_archival_invalidate_for_terms' ) );
		$this->assertSame( 10, has_action( 'created_sp_season', 'blueline_archival_invalidate' ) );
		$this->assertSame( 10, has_action( 'edited_sp_season', 'blueline_archival_invalidate' ) );
		$this->assertSame( 10, has_action( 'delete_sp_season', 'blueline_archival_invalidate' ) );
	}
}
