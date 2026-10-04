<?php
/**
 * SportsPress integration: event timing and state, the countdown pick,
 * team schedule/calendar sections, and add-to-calendar / ICS links.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

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
 * Live-review finding: "The Next Puck Drop" countdown widget (SportsPress's
 * own Countdown widget, sportspress/countdown.php below overrides its
 * template) got permanently stuck on a game from over a week in the past
 * whose result was never entered, showing 00 Days 00 Hrs 00 Mins 00 Secs
 * forever instead of advancing to the real next dated game -- confirmed
 * live on staging while real future games existed the same week. Whatever
 * event the stock widget/template resolves (a pinned "Event" setting, a
 * specific Calendar, or SportsPress's own "next" pick) can end up being a
 * stale one; the template's own countdown math (date_diff() with $interval
 * ->invert zeroed rather than checked) then silently renders zeros for any
 * past date rather than refusing to.
 *
 * Whether a resolved event is "stale" -- pure, so the actual decision
 * blueline_sp_countdown_event() below makes is directly unit testable
 * without a WP_Post.
 *
 * @param string $post_date Event's post_date ('Y-m-d H:i:s', site-local),
 *                           or '' for no event at all.
 * @param int    $now_ts    Unix timestamp to evaluate "past" against.
 * @return bool
 */
function blueline_sp_event_date_is_past( string $post_date, int $now_ts ): bool {
	if ( '' === $post_date ) {
		return false;
	}

	return strtotime( $post_date ) < $now_ts;
}

/**
 * Pure decision: given the event the countdown widget/template's own
 * resolution already picked (id/calendar/next -- see
 * blueline_sp_event_date_is_past()'s docblock) and, separately, the nearest
 * genuinely future-dated event this theme's own lookup found in the same
 * scope (team/league/season), decide which one the countdown should
 * actually show, and whether that is a live countdown or an
 * already-played fallback with no clock to run.
 *
 * - The picked event is not stale (future, or none picked at all): use it
 *   as-is: it is either already correct, or there is nothing to show.
 * - The picked event IS stale, and a real future event was found in scope:
 *   switch to that one -- never show a stale pick when a better answer
 *   exists.
 * - The picked event is stale AND nothing future exists anywhere in scope:
 *   the stale pick survives, but 'is_future' is false, which the template
 *   (never this function) is responsible for rendering as "already played"
 *   copy rather than a countdown claiming to be live.
 *
 * @param array{ID:int, post_date:string}|null $picked      Whatever the stock resolution chose, or null.
 * @param array{ID:int, post_date:string}|null $next_future Nearest future-dated event in scope, or null.
 * @param int                                  $now_ts      Unix timestamp to evaluate "past" against.
 * @return array{event: array{ID:int, post_date:string}|null, is_future: bool}
 */
function blueline_sp_decide_countdown_event( ?array $picked, ?array $next_future, int $now_ts ): array {
	$picked_is_past = null !== $picked && blueline_sp_event_date_is_past( $picked['post_date'] ?? '', $now_ts );

	if ( ! $picked_is_past ) {
		return array(
			'event'     => $picked,
			'is_future' => null !== $picked,
		);
	}

	if ( null !== $next_future ) {
		return array(
			'event'     => $next_future,
			'is_future' => true,
		);
	}

	return array(
		'event'     => $picked,
		'is_future' => false,
	);
}

/**
 * The nearest sp_event, in the same team/league/season scope $scope_args
 * narrows (same meta_query/tax_query shape SportsPress's own countdown.php
 * template builds), whose date is still ahead of $now.
 *
 * Untested at this layer for the same reason blueline_season_state_data()'s
 * own WP_Query calls are (see tests/SeasonStateOverrideTest.php's
 * test_the_query_moment_is_built_from_the_injected_timestamp() docblock): a
 * from-memory WP_Query/date_query stub is exactly how this project's
 * option-lifecycle stubs previously shipped assumptions nobody could check
 * against core's real behaviour. blueline_sp_decide_countdown_event() above
 * carries the actual decision this exists to serve, and IS unit tested.
 *
 * @param array    $scope_args Extra meta_query/tax_query args narrowing the scope.
 * @param int|null $now        Unix timestamp to evaluate against; null for the current time.
 * @return array{ID:int, post_date:string}|null
 */
