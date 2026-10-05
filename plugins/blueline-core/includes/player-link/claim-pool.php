<?php
/**
 * The claim pool: which sp_player posts a name claim (and the sp_user backfill) is scored against.
 *
 * Season-scoped through the sp_season taxonomy, not sp_current_team: that meta is a sticky
 * "last team ever" value set on nearly every player ever created, so alone it would offer
 * years-old players as claim candidates. The decision logic (sparse check, term resolver) is
 * pure and unit tested; the functions that touch WordPress are thin wrappers around it.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * A newest season whose roster is below this fraction of the previous season's is "still filling
 * in": rosters start empty and grow as registrants are assigned to teams, so the test is
 * relative to the previous roster rather than a fixed headcount.
 */
const BLUELINE_CLAIM_POOL_SPARSE_RATIO = 0.5;

/**
 * Recognised sp_season slug shape: a session letter ("w" Winter, "s" Summer) then a digit, for
 * example "w2026-27". A real pattern, not "the first character", so an unrecognised slugging
 * convention is reported instead of silently merging two sessions into one pool.
 */
const BLUELINE_SEASON_SLUG_SESSION_PATTERN = '/^([ws])\d/i';

/**
 * Pure decision: is the newest season's roster too small, relative to the previous one's, to be
 * the claim pool on its own?
 *
 * @param int $current_count  Newest non-playoff sp_season term's player count.
 * @param int $previous_count The next most recent non-playoff term's player count (0 if none).
 * @return bool True if the newest term should be treated as not yet representative.
 */
function blueline_is_claim_pool_sparse( int $current_count, int $previous_count ): bool {
	if ( $current_count <= 0 ) {
		return true;
	}

	if ( $previous_count <= 0 ) {
		return false; // Nothing to compare against, so take the newest term at face value.
	}

	return ( $current_count / $previous_count ) < BLUELINE_CLAIM_POOL_SPARSE_RATIO;
}

/**
 * The session letter ("w" or "s") of an sp_season slug, or null when the slug does not match
 * BLUELINE_SEASON_SLUG_SESSION_PATTERN. Null is a real answer: callers must not guess a session.
 *
 * @param string $slug sp_season term slug.
 * @return string|null
 */
function blueline_season_slug_session_letter( string $slug ): ?string {
	if ( ! preg_match( BLUELINE_SEASON_SLUG_SESSION_PATTERN, $slug, $matches ) ) {
		return null;
	}

	return strtolower( $matches[1] );
}

/**
 * Report, under WP_DEBUG, an sp_season term whose slug has no recognised session, rather than
 * silently excluding it or guessing.
 *
 * @param int    $term_id sp_season term ID.
 * @param string $slug    Its slug.
 */
function blueline_log_nonconforming_season_slug( int $term_id, string $slug ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated: an unclassifiable season slug must never fail silently.
	error_log(
		sprintf(
			'[blueline] sp_season term %1$d has slug "%2$s", which does not match the recognised w<digit>/s<digit> session shape. Excluded from the claim/backfill candidate pool rather than guessed into a session.',
			$term_id,
			$slug
		)
	);
}

/**
 * The number of sp_player posts tagged with $term_id in sp_season.
 *
 * Not the term's own ->count: sp_season is shared by sp_event, sp_table and sp_player, so
 * ->count overstates a roster by every event and table tagged with the same season.
 *
 * @param int $term_id sp_season term ID.
 * @return int
 */
