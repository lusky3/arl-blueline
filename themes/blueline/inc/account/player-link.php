<?php
/**
 * Linking a WordPress user to a SportsPress player.
 *
 * Only ~12% of current-season players carry sp_user, so everything here must
 * behave sensibly when no link exists.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_PLAYER_USER_META = 'sp_user';
const BLUELINE_MATCH_THRESHOLD  = 0.85;

// phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- docblock and body below are kept verbatim per the task-11 brief; not to be "improved".
/**
 * Lowercase, de-accent, strip punctuation, collapse whitespace.
 */
function blueline_normalize_name( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '';
	}
	$translit = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- verbatim per brief; failure is handled explicitly via the false check below.
	if ( false !== $translit ) {
		$name = $translit;
	}
	$name = strtolower( $name );
	// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- verbatim per brief.
	$name = str_replace( array( "'", '’' ), '', $name );  // O'Connor -> oconnor
	$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
	return trim( preg_replace( '/\s+/', ' ', $name ) );
}

// phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- docblock and body below are kept verbatim per the task-11 brief; not to be "improved".
/**
 * Token-set similarity, 0.0–1.0. Order-independent so "Lusk Cody" matches "Cody Lusk",
 * and tolerant of extra middle names.
 */
function blueline_name_match_score( string $a, string $b ): float {
	$ta = array_filter( explode( ' ', blueline_normalize_name( $a ) ) );
	$tb = array_filter( explode( ' ', blueline_normalize_name( $b ) ) );
	if ( empty( $ta ) || empty( $tb ) ) {
		return 0.0;
	}
	$sa     = array_unique( $ta );
	$sb     = array_unique( $tb );
	$common = array_intersect( $sa, $sb );
	// Score against the SMALLER set so extra middle names do not punish a real match.
	$score = count( $common ) / min( count( $sa ), count( $sb ) );
	return round( (float) $score, 4 );
}

/**
 * Reference to the request-scoped user_id => player_id cache backing
 * blueline_get_linked_player_id(), shared with blueline_forget_linked_player_cache()
 * so a successful blueline_link_player_to_user() can invalidate it.
 *
 * A plain function-static can only be reached from inside the one function
 * that declares it, so the shared state lives here instead, behind a
 * by-reference accessor -- still request-scoped (it resets on every PHP
 * process), just visible to more than one function.
 *
 * @return array<int, int|null> Reference to the live cache.
 */
function &blueline_linked_player_cache(): array {
	static $cache = array();
	return $cache;
}

/**
 * The sp_player post (if any) linked to $user_id, via the sp_user meta key
 * that sportspress-player-registration also owns.
 *
 * Cached per request rather than via the object cache: this site runs a
 * persistent (Redis) object cache, and a plain wp_cache_set() here would
 * outlive the request and go stale the moment blueline_link_player_to_user()
 * writes a new value. The request-scoped cache is explicitly busted there
 * instead.
 *
 * @param int $user_id WordPress user ID.
 * @return int|null Player post ID, or null if unlinked / SportsPress inactive.
 */
function blueline_get_linked_player_id( int $user_id ): ?int {
	$cache = &blueline_linked_player_cache();

	if ( array_key_exists( $user_id, $cache ) ) {
		return $cache[ $user_id ];
	}

	if ( $user_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		$cache[ $user_id ] = null;
		return null;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'sp_player',
			'meta_key'       => BLUELINE_PLAYER_USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- required to resolve a single link by value; the sp_user key has low cardinality relative to the 2k-player table and this is a single-row lookup, not a listing query.
			'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$cache[ $user_id ] = $ids ? (int) $ids[0] : null;
	return $cache[ $user_id ];
}

/**
 * Forget the cached link for $user_id so the next blueline_get_linked_player_id()
 * call re-queries. Called after a successful blueline_link_player_to_user().
 *
 * @param int $user_id WordPress user ID.
 */
function blueline_forget_linked_player_cache( int $user_id ): void {
	$cache = &blueline_linked_player_cache();
	unset( $cache[ $user_id ] );
}

/**
 * The display name to match a WordPress user against a SportsPress player:
 * billing first/last name (set at checkout, so present for anyone who has
 * ever registered), falling back to their display name.
 *
 * @param int $user_id WordPress user ID.
 * @return string Trimmed full name, possibly empty for a user with neither.
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
		$name = trim( (string) $user->display_name );
	}

	return $name;
}

/**
 * Current-season sp_player post IDs (sp_current_team set, per the same
 * NOT IN ('', '0') definition of "set" used across this project) that are
 * not already linked to a DIFFERENT user than $exclude_linked_to_user_id.
 *
 * Queried lean -- ids only, via meta_query at the SQL level -- because there
 * are ~2,037 current-season players and this can run on a logged-in page
 * load; loading full post objects for all of them just to filter in PHP
 * would not scale.
 *
 * @param int $exclude_linked_to_user_id A player linked to this user IS
 *                                       still included (e.g. re-checking
 *                                       one's own link); 0 excludes any
 *                                       linked player.
 * @return int[] Player post IDs.
 */
function blueline_current_season_unclaimed_player_ids( int $exclude_linked_to_user_id = 0 ): array {
	return get_posts(
		array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'none',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- deliberate: filtering at SQL level is the fast path here, see docblock.
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
		)
	);
}

