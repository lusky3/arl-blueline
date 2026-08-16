<?php
/**
 * Season state — decides what the site should emphasise right now.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The five season states blueline_decide_season_state() can return. There is
 * no sixth: the break-glass override below refuses anything outside this
 * list, and blueline_render_hero() (inc/homepage-modules.php) falls back to
 * 'offseason' for anything outside it.
 *
 * The order here is presentation order -- roughly the arc of a season -- and
 * is NOT the order blueline_decide_season_state() evaluates its branches in.
 * That function tests registration_open first, then playoffs, then
 * in_season/preseason, then falls through to offseason.
 *
 * KNOWN DUPLICATION: blueline_render_hero() still holds its own literal copy
 * of these same five values. It predates this constant and was not rewritten
 * to read it as part of Task 7, which had no other reason to touch that
 * file. A sixth state would need both edits; tests/SeasonStateOverrideTest.php
 * pins this list's contents, so at least one side of the pair cannot drift
 * unnoticed.
 */
const BLUELINE_SEASON_STATES = array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' );

/**
 * Pure predicate: is registration currently open. P1 finding 4: this used to
 * live only as a branch inside blueline_decide_season_state(), which forces
 * it to be mutually exclusive with every other state -- but this site sells
 * next season's registration while this season's games are still being
 * played for months at a time (confirmed: that overlap is normal, not an
 * edge case), so "are we selling" and "are we playing" are independent
 * facts a caller may need separately. Extracted so both can be asked
 * without going through the five-state enum at all.
 *
 * @param array $signals Same shape as blueline_decide_season_state().
 * @return bool
 */
function blueline_decide_registration_open( array $signals ): bool {
	return ! empty( $signals['has_purchasable_product'] );
}

/**
 * Pure predicate: are games currently being played -- i.e. would an existing
 * player checking the site right now have a "when's my next game" question
 * worth answering. True for both of the enum's in_season and playoffs
 * states (playoffs is a kind of playing), false for preseason (games are
 * scheduled but none have been played recently and the next one isn't
 * imminent) and offseason. See blueline_decide_registration_open()'s
 * docblock for why this is independent of that predicate rather than a
 * branch of the same enum.
 *
 * @param array $signals Same shape as blueline_decide_season_state().
 * @return bool
 */
function blueline_decide_is_playing( array $signals ): bool {
	$upcoming = (int) ( $signals['upcoming_events'] ?? 0 );
	$days     = $signals['days_to_next_event'] ?? null;
	$recent   = (int) ( $signals['recent_events'] ?? 0 );

	if ( $upcoming <= 0 ) {
		return false;
	}

	return $recent > 0 || ( null !== $days && $days <= 14 );
}

/**
 * Pure decision function. Kept free of WordPress calls so it can be unit
 * tested. Still the single five-state summary other code depends on
 * (blueline_season_state() and its consumers) -- registration_open and
 * "games are being played" are independent facts (see
 * blueline_decide_registration_open()/blueline_decide_is_playing()) that
 * this enum necessarily collapses into one mutually-exclusive value, which
 * is exactly why callers that need both facts at once (the homepage layout,
 * P1 finding 4) should read blueline_is_registration_open()/
 * blueline_is_playing() instead of branching on this string.
 *
 * @param array $signals Keys: has_purchasable_product (bool), upcoming_events (int),
 *                       days_to_next_event (int|null), has_playoff_events (bool),
 *                       recent_events (int).
 * @return string One of registration_open|preseason|in_season|playoffs|offseason.
 */
function blueline_decide_season_state( array $signals ): string {
	$upcoming = (int) ( $signals['upcoming_events'] ?? 0 );
	$playoffs = ! empty( $signals['has_playoff_events'] );

	// Selling a registration is always the loudest signal.
	if ( blueline_decide_registration_open( $signals ) ) {
		return 'registration_open';
	}

	if ( $playoffs && $upcoming > 0 ) {
		return 'playoffs';
	}

	if ( $upcoming > 0 ) {
		return blueline_decide_is_playing( $signals ) ? 'in_season' : 'preseason';
	}

	return 'offseason';
}

