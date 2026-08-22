<?php
/**
 * Theme override of SportsPress's Countdown widget template
 * (templates/countdown.php in the installed plugin -- "The Next Puck Drop"
 * widget, sidebar-1, per blueline_sp_has_sidebar()'s own docblock in
 * inc/sportspress.php).
 *
 * Live-review finding: this widget got permanently stuck showing
 * "Puck Dynasty vs Hammers (On time)" -- a game from over a week in the
 * past whose result was never entered -- with a countdown frozen at
 * 00 Days 00 Hrs 00 Mins 00 Secs, while real future games (the same week's
 * Friday slate) existed and the homepage's own "Next games" module found
 * them without trouble. Two compounding causes, confirmed live:
 *
 * 1. Whatever the widget's own resolution picks (a pinned "Event" setting,
 *    a specific Calendar, or SportsPress's own sp_get_next_event()) is not
 *    guaranteed to be a genuinely future-dated event -- a countdown widget
 *    is not the one place on this site that should trust that blindly.
 * 2. Even when the resolved event IS stale, the stock template's own
 *    countdown math -- `date_diff( $now, $date )`, with `$interval->invert`
 *    simply zeroed rather than treated as "already happened" -- collapses
 *    to 00:00:00:00 instead of refusing to render a countdown at all.
 *
 * This override keeps every configuration surface the stock widget exposes
 * (calendar/team/league/season/id/order/orderby, title/caption, show_*
 * toggles) and renders identically to stock in the common case. The one
 * change: after resolving $bl_post exactly as stock does, this ALWAYS
 * re-checks it against blueline_sp_next_dated_event() (inc/sportspress.php)
 * in the same team/league/season scope, via blueline_sp_decide_countdown_event()'s
 * pure decision (unit tested, tests/CountdownEventTest.php) -- swapping to
 * a real future event whenever the resolved pick is stale and one exists,
 * and falling back to "already played" copy (no live countdown, no claim
 * of being "on time") only when nothing future exists anywhere in scope.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

$defaults = array(
	'team'           => null,
	'calendar'       => null,
	'order'          => null,
	'orderby'        => null,
	'league'         => null,
	'season'         => null,
	'id'             => null,
	'title'          => null,
	'live'           => get_option( 'sportspress_enable_live_countdowns', 'yes' ) === 'yes',
	'link_events'    => get_option( 'sportspress_link_events', 'yes' ) === 'yes',
	'link_teams'     => get_option( 'sportspress_link_teams', 'no' ) === 'yes',
	'link_venues'    => get_option( 'sportspress_link_venues', 'no' ) === 'yes',
	'show_logos'     => get_option( 'sportspress_countdown_show_logos', 'no' ) === 'yes',
	'show_thumbnail' => get_option( 'sportspress_countdown_show_thumbnail', 'no' ) === 'yes',
);

extract( $defaults, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors sp_get_template()'s own extract() convention for the args this template receives; EXTR_SKIP never overwrites an already-set variable.

if ( isset( $show_excluded ) && $show_excluded ) {
	$excluded_statuses = array();
} else {
	$excluded_statuses = apply_filters(
		'sp_countdown_excluded_statuses',
		array(
			'postponed',
			'cancelled',
		)
	);
}

/*
 * The team/league/season scope this widget was configured with, in the
 * exact shape blueline_sp_next_dated_event() expects -- built once, up
 * front, regardless of which of the three branches below actually resolves
 * $bl_post, since our OWN "is there a real future event?" re-check below
 * needs to stay within that same scope no matter how the stock pick was
 * made.
 */
$bl_scope_args = array();
if ( $team ) {
	$bl_scope_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- mirrors stock countdown.php's own team scoping; a single-team lookup, not an unbounded query.
		array(
			'key'   => 'sp_team',
			'value' => $team,
		),
	);
}
if ( $league || $season ) {
	$bl_scope_args['tax_query'] = array( 'relation' => 'AND' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- mirrors stock countdown.php's own league/season scoping.

	if ( $league ) {
		$bl_scope_args['tax_query'][] = array(
			'taxonomy' => 'sp_league',
			'terms'    => $league,
		);
	}

	if ( $season ) {
		$bl_scope_args['tax_query'][] = array(
			'taxonomy' => 'sp_season',
			'terms'    => $season,
		);
	}
}

if ( isset( $id ) ) :
	$bl_post = get_post( $id );
elseif ( $calendar ) :
	$calendar = new SP_Calendar( $calendar );
	if ( $team ) {
		$calendar->team = $team;
	}
	$calendar->status = 'future';
	if ( $order ) {
		$calendar->order = $order;
	} else {
		$calendar->order = 'ASC';
	}
	if ( $orderby ) {
		$calendar->orderby = $orderby;
	}
	$data = $calendar->data();

	// Exclude postponed or cancelled events.
	$bl_post = null;
	foreach ( $data as $bl_candidate ) {
		$sp_status = get_post_meta( $bl_candidate->ID, 'sp_status', true );
		if ( ! in_array( $sp_status, $excluded_statuses, true ) ) {
			$bl_post = $bl_candidate;
			break;
		}
	}
else :
	$args = $bl_scope_args;

	// Exclude postponed or cancelled events.
	$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- mirrors stock countdown.php's own status exclusion.
		'relation' => 'OR',
		array(
			'key'     => 'sp_status',
			'compare' => 'NOT IN',
			'value'   => $excluded_statuses,
		),
		array(
			'key'     => 'sp_status',
			'compare' => 'NOT EXISTS',
		),
	);

	$bl_post = sp_get_next_event( $args );
