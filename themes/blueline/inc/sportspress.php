<?php
/**
 * SportsPress integration: body classes, sidebar logic, the header sponsors
 * filter, the horizontal-scroll wrapper for SP's own data tables, the
 * future-status fix for venue archives, and the small hero/teaser renderers
 * consumed by sportspress/*.php.
 *
 * Every SportsPress touchpoint here is guarded with function_exists() /
 * class_exists() / post_type_exists() / taxonomy_exists() so the theme never
 * fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A post's raw title, bypassing the 'the_title' filter entirely -- not
 * merely stripped of tags. SportsPress hooks 'the_title' for sp_player/
 * sp_staff to prepend a "<strong class=\"sp-player-number\">27</strong> " /
 * "<strong class=\"sp-staff-role\">Referee</strong> " badge (confirmed live:
 * get_the_title() on a player named "Matthew Mascola" returns that literal
 * markup plus text). wp_strip_all_tags() alone would only remove the <strong>
 * tags and leave "27 Matthew Mascola" -- still duplicating the number this
 * theme's own hero markup already shows in its own badge. get_post_field()
 * with the 'raw' context returns the exact, unfiltered database value
 * (sanitize_post_field() special-cases 'raw' to skip every filter), so this
 * is the plain title with nothing prepended, ready for esc_html().
 *
 * @param int $post_id Post ID.
 * @return string Plain, unfiltered title.
 */
function blueline_sp_title( $post_id ) {
	return (string) get_post_field( 'post_title', $post_id, 'raw' );
}

/**
 * Whether SportsPress templates should reserve a sidebar column. Mirrors the
 * is_active_sidebar('sidebar-1') check page.php/archive.php already use,
 * promoted to a named, filterable function because all seven sportspress/
 * templates need the same decision (sidebar-1 historically carries SP-
 * relevant widgets -- countdown, recent posts -- so showing it here is
 * intentional, not an oversight).
 *
 * @return bool
 */
function blueline_sp_has_sidebar(): bool {
	return (bool) apply_filters( 'blueline_sp_has_sidebar', is_active_sidebar( 'sidebar-1' ) );
}

add_filter( 'body_class', 'blueline_sp_body_class' );
/**
 * Add bl-sp / bl-sp-{post_type-or-taxonomy} body classes on SportsPress
 * singular and taxonomy views, per the Task 8 interface contract.
 *
 * @param string[] $classes Existing body classes.
 * @return string[]
 */
function blueline_sp_body_class( $classes ) {
	if ( ! function_exists( 'sp_post_types' ) ) {
		return $classes;
	}

	if ( is_singular( sp_post_types() ) ) {
		$classes[] = 'bl-sp';
		$classes[] = 'bl-sp-' . get_post_type();
		return $classes;
	}

	if ( function_exists( 'sp_taxonomies' ) && is_tax( sp_taxonomies() ) ) {
		$term      = get_queried_object();
		$classes[] = 'bl-sp';
		if ( $term instanceof WP_Term ) {
			$classes[] = 'bl-sp-' . $term->taxonomy;
		}
	}

	return $classes;
}

add_filter( 'sportspress_header_sponsors_selector', 'blueline_header_sponsors_selector' );
/**
 * Tell SportsPress which element the header sponsors should be inserted into.
 *
 * @param string $selector Default selector.
 * @return string
 */
function blueline_header_sponsors_selector( $selector ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the filter's own signature; this theme always returns one fixed selector regardless of the default passed in.
	return '.bl-header__sponsors';
}

add_filter( 'the_content', 'blueline_sp_wrap_tables_for_scroll', 20 );
/**
 * SportsPress's own templates (league-table.php, event-blocks.php,
 * player-statistics-league.php, event-details.php, event-list.php,
 * player-list.php, event-officials-table.php, event-logos-block.php) all
 * wrap their <table> in an identical, class-only `<div class="sp-table-
 * wrapper">` with no other classes ever present alongside it -- confirmed
 * by reading every occurrence in the installed plugin. Appending
 * bl-table-scroll there, after shortcodes have already expanded (priority
 * 20, run after SP's own the_content hooks and do_shortcode's default
 * priority 11), gives every SP data table a horizontal scroll container
 * without touching a single plugin file. This is the only place these
 * tables get wrapped -- entity hero markup below never repeats it.
 *
 * @param string $content Post content, already shortcode-expanded.
 * @return string
 */
