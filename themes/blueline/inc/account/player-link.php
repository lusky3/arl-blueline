<?php
/**
 * Linking a WordPress user to a SportsPress player.
 *
 * About 84% of current-season players carry sp_user (76 of the 90 players
 * tagged with the current sp_season term, measured on staging 2026-08-11), so
 * everything here must still behave sensibly for the remaining ~16% with no
 * link, a real minority to serve well, not the default case.
 *
 * CORRECTED PREMISE (found during Task 14's review, 2026-08-11; the
 * percentages above are Task 16's corrected figures): the original "12%"
 * figure, and the original candidate pool below, were both
 * built on `sp_current_team NOT IN ('','0')` as the definition of
 * "current-season player." That field is NOT season-scoped; it is a
 * STICKY "last team this player was ever rostered onto" value, set on
 * 2,037 of 2,134 sp_player posts ever created (95% of every player in the
 * system's history). Filtering on it alone meant every unlinked user's
 * claim card was scored against two decades of names, including players who
 * last played years ago, raising the odds of a wrong-identity match on a
 * common name, which is the one failure mode this whole feature exists to avoid.
 * Real season membership lives in the `sp_season` taxonomy instead (a
 * genuine per-season tag on sp_player, confirmed live: term 674 "W2026-27"
 * tags exactly 90 posts, term 654 "W2025-26" tags 524). See
 * blueline_claim_pool_season_term_ids() below for how the candidate pool now
 * resolves which season(s) to draw from.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_PLAYER_USER_META = 'sp_user';
const BLUELINE_MATCH_THRESHOLD  = 0.85;
const BLUELINE_PLAYER_ROLE      = 'sp_player';

/**
 * How much smaller than the previous (already-final) season's roster the
 * newest sp_season term's roster may be before blueline_resolve_claim_pool_term_ids()
 * treats it as "still filling in" and falls back to that previous term.
 */
const BLUELINE_CLAIM_POOL_SPARSE_RATIO = 0.5;

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
 * The minimum number of DISTINCT normalised tokens the SHORTER of two names
 * must carry before a blueline_name_match_score() between them may be trusted
 * to identify a person.
 *
 * Two, because one is not a name; it is a fragment that identifies nobody.
 */
const BLUELINE_MATCH_MIN_TOKENS = 2;

/**
 * The distinct, normalised tokens of $name, which are the same token set
 * blueline_name_match_score() scores against, exposed on its own so the
 * candidate gate below can reason about set SIZE without reimplementing
 * (or perturbing) that function's normalisation.
 *
 * @param string $name Raw name.
 * @return string[] Distinct tokens, in first-seen order; empty for an empty/unnameable string.
 */
function blueline_name_tokens( string $name ): array {
	return array_values( array_unique( array_filter( explode( ' ', blueline_normalize_name( $name ) ) ) ) );
}

/**
 * SECURITY GATE: may a score between these two names be offered as a
 * candidate identity at all?
 *
 * The matcher, blueline_name_match_score(), divides the token intersection by
 * `min( count( $sa ), count( $sb ) )`, so ANY name that is a strict subset of
 * the other scores exactly 1.0:
 *
 *   "Matthew" vs "Matthew Zielinski"  => 1.0000
 *   "Smith"   vs "John Smith"         => 1.0000
 *
 * That formula is plan-mandated and pinned by six verbatim unit tests, and is
 * deliberately NOT changed, because scoring against the smaller set is what lets a
 * real "Cody James Lusk" match "Cody Lusk". The hazard is not the arithmetic;
 * it is WHICH pairs are allowed to reach it. blueline_user_match_name() builds
 * the account side of that comparison from `billing_first_name` +
 * `billing_last_name`, both of which the account holder edits themselves at
 * /account/edit-address/. A user who blanks their surname and sets their given
 * name to a single common token would otherwise be offered EVERY current-season
 * player sharing that token at a perfect 1.0, one click from confirming, and
 * blueline_link_player_to_user()'s three invariants would not stop it, because
 * they check that the CHOSEN player is unclaimed, not that the candidate list
 * was honestly derived. The consequence is identity squatting: the claimant
 * sees a stranger's team, roster, jersey number, schedule and stats, and the
 * real player is then permanently locked out with `already_linked`.
 *
 * The same hole reached scripts/one-off/2026-08-11-sp-user-backfill.php, whose
 * AUTO rule is "exactly one candidate >= 0.95", because its pool excludes
 * already-linked players, a single-token name whose only remaining namesake is
 * the WRONG one is a unique 1.0 and would have been written automatically.
 *
 * So the gate lives here, at the candidate boundary both paths cross
 * (blueline_score_player_candidates(), reached by
 * blueline_find_player_candidates() and therefore by the backfill too), not in
 * the matcher.
 *
 * @param string $a One name.
 * @param string $b The other name.
 * @return bool True if the pair is specific enough to be scored for identity.
 */
