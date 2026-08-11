<?php
/**
 * Season-aware homepage: hero variants and module rendering.
 *
 * Consumes blueline_season_state()/blueline_season_state_data() (Task 6).
 * Produces the two interface functions template-homepage.php calls:
 * blueline_render_hero( string $state ) and blueline_render_module( string $name ).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The current registration_open product, re-verified live. Season State's
 * own transient can be up to 15 minutes stale, so this never trusts
 * $state_data['product_id'] alone -- a Register CTA must never point at a
 * product that has since sold out or been unpublished.
 *
 * @param array $state_data Result of blueline_season_state_data().
 * @return array{product: object, price_label: string}|null
 */
function blueline_homepage_registration_offer( array $state_data ) {
	if ( empty( $state_data['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$product = wc_get_product( (int) $state_data['product_id'] );

	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return null;
	}

	return array(
		'product'     => $product,
		'price_label' => blueline_homepage_format_price( $product ),
	);
}

/**
 * Plain-text formatted price (e.g. "$145.00"), read from the live product.
 * Never hard-code a price -- this is the only source of the number.
 *
 * @param object $product A WC_Product instance.
 * @return string Empty string if the product has no price set.
 */
function blueline_homepage_format_price( $product ): string {
	$price = $product->get_price();

	if ( '' === $price || null === $price || ! function_exists( 'wc_price' ) ) {
		return '';
	}

	return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Season label for the registration product's own category (e.g.
 * "Winter 2026-27") -- the season being SOLD, not necessarily the season
 * currently being played.
 *
 * @param int $product_id Product ID.
 * @return string Empty string if unavailable.
 */
function blueline_homepage_registration_season_label( int $product_id ): string {
	if ( ! $product_id || ! taxonomy_exists( 'product_cat' ) ) {
		return '';
	}

	$terms = wp_get_post_terms( $product_id, 'product_cat' );

	if ( is_wp_error( $terms ) ) {
		return '';
	}

	foreach ( $terms as $term ) {
		if ( defined( 'BLUELINE_REGISTRATION_TERM_ID' ) && BLUELINE_REGISTRATION_TERM_ID === (int) $term->parent ) {
			return $term->name;
		}
	}

	return '';
}

/**
 * Season label for the sp_event driving the schedule right now (e.g.
 * "S2026") -- the season being PLAYED, read from the sp_season taxonomy.
 *
 * @param int $event_id sp_event post ID.
 * @return string Empty string if unavailable.
 */
function blueline_homepage_event_season_label( int $event_id ): string {
	if ( ! $event_id || ! taxonomy_exists( 'sp_season' ) ) {
		return '';
	}

	$names = wp_get_object_terms( $event_id, 'sp_season', array( 'fields' => 'names' ) );

	if ( is_wp_error( $names ) || empty( $names ) ) {
		return '';
	}

	return (string) $names[0];
}

/**
 * Count of games (post_status publish OR future) starting within the next
 * 7 days. Upcoming games on this site are 'future', not 'publish' -- see
 * the docblock on BLUELINE_PUBLISHED_STATUS in inc/season-state.php; a
 * publish-only query here would silently under-count exactly the way
 * Task 6's first pass did.
 *
 * Drives both the in_season eyebrow's "Week {n}" and the headline's
 * "{n} games this week" -- the brief's table uses the same {n} token in
 * both cells, so both read off this one count.
 *
 * @return int
 */
function blueline_homepage_games_this_week(): int {
	if ( ! post_type_exists( 'sp_event' ) ) {
		return 0;
	}

	$now = current_time( 'mysql' );

	$query = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
				array(
					'column' => 'post_date',
					'after'  => $now,
					'before' => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + 7 * DAY_IN_SECONDS ),
				),
			),
		)
	);

	return count( $query->posts );
}

/**
 * Wrap a word/phrase in the hero headline's single --bl-ice highlight span.
 *
 * @param string $text Already-translated plain text.
 * @return string Safe HTML fragment.
 */
function blueline_hero_highlight( string $text ): string {
	return '<span class="bl-hero__highlight">' . esc_html( $text ) . '</span>';
}

/**
 * Build a headline with one embedded, pre-escaped highlight span. Mirrors
 * the wp_kses( __( ... ) ) + sprintf pattern content-none.php already uses
 * for translatable strings with embedded HTML, so the translators comment
 * stays directly above the real gettext call at each call site.
 *
 * @param string $translated_format A translated string containing exactly one %s.
 * @param string $highlight         Plain text for the highlighted word/phrase.
 * @return string Safe HTML.
 */