/**
 * Candidate current-season players for $user_id to claim, scored by name
 * against billing/display name, sorted best first.
 *
 * Excludes players already linked to a different user; a player already
 * claimed by someone else is never offered here, which is also what keeps
 * the admin_post_blueline_claim_player handler safe -- it only accepts a
 * player_id that appears in this list.
 *
 * @param int $user_id WordPress user ID.
 * @return array<int, array{player_id:int, score:float, name:string}> Sorted descending by score.
 */
function blueline_find_player_candidates( int $user_id ): array {
	if ( ! post_type_exists( 'sp_player' ) ) {
		return array();
	}

	$name = blueline_user_match_name( $user_id );
	if ( '' === $name ) {
		return array();
	}

	$player_ids = blueline_current_season_unclaimed_player_ids( $user_id );
	if ( empty( $player_ids ) ) {
		return array();
	}

	$titles = blueline_get_post_titles( $player_ids );

	$candidates = array();
	foreach ( $player_ids as $player_id ) {
		$player_name = $titles[ $player_id ] ?? '';
		if ( '' === $player_name ) {
			continue;
		}

		$score = blueline_name_match_score( $name, $player_name );
		if ( $score >= BLUELINE_MATCH_THRESHOLD ) {
			$candidates[] = array(
				'player_id' => $player_id,
				'score'     => $score,
				'name'      => $player_name,
			);
		}
	}

	usort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

	return $candidates;
}

/**
 * The post_title for each of $post_ids, in one query -- a targeted title
 * fetch instead of get_the_title()/get_post() per row, which would each hit
 * the object cache (or the DB, on a miss) once per candidate.
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

	// $placeholders is a fixed string of %d tokens sized to count( $post_ids ), never user input;
	// every value is bound via prepare() immediately below.
	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a single batch title fetch with no core API; table name and %d placeholders only (no user input, sniff can't see the %d tokens through the $placeholders interpolation), values bound via prepare() args; not cached since candidate titles must reflect the latest sp_current_team/sp_user state on every call.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $post_ids ) );

	$titles = array();
	foreach ( (array) $rows as $row ) {
		$titles[ (int) $row->ID ] = (string) $row->post_title;
	}

	return $titles;
}

/**
 * Link $player_id to $user_id in sp_user, with the checks a linkage between
 * a person and an account demands:
 *
 * - a player already linked to a DIFFERENT user is never silently repointed;
 * - a user who already has a different player can't pick up a second one;
 * - only the user themselves, or someone who can edit_users, may write the link.
 *
 * @param int $player_id sp_player post ID.
 * @param int $user_id   WordPress user ID.
 * @return true|WP_Error
 */
function blueline_link_player_to_user( int $player_id, int $user_id ) {
	if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to link this player to this account.', 'blueline' )
		);
	}

	$existing_user = (int) get_post_meta( $player_id, BLUELINE_PLAYER_USER_META, true );
	if ( $existing_user > 0 && $existing_user !== $user_id ) {
		return new WP_Error(
			'already_linked',
			__( 'This player is already linked to a different account.', 'blueline' )
		);
	}

	$existing_player = blueline_get_linked_player_id( $user_id );
	if ( null !== $existing_player && $existing_player !== $player_id ) {
		return new WP_Error(
			'user_already_linked',
			__( 'This account is already linked to a different player.', 'blueline' )
		);
	}

	update_post_meta( $player_id, BLUELINE_PLAYER_USER_META, $user_id );
	blueline_forget_linked_player_cache( $user_id );

	return true;
}

add_action( 'admin_post_blueline_claim_player', 'blueline_handle_claim_player_submission' );
/**
 * Handle a logged-in user's "Is this you?" claim submission.
 *
 * The nonce ties the submission to blueline_claim_player, and the submitted
 * player_id is only ever honoured if it appears in that user's OWN current
 * blueline_find_player_candidates() list -- so even a tampered request
 * cannot be used to claim a player who is not a genuine name match for the
 * logged-in account, and never one already claimed by someone else.
 */
function blueline_handle_claim_player_submission(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You must be logged in to do this.', 'blueline' ), 403 );
	}

	check_admin_referer( 'blueline_claim_player' );

	$user_id   = get_current_user_id();
	$player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
	$redirect  = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}

	$candidate_ids = wp_list_pluck( blueline_find_player_candidates( $user_id ), 'player_id' );

	if ( ! $player_id || ! in_array( $player_id, $candidate_ids, true ) ) {
		blueline_redirect_after_claim( $redirect, 'invalid' );
	}

	$result = blueline_link_player_to_user( $player_id, $user_id );

	blueline_redirect_after_claim( $redirect, is_wp_error( $result ) ? $result->get_error_code() : 'linked' );
}

/**
 * Redirect back to $redirect_to with the claim outcome in a query var, and exit.
 *
 * @param string $redirect_to Where to send the user back to.
 * @param string $status      One of 'linked', 'invalid', or a blueline_link_player_to_user() WP_Error code.
 */
function blueline_redirect_after_claim( string $redirect_to, string $status ): void {
	wp_safe_redirect( esc_url_raw( add_query_arg( 'blueline_claim', $status, $redirect_to ) ) );
	exit;
}