function blueline_sp_next_dated_event( array $scope_args = array(), $now = null ) {
	if ( ! post_type_exists( 'sp_event' ) ) {
		return null;
	}

	list( $now_mysql, ) = blueline_season_state_moment( $now );

	$query = new WP_Query(
		array_merge(
			$scope_args,
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'future' ),
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded to a single posts_per_page=1 lookup, not an unbounded query.
					array(
						'column' => 'post_date',
						'after'  => $now_mysql,
					),
				),
			)
		)
	);

	if ( empty( $query->posts ) ) {
		return null;
	}

	$post = $query->posts[0];

	return array(
		'ID'        => (int) $post->ID,
		'post_date' => (string) $post->post_date,
	);
}

/**
 * Pure decision: explanatory copy for a league table (sportspress/
 * league-table.php) where no listed team has played any games yet, or ''
 * when at least one has -- or when there is simply no way to tell.
 *
 * Live-review finding: a division whose season/playoff round hasn't
 * started yet rendered every team at position 0 with a 0-0-0-0 record and
 * NO explanatory text at all -- confirmed live on "Division 5 | Playoffs
 * S2026" -- reading as a broken table rather than "nothing has happened
 * here yet."
 *
 * Kept free of SportsPress/WordPress calls -- the caller alone knows its
 * own table's column shape (whether it has a 'gp' column, or must fall
 * back to W/L/T/OT) and extracts the flat list of per-team figures this
 * function only ever compares to zero -- so the actual "is this table
 * empty" decision is directly unit testable without a real
 * SP_League_Table.
 *
 * @param bool  $has_progress_signal Whether the caller found ANY column
 *                                    (gp, or at least one record component)
 *                                    it could read a progress figure from
 *                                    at all. False means "cannot tell",
 *                                    which must never be treated the same
 *                                    as "confirmed zero".
 * @param array $progress_figures    Every listed team's own progress figure
 *                                    (its 'gp' value, or each of its
 *                                    W/L/T/OT record components) --
 *                                    whichever the caller used to decide
 *                                    $has_progress_signal.
 * @param bool  $has_teams           Whether the table lists any teams at all.
 * @param bool  $is_playoffs         Whether the table's own caption/title
 *                                    names a playoff round.
 * @return string
 */
function blueline_sp_zero_games_note( bool $has_progress_signal, array $progress_figures, bool $has_teams, bool $is_playoffs ): string {
	if ( ! $has_teams || ! $has_progress_signal ) {
		return '';
	}

	foreach ( $progress_figures as $figure ) {
		if ( (int) $figure > 0 ) {
			return '';
		}
	}

	return $is_playoffs
		? __( 'Playoffs have not started yet.', 'blueline' )
		: __( 'No games have been played yet this season.', 'blueline' );
}

/**
 * The effective display state of an sp_event, independent of whether a
 * score has been entered.
 *
 * P0 finding 2: blueline_sp_event_hero() used to decide pre/post-game
 * purely from `sp_get_status() === 'results'`, which SportsPress only ever
 * returns once a result row exists in `sp_results`. Confirmed live:
 * eleven published events in the last 90 days have no `sp_results` row at
 * all, so an event dated well in the past still advertised "Preview" and
 * offered a live "Add to calendar" link. This function is pure (no
 * SportsPress/WordPress calls of its own) so it can be unit tested
 * directly: given whether a result exists and when the event actually
 * starts, it returns exactly one of three states, gated on the clock
 * rather than on data entry:
 *
 *   'final'   : a result has been recorded. Always wins regardless of time
 *                (a game can be scored before its listed start passes, e.g.
 *                a corrected/backdated entry).
 *   'pending' : no result yet, but the start time has already passed.
 *                "Result pending", never "Preview", and never a calendar
 *                link for a game that has already happened.
 *   'preview' : no result, and the start time has not passed yet. The
 *                only state that gets a calendar link.
 *
 * @param bool      $has_results     Whether sp_get_status() returned 'results'.
 * @param int|false $start_timestamp GMT unix timestamp the event starts, or
 *                                    false if unknown (treated as not yet
 *                                    started, i.e. never 'pending').
 * @param int|null  $now_timestamp   Current GMT unix timestamp; defaults to
 *                                    time(). Exposed as a parameter purely so
 *                                    tests can pass a fixed clock.
 * @return string 'final'|'pending'|'preview'.
 */
