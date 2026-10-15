<?php
/**
 * Archival SportsPress pages: old events, players, teams, staff, tables, lists and calendars
 * from past seasons stay indexable (no noindex, canonical untouched) but are left out of the
 * core XML sitemap, so crawl effort goes to the current season.
 *
 * "Archival" is derived, never stored on the post, and the answer is cached: the sitemap needs
 * it for every request to a SportsPress sitemap, and working it out reads the recent events'
 * line-ups. The decision logic (which seasons are recent, which season a term belongs to) is
 * pure and unit tested; the functions that touch WordPress are thin wrappers around it.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transient holding the computed archival ids. Also holds the settings it was computed under,
 * so a changed filter value is never answered from stale data.
 */
const BLUELINE_ARCHIVAL_TRANSIENT = 'blueline_archival_ids';

/**
 * Payload layout version. Bump it when the cached shape changes, so an old transient left by a
 * previous plugin version is recomputed instead of misread.
 */
const BLUELINE_ARCHIVAL_VERSION = 1;

/**
 * Non-persistent object-cache group for the per-request memo. A persistent cache would keep the
 * memo past an invalidation, so it must never be persisted.
 */
const BLUELINE_ARCHIVAL_CACHE_GROUP = 'blueline_archival';

/**
 * Backstop lifetime of the cached ids. Saves and term changes invalidate it at once; this only
 * covers writes that bypass those hooks (WP-CLI imports, direct SQL).
 */
const BLUELINE_ARCHIVAL_TTL = 6 * HOUR_IN_SECONDS;

/**
 * How many of the most recent seasons stay "current" unless the filter says otherwise: the
 * season being played and the one before it.
 */
const BLUELINE_ARCHIVAL_DEFAULT_RECENT_SEASONS = 2;

/**
 * Number of recent seasons that count as current. 0 (or less) switches archival handling off.
 *
 * @return int
 */
function blueline_archival_recent_seasons(): int {
	/**
	 * Filters how many of the most recent sp_season terms are "current". Recency comes from the
	 * latest event date per season, and a season's playoff terms belong to it. Returning 0 turns
	 * the archival sitemap exclusion off.
	 *
	 * @param int $seasons Number of recent seasons. Default 2 (the current one and the previous).
	 */
	return max( 0, (int) apply_filters( 'blueline_core_archival_recent_seasons', BLUELINE_ARCHIVAL_DEFAULT_RECENT_SEASONS ) );
}

/**
 * The post types that can be archival, each with the rule that decides it:
 *
 * - event:   in none of the recent seasons.
 * - player, team, staff: in no recent event's line-up and not tagged with a recent season.
 * - tagged:  tagged with seasons, none of them recent. A post with no season is never archival.
 *
 * Venues (a taxonomy), news posts, pages and products are not listed, so they never are.
 *
 * @return array<string, string> Post type => rule.
 */
function blueline_archival_post_types(): array {
	$types = array(
		'sp_event'    => 'event',
		'sp_player'   => 'player',
		'sp_team'     => 'team',
		'sp_staff'    => 'staff',
		'sp_table'    => 'tagged',
		'sp_list'     => 'tagged',
		'sp_calendar' => 'tagged',
	);

	/**
	 * Filters the post types treated as archival-capable and the rule for each
	 * (event, player, team, staff or tagged).
	 *
	 * @param array<string, string> $types Post type => rule.
	 */
	$types = apply_filters( 'blueline_core_archival_post_types', $types );

	$known = array( 'event', 'player', 'team', 'staff', 'tagged' );
	$clean = array();

	foreach ( (array) $types as $type => $rule ) {
		if ( is_string( $type ) && is_string( $rule ) && in_array( $rule, $known, true ) ) {
			$clean[ $type ] = $rule;
		}
	}

	return $clean;
}

/**
 * Whether an sp_season slug names a playoff term. The same "slug contains playoff" signal the
 * claim pool and Season State use: a playoff term is a postseason subset of a season.
 *
 * @param string $slug Term slug.
 * @return bool
 */
