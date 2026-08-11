<?php
/**
 * Season state — decides what the site should emphasise right now.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision function. Kept free of WordPress calls so it can be unit tested.
 *
 * @param array $signals Keys: has_purchasable_product (bool), upcoming_events (int),
 *                       days_to_next_event (int|null), has_playoff_events (bool),
 *                       recent_events (int).
 * @return string One of registration_open|preseason|in_season|playoffs|offseason.
 */
function blueline_decide_season_state( array $signals ): string {
	$has_product = ! empty( $signals['has_purchasable_product'] );
	$upcoming    = (int) ( $signals['upcoming_events'] ?? 0 );
	$days        = $signals['days_to_next_event'] ?? null;
	$playoffs    = ! empty( $signals['has_playoff_events'] );
	$recent      = (int) ( $signals['recent_events'] ?? 0 );

	// Selling a registration is always the loudest signal.
	if ( $has_product ) {
		return 'registration_open';
	}

	if ( $playoffs && $upcoming > 0 ) {
		return 'playoffs';
	}

	if ( $upcoming > 0 ) {
		// Games already played this season means we are mid-season; otherwise it has not started.
		if ( $recent > 0 || ( null !== $days && $days <= 14 ) ) {
			return 'in_season';
		}
		return 'preseason';
	}

	return 'offseason';
}

/**
 * Term ID of the "Registration" product_cat parent. Each season's products
 * live in a child term of this one (e.g. "Winter 2026-27"); the newest
 * child by term_id is the current season.
 */
const BLUELINE_REGISTRATION_TERM_ID = 91;

/**
 * Gather live season-state signals, run them through the pure decision
 * function, and cache the result.
 *
 * Every WooCommerce/SportsPress touchpoint is guarded with
 * function_exists()/taxonomy_exists()/post_type_exists() so a deactivated
 * plugin degrades the corresponding signal(s) to falsy defaults instead of
 * fataling -- blueline_decide_season_state() then naturally resolves to
 * 'offseason' when every signal is falsy.
 *
 * Purchasability/stock are read exclusively through wc_get_product(
 * $id )->is_purchasable() / ->is_in_stock() -- never the
 * wp_wc_product_meta_lookup table. That lookup is the index catalog
 * queries use, and on this site it goes stale: writing `_stock_status`
 * post meta updates the product object but leaves the lookup row
 * unchanged, and neither $product->save() nor update_lookup_table()
 * syncs it. Reading the lookup table would make Season State disagree
 * with the catalogue.
 *
 * @return array {
 *     @type string   $state         One of the five season states.
 *     @type int|null $product_id    Purchasable product driving registration_open, if any.
 *     @type int|null $next_event_id Soonest upcoming sp_event, if any.
 * }
 */
