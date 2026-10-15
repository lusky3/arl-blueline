<?php
/**
 * Homepage: the season-aware hero. Headline highlight, the per-state hero
 * copy, the faceoff-ring art and blueline_render_hero().
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

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
 * (however it was assembled) is actually echoed: the wp_kses() call in
 * blueline_render_hero(). Deliberately not re-applied here, because doing it in
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
 * A one-line "next game" summary for the hero's registration_open subcopy.
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

	$date = blueline_event_when( $event_id );

	$venue_label = blueline_sp_event_venue_label( $event_id );

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
	// at once: the price breakdown (or lack of one) above, and this line,
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
			blueline_settings( 'hero_registration_highlight' )
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
 * Hero content while only $0 waitlist products are on offer: say so, with
 * no price anywhere, and keep the next-game line while games are being
 * played -- the same sell-and-play overlap the registration hero carries.
 *
 * @param array  $state_data Result of blueline_season_state_data().
 * @param string $season     The waitlisted season's label, or ''.
 * @return array{eyebrow: string, headline_html: string, subcopy_lines: string[], cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_waitlist_content( array $state_data, string $season ): array {
	$subcopy_lines = array();

	if ( ! empty( $state_data['is_playing'] ) && ! empty( $state_data['next_event_id'] ) ) {
		$next_line = blueline_homepage_next_event_line( (int) $state_data['next_event_id'] );

		if ( $next_line ) {
			$subcopy_lines[] = $next_line;
		}
	}

	return array(
		'eyebrow'       => $season
			/* translators: %s: the waitlisted season's label, e.g. "Winter 2026-27". */
			? sprintf( __( '%s · Waitlist open', 'blueline' ), $season )
			: __( 'Waitlist open', 'blueline' ),
		'headline_html' => blueline_hero_headline(
			blueline_settings( 'hero_registration_headline' ),
			blueline_settings( 'hero_registration_highlight' )
		),
		'subcopy_lines' => $subcopy_lines,
		'cta_label'     => __( 'Join the waitlist', 'blueline' ),
		'cta_url'       => blueline_resolve_link( 'page_register' ),
		'cta_variant'   => 'primary',
	);
}

/**
 * Hero content for the in_season state.
 *
 * The eyebrow carries the real week of the season (counted from the
 * season's first game), never the game count, and drops "Week" when the
 * week cannot be worked out. In a week with no games the headline names the
 * next game's date rather than "0 games this week".
 *
 * @param array    $state_data Result of blueline_season_state_data().
 * @param int|null $now        Unix timestamp to evaluate against; null means now.
 * @return array{eyebrow: string, headline_html: string, cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_in_season_content( array $state_data, ?int $now = null ): array {
	$event_id = ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;
	$season   = blueline_homepage_event_season_label( $event_id );
	$count    = blueline_homepage_games_this_week( $now );
	$week     = blueline_homepage_season_week( $event_id, $now );

	if ( $season && $week ) {
		/* translators: 1: current season label, 2: week of the season. */
		$eyebrow = sprintf( __( '%1$s · Week %2$d', 'blueline' ), $season, $week );
	} elseif ( $season ) {
		$eyebrow = $season;
	} elseif ( $week ) {
		/* translators: %d: week of the season. */
		$eyebrow = sprintf( __( 'Week %d', 'blueline' ), $week );
	} else {
		$eyebrow = __( 'In season', 'blueline' );
	}

	if ( 0 === $count && $event_id ) {
		$headline_html = blueline_hero_headline(
			/* translators: %s: the next game's date, e.g. "Fri, Oct 23". */
			__( 'Next game: %s', 'blueline' ),
			blueline_event_date( $event_id )
		);
	} else {
		// The brief's table highlights only {n} ("**{n}** games this week."),
		// not the whole "N games" phrase, so the pluralized noun is a plain,
		// separately-escaped substitution alongside the highlighted number,
		// not passed through blueline_hero_headline()'s single-highlight helper.
		$headline_html = sprintf(
			blueline_settings( 'hero_in_season_headline' ),
			blueline_hero_highlight( (string) $count ),
			esc_html( _n( 'game', 'games', $count, 'blueline' ) )
		);
	}

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
			blueline_settings( 'hero_offseason_highlight' )
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
 * offseason copy, and now reports that fallback back to the caller, so
 * blueline_render_hero() can put a matching bl-hero--{state} class on the
 * markup instead of a class that names one state while showing another's
 * copy. That mismatch was a shipped bug; do not drop this key while editing.
 *
 * When no paid registration is on offer but a $0 waitlist is, and no games
 * are being played, the copy is the waitlist hero
 * (blueline_homepage_hero_waitlist_content()) and the result carries
 * `variant => 'waitlist'`: the class names that variant, while 'state'
 * remains the season state the module order should follow. While games are
 * being played the season's own hero wins.
 *
 * @param string $state      One of the five known season states.
 * @param array  $state_data Result of blueline_season_state_data().
 * @return array{state: string, variant?: string, eyebrow: string, headline_html: string, subcopy_lines?: string[], cta_label: string, cta_url: string, cta_variant: string}
 */
