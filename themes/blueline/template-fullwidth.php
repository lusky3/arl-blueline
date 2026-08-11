<?php
/**
 * Template Name: Full Width
 *
 * Full-bleed page template, no sidebar. SportsPress entity post types
 * (sp_player, sp_staff, sp_team) render through content-nothumb.php --
 * the previous Rookie theme's convention for assigning this template to
 * team/player/staff pages, kept here for Task 8 to build on.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="bl-main" tabindex="-1">
	<div class="bl-container">
		<?php
		while ( have_posts() ) :
			the_post();

			if ( in_array( get_post_type(), array( 'sp_player', 'sp_staff', 'sp_team' ), true ) ) {
				get_template_part( 'content', 'nothumb' );
			} else {
				get_template_part( 'content', 'page' );
			}
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