function blueline_name_pair_is_specific_enough( string $a, string $b ): bool {
	$ta = blueline_name_tokens( $a );
	$tb = blueline_name_tokens( $b );

	if ( empty( $ta ) || empty( $tb ) ) {
		return false;
	}

	return min( count( $ta ), count( $tb ) ) >= BLUELINE_MATCH_MIN_TOKENS;
}

/**
 * Reference to the request-scoped user_id => player_id cache backing
 * blueline_get_linked_player_id(), shared with blueline_forget_linked_player_cache()
 * so a successful blueline_link_player_to_user() can invalidate it.
 *
 * A plain function-static can only be reached from inside the one function
 * that declares it, so the shared state lives here instead, behind a
 * by-reference accessor. It is still request-scoped (it resets on every PHP
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
 * Whether $user_id holds SportsPress' Player role -- the "player group" that
 * sportspress-player-registration assigns at checkout.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_has_player_role( int $user_id ): bool {
	$user = get_userdata( $user_id );

	return $user && in_array( BLUELINE_PLAYER_ROLE, (array) ( $user->roles ?? array() ), true );
}

/**
 * Whether $user_id owns $player_id: is its post_author, the ownership
 * sportspress-player-registration writes alongside sp_user. A name claim
 * writes only sp_user, so it never confers ownership.
 *
 * @param int $user_id   WordPress user ID.
 * @param int $player_id sp_player post ID.
 * @return bool
 */
function blueline_user_owns_player( int $user_id, int $player_id ): bool {
	return $user_id > 0 && $player_id > 0 && (int) get_post_field( 'post_author', $player_id ) === $user_id;
}

/**
 * Whether $user_id owns any sp_player record at all.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_owns_any_player( int $user_id ): bool {
	if ( $user_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		return false;
	}

	return (bool) get_posts(
		array(
			'post_type'      => 'sp_player',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
}

/**
 * Whether $user_id may self-link a player by name match. Registered players
 * (Player role) and owners of a player record are linked by registration or
 * by the league, never by typing a name, which the account holder controls.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
function blueline_user_can_claim_by_name( int $user_id ): bool {
	return ! blueline_user_has_player_role( $user_id ) && ! blueline_user_owns_any_player( $user_id );
}

/**
 * Whether $user_id is the verified owner of their linked player: holds the
 * Player role AND owns the record. Only a verified owner may change the
 * player's photo or see personal registration details; a name-claimed link
 * is read-only, non-personal.
 *
 * @param int      $user_id   WordPress user ID.
 * @param int|null $player_id Linked player, or null to resolve it.
 * @return bool
 */
function blueline_user_is_verified_player_owner( int $user_id, ?int $player_id = null ): bool {
	$player_id = $player_id ?? blueline_get_linked_player_id( $user_id );

	return $player_id
		&& blueline_user_has_player_role( $user_id )
		&& blueline_user_owns_player( $user_id, $player_id );
}