function blueline_hero_headline( string $translated_format, string $highlight ): string {
	return sprintf(
		wp_kses( $translated_format, array( 'span' => array( 'class' => array() ) ) ),
		blueline_hero_highlight( $highlight )
	);
}

/**
 * Hero content for the registration_open state.
 *
 * @param array $offer Result of blueline_homepage_registration_offer(), non-null.
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_registration_content( array $offer ): array {
	$season = blueline_homepage_registration_season_label( $offer['product']->get_id() );

	$eyebrow = $season
		/* translators: %s: current season label, e.g. "Winter 2026-27". */
		? sprintf( __( '%s · Registration open', 'blueline' ), $season )
		: __( 'Registration open', 'blueline' );

	$cta_label = $offer['price_label']
		/* translators: %s: formatted price, e.g. "$145.00". */
		? sprintf( __( 'Register — %s', 'blueline' ), $offer['price_label'] )
		: __( 'Register now', 'blueline' );

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => blueline_hero_headline(
			/* translators: %s: the highlighted word "beginner". */
			__( 'Burlington’s %s league.', 'blueline' ),
			__( 'beginner', 'blueline' )
		),
		'cta_label'     => $cta_label,
		'cta_url'       => home_url( '/register' ),
		'cta_variant'   => 'primary',
	);
}

/**
 * Hero content for the preseason state.
 *
 * @param array $state_data Result of blueline_season_state_data().
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_preseason_content( array $state_data ): array {
	$event_id = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;
	$season   = blueline_homepage_event_season_label( $event_id );
	$date     = $event_id ? get_the_date( 'F j', $event_id ) : '';

	$eyebrow = ( $season && $date )
		/* translators: 1: current season label, 2: the date the season starts. */
		? sprintf( __( '%1$s · Season starts %2$s', 'blueline' ), $season, $date )
		: __( 'Season starts soon', 'blueline' );

	$headline_html = $date
		? blueline_hero_headline(
			/* translators: %s: the highlighted start date. */
			__( 'Puck drops %s.', 'blueline' ),
			$date
		)
		: esc_html__( 'Puck drops soon.', 'blueline' );

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => $headline_html,
		'cta_label'     => __( 'View schedule', 'blueline' ),
		'cta_url'       => home_url( '/schedule' ),
		'cta_variant'   => 'secondary',
	);
}

/**
 * Hero content for the in_season state.
 *
 * @param array $state_data Result of blueline_season_state_data().
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_in_season_content( array $state_data ): array {
	$event_id = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;
	$season   = blueline_homepage_event_season_label( $event_id );
	$count    = blueline_homepage_games_this_week();

	$eyebrow = $season
		/* translators: 1: current season label, 2: number of games this week. */
		? sprintf( __( '%1$s · Week %2$d', 'blueline' ), $season, $count )
		/* translators: %d: number of games this week. */
		: sprintf( __( 'Week %d', 'blueline' ), $count );

	$count_label = sprintf(
		/* translators: %d: number of games this week. */
		_n( '%d game', '%d games', $count, 'blueline' ),
		$count
	);

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => blueline_hero_headline(
			/* translators: %s: the highlighted game count, e.g. "3 games". */
			__( '%s this week.', 'blueline' ),
			$count_label
		),
		'cta_label'     => __( 'My next game', 'blueline' ),
		'cta_url'       => home_url( '/schedule' ),
		'cta_variant'   => 'secondary',
	);
}

/**
 * Hero content for the playoffs state.
 *
 * @param array $state_data Result of blueline_season_state_data().
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_playoffs_content( array $state_data ): array {
	$event_id = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;
	$season   = blueline_homepage_event_season_label( $event_id );

	$eyebrow = $season
		/* translators: %s: current season label. */
		? sprintf( __( '%s · Playoffs', 'blueline' ), $season )
		: __( 'Playoffs', 'blueline' );

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => blueline_hero_highlight( __( 'Playoffs.', 'blueline' ) ),
		'cta_label'     => __( 'View bracket', 'blueline' ),
		'cta_url'       => home_url( '/standings' ),
		'cta_variant'   => 'secondary',
	);
}