function blueline_homepage_hero_content( string $state, array $state_data ): array {
	if ( 'registration_open' === $state ) {
		$offers = blueline_homepage_registration_offers();

		if ( ! empty( $offers ) ) {
			$content          = blueline_homepage_hero_registration_content( $offers, $state_data );
			$content['state'] = 'registration_open';
			return $content;
		}

		// Every product driving registration_open turned out not to be
		// purchasable when re-checked live (a stale transient, at most 15
		// minutes old), or none of them carries the Featured tag the hero
		// sells from; never point a Register button at one. Fall back to
		// whatever the event signals say instead of inventing a sixth state.
		// Games already being played means in_season, not preseason: the
		// preseason copy prints the NEXT game's date as "Season starts ...",
		// which is wrong for the months a running season overlaps a sale.
		if ( ! empty( $state_data['next_event_id'] ) ) {
			$state = ! empty( $state_data['is_playing'] ) ? 'in_season' : 'preseason';
		} else {
			$state = 'offseason';
		}
	}

	/*
	 * One builder per remaining state, each taking $state_data and
	 * returning the hero content array minus its own 'state' key, the
	 * same $orders[ $state ] ?? $orders['offseason'] lookup shape
	 * blueline_homepage_module_order() below already uses for the same
	 * "one of five states" problem, so a new state later is a one-line
	 * map entry rather than a new branch. 'offseason' doubles as both a
	 * real state and the safe fallback for anything else reaching this
	 * point (an unrecognised value, or the registration_open fallback
	 * above with no next event); the ?? covers both in one expression.
	 * blueline_homepage_hero_offseason_content() itself takes no
	 * arguments, unlike the other three, so it is wrapped here rather
	 * than referenced directly, keeping every entry callable the same way.
	 */
	$builders = array(
		'preseason' => 'blueline_homepage_hero_preseason_content',
		'in_season' => 'blueline_homepage_hero_in_season_content',
		'playoffs'  => 'blueline_homepage_hero_playoffs_content',
		'offseason' => static function ( array $state_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- deliberately unused; every entry in this map is called the same way below, and blueline_homepage_hero_offseason_content() itself simply takes no arguments.
			return blueline_homepage_hero_offseason_content();
		},
	);

	$effective_state = isset( $builders[ $state ] ) ? $state : 'offseason';

	// Only $0 waitlists on offer, and no games being played: the hero says so,
	// with no price. While a season is running the hero is for the people
	// playing in it, so it keeps its own copy; the waitlist stays one click
	// away in the header. Checked live, like the paid offers above, rather
	// than off the cached state. 'state' stays the season state, because that
	// is what the module order below the hero follows; 'variant' names the
	// copy shown.
	$waitlist_id = empty( $state_data['is_playing'] )
		? (int) ( blueline_season_state_product_signal()[2] ?? 0 )
		: 0;

	if ( $waitlist_id ) {
		$content            = blueline_homepage_hero_waitlist_content(
			$state_data,
			blueline_homepage_registration_season_label( $waitlist_id )
		);
		$content['state']   = $effective_state;
		$content['variant'] = 'waitlist';
		return $content;
	}

	$builder = $builders[ $effective_state ];

	$content          = $builder( $state_data );
	$content['state'] = $effective_state;

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
 * Render the season-aware homepage hero: skewed eyebrow, headline with one
 * --bl-ice highlighted word, primary CTA, and the faceoff-ring/blue-line-band
 * chrome. Falls back to the offseason variant for any value outside the
 * five known states; this theme never invents a sixth.
 *
 * The bl-hero--{state} class is always taken from the EFFECTIVE state
 * blueline_homepage_hero_content() actually rendered, not the requested
 * $state; those can differ (a registration_open request whose product
 * fails live re-verification renders preseason/offseason copy instead),
 * and the class must agree with what is actually on the page -- which is
 * why the waitlist hero's class is bl-hero--waitlist rather than its
 * season state. The effective state is returned so the caller
 * (template-homepage.php) can keep the module order in sync with whatever
 * the hero actually showed.
 *
 * @param string $state One of registration_open|preseason|in_season|playoffs|offseason.
 * @return string The effective state actually rendered.
 */
function blueline_render_hero( string $state ): string {
	$state = blueline_known_season_state( $state );

	$state_data      = blueline_season_state_data();
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
	<section class="bl-hero bl-hero--<?php echo esc_attr( $content['variant'] ?? $effective_state ); ?><?php echo $bl_has_photo ? ' bl-band-photo' : ''; ?>">
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