/**
 * The display name to match a WordPress user against a SportsPress player:
 * billing first/last name (set at checkout, so present for anyone who has
 * ever registered), falling back to their display name.
 *
 * ATTACKER-CONTROLLED: both billing fields are editable by the account holder
 * at /account/edit-address/, and display_name is editable at
 * /account/edit-account/. Nothing this returns may be treated as evidence of
 * identity on its own; see blueline_name_pair_is_specific_enough().
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
 * Pure decision: is the newest sp_season term's own roster too sparse,
 * relative to the previous (already-final) season's roster, to trust as the
 * claim/backfill candidate pool on its own?
 *
 * "Sparse" is deliberately relative, not a fixed headcount: a season's
 * roster starts at zero and fills in gradually as registrants are assigned
 * to teams over the weeks a registration window is open, so the meaningful
 * question is "how much of last season's final roster size has this one
 * reached so far," which is a ratio that self-calibrates to however large the
 * league happens to be, rather than a constant that would need re-tuning as
 * the league grows or shrinks year to year.
 *
 * Verified live 2026-08-11, the exact case this function exists for:
 * newest = W2026-27 (90 players), previous = W2025-26 (524); 90/524 =~
 * 17%, clearly still filling in. Restricting the claim pool to those 90
 * alone right now would make ~1,947 other sp_current_team players
 * (including many who registered for W2026-27 this same week; see Task
 * 14's report) invisible to both the self-service claim flow and the
 * backfill script, simply because SportsPress has not yet re-tagged their
 * player post with the new season term.
 *
 * @param int $current_count  Newest non-playoff sp_season term's member count.
 * @param int $previous_count The next most recent non-playoff term's member count (0 if none exists).
 * @return bool True if the newest term should be treated as not-yet-representative.
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
 * Recognised sp_season slug shape: a leading session letter ("w" for
 * Winter, "s" for Summer) immediately followed by a digit, e.g.
 * "w2026-27", "s2026". Deliberately a real pattern match, not "whichever
 * character the slug happens to start with": the latter would silently
 * treat any future slugging convention starting with something else (e.g.
 * a year-first "2026-winter") as belonging to session "2" for every term
 * that happened to share that first character, silently re-merging Winter
 * and Summer into one pool, which is exactly the cross-session bug this pattern
 * exists to keep from coming back unnoticed.
 */
const BLUELINE_SEASON_SLUG_SESSION_PATTERN = '/^([ws])\d/i';

/**
 * The session letter ("w" or "s") a sp_season slug belongs to, per
 * BLUELINE_SEASON_SLUG_SESSION_PATTERN, or null if the slug does not
 * match that recognised shape at all. Null is a real, load-bearing answer
 * here, not an error to paper over: callers must not guess a session for a
 * slug this can't classify.
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
 * Report a sp_season term whose slug does not match
 * BLUELINE_SEASON_SLUG_SESSION_PATTERN, loudly and under WP_DEBUG, rather
 * than silently excluding or silently guessing its session. A slug this
 * cannot classify is exactly the situation that let W2026-27/S2026 merge
 * into one pool before that specific case was caught; the next unrecognised
 * shape must not repeat that silently.
 *
 * @param int    $term_id sp_season term ID.
 * @param string $slug    Its slug.
 */
function blueline_log_nonconforming_season_slug( int $term_id, string $slug ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated, deliberate: an unclassifiable sp_season slug must never fail silently; see blueline_resolve_claim_pool_term_ids()'s docblock.
	error_log(
		sprintf(
			'[blueline] sp_season term %1$d has slug "%2$s", which does not match the recognised w<digit>/s<digit> session shape. Excluded from the claim/backfill candidate pool rather than guessed into a session.',
			$term_id,
			$slug
		)
	);
}

