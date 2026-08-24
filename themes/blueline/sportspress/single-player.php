<?php
/**
 * Single sp_player: a custom masthead (number, position from the taxonomy,
 * current team -- see blueline_sp_player_hero() in inc/sportspress.php),
 * then SportsPress's own the_content()-injected sections (player details,
 * season stats table, past teams -- whichever are enabled).
 *
 * The shared skeleton (sidebar-active check, hero, `.bl-content-layout`
 * wrapper, comments) lives in blueline_render_sp_single() -- see that
 * function's own docblock in inc/sportspress.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

blueline_render_sp_single( 'blueline_sp_player_hero' );

get_footer();
