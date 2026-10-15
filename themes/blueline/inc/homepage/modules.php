<?php
/**
 * Homepage: the module framework. Per-state module order, the shared module
 * chrome and empty state, the next_games and latest_news modules, and the
 * blueline_render_module() dispatcher.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-state homepage module order.
 * Any state outside the five known values falls back to the offseason
 * order: the smallest, least "sell registration" set of modules.
 *
 * 'sponsors' is deliberately absent from every order below: SportsPress
 * Pro's own footer sponsors block (SportsPress_Sponsors::footer(), hooked
 * to get_footer sitewide) already renders directly above <footer> on every
 * page, homepage included, carrying the league's own configured title; a
 * module here would render a second, redundant sponsors section only on
 * the homepage. Omitting it here (rather than removing SportsPress's own
 * sitewide block) keeps sponsors showing on every other page; see
 * assets/src/css/sportspress.css for the styling applied to
 * SportsPress's block instead.
 *
 * registration_open's own order below still leads with
 * 'new_here' unconditionally, because the enum cannot tell "nobody has
 * played yet" apart from "the season is already running and we're also
 * selling next season," but those are very different visitors. When
 * $state_data reports is_playing true (the second case, and on
 * this site a months-long overlap, not an edge case), an existing player
 * checking the registration-heavy hero should meet their own next game and
 * the standings first, not three bullets of first-timer reassurance ahead
 * of it.
 *
 * The Sections tab has its own say on top of this table: each module here
 * also has a `module_*` presence toggle (blueline_section_enabled(),
 * inc/settings/sections.php), and this function is that toggle's only
 * consumer for the homepage. Both the is_playing path and the plain
 * $orders lookup resolve $order first and fall through to ONE filter, so
 * an admin's toggle choice applies to both, and "which modules are
 * considered" and "does this state override the order" stay independent
 * questions.
 *
 * @param string $state      Season state.
 * @param array  $state_data Result of blueline_season_state_data(); optional
 *                            so existing callers/tests passing only $state
 *                            keep working unchanged (empty array reads as
 *                            "not playing", i.e. today's behaviour).
 * @return string[] Module names, in render order. Never empty; see the
 *                   floor comment below.
 */
function blueline_homepage_module_order( string $state, array $state_data = array() ): array {
	$orders = array(
		'registration_open' => array( 'new_here', 'next_games', 'standings_snippet', 'latest_news' ),
		'preseason'         => array( 'next_games', 'new_here', 'latest_news' ),
		'in_season'         => array( 'next_games', 'standings_snippet', 'latest_news' ),
		'playoffs'          => array( 'next_games', 'standings_snippet', 'latest_news' ),
		'offseason'         => array( 'latest_news', 'new_here' ),
	);

	$order = $orders[ $state ] ?? $orders['offseason'];

	if ( 'registration_open' === $state && ! empty( $state_data['is_playing'] ) ) {
		$order = array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' );
	}

	$enabled = array_values(
		array_filter(
			$order,
			static function ( $module ) {
				return blueline_section_enabled( 'module_' . $module );
			}
		)
	);

	/*
	 * THE FLOOR. WCAG 2.4.5 wants two ways to find content, and a homepage
	 * with no modules has none. Rather than refuse the save, which would
	 * mean an admin cannot untick the last box even temporarily, the
	 * render path keeps the first module of $order, the array as it stands
	 * for THIS render (is_playing override already applied above, if any),
	 * deliberately not $orders[ $state ]'s raw, un-overridden table
	 * entry, which was never going to appear on this page at all. Under
	 * registration_open + is_playing with every toggle off, that means
	 * 'next_games' survives, not 'new_here': whichever module the visitor
	 * in front of them would actually have seen first. The Sections tab
	 * says so beside the four module_* checkboxes (each one's `help`
	 * string, inc/settings/sections.php).
	 */
	return $enabled ? $enabled : array_slice( $order, 0, 1 );
}