function blueline_archival_is_playoff_slug( string $slug ): bool {
	return false !== strpos( $slug, 'playoff' );
}

/**
 * Pure: map every sp_season term to the season it belongs to. A regular term is its own season;
 * a playoff term belongs to its parent when that parent is a regular term. A playoff term with
 * no usable parent has no season and is left out, so it can neither be recent nor rank anything.
 *
 * @param array<int, array{term_id:int, slug:string, parent:int}> $terms All sp_season terms.
 * @return array<int, int> Term ID => ID of the regular season term it belongs to.
 */
function blueline_archival_season_families( array $terms ): array {
	$by_id = array();
	foreach ( $terms as $term ) {
		$by_id[ (int) $term['term_id'] ] = $term;
	}

	$families = array();
	foreach ( $by_id as $term_id => $term ) {
		if ( ! blueline_archival_is_playoff_slug( (string) $term['slug'] ) ) {
			$families[ $term_id ] = $term_id;
			continue;
		}

		$parent_id = (int) $term['parent'];
		$parent    = $by_id[ $parent_id ] ?? null;

		if ( $parent && ! blueline_archival_is_playoff_slug( (string) $parent['slug'] ) ) {
			$families[ $term_id ] = $parent_id;
		}
	}

	return $families;
}

/**
 * Pure: the N most recent seasons, ranked by the latest event date in each (term_id order says
 * nothing: an admin creates next season's term, or a stray test term, long before anyone plays).
 * A season with no events never ranks, and a playoff term's events count toward its season.
 *
 * @param array<int, int>                                        $families Term ID => season term ID (blueline_archival_season_families()).
 * @param array<int, array{first_date:string, last_date:string}> $ranges   Term ID => first/last event date ('Y-m-d H:i:s').
 * @param int                                                    $count    How many seasons to take.
 * @return array{terms:int[], floor:string} Season term IDs, newest first, and the earliest event
 *                                          date among them ('' when there are none).
 */
function blueline_archival_pick_recent( array $families, array $ranges, int $count ): array {
	$seasons = array();

	foreach ( $ranges as $term_id => $range ) {
		$season_id = $families[ (int) $term_id ] ?? null;

		if ( null === $season_id || '' === (string) $range['last_date'] ) {
			continue;
		}

		if ( ! isset( $seasons[ $season_id ] ) ) {
			$seasons[ $season_id ] = array(
				'first' => (string) $range['first_date'],
				'last'  => (string) $range['last_date'],
			);
			continue;
		}

		$seasons[ $season_id ]['first'] = min( $seasons[ $season_id ]['first'], (string) $range['first_date'] );
		$seasons[ $season_id ]['last']  = max( $seasons[ $season_id ]['last'], (string) $range['last_date'] );
	}

	$ids = array_keys( $seasons );

	// Newest last event first; the higher term ID wins a tie so the order is deterministic.
	usort(
		$ids,
		static function ( $a, $b ) use ( $seasons ) {
			return array( $seasons[ $b ]['last'], $b ) <=> array( $seasons[ $a ]['last'], $a );
		}
	);

	$ids = array_slice( $ids, 0, max( 0, $count ) );

	if ( ! $ids ) {
		return array(
			'terms' => array(),
			'floor' => '',
		);
	}

	return array(
		'terms' => array_map( 'intval', $ids ),
		'floor' => min( array_map( static fn ( $id ) => $seasons[ $id ]['first'], $ids ) ),
	);
}

/**
 * First and last event date per sp_season term, in one grouped query. Published and scheduled
 * events both count: the season being played is the one with games still to come.
 *
 * @return array<int, array{first_date:string, last_date:string}> Term ID => range.
 */
function blueline_archival_term_date_ranges(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an aggregate no WP API offers; the caller caches the whole result in a transient.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tt.term_id AS term_id, MIN( p.post_date ) AS first_date, MAX( p.post_date ) AS last_date
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'future' )
			GROUP BY tt.term_id",
			'sp_season',
			'sp_event'
		)
	);

	$ranges = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$ranges[ (int) $row->term_id ] = array(
			'first_date' => (string) $row->first_date,
			'last_date'  => (string) $row->last_date,
		);
	}

	return $ranges;
}

