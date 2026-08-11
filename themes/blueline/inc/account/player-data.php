<?php
/**
 * League-facing data readers for the My Account dashboard.
 *
 * Every reader here degrades to null/zeros instead of throwing when
 * SportsPress or WooCommerce is missing, or when the player/user has no
 * team, no upcoming game, no recorded stats, or no orders. About ~16% of
 * current-season players carry no sp_user link (Task 16's corrected figure:
 * 76 of 90 current-sp_season players ARE linked, i.e. 84%; the "~88%
 * unlinked" this file used to claim came from the retracted, sticky
 * sp_current_team denominator), and an unlinked or half-populated player is
 * still a routine case a reader must not assume away.
 *
 * STICKY FIELD WARNING: the three readers below key off `sp_current_team`
 * unqualified, and that field is "last team this player was ever rostered
 * onto", never season-scoped -- so a player who last skated in 2019 legitimately
 * resolves to that 2019 team here, with "Record not available yet" and no next
 * game. That is honest degradation (an old team, plainly empty of current
 * data), not a wrong answer, and is deliberately left as-is; do not read these
 * as "this season's team" without adding a season qualifier yourself.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coerce raw SportsPress stat data (missing keys, string numbers, empty
 * strings) into a fixed, always-present, always-int shape.
 *
 * @param array $raw Raw stat data, any shape.
 * @return array{gp:int, g:int, a:int, pim:int}
 */
function blueline_normalize_player_stats( array $raw ): array {
	$out = array();
	foreach ( array( 'gp', 'g', 'a', 'pim' ) as $key ) {
		$out[ $key ] = isset( $raw[ $key ] ) && '' !== $raw[ $key ] ? (int) $raw[ $key ] : 0;
	}
	return $out;
}

/**
 * $team_id's own sp_table post for the season currently driving the
 * schedule (blueline_current_sp_season_term_id()) -- the specific
 * standings table this team is rostered into for that season, found by
 * checking each season-scoped table's own sp_teams roster meta, not by
 * matching strings in the post title (unlike the simpler,
 * Division-1-only homepage snippet in inc/homepage-modules.php, this must
 * work for a team in ANY division).
 *
 * SportsPress's sp_table posts, unlike sp_team/sp_player, carry exactly
 * ONE sp_season term and exactly ONE sp_league term each -- confirmed live: table
 * "Division 1 | S2026" (116143) carries only the S2026 term and only the
 * "Division 1" term, with none of the historical accumulation
 * sp_team/sp_player posts carry (see blueline_player_division_name()'s
 * docblock). That makes a team's current-season table the one clean,
 * genuinely season-scoped anchor available anywhere in this taxonomy for
 * BOTH division and record.
 *
 * @param int $team_id sp_team post ID.
 * @return int|null Null if there is no current season, or no table for it
 *                   rosters this team (e.g. a team not yet assigned for a
 *                   season that just started).
 */
function blueline_team_current_table_id( int $team_id ): ?int {
	if ( $team_id <= 0 || ! post_type_exists( 'sp_table' ) || ! taxonomy_exists( 'sp_season' ) ) {
		return null;
	}

	$season_id = blueline_current_sp_season_term_id();

	if ( ! $season_id ) {
		return null;
	}

	$table_ids = get_posts(
		array(
			'post_type'      => 'sp_table',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to one season, a handful of division tables.
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => $season_id,
				),
			),
		)
	);

	foreach ( $table_ids as $table_id ) {
		$rostered_teams = get_post_meta( $table_id, 'sp_teams', true );

		if ( is_array( $rostered_teams ) && array_key_exists( $team_id, $rostered_teams ) ) {
			return (int) $table_id;
		}
	}

	return null;
}