/**
 * Hero content for the offseason state (also the safe fallback for any
 * value outside the five known states).
 *
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_offseason_content(): array {
	return array(
		'eyebrow'       => __( 'Off-season', 'blueline' ),
		'headline_html' => blueline_hero_headline(
			/* translators: %s: the highlighted word "soon". */
			__( 'Back on the ice %s.', 'blueline' ),
			__( 'soon', 'blueline' )
		),
		'cta_label'     => __( 'Join the mailing list', 'blueline' ),
		'cta_url'       => home_url( '/contact-us' ),
		'cta_variant'   => 'secondary',
	);
}

/**
 * Resolve the eyebrow/headline/CTA copy for one hero variant, per the
 * per-state table in the Task 7 brief.
 *
 * @param string $state      One of the five known season states.
 * @param array  $state_data Result of blueline_season_state_data().
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_content( string $state, array $state_data ): array {
	if ( 'registration_open' === $state ) {
		$offer = blueline_homepage_registration_offer( $state_data );

		if ( $offer ) {
			return blueline_homepage_hero_registration_content( $offer );
		}

		// The product driving registration_open turned out not to be
		// purchasable when re-checked live (a stale transient, at most 15
		// minutes old) -- never point a Register button at it. Fall back to
		// whatever the event signals say instead of inventing a sixth state.
		$state = ! empty( $state_data['next_event_id'] ) ? 'preseason' : 'offseason';
	}

	if ( 'preseason' === $state ) {
		return blueline_homepage_hero_preseason_content( $state_data );
	}

	if ( 'in_season' === $state ) {
		return blueline_homepage_hero_in_season_content( $state_data );
	}

	if ( 'playoffs' === $state ) {
		return blueline_homepage_hero_playoffs_content( $state_data );
	}

	return blueline_homepage_hero_offseason_content();
}

/**
 * Large, low-opacity concentric-ring art inside the hero's navy band
 * (Device #3, "faceoff geometry"). Purely decorative: aria-hidden, no text
 * alternative needed.
 */
function blueline_render_faceoff_rings() {
	?>
	<svg class="bl-hero__ring" viewBox="0 0 200 200" aria-hidden="true" focusable="false">
		<circle cx="100" cy="100" r="90" fill="none" stroke="currentColor" stroke-width="2"></circle>
		<circle cx="100" cy="100" r="60" fill="none" stroke="currentColor" stroke-width="2"></circle>
		<circle cx="100" cy="100" r="6" fill="currentColor"></circle>
	</svg>
	<?php
}

/**
 * Render the season-aware homepage hero: skewed eyebrow, headline with one
 * --bl-ice highlighted word, primary CTA, and the faceoff-ring/blue-line-band
 * chrome. Falls back to the offseason variant for any value outside the
 * five known states -- this theme never invents a sixth.
 *
 * @param string $state One of registration_open|preseason|in_season|playoffs|offseason.
 */
function blueline_render_hero( string $state ) {
	$known_states = array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' );

	if ( ! in_array( $state, $known_states, true ) ) {
		$state = 'offseason';
	}

	$state_data = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();
	$content    = blueline_homepage_hero_content( $state, $state_data );
	$cta_class  = 'primary' === $content['cta_variant'] ? 'bl-btn--primary' : 'bl-btn--secondary';
	?>
	<section class="bl-hero bl-hero--<?php echo esc_attr( $state ); ?>">
		<?php blueline_render_faceoff_rings(); ?>

		<div class="bl-container bl-hero__inner">
			<p class="bl-hero__eyebrow">
				<span class="bl-skew"><span><?php echo esc_html( $content['eyebrow'] ); ?></span></span>
			</p>

			<h1 class="bl-hero__headline">
				<?php echo wp_kses( $content['headline_html'], array( 'span' => array( 'class' => array() ) ) ); ?>
			</h1>

			<a class="bl-btn bl-hero__cta <?php echo esc_attr( $cta_class ); ?>" href="<?php echo esc_url( $content['cta_url'] ); ?>">
				<span class="bl-skew"><span><?php echo esc_html( $content['cta_label'] ); ?></span></span>
			</a>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</section>
	<?php
}

/**
 * Per-state homepage module order -- the binding table in the Task 7 brief.
 * Any state outside the five known values falls back to the offseason
 * order: the smallest, least "sell registration" set of modules.
 *
 * @param string $state Season state.
 * @return string[] Module names, in render order.
 */
