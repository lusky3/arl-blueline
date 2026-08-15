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
 * Every purchasable, in-stock product in the current season's Registration
 * category, re-verified live -- Season State's own transient can be up to
 * 15 minutes stale, so this never trusts a cached product list alone; a
 * Register CTA must never quote a price for a product that has since sold
 * out or been unpublished. Sorted highest price first.
 *
 * P0 finding 1: the hero used to read a SINGLE product id
 * ($state_data['product_id'], "the first purchasable product Season State
 * happened to find") and quote its price on the primary CTA -- on this site
 * that resolved to the $145 Goalie Registration outranking the $550 Player
 * Registration by post ID, so the button read "Register — $145.00" while
 * the skater the button was aimed at actually owed $550 at checkout. This
 * intentionally does not pick a single "winner" product by name or ID
 * (the season's category can hold any number of registration products, and
 * pinned IDs/role names are a known trap in this codebase -- see
 * BLUELINE_REGISTRATION_TERM_ID's own resolve-by-newest-term pattern); it
 * returns every live offer and lets blueline_homepage_registration_cta_pricing()
 * decide, from the actual set, whether one price or a breakdown belongs on
 * the page.
 *
 * @param array $state_data Result of blueline_season_state_data().
 * @return array<int, array{product: object, price_label: string, price: float, role_label: string}>
 */
function blueline_homepage_registration_offers( array $state_data ): array {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return array();
	}

	$product_ids = function_exists( 'blueline_registration_season_product_ids' )
		? blueline_registration_season_product_ids()
		: array_filter( array( (int) ( $state_data['product_id'] ?? 0 ) ) );

	$offers = array();

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			continue;
		}

		$price = $product->get_price();

		$offers[] = array(
			'product'     => $product,
			'price_label' => blueline_homepage_format_price( $product ),
			'price'       => ( '' !== $price && null !== $price ) ? (float) $price : 0.0,
			'role_label'  => blueline_homepage_registration_offer_role_label( $product ),
		);
	}

	usort(
		$offers,
		static function ( $a, $b ) {
			return $b['price'] <=> $a['price'];
		}
	);

	return $offers;
}

/**
 * A short, human label for one registration product -- "Player", "Goalie",
 * whatever the site's own WooCommerce product_tag taxonomy says, so the
 * price-breakdown subcopy (see blueline_homepage_registration_cta_pricing())
 * never has to hardcode a role name. Falls back to the product's own title
 * with a trailing "(Season Label)" parenthetical stripped (e.g. "Player
 * Registration (W2026-27)" -> "Player Registration") when no tag is set.
 *
 * @param object $product A WC_Product instance.
 * @return string
 */
function blueline_homepage_registration_offer_role_label( $product ): string {
	if ( taxonomy_exists( 'product_tag' ) ) {
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			return (string) $tags[0];
		}
	}

	$stripped = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $product->get_name() ) );

	return '' !== $stripped ? $stripped : (string) $product->get_name();
}

/**
 * Pure: given the current season's live registration offers (see
 * blueline_homepage_registration_offers()), decide what the hero's CTA
 * button and subcopy should say about price. Kept free of WooCommerce/
 * WordPress calls -- it only ever sees plain arrays the caller has already
 * extracted from a WC_Product -- so the exact decision this P0 bug turned
 * on (one price on a button vs. several products at different prices) is
 * directly unit testable without a WC_Product fixture.
 *
 * - No offers at all: nothing to show; caller falls back to non-registration copy.
 * - Every offer the same price (including the common case of exactly one
 *   offer): that single price is unambiguous and safe to put on the CTA
 *   itself, exactly as before this fix.
 * - Offers at different prices: quoting any one of them on the button is
 *   the bait-and-switch this was fixed for, so the CTA carries no price at
 *   all and every distinct price gets its own line in the breakdown,
 *   labelled by blueline_homepage_registration_offer_role_label() and
 *   ordered highest first -- matching what /register itself shows.
 *
 * @param array $offers Result of blueline_homepage_registration_offers().
 * @return array{cta_price_label: string, price_breakdown: string}
 */