/**
 * $team_id's division (sp_league term) for the season currently driving
 * the schedule, read from that season's own sp_table post via
 * blueline_team_current_table_id() -- NOT from the team's own sp_league
 * terms directly.
 *
 * An earlier version of this function picked the team's own HIGHEST
 * term_id sp_league term as a "most recently tagged" guess, and its
 * docblock claimed blueline_sp_team_hero() (inc/sportspress.php) made the
 * "identical choice." That claim was wrong: blueline_sp_team_hero()
 * renders EVERY sp_league term comma-joined, with no single-value
 * selection at all, so the two pages could disagree about the same
 * team's division. Both the team's own terms and the player's own terms
 * accumulate every division ever tagged with no "current" flag
 * (confirmed live: team 14955 alone carries 7 different Division terms
 * spanning several seasons) -- "highest term_id" is an artifact of
 * insertion order, not a rule tied to which season is actually current,
 * so it was never defensible.
 *
 * Resolving through the team's current-season sp_table instead is
 * genuinely season-scoped, not a heuristic over insertion order: that
 * table post carries exactly one sp_league term (confirmed live -- see
 * blueline_team_current_table_id()'s docblock).
 *
 * Failure mode, stated plainly: if no table for the current season
 * rosters this team (e.g. a newly created team not yet assigned for a
 * season that just started), this returns '' rather than falling back to
 * a guess -- the My Team dashboard module (inc/account/dashboard.php)
 * already omits the division line entirely when it is empty, which is
 * the honest behaviour here: no confident-but-wrong division, ever.
 *
 * @param int $team_id sp_team post ID.
 * @return string
 */
function blueline_player_division_name( int $team_id ): string {
	$table_id = blueline_team_current_table_id( $team_id );

	if ( ! $table_id || ! taxonomy_exists( 'sp_league' ) ) {
		return '';
	}

	$terms = wp_get_post_terms( $table_id, 'sp_league' );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	return (string) $terms[0]->name;
}

/**
 * $team_id's own row from SportsPress's own already-computed
 * SP_League_Table::data() for the season currently driving the schedule
 * -- the authoritative record source review asked for, instead of a
 * hand-rolled W/L/T aggregation off sp_results (which, for the three most
 * recently played events checked live, was entirely empty -- see the
 * docblock on blueline_get_player_season_stats() for the same
 * sparse-results observation). Confirmed live: table 116143 ("Division 1
 * | S2026") computes team 111510's own row as
 * `['gp'=>14,'w'=>5,'l'=>6,'tie'=>3,'ot'=>0,'pts'=>13,...]` -- real,
 * non-placeholder data, because SP_League_Table derives it from the
 * table's own curated `sp_teams` roster plus event results, not from a
 * single event's sp_results meta the way a naive per-event tally would.
 *
 * @param int $team_id sp_team post ID.
 * @return array<string,mixed>|null The raw computed row (column keys
 *         depend on this site's configured result variables, e.g.
 *         gp/w/l/tie/ot/pts for this hockey install) -- or null if
 *         SportsPress's table class is unavailable or no current-season
 *         table rosters this team (blueline_team_current_table_id()).
 */
function blueline_get_team_record( int $team_id ): ?array {
	if ( $team_id <= 0 || ! class_exists( 'SP_League_Table' ) ) {
		return null;
	}

	$table_id = blueline_team_current_table_id( $team_id );

	if ( ! $table_id ) {
		return null;
	}

	$table = new SP_League_Table( $table_id );
	$data  = $table->data();

	if ( ! is_array( $data ) || empty( $data[ $team_id ] ) || ! is_array( $data[ $team_id ] ) ) {
		return null;
	}

	return $data[ $team_id ];
}

/**
 * $player_id's jersey number (sp_number), or null if unset.
 *
 * @param int $player_id sp_player post ID.
 * @return string|null
 */
function blueline_player_jersey_number( int $player_id ) {
	$number = get_post_meta( $player_id, 'sp_number', true );

	return ( '' !== $number && null !== $number ) ? (string) $number : null;
}

/**
 * $player_id's current team (sp_current_team), bundled with the fields the
 * brief's interface names for this reader: crest, division, and the
 * player's own jersey number. Division is resolved through the team's
 * current-season sp_table (blueline_player_division_name()), not the
 * team's or player's own accumulated sp_league terms directly.
 *
 * "Record" is deliberately NOT part of this return shape -- the brief's
 * interface for THIS function lists exactly team_id/name/logo_id/
 * division/number, and record needed a different, table-based lookup
 * (blueline_get_team_record()) added after review. Keeping it a separate
 * call, called directly by the My Team renderer
 * (inc/account/dashboard.php), avoids silently growing this documented
 * interface.
 *
 * @param int $player_id sp_player post ID.
 * @return array{team_id:int, name:string, logo_id:?int, division:string, number:?string}|null
 *         Null if SportsPress is inactive, the player has no team, or the
 *         team post no longer exists/is unpublished.
 */