/**
 * Published IDs of a post type, optionally narrowed by a season tax_query.
 *
 * @param string                    $post_type Post type.
 * @param array<string, mixed>|null $tax_query One tax_query clause, or null for every post.
 * @param string[]                  $statuses  Post statuses.
 * @param array<string, mixed>      $extra     Further get_posts() arguments.
 * @return int[]
 */
function blueline_archival_query_ids( string $post_type, ?array $tax_query = null, array $statuses = array( 'publish' ), array $extra = array() ): array {
	$args = array(
		'post_type'      => $post_type,
		'post_status'    => $statuses,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'none',
	) + $extra;

	if ( null !== $tax_query ) {
		$args['tax_query'] = array( $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- runs once per cache rebuild, not per request.
	}

	$ids = get_posts( $args );

	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Season term IDs of the recent seasons plus their playoff terms, as one tax_query clause.
 *
 * @param int[] $term_ids Term IDs.
 * @return array<string, mixed>
 */
function blueline_archival_season_clause( array $term_ids ): array {
	return array(
		'taxonomy'         => 'sp_season',
		'field'            => 'term_id',
		'terms'            => array_values( $term_ids ),
		'operator'         => 'IN',
		'include_children' => false, // The playoff terms are listed explicitly.
	);
}

/**
 * The player, team and staff IDs in the line-ups of the given events.
 *
 * @param int[] $event_ids Event IDs.
 * @return array{sp_player:int[], sp_team:int[], sp_staff:int[]} Participant IDs by event meta key.
 */
function blueline_archival_event_participants( array $event_ids ): array {
	$found = array(
		'sp_player' => array(),
		'sp_team'   => array(),
		'sp_staff'  => array(),
	);

	if ( ! $event_ids ) {
		return $found;
	}

	// One query for every event's meta instead of one per event and key.
	update_meta_cache( 'post', $event_ids );

	foreach ( $event_ids as $event_id ) {
		foreach ( array_keys( $found ) as $key ) {
			foreach ( (array) get_post_meta( $event_id, $key, false ) as $value ) {
				// SportsPress stores 0 for an empty line-up slot.
				$id = (int) $value;
				if ( $id > 0 ) {
					$found[ $key ][ $id ] = $id;
				}
			}
		}
	}

	return $found;
}

/**
 * Work out which published posts are archival, uncached.
 *
 * Nothing is archival while the recent seasons cannot be determined (no events, no season
 * terms): with no evidence of what is current, leaving everything listed is the safe answer.
 *
 * @param int|null $seasons How many seasons are recent; null reads the filter.
 * @return array{version:int, seasons:int, types:string[], recent_terms:int[], archival:array<string, int[]>, totals:array<string, int>, generated:int}
 */
function blueline_archival_compute( ?int $seasons = null ): array {
	$seasons = null === $seasons ? blueline_archival_recent_seasons() : max( 0, $seasons );
	$types   = blueline_archival_post_types();

	$data = array(
		'version'      => BLUELINE_ARCHIVAL_VERSION,
		'seasons'      => $seasons,
		'types'        => array_keys( $types ),
		'recent_terms' => array(),
		'archival'     => array_fill_keys( array_keys( $types ), array() ),
		'totals'       => array(),
		'generated'    => time(),
	);

	if ( $seasons < 1 || ! $types || ! taxonomy_exists( 'sp_season' ) || ! post_type_exists( 'sp_event' ) ) {
		return $data;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'sp_season',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return $data;
	}

	$rows = array();
	foreach ( $terms as $term ) {
		$rows[] = array(
			'term_id' => (int) $term->term_id,
			'slug'    => (string) $term->slug,
			'parent'  => (int) $term->parent,
		);
	}

	$families = blueline_archival_season_families( $rows );
	$recent   = blueline_archival_pick_recent( $families, blueline_archival_term_date_ranges(), $seasons );

	if ( ! $recent['terms'] ) {
		return $data;
	}

	$data['recent_terms'] = $recent['terms'];

	// Every term of a recent season: the season itself and its playoff terms.
	$recent_term_ids = array();
	foreach ( $families as $term_id => $season_id ) {
		if ( in_array( $season_id, $recent['terms'], true ) ) {
			$recent_term_ids[] = $term_id;
		}
	}

	$recent_clause = blueline_archival_season_clause( $recent_term_ids );
	$recent_events = blueline_archival_query_ids( 'sp_event', $recent_clause, array( 'publish', 'future' ) );

	$participants = blueline_archival_event_participants( $recent_events );
	$seasonless   = array(
		'taxonomy' => 'sp_season',
		'operator' => 'NOT EXISTS',
	);

	foreach ( $types as $type => $rule ) {
		$all                     = blueline_archival_query_ids( $type );
		$data['totals'][ $type ] = count( $all );

		if ( 'event' === $rule ) {
			// An event with no season is current only when dated inside the recent seasons.
			$keep = array_merge(
				$recent_events,
				blueline_archival_query_ids(
					$type,
					$seasonless,
					array( 'publish', 'future' ),
					array(
						'date_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- runs once per cache rebuild.
							array(
								'column'    => 'post_date',
								'after'     => $recent['floor'],
								'inclusive' => true,
							),
						),
					)
				)
			);
		} elseif ( 'tagged' === $rule ) {
			$keep = array_merge(
				blueline_archival_query_ids( $type, $recent_clause ),
				blueline_archival_query_ids( $type, $seasonless )
			);
		} else {
			$meta_key = 'sp_' . $rule;
			$keep     = array_merge(
				blueline_archival_query_ids( $type, $recent_clause ),
				array_values( $participants[ $meta_key ] ?? array() )
			);
		}

		$archival = array_values( array_diff( $all, $keep ) );
		sort( $archival );

		$data['archival'][ $type ] = $archival;
	}

	return $data;
}

/**
 * The archival ids as cached: the request memo, then the transient, then a fresh computation.
 * A cached copy computed under other settings (season count, post types, payload layout) is
 * ignored.
 *
 * @return array{version:int, seasons:int, types:string[], recent_terms:int[], archival:array<string, int[]>, totals:array<string, int>, generated:int}
 */
function blueline_archival_data(): array {
	static $registered = false;

	if ( ! $registered ) {
		wp_cache_add_non_persistent_groups( BLUELINE_ARCHIVAL_CACHE_GROUP );
		$registered = true;
	}

	$seasons = blueline_archival_recent_seasons();
	$types   = array_keys( blueline_archival_post_types() );

	$usable = static function ( $cached ) use ( $seasons, $types ): bool {
		return is_array( $cached )
			&& ( $cached['version'] ?? 0 ) === BLUELINE_ARCHIVAL_VERSION
			&& ( $cached['seasons'] ?? -1 ) === $seasons
			&& ( $cached['types'] ?? null ) === $types;
	};

	$memo = wp_cache_get( 'data', BLUELINE_ARCHIVAL_CACHE_GROUP, false, $found );
	if ( $found && $usable( $memo ) ) {
		return $memo;
	}

	$stored = get_transient( BLUELINE_ARCHIVAL_TRANSIENT );
	if ( $usable( $stored ) ) {
		wp_cache_set( 'data', $stored, BLUELINE_ARCHIVAL_CACHE_GROUP );
		return $stored;
	}

	$data = blueline_archival_compute( $seasons );

	set_transient( BLUELINE_ARCHIVAL_TRANSIENT, $data, BLUELINE_ARCHIVAL_TTL );
	wp_cache_set( 'data', $data, BLUELINE_ARCHIVAL_CACHE_GROUP );

	return $data;
}

/**
 * Drop the cached archival ids, so the next read recomputes them.
 *
 * @return void
 */
function blueline_archival_invalidate(): void {
	delete_transient( BLUELINE_ARCHIVAL_TRANSIENT );
	wp_cache_delete( 'data', BLUELINE_ARCHIVAL_CACHE_GROUP );
}

/**
 * Pass an archival verdict through the blueline_core_is_archival_post filter.
 *
 * @param bool   $computed Whether the post's seasons say it is archival.
 * @param object $post     The post.
 * @return bool
 */
function blueline_archival_filter_verdict( bool $computed, $post ): bool {
	/**
	 * Filters whether a post is archival, that is, left out of the XML sitemap while staying
	 * indexable. Only called for the post types in blueline_core_archival_post_types.
	 *
	 * @param bool    $archival Whether the post's seasons say it is archival.
	 * @param WP_Post $post     The post.
	 */
	return (bool) apply_filters( 'blueline_core_is_archival_post', $computed, $post );
}

/**
 * Whether one post is archival: computed from its seasons, then passed through the filter.
 *
 * @param WP_Post|int|null $post Post or post ID.
 * @return bool
 */
function blueline_is_archival_post( $post ): bool {
	$post = is_object( $post ) ? $post : get_post( $post );

	if ( ! $post || ! isset( blueline_archival_post_types()[ $post->post_type ] ) ) {
		return false;
	}

	$computed = in_array( (int) $post->ID, blueline_archival_data()['archival'][ $post->post_type ] ?? array(), true );

	return blueline_archival_filter_verdict( $computed, $post );
}

/**
 * The archival IDs of one post type, with the per-post filter applied.
 *
 * Without a filter on blueline_core_is_archival_post this is the cached list as is. With one,
 * every published post of the type is offered to it, which loads each post: a cost only a
 * site that opted in pays.
 *
 * @param string $post_type Post type.
 * @return int[] Ascending.
 */
function blueline_archival_ids( string $post_type ): array {
	if ( ! isset( blueline_archival_post_types()[ $post_type ] ) ) {
		return array();
	}

	$computed = blueline_archival_data()['archival'][ $post_type ] ?? array();

	if ( ! has_filter( 'blueline_core_is_archival_post' ) ) {
		return $computed;
	}

	$flagged = array_flip( $computed );
	$ids     = array();

	foreach ( blueline_archival_query_ids( $post_type ) as $post_id ) {
		$post = get_post( $post_id );

		if ( $post && blueline_archival_filter_verdict( isset( $flagged[ $post_id ] ), $post ) ) {
			$ids[] = $post_id;
		}
	}

	sort( $ids );

	return $ids;
}

add_filter( 'wp_sitemaps_posts_query_args', 'blueline_archival_sitemap_query_args', 11, 2 );
/**
 * Keep archival posts out of core's XML sitemap. Core builds both the per-type sitemap pages
 * and the sitemap index (one entry per page of results) from this same query, so a type with
 * nothing left gets no index entry and the page count of the others shrinks to match.
 *
 * @param mixed  $args      WP_Query args for one post-type sitemap.
 * @param string $post_type Post type.
 * @return mixed
 */
function blueline_archival_sitemap_query_args( $args, $post_type ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	$archival = blueline_archival_ids( (string) $post_type );

	if ( ! $archival ) {
		return $args;
	}

	$existing             = isset( $args['post__not_in'] ) ? array_map( 'intval', (array) $args['post__not_in'] ) : array();
	$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $archival ) ) );

	return $args;
}

