<?php
/**
 * Shared "current season" fixtures, loaded by BOTH suites: the plugin's CurrentSeasonTest and the
 * theme's CurrentSeasonTest assert every call site in their code base against the same expected
 * answers, so a resolver that drifts from the shared definition fails in either suite.
 *
 * Seasons are staging's real terms (2026-10-14): S2026 (666) with its Playoffs child 667,
 * W2026-27 (674) with its Playoffs child 680, plus an orphan playoffs term (690) with no parent.
 * "Now" is 2026-10-14 12:00 in the league's time zone.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * The theme suite's wp_get_object_terms() stand-in, the same contract (and store) as
	 * themes/blueline/tests/HeroPlaceholderFieldsTest.php's: whatever a test put in
	 * $GLOBALS['bl_test_object_terms'] for ($object_id, $taxonomy). The plugin suite defines its own
	 * in tests/stubs/season.php first; blueline_test_current_season_seed() fills both stores.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy name.
	 * @param array  $args      Unused; kept for signature parity with WP core.
	 * @return array
	 */
	function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core.
		return $GLOBALS['bl_test_object_terms'][ $taxonomy ][ (int) $object_id ] ?? array();
	}
}

if ( ! function_exists( 'blueline_test_current_season_scenarios' ) ) {

	/**
	 * Every scenario: the setting (null for unset), the events (ID, status, date, season terms,
	 * sp_status), and the expected current season, its term IDs and the phase term.
	 *
	 * @return array<string, array{setting: string|null, events: array<int, array{0:int, 1:string, 2:string, 3:int[], 4?:string}>, current: int, term_ids: int[], phase: int}>
	 */
	function blueline_test_current_season_scenarios(): array {
		$staging_events = array(
			array( 117500, 'publish', '2026-10-09 21:00:00', array( 674 ) ),
			array( 117518, 'future', '2026-10-16 18:45:00', array( 674 ) ),
		);

		return array(
			'staging today: the setting and the next game agree' => array(
				'setting'  => '674',
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting unset: the next game decides, as before' => array(
				'setting'  => null,
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting unset during playoffs: the season, playoff phase' => array(
				'setting'  => null,
				'events'   => array(
					array( 117500, 'publish', '2026-10-09 21:00:00', array( 674 ) ),
					array( 117600, 'future', '2026-10-16 18:45:00', array( 680 ) ),
				),
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 680,
			),
			'setting equal to the season during playoffs' => array(
				'setting'  => '674',
				'events'   => array(
					array( 117500, 'publish', '2026-10-09 21:00:00', array( 674 ) ),
					array( 117600, 'future', '2026-10-16 18:45:00', array( 680 ) ),
				),
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 680,
			),
			'board has not flipped yet: the older setting wins' => array(
				'setting'  => '666',
				'events'   => array(
					array( 116493, 'publish', '2026-08-28 23:00:00', array( 667 ) ),
					array( 117518, 'future', '2026-10-16 18:45:00', array( 674 ) ),
				),
				'current'  => 666,
				'term_ids' => array( 666, 667 ),
				'phase'    => 666,
			),
			'setting names a deleted term: fall back'     => array(
				'setting'  => '999',
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting is 0: fall back'                     => array(
				'setting'  => '0',
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting is not a number: fall back'          => array(
				'setting'  => 'w2026-27',
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting names a Playoffs term: its season'   => array(
				'setting'  => '680',
				'events'   => $staging_events,
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'setting names an orphan Playoffs term: used as is' => array(
				'setting'  => '690',
				'events'   => $staging_events,
				'current'  => 690,
				'term_ids' => array( 690 ),
				'phase'    => 690,
			),
			'between seasons after playoffs: the last game played' => array(
				'setting'  => null,
				'events'   => array(
					array( 116400, 'publish', '2026-08-01 19:00:00', array( 666 ) ),
					array( 116493, 'publish', '2026-08-28 23:00:00', array( 667 ) ),
				),
				'current'  => 666,
				'term_ids' => array( 666, 667 ),
				'phase'    => 667,
			),
			'a postponed next game is skipped'            => array(
				'setting'  => null,
				'events'   => array(
					array( 117500, 'publish', '2026-10-09 21:00:00', array( 674 ) ),
					array( 117510, 'future', '2026-10-15 18:45:00', array( 680 ), 'postponed' ),
					array( 117518, 'future', '2026-10-16 18:45:00', array( 674 ) ),
				),
				'current'  => 674,
				'term_ids' => array( 674, 680 ),
				'phase'    => 674,
			),
			'no setting and no games: no current season'  => array(
				'setting'  => null,
				'events'   => array(),
				'current'  => 0,
				'term_ids' => array(),
				'phase'    => 0,
			),
		);
	}

	/**
	 * Seed the shared stub store with the seasons, a scenario's events and its setting, at "now".
	 *
	 * @param array $scenario One of blueline_test_current_season_scenarios().
	 * @return void
	 */
	function blueline_test_current_season_seed( array $scenario ): void {
		$state        = &blueline_test_state();
		$state['now'] = ( new DateTimeImmutable( '2026-10-14 12:00:00', wp_timezone() ) )->getTimestamp();

		$state['post_types'] = array_values( array_unique( array_merge( $state['post_types'], array( 'sp_event', 'sp_table' ) ) ) );

		$seasons = array(
			array( 666, 's2026', 'S2026', 0 ),
			array( 667, 's2026-playoffs', 'S2026 Playoffs', 666 ),
			array( 674, 'w2026-27', 'W2026-27', 0 ),
			array( 680, 'w2026-27-playoffs', 'W2026-27 Playoffs', 674 ),
			array( 690, 'old-playoffs', 'Old Playoffs', 0 ),
		);

		foreach ( $seasons as $season ) {
			blueline_test_register_term( $season[0], 'sp_season', $season[2], $season[3] );
			$state['terms'][ $season[0] ]->slug = $season[1];
		}

		foreach ( $scenario['events'] as $event ) {
			$state['posts'][ $event[0] ] = array(
				'type'   => 'sp_event',
				'status' => $event[1],
				'date'   => $event[2],
			);
			blueline_test_set_post_terms( $event[0], 'sp_season', $event[3] );
			// The theme suite's wp_get_object_terms() stand-in reads this store instead.
			$GLOBALS['bl_test_object_terms']['sp_season'][ $event[0] ] = $event[3];

			if ( isset( $event[4] ) ) {
				$state['post_meta'][ $event[0] ]['sp_status'] = $event[4];
			}
		}

		if ( null !== $scenario['setting'] ) {
			$GLOBALS['bl_test_options']['sportspress_season'] = $scenario['setting'];
		}
	}

	/**
	 * A get_posts() model for sp_event lookups the way WP_Query answers them: post_status (one or
	 * several), a post_date date_query's after/before/inclusive bounds, the postponed/cancelled
	 * sp_status exclusion, date order and posts_per_page. Anything else goes to $fallback.
	 *
	 * @param callable|null $fallback Model for other post types; null answers "not mine".
	 * @return callable
	 */
	function blueline_test_current_season_get_posts_model( ?callable $fallback = null ): callable {
		return static function ( array $args ) use ( $fallback ) {
			if ( 'sp_event' !== ( $args['post_type'] ?? '' ) ) {
				return $fallback ? $fallback( $args ) : null;
			}

			$state    = &blueline_test_state();
			$statuses = (array) ( $args['post_status'] ?? 'publish' );
			$live     = isset( $args['meta_query'] );
			$events   = array();

			foreach ( $state['posts'] as $post_id => $post ) {
				if ( 'sp_event' !== ( $post['type'] ?? '' ) || ! in_array( $post['status'] ?? '', $statuses, true ) ) {
					continue;
				}

				if ( $live && in_array( $state['post_meta'][ $post_id ]['sp_status'] ?? '', array( 'postponed', 'cancelled' ), true ) ) {
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

			if ( 'ASC' === ( $args['order'] ?? 'DESC' ) ) {
				asort( $events );
			} else {
				arsort( $events );
			}

			return array_slice( array_keys( $events ), 0, max( 1, (int) ( $args['posts_per_page'] ?? 1 ) ) );
		};
	}

	/**
	 * What Season State reports as the next game for a seeded scenario (the earliest event dated
	 * after now that is scheduled or published and going ahead), or 0.
	 *
	 * @return int
	 */
	function blueline_test_current_season_next_live_event_id(): int {
		$model = blueline_test_current_season_get_posts_model();
		$ids   = $model(
			array(
				'post_type'   => 'sp_event',
				'post_status' => array( 'publish', 'future' ),
				'order'       => 'ASC',
				'date_query'  => array( array( 'after' => current_time( 'mysql' ) ) ),
				'meta_query'  => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a stub's argument, not a query.
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}
}