function blueline_sp_wrap_tables_for_scroll( $content ) {
	if ( false === strpos( $content, 'sp-table-wrapper' ) ) {
		return $content;
	}

	return str_replace( 'class="sp-table-wrapper"', 'class="sp-table-wrapper bl-table-scroll"', $content );
}

add_action( 'pre_get_posts', 'blueline_sp_venue_archive_include_future' );
/**
 * WordPress's default main-query post_status is 'publish' only, which would
 * silently hide every upcoming (future-status) game from a venue's own
 * archive page -- the exact under-counting bug this project has already
 * shipped twice in custom queries (Tasks 6/7). This is the equivalent fix
 * for the one query Task 8 does not build itself: the taxonomy-venue.php
 * main loop, which WordPress core populates before the template ever runs.
 *
 * @param WP_Query $query The main query, passed by reference.
 */
function blueline_sp_venue_archive_include_future( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_tax( 'sp_venue' ) ) {
		$query->set( 'post_status', array( 'publish', 'future' ) );
	}
}

/**
 * GMT unix timestamp an sp_event starts at, or false if it cannot be
 * determined. Used only to build the add-to-calendar link; never used to
 * decide pre-game vs post-game (that is sp_get_status()'s job).
 *
 * @param int $event_id sp_event post ID.
 * @return int|false
 */
function blueline_sp_event_start_timestamp( $event_id ) {
	$local = get_the_time( 'Y-m-d H:i:s', $event_id );

	if ( ! $local ) {
		return false;
	}

	$gmt = get_gmt_from_date( $local );

	return $gmt ? strtotime( $gmt . ' +0000' ) : false;
}

/**
 * A Google Calendar "add event" link for an upcoming sp_event. No JS, no
 * external dependency beyond the calendar.google.com URL scheme -- a plain
 * <a href> that works with or without a Google account.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready URL, or '' if the start time is unknown.
 */
function blueline_sp_event_calendar_url( $event_id ) {
	$start_ts = blueline_sp_event_start_timestamp( $event_id );

	if ( ! $start_ts ) {
		return '';
	}

	$minutes = 90;
	if ( class_exists( 'SP_Event' ) ) {
		$event         = new SP_Event( $event_id );
		$event_minutes = (int) $event->minutes();
		if ( $event_minutes > 0 ) {
			$minutes = $event_minutes;
		}
	}

	$end_ts = $start_ts + ( $minutes * MINUTE_IN_SECONDS );

	$venue_names = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue', array( 'fields' => 'names' ) ) : array();
	$location    = ( ! is_wp_error( $venue_names ) && ! empty( $venue_names ) ) ? $venue_names[0] : '';

	$args = array(
		'action'   => 'TEMPLATE',
		'text'     => blueline_sp_title( $event_id ),
		'dates'    => gmdate( 'Ymd\THis\Z', $start_ts ) . '/' . gmdate( 'Ymd\THis\Z', $end_ts ),
		'location' => $location,
	);

	return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
}

