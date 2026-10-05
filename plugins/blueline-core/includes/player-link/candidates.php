<?php
/**
 * Claim candidates for the player-link module: the account-side name, the pool lookup, and the
 * scorer that applies the name gate (see name-match.php) to every pool player.
 *
 * Every claim path crosses blueline_score_player_candidates(): the claim card, the claim handler,
 * blueline_link_player_to_user() and the sp_user backfill script.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The display name to match a WordPress user against a SportsPress player: billing first and
 * last name (set at checkout), falling back to the display name.
 *
 * ATTACKER-CONTROLLED: both billing fields are editable by the account holder at
 * /account/edit-address/ and display_name at /account/edit-account/. Nothing this returns is
 * evidence of identity on its own; see blueline_name_pair_is_specific_enough().
 *
 * @param int $user_id WordPress user ID.
 * @return string Trimmed full name; empty for a user with neither.
 */
function blueline_user_match_name( int $user_id ): string {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return '';
	}

	$first = trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
	$last  = trim( (string) get_user_meta( $user_id, 'billing_last_name', true ) );
	$name  = trim( $first . ' ' . $last );

	if ( '' === $name ) {
		$name = trim( (string) ( $user->display_name ?? '' ) );
	}

	return $name;
}

/**
 * Candidate current-season players for $user_id to claim, scored by name against the account's
 * billing or display name, best first.
 *
 * Players already linked to a different user are never offered. That is also what keeps the
 * admin_post_blueline_claim_player handler safe: it only accepts a player_id from this list.
 *
 * The account name is USER-EDITABLE, so every pair must clear
 * blueline_name_pair_is_specific_enough() before it is scored; see
 * blueline_score_player_candidates().
 *
 * @param int $user_id WordPress user ID.
 * @return array<int, array{player_id:int, score:float, name:string, team:string, season:string, number:string}> Sorted descending by score.
 */
function blueline_find_player_candidates( int $user_id ): array {
	if ( ! post_type_exists( 'sp_player' ) || ! blueline_user_can_claim_by_name( $user_id ) ) {
		return array();
	}

	$name = blueline_user_match_name( $user_id );
	if ( '' === $name ) {
		return array();
	}

	// Cheap half of the name gate, applied before the pool query: an account name under
	// BLUELINE_MATCH_MIN_TOKENS tokens cannot produce a candidate against ANY player title.
	if ( count( blueline_name_tokens( $name ) ) < BLUELINE_MATCH_MIN_TOKENS ) {
		return array();
	}

	$player_ids = blueline_current_season_unclaimed_player_ids( $user_id );
	if ( empty( $player_ids ) ) {
		return array();
	}

	$titles = blueline_get_post_titles( $player_ids );

	// Re-key in the pool's own order (the title query returns rows in whatever order it likes)
	// so equal scores keep a stable tie-break.
	$ordered_titles = array();
	foreach ( $player_ids as $player_id ) {
		$ordered_titles[ (int) $player_id ] = (string) ( $titles[ $player_id ] ?? '' );
	}

	$candidates = blueline_score_player_candidates( $name, $ordered_titles );

	foreach ( $candidates as &$candidate ) {
		$candidate += blueline_player_candidate_detail( $candidate['player_id'] );
	}
	unset( $candidate );

	return $candidates;
}

/**
 * Disambiguating detail for one claim candidate (team, season, jersey number), shown beside the
 * bare name on the claim card so two players sharing a name are not identical rows.
 *
 * Presentation only: it runs after a candidate has cleared the gate and never influences who is
 * offered.
 *
 * @param int $player_id sp_player post ID.
 * @return array{team: string, season: string, number: string} Any field may be '' if unavailable.
 */
function blueline_player_candidate_detail( int $player_id ): array {
	$team = function_exists( 'blueline_get_player_team' ) ? blueline_get_player_team( $player_id ) : null;

	$number = function_exists( 'blueline_player_jersey_number' ) ? blueline_player_jersey_number( $player_id ) : null;

	return array(
		'team'   => $team ? $team['name'] : '',
		'season' => blueline_player_candidate_season_label( $player_id ),
		'number' => null !== $number ? $number : '',
	);
}

/**
 * The name of the claim-pool season term $player_id carries, which is the season that made them
 * a candidate, not their whole sp_season tag history. Pool terms are newest first, so a player
 * tagged with both the current and a fallback season reports the current one.
 *
 * @param int $player_id sp_player post ID.
 * @return string Term name, or '' if sp_season is inactive, the pool is empty, or the player
 *                carries none of the pool's terms.
 */
function blueline_player_candidate_season_label( int $player_id ): string {
	if ( ! taxonomy_exists( 'sp_season' ) ) {
		return '';
	}

	$pool_term_ids = blueline_claim_pool_season_term_ids();
	if ( empty( $pool_term_ids ) ) {
		return '';
	}

	$terms = wp_get_post_terms( $player_id, 'sp_season' );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	foreach ( $pool_term_ids as $term_id ) {
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === $term_id ) {
				return (string) $term->name;
			}
		}
	}

	return '';
}

/**
 * Pure scorer: for one account name and a player_id => player_name map, the candidates worth
 * offering, best first. A candidate must clear BOTH bars:
 *
 *   1. blueline_name_pair_is_specific_enough(): neither side may be a single-token fragment.
 *   2. BLUELINE_MATCH_THRESHOLD on blueline_name_match_score().
 *
 * Free of WordPress calls so the gate can be unit tested directly.
 *
 * @param string             $name         The account's own name (blueline_user_match_name()).
 * @param array<int, string> $player_names player_id => post_title.
 * @return array<int, array{player_id:int, score:float, name:string}> Sorted descending by score.
 */
function blueline_score_player_candidates( string $name, array $player_names ): array {
	$candidates = array();

	foreach ( $player_names as $player_id => $player_name ) {
		$player_name = (string) $player_name;
		if ( '' === $player_name ) {
			continue;
		}

		if ( ! blueline_name_pair_is_specific_enough( $name, $player_name ) ) {
			continue;
		}

		$score = blueline_name_match_score( $name, $player_name );
		if ( $score >= BLUELINE_MATCH_THRESHOLD ) {
			$candidates[] = array(
				'player_id' => (int) $player_id,
				'score'     => $score,
				'name'      => $player_name,
			);
		}
	}

	usort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

	return $candidates;
}

/**
 * The post_title for each of $post_ids in one query, instead of a get_post() per row.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, string> post ID => post_title.
 */
function blueline_get_post_titles( array $post_ids ): array {
	$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
	if ( empty( $post_ids ) ) {
		return array();
	}

	global $wpdb;

	// A fixed string of %d tokens sized to count( $post_ids ), never user input; values are bound by prepare() below.
	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one batch title fetch with no core API; %d placeholders only (the sniff cannot see them through the interpolation), values bound by prepare(); uncached so titles are always current.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $post_ids ) );

	$titles = array();
	foreach ( (array) $rows as $row ) {
		$titles[ (int) $row->ID ] = (string) $row->post_title;
	}

	return $titles;
}
