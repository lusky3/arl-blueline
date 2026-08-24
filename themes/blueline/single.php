<?php
/**
 * Template for displaying a single post.
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

					get_template_part( 'content', 'single' );

					the_post_navigation(
						array(
							/* translators: %title: title of the previous post. */
							'prev_text' => '<span class="bl-post-nav__label">' . esc_html__( 'Previous', 'blueline' ) . '</span><span class="bl-post-nav__title">%title</span>',
							/* translators: %title: title of the next post. */
							'next_text' => '<span class="bl-post-nav__label">' . esc_html__( 'Next', 'blueline' ) . '</span><span class="bl-post-nav__title">%title</span>',
						)
					);

					if ( comments_open() || get_comments_number() ) :
						comments_template();
					endif;
				endwhile;
				?>
<?php
blueline_page_wrapper_end( $has_sidebar );

get_footer();