/**
 * The event masthead: teams, "vs", venue (and pad, since the venue term
 * name IS the pad here -- e.g. term 14 is literally named "Red", term 13
 * "Black", both sharing one street address), and either an add-to-calendar
 * link (pre-game) or the final score (post-game, i.e. sp_get_status()
 * returns 'results'). Purely additive to what the_content() renders below
 * it -- SportsPress's own event-logos/event-details/event-venue sections
 * still appear and are styled via sportspress.css.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_hero( $event_id ) {
	if ( ! function_exists( 'sp_get_status' ) ) {
		return;
	}

	$teams = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );

	$status    = sp_get_status( $event_id );
	$is_played = ( 'results' === $status );

	$results = array();
	if ( $is_played && function_exists( 'sp_get_main_results' ) ) {
		$results = array_values( (array) sp_get_main_results( $event_id ) );
	}

	$venue_terms = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue' ) : array();
	$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0]->name : '';

	$calendar_url = $is_played ? '' : blueline_sp_event_calendar_url( $event_id );
	?>
	<header class="sp-scoreboard">
		<?php
		if ( function_exists( 'blueline_render_faceoff_rings' ) ) {
			blueline_render_faceoff_rings();
		}
		?>
		<div class="bl-container sp-scoreboard__inner">
			<p class="sp-scoreboard__status">
				<span class="bl-skew"><span><?php echo esc_html( $is_played ? __( 'Final', 'blueline' ) : __( 'Preview', 'blueline' ) ); ?></span></span>
			</p>

			<div class="sp-scoreboard__matchup">
				<?php foreach ( array( 0, 1 ) as $slot ) : ?>
					<?php
					$team_id = $teams[ $slot ] ?? 0;
					$score   = $results[ $slot ] ?? null;
					?>
					<div class="sp-scoreboard__team">
						<?php if ( $team_id && has_post_thumbnail( $team_id ) ) : ?>
							<span class="sp-scoreboard__logo"><?php echo get_the_post_thumbnail( $team_id, 'thumbnail' ); ?></span>
						<?php endif; ?>
						<span class="sp-scoreboard__team-name">
							<?php echo esc_html( $team_id ? blueline_sp_title( $team_id ) : __( 'TBD', 'blueline' ) ); ?>
						</span>
						<?php if ( $is_played && null !== $score ) : ?>
							<span class="sp-scoreboard__score"><?php echo esc_html( $score ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( 0 === $slot ) : ?>
						<span class="sp-scoreboard__vs" aria-hidden="true"><?php esc_html_e( 'vs', 'blueline' ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<p class="sp-scoreboard__meta">
				<time class="sp-scoreboard__time" datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $event_id ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: event date, 2: event time. */
							__( '%1$s · %2$s', 'blueline' ),
							get_the_date( 'D, M j', $event_id ),
							get_the_time( get_option( 'time_format' ), $event_id )
						)
					);
					?>
				</time>
				<?php if ( $venue_name ) : ?>
					<span class="sp-scoreboard__venue"><?php echo esc_html( $venue_name ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( $calendar_url ) : ?>
				<a class="bl-btn bl-btn--secondary sp-scoreboard__calendar" href="<?php echo esc_url( $calendar_url ); ?>">
					<span class="bl-skew"><span><?php esc_html_e( 'Add to calendar', 'blueline' ); ?></span></span>
				</a>
			<?php endif; ?>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * The player masthead: number, position (read from the sp_position
 * taxonomy -- never a meta field), current team, and nationality (escaped
 * for the attribute context it is used in, per the Task 8 brief).
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_sp_player_hero( $player_id ) {
	$number = get_post_meta( $player_id, 'sp_number', true );

	$position_names = array();
	if ( taxonomy_exists( 'sp_position' ) ) {
		$position_terms = wp_get_post_terms( $player_id, 'sp_position' );
		if ( ! is_wp_error( $position_terms ) ) {
			foreach ( $position_terms as $term ) {
				$position_names[] = $term->name;
			}
		}
	}

	$current_team_id = 0;
	if ( class_exists( 'SP_Player' ) ) {
		$sp_player     = new SP_Player( $player_id );
		$current_teams = array_filter( array_map( 'absint', (array) $sp_player->current_teams() ) );
		if ( ! empty( $current_teams ) ) {
			$current_team_id = (int) reset( $current_teams );
		}
	}

	$nationalities = array_filter( (array) get_post_meta( $player_id, 'sp_nationality', false ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--player">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( '' !== $number && null !== $number ) : ?>
				<span class="bl-sp-hero__number" aria-hidden="true"><?php echo esc_html( $number ); ?></span>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $player_id ) ); ?></h1>
				<p class="bl-sp-hero__meta">
					<?php if ( $position_names ) : ?>
						<span class="bl-sp-hero__position"><?php echo esc_html( implode( ', ', $position_names ) ); ?></span>
					<?php endif; ?>
					<?php foreach ( $nationalities as $code ) : ?>
						<?php
						$code = (string) $code;
						if ( '' === $code ) {
							continue;
						}
						?>
						<span class="bl-sp-hero__flag" title="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( strtoupper( $code ) ); ?></span>
					<?php endforeach; ?>
				</p>
			</div>

			<?php if ( $current_team_id && 'publish' === get_post_status( $current_team_id ) ) : ?>
				<a class="bl-sp-hero__team" href="<?php echo esc_url( get_permalink( $current_team_id ) ); ?>">
					<?php if ( has_post_thumbnail( $current_team_id ) ) : ?>
						<?php echo get_the_post_thumbnail( $current_team_id, 'thumbnail' ); ?>
					<?php endif; ?>
					<span><?php echo esc_html( blueline_sp_title( $current_team_id ) ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * The team masthead: crest, division(s). Record is deliberately not
 * recomputed here -- SportsPress's own standings table (auto-rendered by
 * the_content() below, via team-tables.php) already highlights this team's
 * row with .sp-highlight, which is the correct, already-computed source for
 * W/L/T/PTS rather than a second, hand-rolled aggregation.
 *
 * @param int $team_id sp_team post ID.
 */
function blueline_sp_team_hero( $team_id ) {
	$division_names = array();
	if ( taxonomy_exists( 'sp_league' ) ) {
		$league_terms = wp_get_post_terms( $team_id, 'sp_league' );
		if ( ! is_wp_error( $league_terms ) ) {
			foreach ( $league_terms as $term ) {
				$division_names[] = $term->name;
			}
		}
	}
	?>
	<header class="bl-sp-hero bl-sp-hero--team">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $team_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $team_id, 'medium' ); ?></div>
			<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--fallback">
					<?php blueline_leaf_mark( 'bl-sp-hero__crest-mark' ); ?>
				</div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></h1>
				<?php if ( $division_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $division_names ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<?php
}

/**
 * The staff masthead: role(s) and the team(s) they're attached to.
 *
 * @param int $staff_id sp_staff post ID.
 */
function blueline_sp_staff_hero( $staff_id ) {
	$role_names = array();
	if ( taxonomy_exists( 'sp_role' ) ) {
		$role_terms = wp_get_post_terms( $staff_id, 'sp_role' );
		if ( ! is_wp_error( $role_terms ) ) {
			foreach ( $role_terms as $term ) {
				$role_names[] = $term->name;
			}
		}
	}

	$team_ids = array_filter( array_map( 'absint', (array) get_post_meta( $staff_id, 'sp_team', false ) ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--staff">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $staff_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $staff_id, 'thumbnail' ); ?></div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $staff_id ) ); ?></h1>
				<?php if ( $role_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $role_names ) ); ?></p>
				<?php endif; ?>
				<?php if ( $team_ids ) : ?>
					<ul class="bl-sp-hero__teams">
						<?php
						foreach ( $team_ids as $team_id ) :
							if ( 'publish' !== get_post_status( $team_id ) ) {
								continue;
							}
							?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $team_id ) ); ?>"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<?php
}

/**
 * A compact single-line teaser for one sp_event -- used in archive/taxonomy
 * listings (taxonomy-venue.php) where showing every event's full,
 * the_content()-rendered detail would be far too heavy per row.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_teaser( $event_id ) {
	$status    = function_exists( 'sp_get_status' ) ? sp_get_status( $event_id ) : get_post_status( $event_id );
	$is_played = ( 'results' === $status );

	$results = array();
	if ( $is_played && function_exists( 'sp_get_main_results' ) ) {
		$results = array_values( array_filter( (array) sp_get_main_results( $event_id ), 'blueline_sp_filter_positive_or_zero_exists' ) );
	}
	?>
	<a class="bl-sp-event-teaser" href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
		<span class="bl-sp-event-teaser__date"><?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event_id ) ); ?></span>
		<span class="bl-sp-event-teaser__title"><?php echo esc_html( blueline_sp_title( $event_id ) ); ?></span>
		<?php if ( $is_played && $results ) : ?>
			<span class="bl-sp-event-teaser__score"><?php echo esc_html( implode( ' - ', $results ) ); ?></span>
		<?php else : ?>
			<span class="bl-sp-event-teaser__status"><?php esc_html_e( 'Preview', 'blueline' ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * Array_filter() callback: keeps a result value whenever it was actually set
 * (including a genuine 0-0 scoreline), unlike sp_filter_positive()
 * (SportsPress's own helper) which would drop a real "0" score.
 *
 * @param mixed $value Result value.
 * @return bool
 */
function blueline_sp_filter_positive_or_zero_exists( $value ) {
	return '' !== $value && null !== $value;
}