function blueline_homepage_registration_cta_pricing( array $offers ): array {
	if ( empty( $offers ) ) {
		return array(
			'cta_price_label' => '',
			'price_breakdown' => '',
		);
	}

	$distinct_labels = array_unique( array_column( $offers, 'price_label' ) );

	if ( count( $distinct_labels ) <= 1 ) {
		return array(
			'cta_price_label' => $offers[0]['price_label'],
			'price_breakdown' => '',
		);
	}

	$parts = array();

	foreach ( $offers as $offer ) {
		if ( '' === $offer['price_label'] ) {
			continue;
		}

		$parts[] = '' !== $offer['role_label']
			? trim( $offer['role_label'] . ' ' . $offer['price_label'] )
			: $offer['price_label'];
	}

	return array(
		'cta_price_label' => '',
		'price_breakdown' => implode( ' · ', $parts ),
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

	$registration_term = blueline_resolve_registration_term();

	if ( $registration_term <= 0 ) {
		// Neither the configured term nor the documented fallback resolves
		// to a real product_cat term -- there is nothing valid to compare
		// $term->parent against. Guarding here (rather than comparing
		// against 0) is what keeps an ordinary top-level product category
		// from being mistaken for the registration season's parent -- see
		// blueline_resolve_registration_term()'s docblock.
		return '';
	}

	foreach ( $terms as $term ) {
		if ( $registration_term === (int) $term->parent ) {
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
 * Build a headline with one embedded, pre-escaped highlight span. The
 * highlight span itself is already safe (blueline_hero_highlight() escapes
 * its text); this only substitutes it into the surrounding translated copy.
 *
 * Escaping happens exactly once, at the single point every hero headline
 * (however it was assembled) is actually echoed -- the wp_kses() call in
 * blueline_render_hero(). Deliberately not re-applied here: doing it in
 * both places was flagged as redundant double sanitization in review.
 *
 * @param string $translated_format A translated string containing exactly one %s.
 * @param string $highlight         Plain text for the highlighted word/phrase.
 * @return string HTML fragment; the caller (blueline_render_hero()) still
 *                passes the final headline_html through wp_kses() before echoing.
 */
function blueline_hero_headline( string $translated_format, string $highlight ): string {
	return sprintf( $translated_format, blueline_hero_highlight( $highlight ) );
}

/**
 * A one-line "next game" summary for the hero's registration_open subcopy --
 * P1 finding 4: registration can be open for months while the current
 * season is still being played, and an existing player hitting the
 * registration-heavy hero should still see their next game rather than
 * have the sell fully mask it.
 *
 * @param int $event_id sp_event post ID.
 * @return string Empty string if the event/venue cannot be resolved.
 */
function blueline_homepage_next_event_line( int $event_id ): string {
	if ( ! $event_id ) {
		return '';
	}

	$date = get_the_date( 'D, M j \a\t g:ia', $event_id );

	$venue_terms = taxonomy_exists( 'sp_venue' ) ? wp_get_object_terms( $event_id, 'sp_venue' ) : array();
	$venue_term  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : null;
	$venue_label = '';

	if ( $venue_term instanceof WP_Term ) {
		$venue_label = function_exists( 'blueline_venue_label' )
			? blueline_venue_label( $venue_term->term_id )
			: $venue_term->name;
	}

	return $venue_label
		/* translators: 1: next game's date/time, 2: venue label. */
		? sprintf( __( 'Next game: %1$s — %2$s', 'blueline' ), $date, $venue_label )
		/* translators: %s: next game's date/time. */
		: sprintf( __( 'Next game: %s', 'blueline' ), $date );
}

/**
 * Hero content for the registration_open state.
 *
 * @param array $offers     Result of blueline_homepage_registration_offers(), non-empty.
 * @param array $state_data Result of blueline_season_state_data().
 * @return array{eyebrow: string, headline_html: string, subcopy_lines: string[], cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_registration_content( array $offers, array $state_data = array() ): array {
	$season = blueline_homepage_registration_season_label( $offers[0]['product']->get_id() );

	$eyebrow = $season
		? sprintf( blueline_settings( 'hero_registration_eyebrow' ), $season )
		: __( 'Registration open', 'blueline' );

	$pricing = blueline_homepage_registration_cta_pricing( $offers );

	$cta_label = $pricing['cta_price_label']
		? sprintf( blueline_settings( 'hero_registration_cta' ), $pricing['cta_price_label'] )
		: __( 'Register now', 'blueline' );

	$subcopy_lines = array();

	if ( $pricing['price_breakdown'] ) {
		$subcopy_lines[] = $pricing['price_breakdown'];
	}

	// Sell-and-play overlap (P1 finding 4): registration can be open while
	// this season's games are still happening. Rather than let the sell
	// fully mask "when's my next game" for a third of the year, carry both
	// at once -- the price breakdown (or lack of one) above, and this line,
	// can appear together.
	if ( ! empty( $state_data['is_playing'] ) && ! empty( $state_data['next_event_id'] ) ) {
		$next_line = blueline_homepage_next_event_line( (int) $state_data['next_event_id'] );

		if ( $next_line ) {
			$subcopy_lines[] = $next_line;
		}
	}

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => blueline_hero_headline(
			blueline_settings( 'hero_registration_headline' ),
			__( 'beginner', 'blueline' )
		),
		'subcopy_lines' => $subcopy_lines,
		'cta_label'     => $cta_label,
		'cta_url'       => blueline_resolve_link( 'page_register' ),
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
			blueline_settings( 'hero_preseason_headline' ),
			$date
		)
		: esc_html__( 'Puck drops soon.', 'blueline' );

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => $headline_html,
		'cta_label'     => __( 'View schedule', 'blueline' ),
		'cta_url'       => blueline_resolve_link( 'page_schedule' ),
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

	// The brief's table highlights only {n} ("**{n}** games this week."),
	// not the whole "N games" phrase -- so the pluralized noun is a plain,
	// separately-escaped substitution alongside the highlighted number,
	// not passed through blueline_hero_headline()'s single-highlight helper.
	$headline_html = sprintf(
		blueline_settings( 'hero_in_season_headline' ),
		blueline_hero_highlight( (string) $count ),
		esc_html( _n( 'game', 'games', $count, 'blueline' ) )
	);

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => $headline_html,
		'cta_label'     => __( 'My next game', 'blueline' ),
		'cta_url'       => blueline_resolve_link( 'page_schedule' ),
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
		? sprintf( blueline_settings( 'hero_playoffs_eyebrow' ), $season )
		: __( 'Playoffs', 'blueline' );

	return array(
		'eyebrow'       => $eyebrow,
		'headline_html' => blueline_hero_highlight( __( 'Playoffs.', 'blueline' ) ),
		'cta_label'     => __( 'View bracket', 'blueline' ),
		'cta_url'       => blueline_resolve_link( 'page_standings' ),
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
			blueline_settings( 'hero_offseason_headline' ),
			__( 'soon', 'blueline' )
		),
		'cta_label'     => blueline_settings( 'hero_offseason_cta' ),
		'cta_url'       => blueline_contact_url(),
		'cta_variant'   => 'secondary',
	);
}

/**
 * Resolve the eyebrow/headline/CTA copy for one hero variant, per the
 * per-state table in the Task 7 brief.
 *
 * The returned 'state' key is the EFFECTIVE state actually rendered, which
 * can differ from the requested $state: when $state is registration_open
 * but every product fails live re-verification (see
 * blueline_homepage_registration_offers()), this falls back to preseason or
 * offseason copy -- and now reports that fallback back to the caller, so
 * blueline_render_hero() can put a matching bl-hero--{state} class on the
 * markup instead of a class that names one state while showing another's
 * copy. That mismatch was a shipped bug; do not drop this key while editing.
 *
 * @param string $state      One of the five known season states.
 * @param array  $state_data Result of blueline_season_state_data().
 * @return array{state: string, eyebrow: string, headline_html: string, subcopy_lines: string[], cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_content( string $state, array $state_data ): array {
	if ( 'registration_open' === $state ) {
		$offers = blueline_homepage_registration_offers( $state_data );

		if ( ! empty( $offers ) ) {
			$content          = blueline_homepage_hero_registration_content( $offers, $state_data );
			$content['state'] = 'registration_open';
			return $content;
		}

		// Every product driving registration_open turned out not to be
		// purchasable when re-checked live (a stale transient, at most 15
		// minutes old) -- never point a Register button at one. Fall back to
		// whatever the event signals say instead of inventing a sixth state.
		$state = ! empty( $state_data['next_event_id'] ) ? 'preseason' : 'offseason';
	}

	if ( 'preseason' === $state ) {
		$content          = blueline_homepage_hero_preseason_content( $state_data );
		$content['state'] = 'preseason';
		return $content;
	}

	if ( 'in_season' === $state ) {
		$content          = blueline_homepage_hero_in_season_content( $state_data );
		$content['state'] = 'in_season';
		return $content;
	}

	if ( 'playoffs' === $state ) {
		$content          = blueline_homepage_hero_playoffs_content( $state_data );
		$content['state'] = 'playoffs';
		return $content;
	}

	$content          = blueline_homepage_hero_offseason_content();
	$content['state'] = 'offseason';
	return $content;
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
 * The league photographs available as band texture.
 *
 * Six of Michael Durrant's league photographs, chosen for how they read at
 * ~16% opacity rather than how they read as photographs: open ice, legible
 * silhouettes, the subject off-centre so a headline is not sitting on top of
 * it. The portraits and tight group shots in the same set are deliberately
 * absent -- a face reads as a person even at that opacity, which is both
 * worse design here and a larger ask of the player in it.
 *
 * Re-encoded to 720px WebP (assets/images/bands, ~156KB for all six) because
 * nothing above that survives the treatment. Credit is rendered in the footer:
 * the photographer's watermark is illegible once desaturated to this level, so
 * the attribution has to live somewhere the treatment cannot destroy.
 *
 * @return string[] Slugs, each matching assets/images/bands/{slug}.webp.
 */
function blueline_band_shots(): array {
	return array( 'save', 'shot', 'skater', 'race', 'faceoff', 'breakaway' );
}


/**
 * Render the season-aware homepage hero: skewed eyebrow, headline with one
 * --bl-ice highlighted word, primary CTA, and the faceoff-ring/blue-line-band
 * chrome. Falls back to the offseason variant for any value outside the
 * five known states -- this theme never invents a sixth.
 *
 * The bl-hero--{state} class is always taken from the EFFECTIVE state
 * blueline_homepage_hero_content() actually rendered, not the requested
 * $state -- those can differ (a registration_open request whose product
 * fails live re-verification renders preseason/offseason copy instead),
 * and the class must agree with what is actually on the page. The
 * effective state is returned so the caller (template-homepage.php) can
 * keep the module order in sync with whatever the hero actually showed.
 *
 * @param string $state One of registration_open|preseason|in_season|playoffs|offseason.
 * @return string The effective state actually rendered.
 */
function blueline_render_hero( string $state ): string {
	$known_states = array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' );

	if ( ! in_array( $state, $known_states, true ) ) {
		$state = 'offseason';
	}

	$state_data      = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();
	$content         = blueline_homepage_hero_content( $state, $state_data );
	$effective_state = $content['state'];
	$cta_class       = 'primary' === $content['cta_variant'] ? 'bl-btn--primary' : 'bl-btn--secondary';
	?>
	<?php
	/*
	 * The photograph is set on :root by blueline_render_band_photo_head()
	 * immediately below, NOT inline on this section: an inline style would win
	 * over :root and defeat the rotation, which has to happen in the browser
	 * because the page itself is cached (see that function for the full
	 * reasoning). The section only opts in; it never names a photograph.
	 *
	 * .bl-band-photo suppresses the faceoff rings (homepage.css) at the widths
	 * where the photograph actually paints; the rings stay the treatment on
	 * SportsPress entity heroes and remain the fallback on phones, so the two
	 * devices alternate by page type rather than stacking.
	 */
	$bl_has_photo = (bool) blueline_band_photo_sources();

	blueline_render_band_photo_head();
	?>
	<section class="bl-hero bl-hero--<?php echo esc_attr( $effective_state ); ?><?php echo $bl_has_photo ? ' bl-band-photo' : ''; ?>">
		<?php blueline_render_faceoff_rings(); ?>

		<div class="bl-container bl-hero__inner">
			<p class="bl-hero__eyebrow">
				<span class="bl-skew"><span><?php echo esc_html( $content['eyebrow'] ); ?></span></span>
			</p>

			<h1 class="bl-hero__headline">
				<?php echo wp_kses( $content['headline_html'], array( 'span' => array( 'class' => array() ) ) ); ?>
			</h1>

			<?php foreach ( ( $content['subcopy_lines'] ?? array() ) as $bl_hero_subcopy_line ) : ?>
				<p class="bl-hero__subcopy"><?php echo esc_html( $bl_hero_subcopy_line ); ?></p>
			<?php endforeach; ?>

			<a class="bl-btn bl-hero__cta <?php echo esc_attr( $cta_class ); ?>" href="<?php echo esc_url( $content['cta_url'] ); ?>">
				<span class="bl-skew"><span><?php echo esc_html( $content['cta_label'] ); ?></span></span>
			</a>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</section>
	<?php
	return $effective_state;
}

/**
 * Per-state homepage module order -- the binding table in the Task 7 brief.
 * Any state outside the five known values falls back to the offseason
 * order: the smallest, least "sell registration" set of modules.
 *
 * 'sponsors' was removed from every order below (a later fix): SportsPress
 * Pro's own footer sponsors block (SportsPress_Sponsors::footer(), hooked
 * to get_footer sitewide) already renders directly above <footer> on every
 * page, homepage included, carrying the league's own configured title --
 * this module was rendering a second, redundant sponsors section only on
 * the homepage. Removing it here (rather than removing SportsPress's own
 * sitewide block) keeps sponsors showing on every other page; see
 * assets/src/css/sportspress.css for the styling now applied to
 * SportsPress's block instead.
 *
 * P1 finding 4: registration_open's own order below still leads with
 * 'new_here' unconditionally, because the enum cannot tell "nobody has
 * played yet" apart from "the season is already running and we're also
 * selling next season" -- but those are very different visitors. When
 * $state_data reports blueline_is_playing() true (the second case, and on
 * this site a months-long overlap, not an edge case), an existing player
 * checking the registration-heavy hero should meet their own next game and
 * the standings first, not three bullets of first-timer reassurance ahead
 * of it.
 *
 * @param string $state      Season state.
 * @param array  $state_data Result of blueline_season_state_data(); optional
 *                            so existing callers/tests passing only $state
 *                            keep working unchanged (empty array reads as
 *                            "not playing", i.e. today's behaviour).
 * @return string[] Module names, in render order.
 */
function blueline_homepage_module_order( string $state, array $state_data = array() ): array {
	if ( 'registration_open' === $state && ! empty( $state_data['is_playing'] ) ) {
		return array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' );
	}

	$orders = array(
		'registration_open' => array( 'new_here', 'next_games', 'standings_snippet', 'latest_news' ),
		'preseason'         => array( 'next_games', 'new_here', 'latest_news' ),
		'in_season'         => array( 'next_games', 'standings_snippet', 'latest_news' ),
		'playoffs'          => array( 'next_games', 'standings_snippet', 'latest_news' ),
		'offseason'         => array( 'latest_news', 'new_here' ),
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

	blueline_homepage_module_start( 'next_games', __( 'Next games', 'blueline' ), blueline_resolve_link( 'page_schedule' ), __( 'Full schedule', 'blueline' ) );

	if ( empty( $events ) ) {
		blueline_homepage_module_empty_state( __( 'No games on the schedule yet — check back soon.', 'blueline' ) );
	} else {
		?>
		<ul class="bl-next-games">
			<?php foreach ( $events as $event ) : ?>
				<?php
				// P1 finding 3: this used to print only the pad name ("Red")
				// as plain text -- the same value /schedule links to
				// /venue/red, which carries the street address and the
				// sibling-pad cross-link. blueline_venue_label() (package 1)
				// gives the arena name too ("Mr. Lube and Tires Arena —
				// Red"), matching PRODUCT.md principle 4 ("the pad, not just
				// the arena"), and linking it gives a phone-in-a-car-park
				// player one tap to the address.
				$venue_terms = taxonomy_exists( 'sp_venue' )
					? wp_get_object_terms( $event->ID, 'sp_venue' )
					: array();
				$venue_term  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : null;
				$venue_label = '';
				$venue_url   = '';

				if ( $venue_term instanceof WP_Term ) {
					$venue_label = function_exists( 'blueline_venue_label' )
						? blueline_venue_label( $venue_term->term_id )
						: $venue_term->name;

					$term_link = get_term_link( $venue_term );
					$venue_url = ( ! is_wp_error( $term_link ) ) ? $term_link : '';
				}
				?>
				<?php
				// The fixture itself links to its event page (box score, past
				// meetings, the arena map). The venue link below stays separate
				// and keeps going to the arena -- two destinations a reader
				// genuinely wants from this row, so this is deliberately NOT a
				// single row-wide link: an <a> wrapping the whole <li> could
				// not contain the venue's own <a>, since nested anchors are
				// invalid and browsers drop the inner one.
				$event_permalink = get_permalink( $event );
				?>
				<li class="bl-next-games__item">
					<span class="bl-next-games__date">
						<?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event ) ); ?>
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

	blueline_homepage_module_start( 'standings_snippet', __( 'Standings', 'blueline' ), blueline_resolve_link( 'page_standings' ), __( 'Full standings', 'blueline' ) );

	$table_html = $table_id && shortcode_exists( 'league_table' )
		? do_shortcode( '[league_table id="' . absint( $table_id ) . '"]' )
		: '';

	if ( '' === trim( wp_strip_all_tags( $table_html ) ) ) {
		blueline_homepage_module_empty_state( __( 'Standings aren’t posted yet.', 'blueline' ) );
	} else {
		// P1 finding 2: inc/sportspress.php's blueline_sp_wrap_tables_for_scroll()
		// only ever hooks 'the_content' -- do_shortcode() above bypasses that
		// filter entirely, so without this call the table reached the page with
		// .sp-table-wrapper computing overflow-x: visible, and at 360px a 786px
		// table was silently cut off by the html{overflow-x:clip} backstop --
		// only Pos/Team/GP stayed reachable; W/L/Tie/PTS/GF/GA/Diff/L10/Strk
		// were not. Running the shortcode's own output through the SAME wrap
		// function /standings gets via the_content (owned by package 1;
		// called here, not duplicated) keeps this one table scrollable exactly
		// like every other SportsPress table on the site.
		if ( function_exists( 'blueline_sp_wrap_tables_for_scroll' ) ) {
			$table_html = blueline_sp_wrap_tables_for_scroll( $table_html );
		}

		// Deliberately NOT wp_kses_post(). That filter allows no `data-*`
		// attribute of any kind, so running SportsPress's own table through it
		// stripped `data-sp-rows` (the hook SP's own pagination script reads)
		// and every `data-label` (the labels its responsive CSS shows in place
		// of column headers at narrow widths) -- quietly degrading the module
		// while the very same [league_table], rendered through the_content on
		// /standings, kept them (inc/sportspress.php applies no kses there).
		// One plugin's first-party, already-escaped output must not be subject
		// to two different sanitisation policies depending on which template
		// renders it; this is the /standings policy, applied here too.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SportsPress's own shortcode output, escaped by SP itself, and treated exactly as the_content treats it on /standings; see comment above.
		echo $table_html;
	}

	blueline_homepage_module_end();
}

add_action( 'widgets_init', 'blueline_homepage_new_here_widgets_init' );
/**
 * Register a widget area for the new_here module's body copy.
 *
 * P1 finding 5: "Never played? Perfect." is PRODUCT.md's tonal north star --
 * the site's whole reason to exist is convincing the nervous first-timer
 * persona to register -- and this content was three short bullets, hardcoded
 * as English strings in PHP, with no way for a league volunteer (who runs
 * this site day to day, and is not a developer) to update it without a code
 * deploy. Appearance > Widgets is something a volunteer can already use
 * confidently for the footer sidebars this theme registers elsewhere; giving
 * this module the same mechanism means the site's single most important
 * sales copy can be rewritten, re-ordered, or have an image added without
 * touching code. Leaving the widget area empty (the default, out of the
 * box) falls back to blueline_homepage_new_here_default_content() below, so
 * the module is never blank.
 */
function blueline_homepage_new_here_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Homepage — Never played? Perfect.', 'blueline' ),
			'id'            => 'bl-homepage-new-here',
			'description'   => __( 'Body content for the homepage "Never played? Perfect." module -- the reassurance section aimed at first-time players. Leave this widget area empty to use the theme\'s own default copy.', 'blueline' ),
			'before_widget' => '<div class="bl-new-here__widget">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="bl-new-here__widget-title">',
			'after_title'   => '</h3>',
		)
	);
}

/**
 * The new_here module's own default copy, used only when a league volunteer
 * has not configured the 'bl-homepage-new-here' widget area (see
 * blueline_homepage_new_here_widgets_init()). Answers the three questions
 * PRODUCT.md names as what the nervous-beginner persona actually asks --
 * "will I be the worst one there", "what gear do I need", "what if I can't
 * skate" -- in the league's own voice, rather than three interchangeable
 * reassurance bullets with no image and less weight than the standings
 * snippet below it.
 */
function blueline_homepage_new_here_default_content() {
	?>
	<p class="bl-new-here__intro">
		<?php esc_html_e( 'Every player on every team here started exactly where you are: never having played an organized game of hockey. That\'s not the exception in this league. It\'s most of the room.', 'blueline' ); ?>
	</p>

	<dl class="bl-new-here__qa">
		<div class="bl-new-here__qa-item">
			<dt><?php esc_html_e( 'Will I be the worst one out there?', 'blueline' ); ?></dt>
			<dd><?php esc_html_e( 'Almost certainly not, and it wouldn\'t matter if you were. This is a co-ed beginner league by design — no tryouts, no cuts, and teams built to be even, not stacked.', 'blueline' ); ?></dd>
		</div>
		<div class="bl-new-here__qa-item">
			<dt><?php esc_html_e( 'What gear do I actually need?', 'blueline' ); ?></dt>
			<dd>
				<?php
				printf(
					wp_kses(
						/* translators: 1: opening <a> tag to the equipment guide, 2: closing </a> tag. */
						__( 'Less than you\'d think, and you can rent most of it nearby before buying a single thing. %1$sSee the gear guide%2$s.', 'blueline' ),
						array( 'a' => array( 'href' => array() ) )
					),
					'<a href="' . esc_url( blueline_resolve_link( 'page_equipment' ) ) . '">',
					'</a>'
				);
				?>
			</dd>
		</div>
		<div class="bl-new-here__qa-item">
			<dt><?php esc_html_e( 'What if I can\'t really skate yet?', 'blueline' ); ?></dt>
			<dd><?php esc_html_e( 'Then you\'ll fit right in with half the room. Games are paced for people still finding their edges, not for anyone trying out for the NHL.', 'blueline' ); ?></dd>
		</div>
	</dl>
	<?php
}

/**
 * The new_here module: reassurance copy for a first-time player deciding
 * whether to register. Never empty -- if the 'bl-homepage-new-here' widget
 * area has no widgets, blueline_homepage_new_here_default_content() renders
 * the theme's own default copy instead.
 */
function blueline_homepage_module_new_here() {
	blueline_homepage_module_start( 'new_here', __( 'Never played? Perfect.', 'blueline' ), blueline_resolve_link( 'page_faqs' ), __( 'Read the FAQs', 'blueline' ) );

	if ( function_exists( 'blueline_leaf_mark' ) ) {
		blueline_leaf_mark( 'bl-new-here__watermark' );
	}

	if ( is_active_sidebar( 'bl-homepage-new-here' ) ) {
		dynamic_sidebar( 'bl-homepage-new-here' );
	} else {
		blueline_homepage_new_here_default_content();
	}

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
 * Render one named homepage module. Unknown names are a silent no-op -- the
 * brief names exactly four modules and this theme must not invent more.
 *
 * A fifth module, 'sponsors', existed here through Task 7 (rendering
 * SportsPress's own [sponsors] shortcode a second time). It was removed as
 * a duplicate-content fix: SportsPress Pro's own footer sponsors block
 * already renders sitewide, homepage included, directly above <footer> --
 * see blueline_homepage_module_order()'s own comment for the full
 * reasoning.
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

/**
 * Every photograph available to the hero band, as { url, position } pairs.
 *
 * The control panel's list wins when it has anything in it; an empty list
 * means the photographs that ship with the theme, which is what makes the
 * setting an override rather than a switch (see blueline_settings_schema()).
 *
 * Admin-chosen photographs are rendered at the `blueline-band` size, never at
 * their uploaded original -- see inc/setup.php for why that matters. A chosen
 * attachment that has since been deleted simply drops out here rather than
 * emitting a url() pointing at nothing.
 *
 * @return array<int,array{url:string,position:string}> Possibly empty.
 */
function blueline_band_photo_sources(): array {
	$alignments = function_exists( 'blueline_band_photo_alignments' )
		? blueline_band_photo_alignments()
		: array();

	$configured = function_exists( 'blueline_settings' ) ? blueline_settings( 'hero_photos' ) : array();
	$sources    = array();

	if ( is_array( $configured ) && $configured ) {
		foreach ( $configured as $row ) {
			$id = absint( $row['id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $id, 'blueline-band' );

			if ( ! $url ) {
				continue;
			}

			$align = (string) ( $row['align'] ?? 'center-center' );

			$sources[] = array(
				'url'      => (string) $url,
				'position' => $alignments[ $align ] ?? 'center 40%',
			);
		}
	}

	if ( $sources ) {
		return $sources;
	}

	foreach ( blueline_band_shots() as $slug ) {
		$sources[] = array(
			'url'      => BLUELINE_URI . '/assets/images/bands/' . $slug . '.webp',
			'position' => 'center 40%',
		);
	}

	return $sources;
}

/**
 * Print the hero band's photograph, and the rotation that picks it.
 *
 * WHY THIS IS INLINE AND WHY IT IS HERE. The site sits behind an nginx srcache
 * page cache in production (staging has none, see DESIGN.md), so a photograph
 * chosen in PHP is chosen once per cache fill, not once per visitor -- server-
 * side rotation would look perfect on staging and quietly never rotate in
 * production, which is precisely the class of divergence DESIGN.md already
 * warns about for the cache purge. Choosing in the browser is the only way
 * "different on each page load" can be true of a cached page.
 *
 * Printed immediately before the band itself rather than from wp_head: the
 * <style> establishes the resting value and the <script> overwrites it, both
 * parsed before the section that reads them, so there is no flash of the first
 * photograph being replaced. It also means no page that lacks a hero pays for
 * any of it.
 *
 * With JavaScript unavailable the <style> alone is a complete answer: a real
 * photograph, correctly aligned, chosen deterministically.
 *
 * @return void
 */
function blueline_render_band_photo_head(): void {
	$sources = blueline_band_photo_sources();

	if ( ! $sources ) {
		return;
	}

	$rotate = function_exists( 'blueline_settings' ) ? (bool) blueline_settings( 'hero_photo_rotate' ) : true;

	// The resting choice is deterministic rather than the first in the list, so
	// a no-JS visitor and a cache fill do not both always land on the same one.
	$resting = $sources[ crc32( 'hero' ) % count( $sources ) ];

	printf(
		'<style id="bl-band-photo">:root{--bl-band-photo:url("%1$s");--bl-band-photo-position:%2$s}</style>',
		esc_url( $resting['url'] ),
		esc_html( $resting['position'] )
	);

	if ( ! $rotate || count( $sources ) < 2 ) {
		return;
	}

	/*
	 * wp_json_encode() and not manual quoting: these strings become JavaScript
	 * source, where esc_url()/esc_attr() are the wrong escaping entirely -- a
	 * quote or backslash in a filename would end the string literal and
	 * everything after it becomes code.
	 */
	$payload = wp_json_encode( array_values( $sources ) );

	if ( ! $payload ) {
		return;
	}

	printf(
		'<script id="bl-band-photo-rotate">(function(){try{var s=%1$s,p=s[Math.floor(Math.random()*s.length)],r=document.documentElement.style;r.setProperty("--bl-band-photo","url(\'"+p.url+"\')");r.setProperty("--bl-band-photo-position",p.position);}catch(e){}})();</script>',
		$payload // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output IS the escaping for a JS string literal context; esc_* would corrupt it.
	);
}
