<?php
/**
 * Single sp_staff: a custom masthead (role, teams -- see
 * blueline_sp_staff_hero() in inc/sportspress.php), then SportsPress's own
 * the_content()-injected staff-details section.
 *
 * The shared skeleton (sidebar-active check, hero, `.bl-content-layout`
 * wrapper, comments) lives in blueline_render_sp_single() -- see that
 * function's own docblock in inc/sportspress.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

blueline_render_sp_single( 'blueline_sp_staff_hero' );

get_footer();