function blueline_sp_event_state( $has_results, $start_timestamp, $now_timestamp = null ) {
	if ( $has_results ) {
		return 'final';
	}

	if ( null === $now_timestamp ) {
		$now_timestamp = time();
	}

	if ( $start_timestamp && $start_timestamp <= $now_timestamp ) {
		return 'pending';
	}

	return 'preview';
}

/**
 * Whether a played game with no result is recent enough that a score is still
 * expected (QA C-24: "Final score coming soon" was shown on 2016 games).
 *
 * @param int|false $start_timestamp GMT unix start time, or false if unknown.
 * @param int|null  $now_timestamp   Current GMT unix time; defaults to time().
 * @return bool
 */
function blueline_sp_result_still_expected( $start_timestamp, $now_timestamp = null ) {
	if ( ! $start_timestamp ) {
		return false;
	}

	if ( null === $now_timestamp ) {
		$now_timestamp = time();
	}

	return $now_timestamp - (int) $start_timestamp <= 14 * DAY_IN_SECONDS;
}

/**
 * Number of columns sportspress/event-list.php prints, so its date-heading
 * row spans the whole table (QA C-16/B-05: the Result column was missed).
 * Mirrors sp_column_active(): an empty column list means "every column".
 *
 * @param array|null $usecolumns Active column keys.
 * @return int
 */