endif;

/*
 * The actual fix: never trust the resolution above to have picked a
 * genuinely future-dated event -- re-check it, and swap to a real one in
 * the same scope when it's stale and one exists (see this file's own
 * top-of-file docblock and blueline_sp_decide_countdown_event()'s).
 */
$bl_picked = ( $bl_post instanceof WP_Post )
	? array(
		'ID'        => $bl_post->ID,
		'post_date' => $bl_post->post_date,
	)
	: null;

// blueline_season_state_moment() (inc/season-state.php), not current_time(
// 'timestamp' ) (deprecated since WP 5.3, and this theme's own established
// way to get "now" as a timestamp directly comparable to post_date -- see
// that function's own docblock on why both branches must derive from the
// exact same site-local wall-clock string).
list( , $bl_now_ts ) = function_exists( 'blueline_season_state_moment' )
	? blueline_season_state_moment()
	: array( null, time() );

$bl_next_future = ( null !== $bl_picked && blueline_sp_event_date_is_past( $bl_picked['post_date'], $bl_now_ts ) )
	? blueline_sp_next_dated_event( $bl_scope_args )
	: null;

$bl_decision = blueline_sp_decide_countdown_event( $bl_picked, $bl_next_future, $bl_now_ts );

if ( null === $bl_decision['event'] ) {
	return;
}

// The decided event may differ from $bl_post (swapped to a real future one) --
// re-resolve the actual WP_Post either way, since $bl_decision only ever
// carries the lightweight ID/post_date shape.
$bl_post = get_post( $bl_decision['event']['ID'] );

if ( ! $bl_post ) {
	return;
}

$bl_is_future = $bl_decision['is_future'];

if ( $title ) {
	echo '<h4 class="sp-table-caption">' . wp_kses_post( $title ) . '</h4>';
}

$bl_title = $bl_post->post_title;
if ( $link_events ) {
	$bl_title = '<a href="' . get_post_permalink( $bl_post->ID, false, true ) . '">' . $bl_title . '</a>';
}
if ( isset( $show_status ) && $show_status ) {
	$sp_status = get_post_meta( $bl_post->ID, 'sp_status', true );
	if ( '' === $sp_status ) {
		$sp_status = 'ok';
	}
	$statuses = apply_filters(
		'sportspress_event_statuses',
		array(
			'ok'        => esc_attr__( 'On time', 'sportspress' ),
			'tbd'       => esc_attr__( 'TBD', 'sportspress' ),
			'postponed' => esc_attr__( 'Postponed', 'sportspress' ),
			'cancelled' => esc_attr__( 'Canceled', 'sportspress' ),
		)
	);
	// A stale fallback (no future event anywhere in scope) is, by
	// definition, already in the past -- "On time" reads as a live claim
	// this game is still on schedule, which is no longer meaningful once
	// its own date has passed. The status line is dropped in that one
	// case rather than paired with an outdated status label.
	if ( $bl_is_future ) {
		$bl_title = $bl_title . ' (' . $statuses[ $sp_status ] . ')';
	}
}