function blueline_season_state_data() {
	$cached = get_transient( 'blueline_season_state' );
	if ( is_array( $cached ) && isset( $cached['state'] ) ) {
		return $cached;
	}

	$signals = array(
		'has_purchasable_product' => false,
		'upcoming_events'         => 0,
		'days_to_next_event'      => null,
		'has_playoff_events'      => false,
		'recent_events'           => 0,
	);

	$product_id    = null;
	$next_event_id = null;

	// --- WooCommerce: purchasable product in the current season's product_cat. ---
	if ( function_exists( 'wc_get_product' ) && taxonomy_exists( 'product_cat' ) ) {
		$season_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => BLUELINE_REGISTRATION_TERM_ID,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
				'number'     => 1,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $season_terms ) && ! empty( $season_terms ) ) {
			$season_term_id = $season_terms[0]->term_id;

			$product_query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to a single small season term, not an unbounded query.
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $season_term_id,
						),
					),
				)
			);

			foreach ( $product_query->posts as $candidate_id ) {
				$product = wc_get_product( $candidate_id );

				if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
					$signals['has_purchasable_product'] = true;
					$product_id                         = (int) $candidate_id;
					break;
				}
			}
		}
	}

	// --- SportsPress: upcoming/recent events, playoff detection. ---
	if ( post_type_exists( 'sp_event' ) ) {
		$now    = current_time( 'mysql' );
		$now_ts = strtotime( $now );

		$upcoming_query = new WP_Query(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
					array(
						'column' => 'post_date',
						'after'  => $now,
					),
				),
			)
		);

		$upcoming_ids = $upcoming_query->posts;

		$signals['upcoming_events'] = count( $upcoming_ids );

		if ( ! empty( $upcoming_ids ) ) {
			$next_event_id = (int) $upcoming_ids[0];
			$next_post     = get_post( $next_event_id );

			if ( $next_post ) {
				$diff_seconds                  = strtotime( $next_post->post_date ) - $now_ts;
				$signals['days_to_next_event'] = (int) ceil( $diff_seconds / DAY_IN_SECONDS );
			}

			// Playoffs: an upcoming event carries a term, in a league or
			// division taxonomy, whose slug contains "playoff". SportsPress
			// registers this as the `sp_league` taxonomy -- relabeled
			// "Divisions" in this site's admin UI -- but the taxonomies are
			// discovered dynamically so a site-added `sp_division` taxonomy
			// would also be picked up.
			$playoff_taxonomies = array_filter(
				get_object_taxonomies( 'sp_event' ),
				static function ( $taxonomy ) {
					return false !== strpos( $taxonomy, 'league' ) || false !== strpos( $taxonomy, 'division' );
				}
			);

			foreach ( $playoff_taxonomies as $taxonomy ) {
				$slugs = wp_get_object_terms( $upcoming_ids, $taxonomy, array( 'fields' => 'slugs' ) );

				if ( is_wp_error( $slugs ) ) {
					continue;
				}

				foreach ( $slugs as $slug ) {
					if ( false !== strpos( $slug, 'playoff' ) ) {
						$signals['has_playoff_events'] = true;
						break 2;
					}
				}
			}
		}

		$recent_query = new WP_Query(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
					array(
						'column'    => 'post_date',
						'after'     => gmdate( 'Y-m-d H:i:s', $now_ts - 30 * DAY_IN_SECONDS ),
						'before'    => $now,
						'inclusive' => true,
					),
				),
			)
		);

		$signals['recent_events'] = count( $recent_query->posts );
	}

	$state = blueline_decide_season_state( $signals );

	$data = array(
		'state'         => $state,
		'product_id'    => $product_id,
		'next_event_id' => $next_event_id,
	);

	set_transient( 'blueline_season_state', $data, 15 * MINUTE_IN_SECONDS );

	return $data;
}

/**
 * Public accessor: the current season state.
 *
 * @return string One of registration_open|preseason|in_season|playoffs|offseason.
 */
function blueline_season_state() {
	$data = blueline_season_state_data();

	/**
	 * Filters the computed season state -- e.g. to force a value while
	 * testing, or as a manual override during an unusual season transition.
	 *
	 * @param string $state Computed season state.
	 */
	return apply_filters( 'blueline_season_state', $data['state'] );
}

/**
 * Bust the cached season-state signals so the next read recomputes them.
 */
function blueline_bust_season_state_cache() {
	delete_transient( 'blueline_season_state' );
}

// Guarded: this file is require_once'd directly by tests/SeasonStateTest.php
// against the bare PHPUnit bootstrap, which stubs only a handful of WP
// functions (see tests/bootstrap.php) and does not define add_action(). The
// guard keeps that require side-effect-free while still wiring the real
// hooks under WordPress.
if ( function_exists( 'add_action' ) ) {
	add_action( 'save_post_product', 'blueline_bust_season_state_cache' );
	add_action( 'save_post_sp_event', 'blueline_bust_season_state_cache' );
	add_action( 'woocommerce_update_product', 'blueline_bust_season_state_cache' );
}
