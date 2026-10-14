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
	 * The get_posts() model in force before this test.
	 *
	 * @var mixed
	 */
	private $previous_model;

	/**
	 * Fresh state, plus a get_posts() model that also answers sp_event lookups.
	 */
	protected function setUp(): void {
		parent::setUp();
		blueline_test_reset();
		blueline_test_reset_state();

		$this->previous_model               = $GLOBALS['bl_test_get_posts_model'] ?? null;
		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) {
			if ( 'sp_event' !== ( $args['post_type'] ?? '' ) ) {
				return blueline_core_test_claim_pool_model( $args );
			}

			$state  = &blueline_test_state();
			$events = array();
			foreach ( $state['posts'] as $post_id => $post ) {
				if ( 'sp_event' === ( $post['type'] ?? '' ) && ( $post['status'] ?? '' ) === $args['post_status'] ) {
					$events[ (int) $post_id ] = (string) $post['date'];
				}
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