?>
<div class="sp-template sp-template-countdown">
	<div class="sp-countdown-wrapper">
	<?php
	if ( $show_thumbnail && has_post_thumbnail( $bl_post ) ) {
		?>
	<div class="event-image sp-event-image">
		<?php echo get_the_post_thumbnail( $bl_post ); ?>
	</div>
	<?php } ?>
		<h3 class="event-name sp-event-name">
			<?php
			if ( $show_logos ) {
				$bl_teams = array_unique( (array) get_post_meta( $bl_post->ID, 'sp_team', false ) );
				$bl_i     = 0;

				foreach ( $bl_teams as $bl_team ) {
					++$bl_i;
					if ( has_post_thumbnail( $bl_team ) ) {
						if ( $link_teams ) {
							echo '<a class="team-logo logo-' . ( $bl_i % 2 ? 'odd' : 'even' ) . '" href="' . esc_url( get_post_permalink( $bl_team ) ) . '" title="' . esc_attr( get_the_title( $bl_team ) ) . '">' . get_the_post_thumbnail( $bl_team, 'sportspress-fit-icon' ) . '</a>';
						} else {
							echo get_the_post_thumbnail( $bl_team, 'sportspress-fit-icon', array( 'class' => 'team-logo logo-' . ( $bl_i % 2 ? 'odd' : 'even' ) ) );
						}
					}
				}
			}
			?>
			<?php echo wp_kses_post( $bl_title ); ?>
		</h3>
		<?php
		if ( isset( $show_date ) && $show_date ) :
			?>
			<h5 class="event-venue sp-event-venue event-date sp-event-date">
				<?php echo wp_kses_post( get_the_time( get_option( 'date_format' ), $bl_post ) ); ?>
			</h5>
			<?php
		endif;

		if ( isset( $show_venue ) && $show_venue ) :
			$bl_venues = get_the_terms( $bl_post->ID, 'sp_venue' );
			if ( $bl_venues && ! is_wp_error( $bl_venues ) ) :
				?>
				<h5 class="event-venue sp-event-venue">
					<?php
					if ( $link_venues ) {
						the_terms( $bl_post->ID, 'sp_venue' );
					} else {
						echo wp_kses_post( implode( '/', wp_list_pluck( $bl_venues, 'name' ) ) );
					}
					?>
				</h5>
				<?php
			endif;
		endif;

		if ( isset( $show_league ) && $show_league ) :
			$bl_leagues = get_the_terms( $bl_post->ID, 'sp_league' );
			if ( $bl_leagues && ! is_wp_error( $bl_leagues ) ) :
				foreach ( $bl_leagues as $bl_league ) :
					?>
					<h5 class="event-league sp-event-league"><?php echo wp_kses_post( $bl_league->name ); ?></h5>
					<?php
				endforeach;
			endif;
		endif;

		if ( $bl_is_future ) :
			$bl_now      = new DateTime( current_time( 'mysql', 0 ) ); // phpcs:ignore WordPress.DateTime.CurrentTime.Requested -- matches stock countdown.php exactly; see this file's own docblock.
			$bl_date     = new DateTime( $bl_post->post_date );
			$bl_interval = date_diff( $bl_now, $bl_date );

			$bl_days = $bl_interval->invert ? 0 : $bl_interval->days;
			$bl_h    = $bl_interval->invert ? 0 : $bl_interval->h;
			$bl_min  = $bl_interval->invert ? 0 : $bl_interval->i;
			$bl_s    = $bl_interval->invert ? 0 : $bl_interval->s;
			?>
			<p class="countdown sp-countdown<?php echo $bl_days >= 10 ? ' long-countdown' : ''; ?>">
				<time datetime="<?php echo esc_attr( $bl_post->post_date ); ?>"
					<?php if ( $live ) : ?>
						data-countdown="<?php echo esc_attr( str_replace( '-', '/', get_gmt_from_date( $bl_post->post_date ) ) ); ?>"
					<?php endif; ?>
				>
					<span><?php echo esc_html( sprintf( '%02d', $bl_days ) ); ?> <small><?php esc_html_e( 'days', 'sportspress' ); ?></small></span>
					<span><?php echo esc_html( sprintf( '%02d', $bl_h ) ); ?> <small><?php esc_html_e( 'hrs', 'sportspress' ); ?></small></span>
					<span><?php echo esc_html( sprintf( '%02d', $bl_min ) ); ?> <small><?php esc_html_e( 'mins', 'sportspress' ); ?></small></span>
					<span><?php echo esc_html( sprintf( '%02d', $bl_s ) ); ?> <small><?php esc_html_e( 'secs', 'sportspress' ); ?></small></span>
				</time>
			</p>
		<?php else : ?>
			<?php
			/*
			 * No future event anywhere in scope -- the countdown widget has
			 * nothing left to count down to. Rather than a frozen
			 * 00:00:00:00 (the exact bug this override exists to fix),
			 * this says plainly that the result is what's pending, matching
			 * the "Final score coming soon" language this same live review
			 * asked for on the schedule tables (sportspress/event-list.php)
			 * for the identical underlying situation: a past, unplayed-per-
			 * the-data game.
			 */
			?>
			<p class="countdown sp-countdown bl-sp-countdown--past">
				<?php esc_html_e( 'Final score coming soon.', 'blueline' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