/**
 * The number of sp_player posts tagged with $term_id in the sp_season
 * taxonomy, deliberately NOT the term's own ->count property from
 * get_terms(). That property counts every post type sp_season is
 * registered for (sp_event, sp_table, sp_player all share this one
 * taxonomy), not sp_player specifically. Confirmed live 2026-08-11: term
 * 666 "S2026" reports ->count = 568, but only 350 of those relationships
 * are to sp_player posts (the rest are events/tables tagged with the same
 * season). Using the raw ->count would make a season with many tagged
 * events look like a bigger player roster than it actually has, which is
 * exactly the wrong axis for a function whose whole job is sizing player
 * rosters.
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
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term, called a handful of times per blueline_claim_pool_season_term_ids() invocation, not a listing query.
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
 * Pure resolver: given a list of non-playoff sp_season terms as plain data
 * (already ordered newest-first by term_id, e.g. from get_terms()), and a
 * callable that reports a term's real sp_player member count, decide which
 * term_id(s) form the claim/backfill candidate pool. Kept free of WordPress
 * calls (get_terms()/WP_Query live in blueline_claim_pool_season_term_ids(),
 * the thin wrapper below) so this, the actual fragile decision logic,
 * can be unit tested directly, the same pure/impure split this file already
 * uses for blueline_is_claim_pool_sparse() vs. the query functions around it.
 *
 * ADDITIVE, not exclusive-or: the newest term is ALWAYS included in the
 * result, even when it is judged sparse; a fallback term is ADDED alongside
 * it, never substituted in its place. A single-term result would make a
 * genuine first-time registrant, tagged only with the brand-new season and
 * carrying no history at all, invisible during exactly the early-season
 * window they are most likely to need the claim flow. Verified live
 * 2026-08-11 that this matters, not just in theory: today's real newest
 * term (674 "W2026-27", 90 players) is judged sparse against 654's 524, so
 * the resolved pool is BOTH terms: a returning player tagged only with
 * 654 and a first-timer tagged only with 674 are both reachable.
 *
 * Session-scoped: every comparison and fallback stays within the terms
 * sharing the newest term's own session letter
 * (blueline_season_slug_session_letter()); see
 * blueline_claim_pool_season_term_ids()'s docblock for why crossing
 * sessions (Winter vs. Summer) is a real, previously-live bug, not a
 * hypothetical. A term whose slug does not match the recognised session
 * shape at all is excluded from consideration (reported via
 * blueline_log_nonconforming_season_slug() by the caller, since this
 * function is pure and does no logging itself) rather than guessed into
 * either session. If the NEWEST term itself is unclassifiable, this
 * returns an empty array; the caller then degrades to the broad,
 * pre-fix pool, since there is no reliable session anchor to scope by at
 * all.
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
		return array(); // Anchor term's shape is unreliable, so there is no session to scope by.
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
			break; // Additive: current + ONE fallback, not every older term with members.
		}
	}

	return $term_ids;
}

/**
 * The sp_season term_id(s) whose members form the claim/backfill candidate
 * pool: always including the current season, additionally including the
 * most recent one with actual sp_player members when the current one is
 * still sparse. The actual decision logic lives in
 * blueline_resolve_claim_pool_term_ids() (pure, unit tested); this function
 * is the thin WordPress-touching wrapper that gathers real terms/counts and
 * reports anything unclassifiable.
 *
 * Playoff sub-terms (e.g. "S2026 Playoffs") are excluded from consideration
 * entirely, the same "slug contains playoff" signal Season State uses
 * (inc/season-state.php), because they are a POSTSEASON SUBSET of a
 * season's roster, not its base roster; comparing a fresh regular season's
 * count against an already-final playoff round's count would compare the
 * wrong two populations.
 *
 * This league runs BOTH a Winter and a Summer session, tagged with
 * interleaved sp_season terms sharing one term_id sequence. Confirmed
 * live 2026-08-11: 674 "W2026-27", 666 "S2026", 654 "W2025-26", 647
 * "S2025", ... A fallback that walked that sequence blindly by term_id
 * would, and on this exact live data DID before this restriction was added,
 * land on the newest term of the OTHER session (S2026) instead of the
 * previous term of the SAME session (W2025-26), silently excluding any
 * genuinely-current Winter registrant whose player post happens not to
 * carry a Summer tag simply because they don't play the other session
 * (verified live: a real current W2026-27 registrant carries W2025-26 and
 * W2024-25 tags but no S-prefixed tag at all).
 *
 * @return int[] Term ID(s), possibly empty (blueline_current_season_unclaimed_player_ids()
 *               then degrades to the broad, pre-fix pool).
 */