/**
 * Term ID of the "Registration" product_cat parent. Each season's products
 * live in a child term of this one (e.g. "Winter 2026-27"); the newest
 * child by term_id is the current season.
 *
 * This is the DOCUMENTED FALLBACK, not a value any code should read
 * directly any more: an inventory pass could not confirm 91 by numeric ID
 * (the available tooling filters product categories by slug, and
 * `category: 91` returned nothing while `category: "registration"` returned
 * the live products correctly), so 91 is plausible but unverified. Every
 * call site now resolves the actual term to use through
 * blueline_resolve_registration_term() (inc/settings/commerce.php), which
 * checks the admin-configured `registration_term` setting AND this constant
 * against get_term() before trusting either. The constant stays defined
 * (and this is still its fallback value in the schema, inc/settings/
 * defaults.php) because other code guards on defined( 'BLUELINE_REGISTRATION_TERM_ID' )
 * as a "is season-state.php loaded" sanity check (see
 * inc/account/player-data.php).
 */
const BLUELINE_REGISTRATION_TERM_ID = 91;

/**
 * The post_status a published, already-visible product or event carries.
 *
 * Used as-is for the product query and the recent-events query, both of
 * which only ever want posts that are actually live. The upcoming-events
 * query is deliberately NOT this constant alone: WordPress core
 * auto-assigns `future` (not `publish`) to any post whose post_date is
 * later than now, so a query for genuinely upcoming sp_event posts must
 * include `future` too or it silently returns nothing but already-past
 * games -- confirmed on staging, where every real upcoming game (33 of
 * them) carries `future` and zero upcoming games carry `publish`.
 */
const BLUELINE_PUBLISHED_STATUS = 'publish';

/**
 * Post IDs of every product in the current season's Registration category
 * (BLUELINE_REGISTRATION_TERM_ID's newest child term by term_id) --
 * unfiltered by purchasability/stock, since callers need to make that live
 * call themselves (see blueline_season_state_data()'s own docblock on why
 * purchasability is never read from a cache).
 *
 * P0 finding 1: this exists so a consumer that needs to know about EVERY
 * product in the season -- not just "the first purchasable one", which is
 * all blueline_season_state_data() itself needs -- doesn't have to
 * duplicate the season-term resolution query. The homepage hero used to
 * quote the price of whatever single product this query happened to return
 * first (post ID order), which meant a $145 Goalie Registration could
 * outrank a $550 Player Registration on the site's primary CTA. Nothing
 * here picks a "winner" by name or ID; every purchasable product this
 * returns is the caller's to price and label.
 *
 * @return int[] Product IDs, in whatever order WP_Query returned them.
 */
function blueline_registration_season_product_ids(): array {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return array();
	}

	$registration_term = blueline_resolve_registration_term();

	if ( $registration_term <= 0 ) {
		// Neither the configured term nor the documented fallback resolves
		// to a real product_cat term (see blueline_resolve_registration_term()'s
		// docblock) -- nothing to query. Returning early here is what keeps
		// a 0 from ever reaching get_terms()'s `parent` argument, where it
		// would mean something else entirely ("top-level terms").
		return array();
	}

	$season_terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => $registration_term,
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => 1,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $season_terms ) || empty( $season_terms ) ) {
		return array();
	}

	$product_query = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => BLUELINE_PUBLISHED_STATUS,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- scoped to a single small season term, not an unbounded query.
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $season_terms[0]->term_id,
				),
			),
		)
	);

	return array_map( 'absint', $product_query->posts );
}