add_action( 'save_post', 'blueline_archival_invalidate_for_post', 10, 1 );
add_action( 'before_delete_post', 'blueline_archival_invalidate_for_post', 10, 1 );
/**
 * Drop the cache when a post that feeds it is saved or deleted: any archival-capable type (a
 * published event or a new player changes the answer), or an event whose line-up changed.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function blueline_archival_invalidate_for_post( $post_id ): void {
	$type = get_post_type( (int) $post_id );

	if ( is_string( $type ) && isset( blueline_archival_post_types()[ $type ] ) ) {
		blueline_archival_invalidate();
	}
}

add_action( 'transition_post_status', 'blueline_archival_invalidate_for_transition', 10, 3 );
/**
 * Drop the cache when an archival-capable post is published, unpublished or trashed. Covers the
 * cron that publishes a scheduled event, which does not run save_post.
 *
 * @param string $new_status New status.
 * @param string $old_status Old status.
 * @param object $post       The post.
 * @return void
 */
function blueline_archival_invalidate_for_transition( $new_status, $old_status, $post ): void {
	if ( $new_status === $old_status || ! is_object( $post ) ) {
		return;
	}

	if ( isset( blueline_archival_post_types()[ (string) ( $post->post_type ?? '' ) ] ) ) {
		blueline_archival_invalidate();
	}
}