function blueline_claim_pool_season_term_ids(): array {
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

	// The newest non-playoff term is the anchor the whole resolution below
	// depends on. If ITS shape can't be classified, there is no reliable
	// session to scope by at all; treating the next-newest term as if IT
	// were "current" instead would itself be a guess (possibly the wrong
	// one, if the true newest term really was this season's, just
	// unconventionally named). Degrade to the broad, pre-fix pool entirely
	// rather than silently shift the anchor.
	if ( null === blueline_season_slug_session_letter( $non_playoff_terms[0]->slug ) ) {
		blueline_log_nonconforming_season_slug( (int) $non_playoff_terms[0]->term_id, $non_playoff_terms[0]->slug );
		return array();
	}

	$season_terms = array();
	foreach ( $non_playoff_terms as $term ) {
		if ( null === blueline_season_slug_session_letter( $term->slug ) ) {
			blueline_log_nonconforming_season_slug( (int) $term->term_id, $term->slug );
			continue; // Not the anchor, so it's safely excluded rather than guessed into a session.
		}

		$season_terms[] = array(
			'term_id' => (int) $term->term_id,
			'slug'    => $term->slug,
		);
	}

	return blueline_resolve_claim_pool_term_ids( $season_terms, 'blueline_sp_season_player_count' );
}

/**
 * Current-season (plus, during the pre-roster window described by
 * blueline_claim_pool_season_term_ids(), the most-recent-populated season
 * too, ADDITIVE, both at once, not one-or-the-other) sp_player post IDs
 * that are not already linked to a DIFFERENT user than
 * $exclude_linked_to_user_id.
 *
 * Season-scoped via blueline_claim_pool_season_term_ids() as of the Task 14
 * review fixes (2026-08-11); see this file's header docblock for why the
 * previous `sp_current_team`-only definition let the claim pool include two
 * decades of retired players. `sp_current_team` (still required to be set,
 * defense in depth: a season-tagged player with genuinely no team would be
 * an odd edge case) remains part of the query below alongside the new
 * sp_season tax_query, not in place of it.
 *
 * Why additive: an EARLIER version of this fix scoped to a single term
 * (current, or the fallback when current was sparse) and was caught in
 * review before shipping, because during the pre-roster window right now, that
 * would have made a genuine first-time registrant (tagged only with the
 * brand-new current season, no history at all) invisible to the claim card
 * for the exact weeks they most need it, since only 4 RETURNING players
 * (who carry multi-season tags) had been checked, never a first-timer.
 * Passing an array to tax_query's `terms` (default `compare => 'IN'`)
 * matches EITHER term, so a returning player tagged only with the fallback
 * season and a first-timer tagged only with the current one are both
 * reachable at once.
 *
 * Graceful degradation, stated plainly: if sp_season is missing/inactive,
 * has no usable terms, or the newest term's slug can't be classified into a
 * session at all (blueline_claim_pool_season_term_ids() returns an empty
 * array in every one of those cases), this falls back to the PRE-FIX,
 * sp_current_team-only pool: broader and more collision-prone, but a
 * working claim flow beats a broken one.
 *
 * Queried lean, ids only, via meta_query/tax_query at the SQL level,
 * because this can run on a logged-in page load; loading full post objects
 * just to filter in PHP would not scale.
 *
 * @param int $exclude_linked_to_user_id A player linked to this user IS
 *                                       still included (e.g. re-checking
 *                                       one's own link); 0 excludes any
 *                                       linked player.
 * @return int[] Player post IDs.
 */