/**
 * The single moment every time-sensitive read in blueline_season_state_data()
 * derives from, in the two forms that function needs it: the site-local
 * `Y-m-d H:i:s` string its `date_query` bounds are expressed in, and the Unix
 * timestamp its days-to-next-event arithmetic subtracts from.
 *
 * Those bounds are compared against `post_date`, which the null branch below
 * has always treated as site-local by comparing it against
 * `current_time( 'mysql' )` -- so an injected timestamp is first formatted
 * in the site's own zone (blueline_site_timestamp()'s inverse,
 * inc/announcement.php) rather than as UTC, which would put every bound out
 * by the site's offset.
 *
 * ## Why BOTH values come off the same string
 *
 * The returned timestamp is deliberately NOT the injected instant passed
 * straight through. Both branches end at `strtotime( $mysql )` -- the
 * site-local wall clock read back in whatever frame strtotime() uses -- so
 * the two are equal by construction rather than by coincidence.
 *
 * That is not decoration. Two consumers subtract from this timestamp and
 * then compare the result against a site-local value:
 *
 *   - the recent-events query's `after` bound, `gmdate( ..., $now_ts - 30 *
 *     DAY_IN_SECONDS )`, sitting directly beside a `before` bound that is
 *     `$mysql` itself;
 *   - `$diff_seconds = strtotime( $next_post->post_date ) - $now_ts`, where
 *     `post_date` is site-local and read by the same strtotime().
 *
 * An earlier version of this function returned the raw instant here. The
 * `$mysql` half was right, so the paired bounds looked right, but the
 * derived ones were out by the site's offset -- four or five hours for this
 * league. `days_to_next_event` skewed by the same amount, which is enough
 * for blueline_decide_is_playing()'s `<= 14` day test to flip in_season and
 * preseason around a boundary. Nothing shipped broken (the raw-instant
 * branch is only reachable through `$now`, which no production call site
 * passes), but the docblock claimed the two branches matched while one
 * derived bound did not.
 * tests/SeasonStateOverrideTest.php pins the parity directly.
 *
 * This is its own function so the `$now` branch is directly testable: the
 * queries it feeds need a real WordPress database, this does not, and
 * "does an injected timestamp actually move the bounds" is the entire
 * question that parameter exists to answer.
 *
 * @param int|null $now Unix timestamp, or null for the current time.
 * @return array{0:string,1:int} The site-local mysql datetime and its timestamp.
 */
function blueline_season_state_moment( ?int $now = null ): array {
	$mysql = null === $now
		? current_time( 'mysql' )
		: ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );

	return array( $mysql, (int) strtotime( $mysql ) );
}

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
 * ## The `$now` parameter and the cache
 *
 * Every time-sensitive read in this function -- the upcoming/recent event
 * queries and the days-to-next-event arithmetic -- derives from one moment.
 * Passing `$now` replaces the clock as the source of that moment, which is
 * what makes a test about "what state is this site in on such-and-such a
 * date" answerable at all.
 *
 * When `$now` is given, the 15-minute transient is bypassed in BOTH
 * directions: not read, and not written. Read, because a value computed at
 * some other moment is not an answer to a question about this one -- that
 * bypass is also what makes the parameter observably threaded rather than
 * accepted and dropped (see tests/SeasonStateOverrideTest.php). Written,
 * because one such read would otherwise poison the cache for every ordinary
 * request that followed it. The transient is deliberately NOT keyed by
 * `$now` instead: that would be an unbounded set of cache keys for a value
 * only the live path ever reuses.
 *
 * @param int|null $now Unix timestamp to evaluate against; null means now.
 * @return array {
 *     @type string   $state                 One of the five season states.
 *     @type int|null $product_id            Purchasable product driving registration_open, if any.
 *     @type int|null $next_event_id         Soonest upcoming sp_event, if any.
 *     @type bool     $is_registration_open  Independent of $state -- see blueline_decide_registration_open().
 *     @type bool     $is_playing            Independent of $state -- see blueline_decide_is_playing().
 * }
 */