/**
 * Shared chrome (open half): module section, heading, optional "see more"
 * link. Pair with blueline_homepage_module_end() around each module's own
 * body markup.
 *
 * @param string $name       Module slug, becomes the bl-module--{name} modifier.
 * @param string $title      Module heading text.
 * @param string $link_url   "See more" URL, or '' to omit the link.
 * @param string $link_label "See more" link text.
 */
function blueline_homepage_module_start( string $name, string $title, string $link_url = '', string $link_label = '' ) {
	?>
	<section class="bl-module bl-module--<?php echo esc_attr( $name ); ?>">
		<div class="bl-container bl-module__inner">
			<header class="bl-module__header">
				<h2 class="bl-module__title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( $link_url ) : ?>
					<a class="bl-module__link" href="<?php echo esc_url( $link_url ); ?>">
						<?php echo esc_html( $link_label ); ?> <span aria-hidden="true">&rarr;</span>
					</a>
				<?php endif; ?>
			</header>
			<div class="bl-module__body">
	<?php
}

/**
 * Shared chrome (close half). See blueline_homepage_module_start().
 */
function blueline_homepage_module_end() {
	?>
			</div>
		</div>
	</section>
	<?php
}

/**
 * A module's empty state: the blue-leaf mark plus one line of copy. Every
 * module must use this instead of rendering an empty container.
 *
 * @param string $message One line of copy.
 */
function blueline_homepage_module_empty_state( string $message ) {
	?>
	<div class="bl-module__empty">
		<?php blueline_leaf_mark( 'bl-module__empty-mark' ); ?>
		<p class="bl-module__empty-text"><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * The next_games module's query: the next four games that are going ahead.
 * post_status MUST include 'future', because WordPress core assigns that
 * status (not 'publish') to any post dated ahead of now, and every genuinely
 * upcoming sp_event on this site carries it. See the docblock on
 * BLUELINE_PUBLISHED_STATUS in inc/season-state.php. Postponed and cancelled
 * games are left out, so the next real game shows in their place.
 *
 * @param int|null $now Unix timestamp to evaluate against; null means now.
 * @return array WP_Query args.
 */
function blueline_homepage_next_games_query_args( ?int $now = null ): array {
	list( $now_mysql ) = blueline_season_state_moment( $now );

	return array(
		'post_type'      => 'sp_event',
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => 4,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
			array(
				'column' => 'post_date',
				'after'  => $now_mysql,
			),
		),
		'meta_query'     => blueline_season_state_live_event_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- four rows of one post type.
	);
}

/**
 * The next_games module: real upcoming games (see
 * blueline_homepage_next_games_query_args()).
 */
