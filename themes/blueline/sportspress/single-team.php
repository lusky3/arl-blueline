<?php
/**
 * Single sp_team: a custom masthead (crest, division -- see
 * blueline_sp_team_hero() in inc/sportspress.php), then SportsPress's own
 * the_content()-injected sections: roster (via this theme's own
 * sportspress/team-lists.php override), standings (the team's row
 * highlighted), and schedule (fixtures and results, both status-correct --
 * see event-fixtures-results.php's own 'future'-status fixtures query).
 *
 * Finding 13: the team's colour custom properties are printed a second time
 * here, on <main> itself, so they are visible to the_content()'s own
 * league-table markup too -- a SIBLING of the hero <header>, not one of its
 * descendants, so a property declared only on the header could never reach
 * it. blueline_team_color_style_attr() itself (inc/team-colors.php) is
 * untouched -- same derivation, same guard, just read from a wider scope.
 * blueline_render_sp_single()'s own $extra_main_attr parameter (see
 * inc/sportspress.php) is what carries it onto `<main>` -- the one place this
 * template differs from the other three single-*.php templates sharing that
 * skeleton.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$team_color_attr = function_exists( 'blueline_team_color_style_attr' )
	? blueline_team_color_style_attr( get_queried_object_id() )
	: '';

// A logged-in, unclaimed visitor's nudge to link their player, above the
// roster -- see blueline_render_claim_nudge()'s own docblock
// (inc/account/dashboard.php). Passed as a callback rather than called
// unconditionally inside blueline_render_sp_single() itself: that function
// is the shared skeleton behind all four SportsPress single templates, and
// this nudge belongs on a team's own page only.
$bl_before_content = function_exists( 'blueline_render_claim_nudge' ) ? 'blueline_render_claim_nudge' : null;

blueline_render_sp_single( 'blueline_sp_team_hero', $team_color_attr, $bl_before_content );

get_footer();
