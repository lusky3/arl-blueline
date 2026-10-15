<?php
/**
 * The one definition of "the current SportsPress season", shared by the plugin and the theme.
 *
 * The current season is SportsPress's own setting (SportsPress > Settings > General > Current
 * Season, option `sportspress_season`). The league board changes it when a new season starts
 * PLAYING. The season that registration is open for is a different thing (the next season, read
 * from the registration products) and is not decided here.
 *
 * Rules, in order:
 * 1. The setting, when it names an sp_season term that exists.
 * 2. Otherwise the season of the next game that is going ahead (not postponed or cancelled), or,
 *    with nothing scheduled, of the most recent game played. This is the rule every resolver used
 *    before the setting was read, so a site that never set it behaves as it did.
 * Either way a Playoffs term counts as the season it belongs to (its parent, when that parent is a
 * regular-season term): a setting or a game tagged "W2026-27 Playoffs" gives W2026-27. A Playoffs
 * term with no regular-season parent is used as it is.
 *
 * Callers that need the playoff phase too ask for blueline_core_current_season_term_ids() (the
 * season plus its Playoffs terms) or blueline_core_current_season_phase_term_id() (the Playoffs
 * term while the current season's playoff games are the ones being played).
 *
 * THIS FILE EXISTS TWICE, byte for byte: plugins/blueline-core/includes/season/season.php
 * (the source) and themes/blueline/inc/current-season-core.php (the copy the theme loads
 * only when the plugin is missing or older). Edit the plugin's and copy it over; a test in each
 * suite fails while the two differ. Everything sits inside one function_exists() guard so whichever
 * copy loads first wins and the other is skipped.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'blueline_core_current_season_id' ) ) {

	/**
	 * The current season's sp_season term ID (a regular-season term), or 0 when there is none:
	 * SportsPress is inactive, or the setting is unusable and no game has a season.
	 *
	 * @return int
	 */
	function blueline_core_current_season_id(): int {
		$cache = &blueline_core_current_season_cache();

		if ( ! array_key_exists( 'id', $cache ) ) {
			$cache['id'] = blueline_core_decide_current_season_id(
				blueline_core_current_season_setting_id(),
				static function () {
					return blueline_core_current_season_derived_id();
				}
			);
		}

		/**
		 * Filters the current season's sp_season term ID.
		 *
		 * @param int $season_id Term ID, or 0 for none.
		 */
		return (int) apply_filters( 'blueline_core_current_season_id', (int) $cache['id'] );
	}

	/**
	 * Pure: the setting wins whenever it names a usable term; the derived season is only worked out
	 * (it costs up to two queries) when it does not.
	 *
	 * @param int      $setting_id The setting, already checked and normalised (0 when unusable).
	 * @param callable $derived    Returns the derived season's term ID, or 0.
	 * @return int
	 */
	function blueline_core_decide_current_season_id( int $setting_id, callable $derived ): int {
		return $setting_id > 0 ? $setting_id : max( 0, (int) $derived() );
	}

	/**
	 * The current season's term object, or null.
	 *
	 * @return object|null WP_Term.
	 */
	function blueline_core_current_season_term() {
		$season_id = blueline_core_current_season_id();
		$term      = $season_id > 0 ? get_term( $season_id, 'sp_season' ) : null;

		return is_object( $term ) && ! is_wp_error( $term ) ? $term : null;
	}

	/**
	 * The current season's term ID followed by its Playoffs terms' IDs: every sp_season term whose
	 * members belong to the current season. Empty when there is no current season.
	 *
	 * @return int[]
	 */
	function blueline_core_current_season_term_ids(): array {
		$season_id = blueline_core_current_season_id();
		$term_ids  = $season_id > 0 ? array_merge( array( $season_id ), blueline_core_season_playoff_term_ids( $season_id ) ) : array();

		/**
		 * Filters the term IDs that make up the current season (the season, then its Playoffs terms).
		 *
		 * @param int[] $term_ids  Term IDs.
		 * @param int   $season_id The current season's term ID.
		 */
		$term_ids = apply_filters( 'blueline_core_current_season_term_ids', $term_ids, $season_id );

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $term_ids ) ) ) );
	}

	/**
	 * The term for the phase of the current season being played: one of its Playoffs terms when the
	 * next game going ahead (or, with none, the last game played) carries it, otherwise the season
	 * itself. 0 when there is no current season.
	 *
	 * @return int
	 */
	function blueline_core_current_season_phase_term_id(): int {
		$season_id = blueline_core_current_season_id();

		if ( $season_id <= 0 ) {
			return 0;
		}

		$playoff_ids = blueline_core_season_playoff_term_ids( $season_id );

		foreach ( blueline_core_current_season_active_event_term_ids() as $term_id ) {
			if ( in_array( $term_id, $playoff_ids, true ) ) {
				return $term_id;
			}
		}

		return $season_id;
	}

	/**
	 * The SportsPress setting as a usable season: its term ID normalised to the regular season,
	 * or 0 when it is unset, not a number, or names no existing sp_season term.
	 *
	 * @return int
	 */
	function blueline_core_current_season_setting_id(): int {
		if ( ! taxonomy_exists( 'sp_season' ) ) {
			return 0;
		}

		$setting = get_option( 'sportspress_season', '' );

		if ( ! is_numeric( $setting ) || (int) $setting <= 0 ) {
			return 0;
		}

		$term = get_term( (int) $setting, 'sp_season' );

		return is_object( $term ) && ! is_wp_error( $term ) ? blueline_core_season_regular_term_id( (int) $term->term_id ) : 0;
	}

	/**
	 * The fallback: the season of the game the schedule is on (the first sp_season term of the next
	 * game going ahead, else of the last game played), normalised to the regular season. 0 when
	 * there is no such game or it has no season.
	 *
	 * @return int
	 */
	function blueline_core_current_season_derived_id(): int {
		$term_ids = blueline_core_current_season_active_event_term_ids();

		return $term_ids ? blueline_core_season_regular_term_id( $term_ids[0] ) : 0;
	}

	/**
	 * The sp_season term IDs of the game the schedule is on: the next one going ahead, or, with
	 * nothing scheduled, the most recent one played. Memoised for the request.
	 *
	 * @return int[]
	 */
	function blueline_core_current_season_active_event_term_ids(): array {
		$cache = &blueline_core_current_season_cache();

		if ( array_key_exists( 'active_event_terms', $cache ) ) {
			return $cache['active_event_terms'];
		}

		$cache['active_event_terms'] = array();

		if ( ! post_type_exists( 'sp_event' ) || ! taxonomy_exists( 'sp_season' ) ) {
			return $cache['active_event_terms'];
		}

		$event_id = blueline_core_current_season_next_event_id();

		if ( ! $event_id ) {
			$event_id = blueline_core_current_season_last_played_event_id();
		}

		if ( $event_id ) {
			$terms = wp_get_object_terms( $event_id, 'sp_season', array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $terms ) && $terms ) {
				$cache['active_event_terms'] = array_values( array_map( 'intval', $terms ) );
			}
		}

		return $cache['active_event_terms'];
	}

	/**
	 * The next sp_event going ahead: dated from now on, published or scheduled ('future' is the
	 * status WordPress gives a post dated ahead), and not postponed or cancelled. 0 when none.
	 *
	 * The theme already works this out for Season State and caches it, so it hands its answer in
	 * through the filter and this query is skipped; without the theme (or with a theme that does
	 * not answer) the query runs.
	 *
	 * @return int
	 */
	function blueline_core_current_season_next_event_id(): int {
		/**
		 * Supplies the next sp_event going ahead, so this resolver need not query for it.
		 *
		 * @param int|null $event_id Null to let the resolver query; 0 for "no game scheduled".
		 */
		$supplied = apply_filters( 'blueline_core_current_season_next_event_id', null );

		if ( null !== $supplied ) {
			return max( 0, (int) $supplied );
		}

		$event_ids = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- one row, bounded by post_type sp_event.
					array(
						'column' => 'post_date',
						'after'  => current_time( 'mysql' ),
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one row, bounded by post_type sp_event and the date above.
					'relation' => 'OR',
					array(
						'key'     => 'sp_status',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'sp_status',
						'value'   => array( 'postponed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		return $event_ids ? (int) $event_ids[0] : 0;
	}

	/**
	 * The most recent published sp_event dated up to now. 0 when none.
	 *
	 * Bounded to now so a game published ahead of its date, but postponed, is not mistaken for the
	 * last one played.
	 *
	 * @return int
	 */
	function blueline_core_current_season_last_played_event_id(): int {
		$event_ids = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- one row, bounded by post_type sp_event.
					array(
						'column'    => 'post_date',
						'before'    => current_time( 'mysql' ),
						'inclusive' => true,
					),
				),
			)
		);

		return $event_ids ? (int) $event_ids[0] : 0;
	}

	/**
	 * Whether an sp_season slug names a Playoffs term: the same "slug contains playoff" signal Season
	 * State, the claim pool and the indexing module use.
	 *
	 * @param string $slug Term slug.
	 * @return bool
	 */
	function blueline_core_season_is_playoff_slug( string $slug ): bool {
		return false !== strpos( $slug, 'playoff' );
	}

	/**
	 * The regular-season term an sp_season term belongs to: itself for a regular-season term, its
	 * parent for a Playoffs term whose parent is a regular-season term, otherwise itself unchanged.
	 * 0 for a term that does not exist.
	 *
	 * @param int $term_id sp_season term ID.
	 * @return int
	 */
	function blueline_core_season_regular_term_id( int $term_id ): int {
		$term = $term_id > 0 ? get_term( $term_id, 'sp_season' ) : null;

		if ( ! is_object( $term ) || is_wp_error( $term ) ) {
			return 0;
		}

		if ( ! blueline_core_season_is_playoff_slug( (string) $term->slug ) || (int) $term->parent <= 0 ) {
			return (int) $term->term_id;
		}

		$parent = get_term( (int) $term->parent, 'sp_season' );

		if ( is_object( $parent ) && ! is_wp_error( $parent ) && ! blueline_core_season_is_playoff_slug( (string) $parent->slug ) ) {
			return (int) $parent->term_id;
		}

		return (int) $term->term_id;
	}

	/**
	 * A season's Playoffs terms: its child sp_season terms whose slug says playoff, by term ID.
	 *
	 * @param int $season_id Regular-season sp_season term ID.
	 * @return int[]
	 */
	function blueline_core_season_playoff_term_ids( int $season_id ): array {
		$cache = &blueline_core_current_season_cache();
		$key   = 'playoffs_' . $season_id;

		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}

		$cache[ $key ] = array();

		if ( $season_id <= 0 || ! taxonomy_exists( 'sp_season' ) ) {
			return $cache[ $key ];
		}

		$children = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'parent'     => $season_id,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $children ) || ! is_array( $children ) ) {
			return $cache[ $key ];
		}

		foreach ( $children as $child ) {
			if ( blueline_core_season_is_playoff_slug( (string) $child->slug ) ) {
				$cache[ $key ][] = (int) $child->term_id;
			}
		}

		return $cache[ $key ];
	}

	/**
	 * The request's memo. Keyed by the theme's SportsPress cache generation, so a bump (any
	 * SportsPress post, term or setting change) starts it afresh; the hooks below also clear it at
	 * once, since the theme only bumps the generation at the end of the request.
	 *
	 * @return array<string, mixed>
	 */
	function &blueline_core_current_season_cache(): array {
		static $cache = array();

		$generation = (int) get_option( 'blueline_sp_cache_gen', 0 );

		if ( ( $cache['generation'] ?? null ) !== $generation ) {
			$cache = array( 'generation' => $generation );
		}

		return $cache;
	}

	/**
	 * Forget the request's memo, so the next read resolves again.
	 *
	 * @return void
	 */
	function blueline_core_current_season_flush(): void {
		$cache = &blueline_core_current_season_cache();
		$cache = array( 'generation' => (int) get_option( 'blueline_sp_cache_gen', 0 ) );
	}

	/**
	 * Forget the memo when the SportsPress setting changes.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	function blueline_core_current_season_on_option_change( $option ): void {
		if ( 'sportspress_season' === $option ) {
			blueline_core_current_season_flush();
		}
	}

	/**
	 * Forget the memo when an sp_event or an sp_season term changes.
	 *
	 * @param int    $object_id Post or term ID.
	 * @param mixed  $unused    Unused.
	 * @param string $taxonomy  Taxonomy, for term hooks.
	 * @return void
	 */
	function blueline_core_current_season_on_data_change( $object_id = 0, $unused = null, $taxonomy = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		if ( '' === $taxonomy || 'sp_season' === $taxonomy ) {
			blueline_core_current_season_flush();
		}
	}

	add_action( 'added_option', 'blueline_core_current_season_on_option_change' );
	add_action( 'updated_option', 'blueline_core_current_season_on_option_change' );
	add_action( 'deleted_option', 'blueline_core_current_season_on_option_change' );
	add_action( 'save_post_sp_event', 'blueline_core_current_season_on_data_change' );
	add_action( 'created_term', 'blueline_core_current_season_on_data_change', 10, 3 );
	add_action( 'edited_term', 'blueline_core_current_season_on_data_change', 10, 3 );
	add_action( 'delete_term', 'blueline_core_current_season_on_data_change', 10, 3 );
}