function blueline_season_state_data( ?int $now = null ): array {
	$use_cache = null === $now;

	if ( $use_cache ) {
		$cached = get_transient( 'blueline_season_state' );
		if ( is_array( $cached ) && isset( $cached['state'], $cached['is_playing'], $cached['is_registration_open'] ) ) {
			return $cached;
		}
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
	if ( function_exists( 'wc_get_product' ) ) {
		foreach ( blueline_registration_season_product_ids() as $candidate_id ) {
			$product = wc_get_product( $candidate_id );

			if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
				$signals['has_purchasable_product'] = true;
				$product_id                         = (int) $candidate_id;
				break;
			}
		}
	}

	// --- SportsPress: upcoming/recent events, playoff detection. ---
	if ( post_type_exists( 'sp_event' ) ) {
		list( $now_mysql, $now_ts ) = blueline_season_state_moment( $now );

		$upcoming_query = new WP_Query(
			array(
				'post_type'      => 'sp_event',
				// Upcoming games are 'future', not 'publish' -- WordPress
				// core auto-assigns 'future' to any post whose post_date is
				// later than now. See BLUELINE_PUBLISHED_STATUS's docblock.
				'post_status'    => array( BLUELINE_PUBLISHED_STATUS, 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
					array(
						'column' => 'post_date',
						'after'  => $now_mysql,
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
				'post_status'    => BLUELINE_PUBLISHED_STATUS,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
					array(
						'column'    => 'post_date',
						'after'     => gmdate( 'Y-m-d H:i:s', $now_ts - 30 * DAY_IN_SECONDS ),
						'before'    => $now_mysql,
						'inclusive' => true,
					),
				),
			)
		);

		$signals['recent_events'] = count( $recent_query->posts );
	}

	$state = blueline_decide_season_state( $signals );

	$data = array(
		'state'                => $state,
		'product_id'           => $product_id,
		'next_event_id'        => $next_event_id,
		'is_registration_open' => blueline_decide_registration_open( $signals ),
		'is_playing'           => blueline_decide_is_playing( $signals ),
	);

	if ( $use_cache ) {
		set_transient( 'blueline_season_state', $data, 15 * MINUTE_IN_SECONDS );
	}

	return $data;
}

/**
 * Public accessor: is registration open right now, independent of whether
 * games are also being played (P1 finding 4). Unlike blueline_season_state(),
 * this and blueline_is_playing() can BOTH be true at once -- which is the
 * normal, months-long state of this site whenever next season's
 * registration opens before this season's games finish.
 *
 * @return bool
 */
function blueline_is_registration_open(): bool {
	$data = blueline_season_state_data();
	return ! empty( $data['is_registration_open'] );
}

/**
 * Public accessor: are games currently being played, independent of whether
 * registration is also open. See blueline_is_registration_open()'s docblock.
 *
 * @return bool
 */
function blueline_is_playing(): bool {
	$data = blueline_season_state_data();
	return ! empty( $data['is_playing'] );
}

/**
 * A human label for one of the five states, for admin copy. Printing
 * `in_season` at a volunteer is printing them an implementation detail.
 *
 * @param string $state One of BLUELINE_SEASON_STATES.
 * @return string A readable label, or the raw key for anything unknown --
 *                which nothing should ever pass, but a blank label would be
 *                worse than an ugly one.
 */
function blueline_season_state_label( string $state ): string {
	$labels = array(
		'registration_open' => __( 'Registration open', 'blueline' ),
		'preseason'         => __( 'Preseason', 'blueline' ),
		'in_season'         => __( 'In season', 'blueline' ),
		'playoffs'          => __( 'Playoffs', 'blueline' ),
		'offseason'         => __( 'Off-season', 'blueline' ),
	);

	return $labels[ $state ] ?? $state;
}

/**
 * The season state an admin has forced, or '' when nothing is being forced.
 *
 * BREAK-GLASS, not routine configuration. It exists for the case where the
 * computed state is wrong -- a catalogue mistake, a schedule import gone
 * sideways -- and the site has to say the right thing before anyone can fix
 * the underlying data.
 *
 * Three separate conditions each mean "no override", and all three fall
 * through to the computed state rather than failing:
 *
 * 1. The stored state is not one of BLUELINE_SEASON_STATES. This theme never
 *    invents a sixth state. The panel now renders this field as a dropdown
 *    over exactly these five plus "no override", and refuses anything else
 *    at save time (the schema's `choices` list, inc/settings/defaults.php) --
 *    so an off-list value can now only arrive by an import or a direct
 *    update_option(). Before that, a typo saved cleanly, changed nothing,
 *    and produced no notice, because the notice below only renders while
 *    this function returns non-empty: a silent no-op on an emergency
 *    control. This check is what still catches the remaining routes in.
 * 2. There is no expiry date. The expiry is MANDATORY, and this is the whole
 *    design: an override nobody remembers setting quietly becomes the site's
 *    permanent state, at which point the season logic is dead code and
 *    nobody knows it. An override that cannot outlive its usefulness is the
 *    only kind worth shipping.
 * 3. The expiry has passed, or cannot be parsed. An unparseable expiry is
 *    treated as no expiry -- the opposite direction to the announcement
 *    banner's unparseable window bound (inc/announcement.php), and
 *    deliberately: there, ignoring a broken bound keeps a banner an admin
 *    believes is live on screen; here, ignoring a broken expiry is what
 *    keeps a forgotten override from being permanent.
 *
 * The expiry day is INCLUSIVE and runs to the end of that day in site time,
 * matching the announcement window's own bounds.
 *
 * @param int|null $now Unix timestamp to evaluate the expiry against; null
 *                      means now.
 * @return string One of BLUELINE_SEASON_STATES, or '' for no override.
 */
function blueline_season_state_override( ?int $now = null ): string {
	$state = (string) blueline_settings( 'season_state_override' );

	if ( ! in_array( $state, BLUELINE_SEASON_STATES, true ) ) {
		return '';
	}

	$until = trim( (string) blueline_settings( 'season_state_override_until' ) );

	if ( '' === $until ) {
		return '';
	}

	$expires = blueline_site_timestamp( $until . ' 23:59:59' );

	if ( null === $expires || ( $now ?? time() ) > $expires ) {
		return '';
	}

	return $state;
}

/**
 * Public accessor: the current season state.
 *
 * An active override (blueline_season_state_override()) replaces the
 * computed state, and does so BEFORE the `blueline_season_state` filter
 * fires, so a developer filter still has the last word -- filters are the
 * last word everywhere else in this theme and this is not the place to make
 * an exception.
 *
 * The override deliberately does NOT touch blueline_is_registration_open()
 * or blueline_is_playing(). Those two report live facts about the catalogue
 * and the schedule (P1 finding 4) rather than the emphasis this enum
 * collapses them into; forcing them as well would mean the panel could make
 * the site claim registration is open when nothing is actually purchasable.
 *
 * @param int|null $now Unix timestamp to evaluate against; null means now.
 *                      Threaded all the way to blueline_season_state_data(),
 *                      where the time-sensitive queries actually live.
 * @return string One of BLUELINE_SEASON_STATES.
 */
function blueline_season_state( ?int $now = null ): string {
	$override = blueline_season_state_override( $now );

	// An active override makes the computed state irrelevant, so the queries
	// behind it are skipped rather than run and thrown away.
	$state = '' !== $override ? $override : blueline_season_state_data( $now )['state'];

	/**
	 * Filters the season state -- e.g. to force a value while testing, or as
	 * a manual override during an unusual season transition.
	 *
	 * @param string $state The season state, after any admin override.
	 */
	return apply_filters( 'blueline_season_state', $state );
}

/**
 * The persistent admin notice shown while an override is active.
 *
 * This notice is half the feature. The override's mandatory expiry stops a
 * forgotten one lasting forever; this stops it being forgotten in the first
 * place, by saying so on every wp-admin screen rather than only on the
 * settings page a volunteer may not open again for weeks --  the same
 * reasoning inc/settings/cache.php's own persistent notice is built on, and
 * the same `manage_options` gate, so nobody is shown an instruction they
 * cannot act on.
 *
 * A `<section>`, never a `<div>`: a third-party plugin active on this
 * install strips any `<div>` whose class contains "notice", "error",
 * "warning", "info" or "updated" (see tests/NoticeDivGuardTest.php and
 * inc/settings/cache.php's own notice for the full account). The
 * `notice notice-warning` classes here match that pattern exactly.
 *
 * @return void
 */
function blueline_render_season_state_override_notice(): void {
	$override = blueline_season_state_override();

	if ( '' === $override ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$until = trim( (string) blueline_settings( 'season_state_override_until' ) );
	?>
	<section class="notice notice-warning bl-season-override-notice">
		<p>
			<?php
			printf(
				/* translators: 1: the forced season state's label, 2: the date the override lifts, as YYYY-MM-DD. */
				esc_html__( 'Season state is being forced to "%1$s" until %2$s. Until then the site ignores what it would otherwise work out from the registration catalogue and the schedule.', 'blueline' ),
				esc_html( blueline_season_state_label( $override ) ),
				esc_html( $until )
			);
			?>
		</p>
		<p><?php esc_html_e( 'Clear it under Appearance → Blueline as soon as the underlying data is right again.', 'blueline' ); ?></p>
	</section>
	<?php
}

/**
 * Bust the cached season-state signals so the next read recomputes them.
 */
function blueline_bust_season_state_cache() {
	delete_transient( 'blueline_season_state' );
}

// Guarded so this file stays safe to require_once directly, the way
// tests/SeasonStateTest.php does -- the guard predates the test bootstrap
// growing its own add_action() stub and is kept because a bare require of an
// inc/ file must not depend on which WordPress functions happen to exist.
if ( function_exists( 'add_action' ) ) {
	add_action( 'save_post_product', 'blueline_bust_season_state_cache' );
	add_action( 'save_post_sp_event', 'blueline_bust_season_state_cache' );
	add_action( 'woocommerce_update_product', 'blueline_bust_season_state_cache' );
	add_action( 'admin_notices', 'blueline_render_season_state_override_notice' );
}
