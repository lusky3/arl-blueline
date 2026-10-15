<?php
/**
 * Single sp_event: a custom pre-game/post-game masthead (see
 * blueline_sp_event_hero() in inc/sportspress/heroes.php), then SportsPress's own
 * the_content()-injected sections (teams, details, venue, box score --
 * whichever are enabled in the SportsPress admin settings for events).
 *
 * The shared skeleton (sidebar-active check, hero, `.bl-content-layout`
 * wrapper, comments) lives in blueline_render_sp_single() -- see that
 * function's own docblock in inc/sportspress/core.php.
 *
 * Upstream: none (theme-authored; SportsPress ships no template of this name).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

blueline_render_sp_single( 'blueline_sp_event_hero' );

get_footer();
