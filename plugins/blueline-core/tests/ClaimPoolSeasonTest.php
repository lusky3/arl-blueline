<?php
/**
 * Unit tests for how the claim pool picks its seasons: the season being played (from the
 * schedule), the resolver wiring, and the season label on a claim candidate.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';

/**
 * Covers blueline_claim_pool_active_season_term_ids(), blueline_compute_claim_pool_season_term_ids()
 * and the candidate season label.
 */
final class ClaimPoolSeasonTest extends TestCase {

	/**
	 * The get_posts() model saved in setUp() and restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_model;

	/**
	 * Fresh state at a fixed "now", plus a get_posts() model that
	 * also answers sp_event lookups, honouring post_status (one or several) and a post_date
	 * date_query's after/before bounds the way WP_Query does.
	 */
	protected function setUp(): void {
		parent::setUp();
		blueline_test_reset();
		blueline_test_reset_state();

		$state        = &blueline_test_state();
		$state['now'] = ( new DateTimeImmutable( '2026-10-14 12:00:00', wp_timezone() ) )->getTimestamp();

		$this->previous_model               = $GLOBALS['bl_test_get_posts_model'] ?? null;
		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) {
			if ( 'sp_event' !== ( $args['post_type'] ?? '' ) ) {
				return blueline_core_test_claim_pool_model( $args );
			}

			$state    = &blueline_test_state();
			$statuses = (array) $args['post_status'];
			$events   = array();
			foreach ( $state['posts'] as $post_id => $post ) {
				if ( 'sp_event' !== ( $post['type'] ?? '' ) || ! in_array( $post['status'] ?? '', $statuses, true ) ) {
					continue;
				}

				$date = (string) $post['date'];
				foreach ( $args['date_query'] ?? array() as $clause ) {
					$inclusive = ! empty( $clause['inclusive'] );
					if ( isset( $clause['after'] ) && ( $inclusive ? $date < $clause['after'] : $date <= $clause['after'] ) ) {
						continue 2;
					}
					if ( isset( $clause['before'] ) && ( $inclusive ? $date > $clause['before'] : $date >= $clause['before'] ) ) {
						continue 2;
					}
				}

				$events[ (int) $post_id ] = $date;
			}

			'DESC' === $args['order'] ? arsort( $events ) : asort( $events );

			return array_slice( array_keys( $events ), 0, (int) $args['posts_per_page'] );
		};
	}

	/**
	 * Restore the shared get_posts() model and drop the fake $wpdb.
	 */
	protected function tearDown(): void {
		$GLOBALS['bl_test_get_posts_model'] = $this->previous_model;
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_core_test_claim_pool_queries'] );
		parent::tearDown();
	}

	/**
	 * Register an sp_season term with a real slug (the shared helper makes "term-<id>").
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    Slug, e.g. w2026-27.
	 */
	private function season( int $term_id, string $slug ): void {
		blueline_test_register_term( $term_id, 'sp_season', strtoupper( $slug ) );
		$state                            = &blueline_test_state();
		$state['terms'][ $term_id ]->slug = $slug;
		$state['post_types']              = array_values( array_unique( array_merge( $state['post_types'], array( 'sp_event' ) ) ) );
	}

	/**
	 * Register an sp_event with a status, a date and its season term(s).
	 *
	 * @param int    $event_id Post ID.
	 * @param string $status   'future' or 'publish'.
	 * @param string $date     Y-m-d H:i:s.
	 * @param int[]  $seasons  sp_season term IDs.
	 */
	private function event( int $event_id, string $status, string $date, array $seasons ): void {
		$state                       = &blueline_test_state();
		$state['posts'][ $event_id ] = array(
			'type'   => 'sp_event',
			'status' => $status,
			'date'   => $date,
		);
		blueline_test_set_post_terms( $event_id, 'sp_season', $seasons );
	}

	/**
	 * The staging seasons: an empty stray s2099 above the winter season being played.
	 */
	private function seed_staging_seasons(): void {
		$this->season( 654, 'w2025-26' );
		$this->season( 666, 's2026' );
		$this->season( 674, 'w2026-27' );
		$this->season( 682, 's2099' );
	}

	/**
	 * Player counts per season on staging.
	 *
	 * @return callable
	 */
	private function staging_counts(): callable {
		$counts = array(
			654 => 524,
			666 => 300,
			674 => 496,
			682 => 0,
		);

		return static function ( $term_id ) use ( $counts ) {
			return $counts[ $term_id ] ?? 0;
		};
	}

	/**
	 * The season being played is the next scheduled game's, not the newest term's.
	 */
	public function test_active_season_is_the_next_scheduled_games_season(): void {
		$this->seed_staging_seasons();
		$this->event( 900, 'publish', '2026-10-01 19:00:00', array( 666 ) );
		$this->event( 901, 'future', '2026-10-20 19:00:00', array( 674 ) );
		$this->event( 902, 'future', '2026-11-20 19:00:00', array( 682 ) );

		$this->assertSame( array( 674 ), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * Between seasons (nothing scheduled) the most recently played game's season is used.
	 */
	public function test_active_season_between_seasons_is_the_last_played_games_season(): void {
		$this->seed_staging_seasons();
		$this->event( 900, 'publish', '2026-03-01 19:00:00', array( 654 ) );
		$this->event( 901, 'publish', '2026-08-30 19:00:00', array( 666 ) );

		$this->assertSame( array( 666 ), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * The staging repro: two "TEST S2099" events stuck in 'future' (a missed schedule: never
	 * published, dated Oct 2 and Oct 9, already past) must not stand in for the next game. The
	 * next game really scheduled is W2026-27's.
	 */
	public function test_past_dated_unpublished_events_are_not_the_next_game(): void {
		$this->seed_staging_seasons();
		$this->event( 117630, 'future', '2026-10-02 19:00:00', array( 682 ) );
		$this->event( 117631, 'future', '2026-10-09 19:00:00', array( 682 ) );
		$this->event( 117500, 'publish', '2026-10-09 21:00:00', array( 674 ) );
		$this->event( 117518, 'future', '2026-10-16 18:45:00', array( 674 ) );

		$this->assertSame( array( 674 ), blueline_claim_pool_active_season_term_ids() );
		$this->assertSame( array( 674 ), blueline_compute_claim_pool_season_term_ids( $this->staging_counts() ) );
	}

	/**
	 * Between seasons, an unpublished event of another season dated in the past (or a draft) is
	 * not "the last game played": only a published one is.
	 */
	public function test_between_seasons_unpublished_events_are_ignored(): void {
		$this->seed_staging_seasons();
		$this->event( 900, 'publish', '2026-08-30 19:00:00', array( 666 ) );
		$this->event( 117630, 'future', '2026-10-02 19:00:00', array( 682 ) );
		$this->event( 117632, 'draft', '2026-10-10 19:00:00', array( 682 ) );

		$this->assertSame( array( 666 ), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * A game published ahead of its date still counts as scheduled, and the earliest scheduled
	 * game wins whichever of the two statuses it has.
	 */
	public function test_a_published_future_game_is_the_next_game(): void {
		$this->seed_staging_seasons();
		$this->event( 900, 'publish', '2026-10-09 19:00:00', array( 666 ) );
		$this->event( 901, 'publish', '2026-10-20 19:00:00', array( 674 ) );
		$this->event( 902, 'future', '2026-11-01 19:00:00', array( 682 ) );

		$this->assertSame( array( 674 ), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * With nothing scheduled from now on, the most recent game played (published, dated up to now)
	 * decides, not a published game that is still ahead of the stray ones.
	 */
	public function test_no_upcoming_games_falls_back_to_the_last_played_season(): void {
		$this->seed_staging_seasons();
		$this->event( 900, 'publish', '2026-03-01 19:00:00', array( 654 ) );
		$this->event( 901, 'publish', '2026-10-13 19:00:00', array( 674 ) );
		$this->event( 117631, 'future', '2026-10-09 19:00:00', array( 682 ) );

		$this->assertSame( array( 674 ), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * No events, or no SportsPress, means no active season (the resolver then uses the newest term).
	 */
	public function test_active_season_is_empty_without_events(): void {
		$this->assertSame( array(), blueline_claim_pool_active_season_term_ids() );

		$this->seed_staging_seasons();
		$this->assertSame( array(), blueline_claim_pool_active_season_term_ids() );
	}

	/**
	 * End to end on the staging data: with s2099 created but empty, the pool is the winter season
	 * being played, not s2099 plus last summer.
	 */
	public function test_pool_follows_the_season_being_played_on_staging_data(): void {
		$this->seed_staging_seasons();
		$this->event( 901, 'future', '2026-10-20 19:00:00', array( 674 ) );

		$this->assertSame( array( 674 ), blueline_compute_claim_pool_season_term_ids( $this->staging_counts() ) );
	}

	/**
	 * A winter-only player is a claim candidate again, and a player tagged with last summer and
	 * this winter is labelled with this winter.
	 */
	public function test_winter_player_is_offered_and_labelled_with_the_current_season(): void {
		blueline_core_test_seed_claim_pool(
			5,
			'Brent Posthuma',
			array(
				300 => 'Brent Posthuma',
				301 => 'Somebody Else',
			)
		);
		$this->seed_staging_seasons();
		$this->event( 901, 'future', '2026-10-20 19:00:00', array( 674 ) );
		blueline_test_set_post_terms( 300, 'sp_season', array( 666, 674 ) );
		blueline_test_set_post_terms( 301, 'sp_season', array( 666 ) );

		wp_cache_set( 'claim_pool_season_term_ids', blueline_compute_claim_pool_season_term_ids( $this->staging_counts() ), blueline_player_link_cache_group() );

		$candidates = blueline_find_player_candidates( 5 );

		$this->assertCount( 1, $candidates );
		$this->assertSame( 300, $candidates[0]['player_id'] );
		$this->assertSame( 'W2026-27', $candidates[0]['season'] );
	}
}