function blueline_homepage_module_order( string $state ): array {
	$orders = array(
		'registration_open' => array( 'new_here', 'next_games', 'standings_snippet', 'latest_news', 'sponsors' ),
		'preseason'         => array( 'next_games', 'new_here', 'latest_news', 'sponsors' ),
		'in_season'         => array( 'next_games', 'standings_snippet', 'latest_news', 'sponsors' ),
		'playoffs'          => array( 'next_games', 'standings_snippet', 'latest_news', 'sponsors' ),
		'offseason'         => array( 'latest_news', 'new_here', 'sponsors' ),
	);

	return $orders[ $state ] ?? $orders['offseason'];
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
 * The next_games module: real upcoming games. post_status MUST include 'future'
 * -- WordPress core assigns that status (not 'publish') to any post dated
 * ahead of now, and every genuinely upcoming sp_event on this site carries
 * it. See the docblock on BLUELINE_PUBLISHED_STATUS in inc/season-state.php.
 */
function blueline_homepage_module_next_games() {
	$events = array();

	if ( post_type_exists( 'sp_event' ) ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 4,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded by post_type sp_event, not an unbounded query.
					array(
						'column' => 'post_date',
						'after'  => current_time( 'mysql' ),
					),
				),
			)
		);

		$events = $query->posts;
	}

	blueline_homepage_module_start( 'next_games', __( 'Next games', 'blueline' ), home_url( '/schedule' ), __( 'Full schedule', 'blueline' ) );

	if ( empty( $events ) ) {
		blueline_homepage_module_empty_state( __( 'No games on the schedule yet — check back soon.', 'blueline' ) );
	} else {
		?>
		<ul class="bl-next-games">
			<?php foreach ( $events as $event ) : ?>
				<?php
				$venue_names = taxonomy_exists( 'sp_venue' )
					? wp_get_object_terms( $event->ID, 'sp_venue', array( 'fields' => 'names' ) )
					: array();
				$venue       = ( ! is_wp_error( $venue_names ) && ! empty( $venue_names ) ) ? $venue_names[0] : '';
				?>
				<li class="bl-next-games__item">
					<span class="bl-next-games__date">
						<?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event ) ); ?>
					</span>
					<span class="bl-next-games__title"><?php echo esc_html( get_the_title( $event ) ); ?></span>
					<?php if ( $venue ) : ?>
						<span class="bl-next-games__venue"><?php echo esc_html( $venue ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	blueline_homepage_module_end();
}

/**
 * The sp_season term id attached to the sp_event currently driving the
 * schedule: the next upcoming game if there is one, otherwise the most
 * recently played game. Used to find the matching sp_table for the
 * standings snippet -- independent of which hero/registration season is
 * being sold, since the games being played can be a different season
 * (e.g. a running summer league while next winter's registration is open).
 *
 * @return int|null
 */