function blueline_sp_event_list_column_count( $usecolumns ) {
	$active = static function ( $column ) use ( $usecolumns ) {
		return empty( $usecolumns ) || in_array( $column, (array) $usecolumns, true );
	};

	$count = 2; // Date/time, plus Arena (always printed, hidden when inactive).

	if ( $active( 'event' ) ) {
		$count += 2; // Home + Away.
	}

	foreach ( array( 'time', 'league', 'season', 'article', 'day' ) as $column ) {
		if ( $active( $column ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Whether any row of a player statistics table has a real stat value; a table
 * of blanks and dashes gets the empty state instead (QA B-14).
 *
 * @param array    $rows      Rows keyed by season id, each keyed by column.
 * @param string[] $stat_keys Stat column keys.
 * @return bool
 */
function blueline_sp_stat_rows_have_values( array $rows, array $stat_keys ) {
	foreach ( $rows as $row ) {
		foreach ( $stat_keys as $key ) {
			$value = trim( html_entity_decode( wp_strip_all_tags( (string) ( $row[ $key ] ?? '' ) ) ) );

			if ( ! in_array( $value, array( '', '-', "\u{2014}" ), true ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Subscribe URLs for a team's whole SportsPress calendar.
 *
 * The league already publishes one iCal feed per team: an sp_calendar post
 * carrying `sp_team` = the team's id, whose permalink serves iCal when asked
 * with `?feed=sp-ical`. Each team's own page links it by hand in its post
 * content; this resolves the same feed from a team id so the account dashboard
 * can offer it without anyone maintaining a second copy of the URL.
 *
 * Returns BOTH forms because no single one works everywhere. `webcal://` is
 * what iOS and macOS hand to Calendar, and what Outlook takes on Windows;
 * Android generally does nothing with it, and wants Google's own add-by-URL
 * screen instead. Choosing between them is a presentation decision, made in
 * the template and refined by assets/src/js/calendar-links.js, not here.
 *
 * A SUBSCRIPTION, not an export: the reader's calendar re-reads the feed, so a
 * rescheduled game corrects itself instead of leaving a stale entry behind, and
 * one action covers the whole season rather than one game.
 *
 * @param int $team_id sp_team post ID.
 * @return array{webcal:string, google:string, calendar_id:int}|null
 *         Null when the team has no published calendar.
 */
function blueline_team_calendar_urls( $team_id ) {
	$team_id = absint( $team_id );

	if ( ! $team_id || ! post_type_exists( 'sp_calendar' ) ) {
		return null;
	}

	$calendars = get_posts(
		array(
			'post_type'      => 'sp_calendar',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'sp_team', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-row lookup of one team's calendar, not a listing query.
			'meta_value'     => (string) $team_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'        => 'ID',
			'order'          => 'DESC',
		)
	);

	if ( ! $calendars ) {
		return null;
	}

	$calendar_id = (int) $calendars[0];
	$permalink   = get_permalink( $calendar_id );

	if ( ! $permalink ) {
		return null;
	}

	$feed = add_query_arg( 'feed', 'sp-ical', $permalink );

	// webcal:// is the same URL under a scheme that tells the OS "subscribe"
	// rather than "download once". Replacing only the leading scheme, so a
	// host or path that happens to contain "http" is untouched.
	$webcal = preg_replace( '#^https?://#', 'webcal://', $feed );

	return array(
		'calendar_id' => $calendar_id,
		'webcal'      => $webcal,

		/*
		 * Encoded HERE, deliberately. add_query_arg() does NOT encode values:
		 * it builds through build_query(), which calls
		 * _http_build_query( $data, null, '&', '', false ); that last `false`
		 * is $urlencode (wp-includes/functions.php, verified against the
		 * installed core rather than assumed). Without this the cid would
		 * carry a literal "://" and a second "?", and Google truncates the
		 * feed URL at that "?".
		 */
		'google'      => add_query_arg(
			'cid',
			rawurlencode( $webcal ),
			'https://calendar.google.com/calendar/render'
		),
	);
}

/**
 * Print a team's "subscribe to this season" calendar links -- Apple/Outlook
 * (webcal) and Google -- or nothing at all when the team has no published
 * calendar (blueline_team_calendar_urls() itself returns null).
 *
 * Every team's page carried this by hand until now: the same two links,
 * the same "Take your schedule with you." lead-in, and (per a live content
 * audit) the same 2016-era GCal.png/iCal.png image attachments and a
 * hardcoded, environment-specific webcal:// URL -- one team at a time,
 * copy-pasted, unable to follow a domain change or a URL scheme fix. This
 * renders the identical two destinations from the one already-tested
 * source (blueline_team_calendar_urls(), used unchanged by the account
 * dashboard's "My next game" module) instead.
 *
 * data-calendar-links / data-calendar="apple|google" is the same contract
 * assets/src/js/calendar-links.js already reads sitewide (it is imported
 * once, globally, in assets/src/js/index.js) -- it reorders the two links
 * so the reader's likely platform comes first, with no new JS needed here.
 *
 * Rendered as its own SportsPress team section ("calendar", see
 * blueline_register_team_page_sections() below), not from inside
 * sportspress/team-events.php: that partial can land inside a hidden tab
 * when the team layout uses SportsPress's "tabs" divider.
 *
 * @param int $team_id sp_team post ID.
 * @return void
 */
function blueline_render_team_calendar_links( $team_id ) {
	$team_calendar = blueline_team_calendar_urls( $team_id );

	if ( ! $team_calendar ) {
		return;
	}
	?>
	<div class="bl-sp-team-calendar" data-calendar-links>
		<span class="bl-sp-team-calendar__label"><?php esc_html_e( 'Take your schedule with you:', 'blueline' ); ?></span>
		<a class="bl-btn bl-btn--secondary" data-calendar="apple" href="<?php echo esc_url( $team_calendar['webcal'], array( 'webcal', 'http', 'https' ) ); ?>">
			<span class="bl-skew"><span><?php esc_html_e( 'Apple / Outlook', 'blueline' ); ?></span></span>
		</a>
		<a class="bl-btn bl-btn--secondary" data-calendar="google" href="<?php echo esc_url( $team_calendar['google'] ); ?>">
			<span class="bl-skew"><span><?php esc_html_e( 'Google', 'blueline' ); ?></span></span>
		</a>
	</div>
	<?php
}

/**
 * Print a team's own "Upcoming Games" table -- the same [event_list]
 * shortcode /schedule uses (id pointing at a saved event-list
 * configuration), reading the team's own sp_calendar post as that
 * configuration, via sportspress/event-list.php's own theme override
 * (grouped date headings, muted venue text -- see that file's own
 * docblock).
 *
 * Every team's page carried this by hand until now, as a literal
 * `[event_list id="..." title="Upcoming Games" ...]` shortcode pasted into
 * the team's Description -- one PER-TEAM sp_calendar post id, unique to
 * that team, hand-copied from wherever the shortcode was first generated.
 * A live content audit clearing that pasted boilerplate (see
 * blueline_render_team_calendar_links()'s own docblock for the matching
 * calendar-links half of the same cleanup) removed the shortcode text but
 * left the underlying sp_calendar posts themselves untouched -- this
 * renders the identical table from that same post, auto-resolved via
 * blueline_team_calendar_urls()'s own calendar_id lookup, instead of
 * requiring the shortcode to be pasted back in by hand.
 *
 * This is NOT the same thing as sportspress/team-events.php's own
 * "Fixtures"/"Results" cards (the "Games" tab, sportspress_after_single_team
 * teammate below) -- reported live as two visibly different features:
 * this is a plain grouped table of the next 5 upcoming games (matching
 * /schedule's own layout exactly), that is a card-based fixtures/results
 * breakdown. Both have coexisted on a team's own page before this
 * session's changes (confirmed against team-events.php's own docblock,
 * which already described a "Results" card sitting directly beneath an
 * "Upcoming Games" table from the pasted shortcode) -- restoring this
 * table does not replace or duplicate that section's job, it restores
 * the piece that went missing when the pasted shortcode was cleared.
 *
 * @param int $team_id sp_team post ID.
 * @return void
 */
function blueline_render_team_schedule_table( $team_id ) {
	$team_calendar = blueline_team_calendar_urls( $team_id );

	if ( ! $team_calendar || empty( $team_calendar['calendar_id'] ) || ! function_exists( 'sp_get_template' ) ) {
		return;
	}

	sp_get_template(
		'event-list.php',
		array(
			'id'                   => $team_calendar['calendar_id'],
			'title'                => __( 'Upcoming Games', 'blueline' ),
			'status'               => 'future',
			'number'               => 5,
			'order'                => 'default',
			'columns'              => array( 'event', 'teams', 'time', 'venue' ),
			'show_all_events_link' => true,

			/*
			 * Reversed live, per explicit request: an earlier round of
			 * feedback ("too busy") turned this off for this one table --
			 * this site's own sportspress_event_list_show_logos option is
			 * "yes" (default "no"), so /schedule and any other [event_list]
			 * shortcode already show these crests regardless of this
			 * override. Re-enabled now that the table's own column widths
			 * (see .sp-event-list td.data-home/.data-away below) are also
			 * being fixed -- the two changes were requested together, on
			 * the reasoning that a tighter, better-proportioned table
			 * would no longer feel cluttered with the crests back.
			 */
			'show_team_logo'       => true,
		)
	);
}

add_filter( 'sportspress_after_team_template', 'blueline_register_team_page_sections', 20 );
/**
 * Register the calendar links and the Upcoming Games table as SportsPress
 * team-page sections, so SportsPress > Settings > Teams > Layout can reorder
 * or hide them like its own sections. Keys missing from a saved order are
 * appended after it, i.e. where these rendered before.
 *
 * @param array $templates Team templates keyed by section slug.
 * @return array
 */
function blueline_register_team_page_sections( $templates ) {
	$templates = (array) $templates;

	$templates['calendar'] = array(
		'title'   => __( 'Add to Calendar', 'blueline' ),
		'option'  => 'sportspress_team_show_calendar',
		'action'  => 'blueline_output_team_calendar_section',
		'default' => 'yes',
	);

	$templates['schedule'] = array(
		'title'   => __( 'Upcoming Games', 'blueline' ),
		'option'  => 'sportspress_team_show_schedule',
		'action'  => 'blueline_output_team_schedule_section',
		'default' => 'yes',
	);

	return $templates;
}

/**
 * SportsPress section callback: the current team's calendar links.
 *
 * @return void
 */
function blueline_output_team_calendar_section() {
	blueline_render_team_calendar_links( get_the_ID() );
}

/**
 * SportsPress section callback: the current team's Upcoming Games table.
 *
 * @return void
 */
function blueline_output_team_schedule_section() {
	blueline_render_team_schedule_table( get_the_ID() );
}

/**
 * Shared start/end timestamps and venue label for a single sp_event's
 * calendar links (Google and Apple/Outlook, blueline_sp_event_calendar_url()
 * and blueline_sp_event_ics_url() below) -- kept in one place so the two
 * links can never silently disagree on when the game starts/ends or where
 * it is.
 *
 * @param int $event_id sp_event post ID.
 * @return array{start:int, end:int, location:string}|null Null when the
 *         start time is unknown.
 */
function blueline_sp_event_calendar_bounds( $event_id ) {
	$start_ts = blueline_sp_event_start_timestamp( $event_id );

	if ( ! $start_ts ) {
		return null;
	}

	$minutes = 90;
	if ( class_exists( 'SP_Event' ) ) {
		$event         = new SP_Event( $event_id );
		$event_minutes = (int) $event->minutes();
		if ( $event_minutes > 0 ) {
			$minutes = $event_minutes;
		}
	}

	$venue_names = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue', array( 'fields' => 'names' ) ) : array();

	return array(
		'start'    => $start_ts,
		'end'      => $start_ts + ( $minutes * MINUTE_IN_SECONDS ),
		'location' => ( ! is_wp_error( $venue_names ) && ! empty( $venue_names ) ) ? $venue_names[0] : '',
	);
}

/**
 * A Google Calendar "add event" link for a single upcoming sp_event. No JS, no
 * external dependency beyond the calendar.google.com URL scheme: a plain
 * <a href> that works with or without a Google account.
 *
 * Kept for one-off use; the account dashboard offers the team's whole season
 * through blueline_team_calendar_urls() instead, since a subscription both
 * covers every game and corrects itself when one is rescheduled.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready URL, or '' if the start time is unknown.
 */
function blueline_sp_event_calendar_url( $event_id ) {
	$bounds = blueline_sp_event_calendar_bounds( $event_id );

	if ( ! $bounds ) {
		return '';
	}

	$args = array(
		'action'   => 'TEMPLATE',
		'text'     => blueline_sp_title( $event_id ),
		'dates'    => gmdate( 'Ymd\THis\Z', $bounds['start'] ) . '/' . gmdate( 'Ymd\THis\Z', $bounds['end'] ),
		'location' => $bounds['location'],
	);

	return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
}

/**
 * An Apple/Outlook "add event" link for a single upcoming sp_event.
 *
 * Reported live: the event page's "Add to calendar" only ever offered
 * Google -- the one-off single-event equivalent of the gap
 * blueline_render_team_calendar_links() already closed for a team's whole
 * season (that function's own docblock covers why a subscription needs
 * BOTH forms; the same "no single link works everywhere" reasoning applies
 * here). A subscription feed is the wrong shape for a one-off add, though
 * (RFC 5545 requires webcal:// to serve reachable HTTP with the calendar
 * app then polling it forever), so this builds a self-contained VCALENDAR/
 * VEVENT as a `data:text/calendar` URI instead of a URL -- no server
 * endpoint needed, the same "no external dependency" property
 * blueline_sp_event_calendar_url() already has for Google. Opening it
 * downloads a one-shot .ics that iOS/macOS Calendar and Windows Outlook
 * all already know how to import directly.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready data: URI, or '' if the start time is unknown.
 */
function blueline_sp_event_ics_url( $event_id ) {
	$bounds = blueline_sp_event_calendar_bounds( $event_id );

	if ( ! $bounds ) {
		return '';
	}

	// RFC 5545 TEXT escaping: backslash first, so the characters this adds
	// are never themselves re-escaped by the following replacements.
	$escape = static function ( $text ) {
		$text = str_replace( '\\', '\\\\', (string) $text );
		return str_replace( array( ',', ';', "\n" ), array( '\,', '\;', '\n' ), $text );
	};

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//' . $host . '//Event//EN',
		'BEGIN:VEVENT',
		'UID:sp-event-' . $event_id . '@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . gmdate( 'Ymd\THis\Z', $bounds['start'] ),
		'DTEND:' . gmdate( 'Ymd\THis\Z', $bounds['end'] ),
		'SUMMARY:' . $escape( blueline_sp_title( $event_id ) ),
	);

	if ( $bounds['location'] ) {
		$lines[] = 'LOCATION:' . $escape( $bounds['location'] );
	}

	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';

	// CRLF line endings are RFC 5545, not a style choice -- some calendar
	// clients reject a feed that uses bare \n instead.
	$ics = implode( "\r\n", $lines ) . "\r\n";

	return 'data:text/calendar;charset=utf8,' . rawurlencode( $ics );
}