add_action( 'set_object_terms', 'blueline_archival_invalidate_for_terms', 10, 4 );
/**
 * Drop the cache when a post's season terms change.
 *
 * @param int    $object_id Post ID.
 * @param array  $terms     Terms as passed.
 * @param array  $tt_ids    Term taxonomy IDs now attached.
 * @param string $taxonomy  Taxonomy.
 * @return void
 */
function blueline_archival_invalidate_for_terms( $object_id, $terms, $tt_ids, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with the set_object_terms action.
	if ( 'sp_season' === $taxonomy ) {
		blueline_archival_invalidate();
	}
}

add_action( 'created_sp_season', 'blueline_archival_invalidate' );
add_action( 'edited_sp_season', 'blueline_archival_invalidate' );
add_action( 'delete_sp_season', 'blueline_archival_invalidate' );

add_filter( 'site_status_tests', 'blueline_archival_register_site_health_test' );
/**
 * Register the "Archival pages" Site Health direct test.
 *
 * @param mixed $tests Site Health tests.
 * @return mixed
 */
function blueline_archival_register_site_health_test( $tests ) {
	if ( ! is_array( $tests ) ) {
		return $tests;
	}

	$tests['direct']['blueline_archival_pages'] = array(
		'label' => __( 'Archival pages in the sitemap', 'blueline-core' ),
		'test'  => 'blueline_archival_run_site_health_test',
	);

	return $tests;
}