function blueline_current_season_unclaimed_player_ids( int $exclude_linked_to_user_id = 0 ): array {
	$query_args = array(
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
	);

	$season_term_ids = blueline_claim_pool_season_term_ids();

	if ( ! empty( $season_term_ids ) ) {
		$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to one or two season terms.
			array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => $season_term_ids, // Array + default 'compare' => 'IN' means additive OR; see docblock.
			),
		);
	}

	return get_posts( $query_args );
}

/**
 * Candidate current-season players for $user_id to claim, scored by name
 * against billing/display name, sorted best first.
 *
 * Excludes players already linked to a different user; a player already
 * claimed by someone else is never offered here, which is also what keeps
 * the admin_post_blueline_claim_player handler safe, since it only accepts a
 * player_id that appears in this list.
 *
 * The name this scores with is USER-EDITABLE (blueline_user_match_name() reads
 * billing_first_name/billing_last_name, which the account holder sets at
 * /account/edit-address/), so every pair must clear
 * blueline_name_pair_is_specific_enough() before it is scored at all; see
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

	// Cheap half of the blueline_name_pair_is_specific_enough() gate, applied
	// before the pool query rather than after it: an account name of fewer
	// than BLUELINE_MATCH_MIN_TOKENS tokens can never produce a candidate
	// against ANY player title, so there is nothing to query for.
	if ( count( blueline_name_tokens( $name ) ) < BLUELINE_MATCH_MIN_TOKENS ) {
		return array();
	}

	$player_ids = blueline_current_season_unclaimed_player_ids( $user_id );
	if ( empty( $player_ids ) ) {
		return array();
	}

	$titles = blueline_get_post_titles( $player_ids );

	// Re-key by the pool's own order (blueline_get_post_titles() returns rows
	// in whatever order the IN() query yielded) so equal scores keep a stable,
	// query-order tie-break.
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
 * Disambiguating detail for one claim candidate: team, season, jersey
 * number, shown alongside the bare name on the claim card (P4 finding 6).
 * Without it, two players sharing a common name are offered as identical
 * rows with an identical "Yes, that's me" button, and the user is guessing.
 *
 * Presentation only: does not touch blueline_score_player_candidates(),
 * blueline_name_pair_is_specific_enough(), or the two-token gate, all of
 * which are settled (see the P4 review's residual-risk record) and out of
 * scope here. This runs AFTER a candidate has already cleared that gate; it
 * never influences which players are offered, only how one already-offered
 * row is described.
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
 * The name of whichever blueline_claim_pool_season_term_ids() term
 * $player_id actually carries, the season that made this player a claim
 * candidate in the first place, not their entire multi-year sp_season tag
 * history. Pool terms are newest-first, so a player tagged with both the
 * current and a sparse-pool fallback season reports the current one.
 *
 * @param int $player_id sp_player post ID.
 * @return string Term name, or '' if sp_season is inactive, the pool is
 *                empty, or the player carries none of the pool's terms.
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
 * Pure scorer: given one account name and a player_id => player_name map,
 * the candidates worth offering, best first.
 *
 * This is the single gate every claim path crosses:
 * blueline_find_player_candidates() above, and therefore
 * scripts/one-off/2026-08-11-sp-user-backfill.php's AUTO path too, since that
 * script delegates all matching here rather than reimplementing it. A
 * candidate must clear BOTH bars to be offered:
 *
 *   1. blueline_name_pair_is_specific_enough(): neither side may be a
 *      single-token fragment. See that function for the identity-squatting
 *      attack this closes.
 *   2. BLUELINE_MATCH_THRESHOLD on blueline_name_match_score().
 *
 * Kept free of WordPress calls, the same pure/impure split this file already
 * uses for blueline_resolve_claim_pool_term_ids(), so the gate itself can be
 * unit tested directly rather than only through a live query.
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
 * The post_title for each of $post_ids, in one query: a targeted title
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

	if ( ! current_user_can( 'edit_users' ) && ! blueline_user_can_claim_by_name( $user_id ) ) {
		return new WP_Error(
			'not_eligible',
			__( 'Registered players are linked by the league, not by name.', 'blueline' )
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
 * blueline_find_player_candidates() list, so even a tampered request
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
