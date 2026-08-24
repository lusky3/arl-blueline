<?php
/**
 * Template for displaying pages.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = is_active_sidebar( 'sidebar-1' );

blueline_page_wrapper_start( $has_sidebar );
?>
				<?php
				while ( have_posts() ) :
					the_post();

					get_template_part( 'content', 'page' );

					if ( comments_open() || get_comments_number() ) :
						comments_template();
					endif;
				endwhile;
				?>
<?php
blueline_page_wrapper_end( $has_sidebar );

get_footer();