/**
 * Run the Site Health test against the cached archival data.
 *
 * @return array<string, mixed>
 */
function blueline_archival_run_site_health_test(): array {
	return blueline_archival_site_health_result( blueline_archival_data() );
}

/**
 * The Site Health result for the archival sitemap exclusion. Informational: it always reads
 * "good", because leaving archival pages out is the intended state, not a problem.
 *
 * @param array $data Archival data (blueline_archival_data()).
 * @return array<string, mixed>
 */
function blueline_archival_site_health_result( array $data ): array {
	$seasons = (int) ( $data['seasons'] ?? 0 );
	$left    = 0;
	$listed  = 0;

	foreach ( (array) ( $data['totals'] ?? array() ) as $type => $total ) {
		$excluded = count( (array) ( $data['archival'][ $type ] ?? array() ) );
		$left    += $excluded;
		$listed  += max( 0, (int) $total - $excluded );
	}

	if ( $seasons < 1 ) {
		$label       = __( 'Archival pages are not being left out of the sitemap', 'blueline-core' );
		$description = __( 'The number of recent seasons was set to 0 by a filter, so every SportsPress page stays in the XML sitemap.', 'blueline-core' );
	} elseif ( empty( $data['recent_terms'] ) ) {
		$label       = __( 'No archival pages are being left out of the sitemap', 'blueline-core' );
		$description = __( 'No season has any events yet, so nothing can be told apart as past. Every SportsPress page stays in the XML sitemap.', 'blueline-core' );
	} else {
		$label = sprintf(
			/* translators: 1: number of archival pages left out of the sitemap, 2: number of pages still listed. */
			__( '%1$s archival pages are left out of the sitemap, %2$s listed', 'blueline-core' ),
			number_format_i18n( $left ),
			number_format_i18n( $listed )
		);

		$description = sprintf(
			/* translators: %d: number of recent seasons. */
			__( 'Events, players, teams, staff, tables, lists and calendars from before the %d most recent seasons are left out of the XML sitemap so search engines spend their visits on the current season. The pages stay indexable and keep their canonical address, and the site search is unchanged.', 'blueline-core' ),
			$seasons
		);
	}

	return array(
		'label'       => $label,
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Blueline', 'blueline-core' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => '',
		'test'        => 'blueline_archival_pages',
	);
}