function blueline_homepage_active_event_season_term_id() {
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
 * The sp_table post id for the division/season snippet to show on the
 * homepage. Prefers the lowest-numbered division for the active season
 * (sp_table titles on this site follow "Division N | <Season>", e.g.
 * "Division 1 | S2026", or "Division N | Playoffs <Season>"), matching the
 * Playoffs variant only while the playoffs state is active.
 *
 * @param string $state Season state.
 * @return int|null
 */
function blueline_homepage_current_standings_table_id( string $state ) {
	if ( ! post_type_exists( 'sp_table' ) || ! taxonomy_exists( 'sp_season' ) ) {
		return null;
	}

	$season_term_id = blueline_homepage_active_event_season_term_id();

	if ( ! $season_term_id ) {
		return null;
	}

	$season_term = get_term( $season_term_id, 'sp_season' );

	if ( ! $season_term || is_wp_error( $season_term ) ) {
		return null;
	}

	$is_playoffs = ( 'playoffs' === $state );
	$needle      = $is_playoffs ? 'Playoffs ' . $season_term->name : $season_term->name;

	$candidates = get_posts(
		array(
			'post_type'      => 'sp_table',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			's'              => $needle,
		)
	);

	foreach ( $candidates as $candidate_id ) {
		$title = get_the_title( $candidate_id );

		if ( false === strpos( $title, $needle ) ) {
			continue; // The 's' search can match loosely; confirm the exact token is present.
		}

		if ( ! $is_playoffs && false !== stripos( $title, 'playoffs' ) ) {
			continue; // Exclude playoff tables outside the playoffs state.
		}

		return (int) $candidate_id;
	}

	return null;
}

/**
 * The standings_snippet module: the current season's Division 1 table (or its
 * Playoffs variant during the playoffs state), rendered through
 * SportsPress's own [league_table] shortcode -- not a custom entity
 * template, which stays out of Task 7's scope.
 */
function blueline_homepage_module_standings_snippet() {
	$state    = function_exists( 'blueline_season_state' ) ? blueline_season_state() : 'offseason';
	$table_id = blueline_homepage_current_standings_table_id( $state );

	blueline_homepage_module_start( 'standings_snippet', __( 'Standings', 'blueline' ), home_url( '/standings' ), __( 'Full standings', 'blueline' ) );

	$table_html = $table_id && shortcode_exists( 'league_table' )
		? do_shortcode( '[league_table id="' . absint( $table_id ) . '"]' )
		: '';

	if ( '' === trim( wp_strip_all_tags( $table_html ) ) ) {
		blueline_homepage_module_empty_state( __( 'Standings aren’t posted yet.', 'blueline' ) );
	} else {
		echo wp_kses_post( $table_html );
	}

	blueline_homepage_module_end();
}

/**
 * The new_here module: static reassurance copy for a first-time player deciding
 * whether to register. Never empty -- there is no data source to fail.
 */
function blueline_homepage_module_new_here() {
	blueline_homepage_module_start( 'new_here', __( 'Never played? Perfect.', 'blueline' ), home_url( '/faqs' ), __( 'Read the FAQs', 'blueline' ) );
	?>
	<ul class="bl-new-here__list">
		<li><?php esc_html_e( 'No hockey experience required — most players start here as complete beginners.', 'blueline' ); ?></li>
		<li><?php esc_html_e( 'Co-ed, adult, beginner-paced games every week.', 'blueline' ); ?></li>
		<li><?php esc_html_e( 'No gear yet? Rental options are available nearby — no need to buy everything up front.', 'blueline' ); ?></li>
	</ul>
	<?php
	blueline_homepage_module_end();
}

/**
 * The latest_news module: the most recent published blog posts. Deliberately
 * publish-only -- unlike sp_event, a 'future' (scheduled) post genuinely
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

	blueline_homepage_module_start( 'latest_news', __( 'Latest news', 'blueline' ), home_url( '/news' ), __( 'All news', 'blueline' ) );

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
 * The sponsors module: SportsPress's own [sponsors] shortcode. No link/see-more
 * -- sponsors are logos, not a list with a fuller page to browse.
 *
 * Emptiness is checked against the underlying sp_sponsor posts directly,
 * not by stripping tags from the shortcode's own HTML: every real sponsor
 * here renders as a bare `<img>` with an empty alt (decorative logo, no
 * fallback text), so wp_strip_all_tags() on genuinely non-empty markup
 * still returns '' -- that check would misreport real sponsors as absent.
 */
function blueline_homepage_module_sponsors() {
	blueline_homepage_module_start( 'sponsors', __( 'Our sponsors', 'blueline' ) );

	$has_sponsors = post_type_exists( 'sp_sponsor' ) && ! empty(
		get_posts(
			array(
				'post_type'      => 'sp_sponsor',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);

	if ( $has_sponsors && shortcode_exists( 'sponsors' ) ) {
		echo wp_kses_post( do_shortcode( '[sponsors]' ) );
	} else {
		blueline_homepage_module_empty_state( __( 'Sponsor spotlights are coming soon.', 'blueline' ) );
	}

	blueline_homepage_module_end();
}

/**
 * Render one named homepage module. Unknown names are a silent no-op -- the
 * brief names exactly five modules and this theme must not invent more.
 *
 * @param string $name One of next_games|standings_snippet|new_here|latest_news|sponsors.
 */
function blueline_render_module( string $name ) {
	$modules = array(
		'next_games'        => 'blueline_homepage_module_next_games',
		'standings_snippet' => 'blueline_homepage_module_standings_snippet',
		'new_here'          => 'blueline_homepage_module_new_here',
		'latest_news'       => 'blueline_homepage_module_latest_news',
		'sponsors'          => 'blueline_homepage_module_sponsors',
	);

	if ( isset( $modules[ $name ] ) ) {
		call_user_func( $modules[ $name ] );
	}
}