function blueline_get_player_team( int $player_id ): ?array {
	if ( $player_id <= 0 || ! post_type_exists( 'sp_player' ) || ! post_type_exists( 'sp_team' ) ) {
		return null;
	}

	$team_id = absint( get_post_meta( $player_id, 'sp_current_team', true ) );

	if ( ! $team_id || 'publish' !== get_post_status( $team_id ) ) {
		return null;
	}

	return array(
		'team_id'  => $team_id,
		'name'     => function_exists( 'blueline_sp_title' ) ? blueline_sp_title( $team_id ) : get_the_title( $team_id ),
		'logo_id'  => has_post_thumbnail( $team_id ) ? (int) get_post_thumbnail_id( $team_id ) : null,
		'division' => blueline_player_division_name( $team_id ),
		'number'   => blueline_player_jersey_number( $player_id ),
	);
}

/**
 * The sp_season term id currently driving the schedule: the season of the
 * next upcoming sp_event if there is one, otherwise the season of the most
 * recently played one.
 *
 * Deliberately its own copy rather than reusing
 * blueline_homepage_active_event_season_term_id() (inc/homepage-modules.php,
 * Task 7): that function is scoped to -- and may evolve for reasons
 * specific to -- the homepage hero/standings snippet. Coupling "My season"
 * stats to it would mean a future homepage-only change could silently
 * change what this reader shows. The two are intentionally allowed to
 * drift; this copy is small enough that the duplication is cheaper than
 * the coupling.
 *
 * @return int|null
 */
function blueline_current_sp_season_term_id(): ?int {
	if ( ! post_type_exists( 'sp_event' ) || ! taxonomy_exists( 'sp_season' ) ) {
		return null;
	}

	$state_data = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();
	$event_id   = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;

	if ( ! $event_id ) {
		$recent = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$event_id = ! empty( $recent ) ? (int) $recent[0] : 0;
	}

	if ( ! $event_id ) {
		return null;
	}

	$terms = wp_get_object_terms( $event_id, 'sp_season', array( 'fields' => 'ids' ) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}

	return (int) $terms[0];
}

/**
 * The soonest sp_event whose sp_team meta includes $player_id's current
 * team, today or later. post_status MUST include 'future' -- WordPress
 * core assigns that status (not 'publish') to any post dated ahead of now,
 * and every genuinely upcoming sp_event on this site carries it (33 of 33
 * on staging; see BLUELINE_PUBLISHED_STATUS's docblock in
 * inc/season-state.php for the confirmed count). A publish-only query here
 * would silently show a player's LAST game as their "next" one.
 *
 * @param int $player_id sp_player post ID.
 * @return array{event_id:int, timestamp:int|null, venue:string, venue_term_id:?int, opponent_team_id:?int, is_home:bool}|null
 */
function blueline_get_player_next_event( int $player_id ): ?array {
	if ( $player_id <= 0 || ! post_type_exists( 'sp_event' ) ) {
		return null;
	}

	$team_id = absint( get_post_meta( $player_id, 'sp_current_team', true ) );

	if ( ! $team_id ) {
		return null;
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped to one team's own games, not an unbounded query.
				array(
					'key'   => 'sp_team',
					'value' => (string) $team_id,
				),
			),
			'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
				array(
					'column' => 'post_date',
					'after'  => current_time( 'mysql' ),
				),
			),
		)
	);

	if ( empty( $query->posts ) ) {
		return null;
	}

	$event_id = (int) $query->posts[0];

	$teams   = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
	$is_home = ! empty( $teams ) && $team_id === $teams[0];

	$opponent_team_id = null;
	foreach ( $teams as $t ) {
		if ( $t !== $team_id ) {
			$opponent_team_id = $t;
			break;
		}
	}

	$venue_terms = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue' ) : array();
	$venue_term  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : null;

	$timestamp = function_exists( 'blueline_sp_event_start_timestamp' ) ? blueline_sp_event_start_timestamp( $event_id ) : false;

	return array(
		'event_id'         => $event_id,
		'timestamp'        => $timestamp ? $timestamp : null,
		'venue'            => $venue_term ? (string) $venue_term->name : '',
		'venue_term_id'    => $venue_term ? (int) $venue_term->term_id : null,
		'opponent_team_id' => $opponent_team_id,
		'is_home'          => $is_home,
	);
}