function blueline_sp_season_player_count( int $term_id ): int {
	$query = new WP_Query(
		array(
			'post_type'      => 'sp_player',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term, a handful of calls per pool resolution, not a listing query.
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * Pure resolver: which sp_season term_id(s) form the claim pool.
 *
 * Takes the non-playoff terms newest first, as plain data, and a callable reporting a term's real
 * player count. Pure, so logging of unclassifiable slugs is the caller's job.
 *
 * - Session-scoped: the league runs Winter and Summer sessions on one interleaved term_id
 *   sequence, so every comparison and fallback stays within the newest term's session letter.
 *   Walking the sequence blindly lands on the OTHER session's term and hides current
 *   registrants who do not play both sessions.
 * - Additive: the newest term is ALWAYS included; while it is sparse, the most recent
 *   same-session term with players is ADDED, never substituted. Substituting would hide a
 *   first-time registrant (tagged only with the new season) exactly when they need the claim
 *   flow, and keeping both leaves returning players (still tagged with the previous season)
 *   reachable.
 * - A term whose slug has no recognised session is excluded, not guessed. If the newest term
 *   itself is unclassifiable there is no session anchor and the result is empty, which the
 *   caller treats as "use the broad pool".
 *
 * @param array<int, array{term_id:int, slug:string}> $terms    Non-playoff sp_season terms, newest first.
 * @param callable                                    $count_fn int $term_id -> int sp_player member count.
 * @return int[] Term ID(s) to include in the pool; empty if nothing usable.
 */
function blueline_resolve_claim_pool_term_ids( array $terms, callable $count_fn ): array {
	if ( empty( $terms ) ) {
		return array();
	}

	$current_letter = blueline_season_slug_session_letter( $terms[0]['slug'] );

	if ( null === $current_letter ) {
		return array();
	}

	$same_session_terms = array();
	foreach ( $terms as $term ) {
		if ( blueline_season_slug_session_letter( $term['slug'] ) === $current_letter ) {
			$same_session_terms[] = $term;
		}
	}

	if ( empty( $same_session_terms ) ) {
		return array();
	}

	$current        = $same_session_terms[0];
	$current_count  = $count_fn( $current['term_id'] );
	$previous_count = isset( $same_session_terms[1] ) ? $count_fn( $same_session_terms[1]['term_id'] ) : 0;

	$term_ids = array( (int) $current['term_id'] );

	if ( ! blueline_is_claim_pool_sparse( $current_count, $previous_count ) ) {
		return $term_ids;
	}

	foreach ( array_slice( $same_session_terms, 1 ) as $term ) {
		if ( $count_fn( $term['term_id'] ) > 0 ) {
			$term_ids[] = (int) $term['term_id'];
			break; // Current plus ONE fallback, not every older term with members.
		}
	}

	return $term_ids;
}

/**
 * The sp_season term_id(s) whose members form the claim pool: the current season, plus the most
 * recent populated same-session one while the current one is sparse (see
 * blueline_resolve_claim_pool_term_ids(), which holds the decision logic).
 *
 * Playoff terms are excluded up front (the same "slug contains playoff" signal as Season State):
 * they are a postseason subset of a roster, not its base.
 *
 * Memoised for the request in the module's non-persistent cache group: the pool is the same for
 * every candidate and for the handler's second candidate lookup, and each resolution costs a
 * get_terms() plus one or two counts.
 *
 * @return int[] Term ID(s), possibly empty (the pool then degrades to the broad,
 *               sp_current_team-only one).
 */
function blueline_claim_pool_season_term_ids(): array {
	$group  = blueline_player_link_cache_group();
	$cached = wp_cache_get( 'claim_pool_season_term_ids', $group, false, $found );

	if ( $found ) {
		return (array) $cached;
	}

	$term_ids = blueline_compute_claim_pool_season_term_ids();
	wp_cache_set( 'claim_pool_season_term_ids', $term_ids, $group );

	return $term_ids;
}

/**
 * Resolve the claim pool's season term IDs from the database, uncached.
 * See blueline_claim_pool_season_term_ids() for what the pool is.
 *
 * @return int[] Term ID(s), possibly empty.
 */
function blueline_compute_claim_pool_season_term_ids(): array {
	if ( ! taxonomy_exists( 'sp_season' ) ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'sp_season',
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$non_playoff_terms = array_values(
		array_filter(
			$terms,
			static function ( $term ) {
				return false === strpos( $term->slug, 'playoff' );
			}
		)
	);

	if ( empty( $non_playoff_terms ) ) {
		return array();
	}

	// The newest term anchors the whole resolution. If its shape cannot be classified, degrade to
	// the broad pool rather than shift the anchor to the next-newest term, which would be a guess.
	if ( null === blueline_season_slug_session_letter( $non_playoff_terms[0]->slug ) ) {
		blueline_log_nonconforming_season_slug( (int) $non_playoff_terms[0]->term_id, $non_playoff_terms[0]->slug );
		return array();
	}

	$season_terms = array();
	foreach ( $non_playoff_terms as $term ) {
		if ( null === blueline_season_slug_session_letter( $term->slug ) ) {
			blueline_log_nonconforming_season_slug( (int) $term->term_id, $term->slug );
			continue;
		}

		$season_terms[] = array(
			'term_id' => (int) $term->term_id,
			'slug'    => $term->slug,
		);
	}

	return blueline_resolve_claim_pool_term_ids( $season_terms, 'blueline_sp_season_player_count' );
}

/**
 * The sp_player IDs eligible to be claimed: tagged with the claim pool's season term(s), with a
 * team set, and not linked to a DIFFERENT user. A player linked to $exclude_linked_to_user_id
 * stays in (re-checking one's own link), as does one carrying a '' or '0' placeholder sp_user;
 * 0 excludes every linked player.
 *
 * Without a usable season pool (sp_season missing, no terms, or an unclassifiable newest slug)
 * the season filter is dropped and only sp_current_team applies: broader and more
 * collision-prone, but a working claim flow beats a broken one.
 *
 * Queried at SQL level, ids only: this can run on a logged-in page load, and loading post
 * objects to filter in PHP would not scale.
 *
 * @param int $exclude_linked_to_user_id The user whose own link stays in the pool.
 * @return int[] Player post IDs.
 */
function blueline_current_season_unclaimed_player_ids( int $exclude_linked_to_user_id = 0 ): array {
	$query_args = array(
		'post_type'      => 'sp_player',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'none',
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- SQL-level filtering is the fast path, see docblock.
			'relation' => 'AND',
			array(
				'key'     => 'sp_current_team',
				'value'   => array( '', '0' ),
				'compare' => 'NOT IN',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => BLUELINE_PLAYER_USER_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => BLUELINE_PLAYER_USER_META,
					'value'   => array( '', '0' ),
					'compare' => 'IN',
				),
				array(
					'key'     => BLUELINE_PLAYER_USER_META,
					'value'   => (string) $exclude_linked_to_user_id,
					'compare' => '=',
				),
			),
		),
	);

	$season_term_ids = blueline_claim_pool_season_term_ids();

	if ( ! empty( $season_term_ids ) ) {
		$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to one or two season terms.
			array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => $season_term_ids, // An array with the default IN compare matches ANY term, which is what makes the pool additive.
			),
		);
	}

	return get_posts( $query_args );
}
