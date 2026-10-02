<?php
/**
 * Team events override -- adds the theme's own `sp_team_events_season`
 * filter (blueline_team_events_current_season_id(), inc/sportspress.php)
 * to the 'blocks' branch, the one branch of the plugin's own
 * team-events.php that took no season input at all.
 *
 * Live-site review: a team's public "Results" card showed games years old
 * directly beneath "Upcoming Games." Confirmed against the SportsPress
 * plugin's own team-events.php/event-fixtures-results.php (this site's
 * configured `sportspress_team_events_format` option is 'blocks', the
 * `else` branch below): it calls `sp_get_template(
 * 'event-fixtures-results.php', array( 'team' => $id ) )` with no `season`
 * key whatsoever, so SP_Calendar (event-fixtures-results.php's own
 * `if ( $season ) { $calendar->season = $season; }` never fires) falls
 * back to every sp_event the team has EVER played. The 'list' branch
 * already ran its own value through `apply_filters(
 * 'sp_team_events_season', 0 )`; this override adds the identical call to
 * the 'blocks' branch so both display formats are scoped the same way, via
 * the same filter, regardless of which one an admin has configured.
 *
 * This is a partial, not a page template, mirroring team-lists.php:
 * SportsPress' own sportspress_output_team_events() calls
 * sp_get_template( 'team-events.php' ) with no arguments, and
 * sp_locate_template() finds this theme override first. Everything else
 * here is copied unchanged from the plugin's own team-events.php (version
 * 2.6.9, confirmed live on staging) -- the 'calendar' branch has no season
 * concept to scope (a calendar view legitimately spans seasons), so it is
 * untouched.
 *
 * NOTE: this partial only ever renders inside the "Games" tab of the
 * Division Table/Games sp-tab-group SP_Template_Loader builds around
 * table_content + this file's own output (both registered as tab
 * templates, not stacked sections) -- confirmed live: its output sits in
 * <div class="sp-tab-content sp-tab-content-events" ...>, hidden until
 * that tab is clicked. The team's calendar-subscribe links used to be
 * printed here, but that made them invisible by default too -- see
 * blueline_register_team_page_sections() (inc/sportspress.php), which
 * makes them their own reorderable SportsPress team section.
 *
 * Overrides SportsPress templates/team-events.php, core template version 2.6.9 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $id ) ) {
	$id = get_the_ID(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $id is this template's documented extract()-provided argument name (sp_get_template()'s own convention, mirrored from the plugin's default team-events.php).
}

$format = get_option( 'sportspress_team_events_format', 'blocks' );

if ( 'calendar' === $format ) {
	sp_get_template( 'event-calendar.php', array( 'team' => $id ) );
} elseif ( 'list' === $format ) {
	$args = array(
		'team'         => $id,
		'league'       => apply_filters( 'sp_team_events_league', 0 ),
		'season'       => apply_filters( 'sp_team_events_season', 0 ),
		'title_format' => 'homeaway',
		'time_format'  => 'separate',
		'columns'      => array( 'event', 'time', 'results' ),
		'order'        => 'DESC',
	);
	$args = apply_filters( 'sp_team_events_list_args', $args );
	sp_get_template( 'event-list.php', $args );
} else {
	sp_get_template(
		'event-fixtures-results.php',
		array(
			'team'   => $id,
			'season' => apply_filters( 'sp_team_events_season', 0 ),
		)
	);
}
