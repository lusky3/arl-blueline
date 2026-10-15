<?php
/**
 * Single sp_team: a custom masthead (crest, division -- see
 * blueline_sp_team_hero() in inc/sportspress/heroes.php), then SportsPress's own
 * the_content()-injected sections: roster (via this theme's own
 * sportspress/team-lists.php override), standings (the team's row
 * highlighted), and schedule (fixtures and results, both status-correct --
 * see event-fixtures-results.php's own 'future'-status fixtures query).
 *
 * The team's colour custom properties are printed a second time
 * here, on <main> itself, so they are visible to the_content()'s own
 * league-table markup too -- a SIBLING of the hero <header>, not one of its
 * descendants, so a property declared only on the header could never reach
 * it. blueline_team_color_style_attr() itself (inc/team-colors.php) is
 * untouched -- same derivation, same guard, just read from a wider scope.
 * blueline_render_sp_single()'s own $extra_main_attr parameter (see
 * inc/sportspress/core.php) is what carries it onto `<main>` -- the one place this
 * template differs from the other three single-*.php templates sharing that
 * skeleton.
 *
 * Upstream: none (theme-authored; SportsPress ships no template of this name).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$team_color_attr = blueline_team_color_style_attr( get_queried_object_id() );

// A logged-in, unclaimed visitor's nudge to link their player, above the
// roster -- see blueline_render_claim_nudge()'s own docblock
// (inc/account/dashboard.php). Passed as a callback rather than called
// unconditionally inside blueline_render_sp_single() itself: that function
// is the shared skeleton behind all four SportsPress single templates, and
// this nudge belongs on a team's own page only.
//
// The calendar-subscribe links (blueline_render_team_calendar_links(),
// inc/sportspress/calendar.php) are not rendered here: they sit in
// sportspress/team-events.php instead, printed directly above the
// Fixtures/Results content they visually introduce, rather than floating
// this far from the schedule they belong to.
blueline_render_sp_single( 'blueline_sp_team_hero', $team_color_attr, 'blueline_render_claim_nudge' );

get_footer();