function blueline_homepage_module_next_games() {
	$events = array();

	if ( post_type_exists( 'sp_event' ) ) {
		$query = new WP_Query( blueline_homepage_next_games_query_args() );

		$events = $query->posts;
	}

	blueline_homepage_module_start( 'next_games', __( 'Next games', 'blueline' ), blueline_resolve_link( 'page_schedule' ), __( 'Full schedule', 'blueline' ) );

	if ( empty( $events ) ) {
		// This empty state must not say the same "nothing scheduled" line
		// regardless of context: while the Standings module one scroll
		// down shows a fully populated, live table, that is a plain
		// contradiction with no explanation. No
		// upcoming sp_event post existing does not mean nothing is
		// happening: it means no NEXT-game post has been created yet, which
		// is a different, much less alarming fact when the season demonstrably
		// has recent results to show.
		$blueline_next_games_state = blueline_season_state();
		$blueline_has_standings    = ! empty( blueline_homepage_current_standings_tables( $blueline_next_games_state ) );

		blueline_homepage_module_empty_state(
			$blueline_has_standings
				? __( 'No new games posted yet — check Standings below for how the season’s going.', 'blueline' )
				: __( 'No games on the schedule yet — check back soon.', 'blueline' )
		);
	} else {
		?>
		<ul class="bl-next-games">
			<?php foreach ( $events as $event ) : ?>
				<?php
				// The label is the pad name plus the arena name via
				// blueline_venue_label() ("Mr. Lube and Tires Arena —
				// Red"), matching PRODUCT.md principle 4 ("the pad, not just
				// the arena"), and links to the same /venue/red page
				// /schedule uses, which carries the street address and the
				// sibling-pad cross-link, so a phone-in-a-car-park player
				// gets one tap to the address.
				$venue_terms = taxonomy_exists( 'sp_venue' )
					? wp_get_object_terms( $event->ID, 'sp_venue' )
					: array();
				$venue_term  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : null;
				$venue_label = '';
				$venue_url   = '';

				if ( $venue_term instanceof WP_Term ) {
					$venue_label = blueline_venue_label( $venue_term->term_id );

					$term_link = get_term_link( $venue_term );
					$venue_url = ( ! is_wp_error( $term_link ) ) ? $term_link : '';
				}
				?>
				<?php
				// The fixture itself links to its event page (box score, past
				// meetings, the arena map). The venue link below stays separate
				// and keeps going to the arena: two destinations a reader
				// genuinely wants from this row, so this is deliberately NOT a
				// single row-wide link: an <a> wrapping the whole <li> could
				// not contain the venue's own <a>, since nested anchors are
				// invalid and browsers drop the inner one.
				$event_permalink = get_permalink( $event );
				?>
				<li class="bl-next-games__item">
					<span class="bl-next-games__date">
						<?php echo esc_html( blueline_event_when( $event ) ); ?>
					</span>
					<?php if ( $event_permalink ) : ?>
						<a class="bl-next-games__title bl-next-games__title--link" href="<?php echo esc_url( $event_permalink ); ?>"><?php echo esc_html( get_the_title( $event ) ); ?></a>
					<?php else : ?>
						<span class="bl-next-games__title"><?php echo esc_html( get_the_title( $event ) ); ?></span>
					<?php endif; ?>
					<?php if ( $venue_label && $venue_url ) : ?>
						<a class="bl-next-games__venue" href="<?php echo esc_url( $venue_url ); ?>"><?php echo esc_html( $venue_label ); ?></a>
					<?php elseif ( $venue_label ) : ?>
						<span class="bl-next-games__venue"><?php echo esc_html( $venue_label ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	blueline_homepage_module_end();
}

/**
 * The latest_news module: the most recent published blog posts. Deliberately
 * publish-only: unlike sp_event, a 'future' (scheduled) post genuinely
 * should not appear on the news list until it publishes.
 */
function blueline_homepage_module_latest_news() {
	$posts = get_posts(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 3,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);

	blueline_homepage_module_start( 'latest_news', __( 'Latest news', 'blueline' ), blueline_resolve_link( 'page_news' ), __( 'All news', 'blueline' ) );

	if ( empty( $posts ) ) {
		blueline_homepage_module_empty_state( __( 'No news posted yet.', 'blueline' ) );
	} else {
		?>
		<ul class="bl-latest-news">
			<?php foreach ( $posts as $news_post ) : ?>
				<li class="bl-latest-news__item">
					<a class="bl-latest-news__title" href="<?php echo esc_url( get_permalink( $news_post ) ); ?>">
						<?php echo esc_html( get_the_title( $news_post ) ); ?>
					</a>
					<span class="bl-latest-news__date"><?php echo esc_html( get_the_date( '', $news_post ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	blueline_homepage_module_end();
}

/**
 * Render one named homepage module. Unknown names are a silent no-op, because
 * exactly four modules exist and this theme must not invent more.
 *
 * There is deliberately no 'sponsors' module: SportsPress Pro's own footer
 * sponsors block already renders sitewide, homepage included, directly
 * above <footer>, so a module rendering SportsPress's [sponsors] shortcode
 * a second time would be duplicate content; see
 * blueline_homepage_module_order()'s own comment for the full reasoning.
 *
 * @param string $name One of next_games|standings_snippet|new_here|latest_news.
 */
function blueline_render_module( string $name ) {
	$modules = array(
		'next_games'        => 'blueline_homepage_module_next_games',
		'standings_snippet' => 'blueline_homepage_module_standings_snippet',
		'new_here'          => 'blueline_homepage_module_new_here',
		'latest_news'       => 'blueline_homepage_module_latest_news',
	);

	if ( isset( $modules[ $name ] ) ) {
		call_user_func( $modules[ $name ] );
	}
}