/**
 * $player_id's normalized stat line for their current team in the season
 * currently driving the schedule (blueline_current_sp_season_term_id()).
 *
 * SportsPress stores sp_statistics on the player as [ team_id =>
 * [ season_id => [ 'gp'=>, 'g'=>, 'a'=>, 'pim'=>, 'p'=> ] ] ] -- confirmed live on
 * staging (player 683: team 8 / season 57 held real g/a/pim/p/gp values).
 * Every team a player has EVER been on, and every season that ever
 * existed, gets a placeholder row (usually all empty strings), so the
 * common case for a player with no recorded stats this season is a
 * present-but-blank nested array, not a missing key -- both are handled
 * identically here via blueline_normalize_player_stats().
 *
 * @param int $player_id sp_player post ID.
 * @return array{gp:int, g:int, a:int, pim:int} Always the four keys, zero-filled if unavailable.
 */
function blueline_get_player_season_stats( int $player_id ): array {
	$zero = blueline_normalize_player_stats( array() );

	if ( $player_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		return $zero;
	}

	$team_id = absint( get_post_meta( $player_id, 'sp_current_team', true ) );

	if ( ! $team_id ) {
		return $zero;
	}

	$season_id = blueline_current_sp_season_term_id();

	if ( ! $season_id ) {
		return $zero;
	}

	$stats = get_post_meta( $player_id, 'sp_statistics', true );

	if ( ! is_array( $stats )
		|| empty( $stats[ $team_id ][ $season_id ] )
		|| ! is_array( $stats[ $team_id ][ $season_id ] ) ) {
		return $zero;
	}

	return blueline_normalize_player_stats( $stats[ $team_id ][ $season_id ] );
}

/**
 * The most recent of $user_id's orders (newest first) containing a
 * product from $product_ids, or null. Paginated in small batches rather
 * than one `limit => -1` fetch of the user's entire order history: the
 * match is almost always in the very first, most-recent page (a
 * registrant's newest order is normally for the current season), so this
 * short-circuits after page one in the common case while still bounding
 * the worst case (a user who registered for the current season only in
 * some older order) to at most `$max_pages` batches rather than hydrating
 * every order a long-time customer has ever placed.
 *
 * @param int   $user_id     WordPress user ID.
 * @param int[] $product_ids Product IDs that count as a match.
 * @return WC_Order|null
 */
function blueline_find_registration_order( int $user_id, array $product_ids ) {
	$batch_size = 20;
	$max_pages  = 10;

	for ( $page = 1; $page <= $max_pages; $page++ ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => $batch_size,
				'paged'       => $page,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		foreach ( (array) $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( in_array( $item->get_product_id(), $product_ids, true ) ) {
					return $order;
				}
			}
		}

		if ( count( $orders ) < $batch_size ) {
			return null; // Last page reached with no match.
		}
	}

	return null;
}

/**
 * $user_id's registration for the current season: the most recent
 * WooCommerce order containing a product in the newest child product_cat
 * of Registration (term 91 -- the same season-resolution rule Season
 * State uses, see BLUELINE_REGISTRATION_TERM_ID in inc/season-state.php).
 *
 * @param int $user_id WordPress user ID.
 * @return array{season:string, order_id:int, status:string, paid:bool}|null
 *         Null if WooCommerce is missing, no product exists for the
 *         current season, or the user has no order containing one.
 */
function blueline_get_user_registration_status( int $user_id ): ?array {
	if ( $user_id <= 0
		|| ! function_exists( 'wc_get_orders' )
		|| ! taxonomy_exists( 'product_cat' )
		|| ! defined( 'BLUELINE_REGISTRATION_TERM_ID' ) ) {
		return null;
	}

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

	if ( is_wp_error( $season_terms ) || empty( $season_terms ) ) {
		return null;
	}

	$season_term = $season_terms[0];

	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to one small season term, not an unbounded query.
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $season_term->term_id,
				),
			),
		)
	);

	if ( empty( $product_ids ) ) {
		return null;
	}

	$order = blueline_find_registration_order( $user_id, $product_ids );

	if ( ! $order ) {
		return null;
	}

	return array(
		'season'   => (string) $season_term->name,
		'order_id' => $order->get_id(),
		'status'   => $order->get_status(),
		'paid'     => (bool) $order->is_paid(),
	);
}
