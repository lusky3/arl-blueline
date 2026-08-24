<?php
/**
 * Template for displaying archive pages (category, tag, date, author...).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = is_active_sidebar( 'sidebar-1' );

blueline_page_wrapper_start( $has_sidebar );
?>
				<?php if ( have_posts() ) : ?>

					<header class="bl-archive-header">
						<?php
						the_archive_title( '<h1 class="bl-archive-header__title">', '</h1>' );
						the_archive_description( '<div class="bl-archive-header__description">', '</div>' );
						?>
					</header>

					<div class="bl-post-list">
						<?php
						while ( have_posts() ) :
							the_post();
							get_template_part( 'content', get_post_format() );
						endwhile;
						?>
					</div>

					<?php blueline_pagination(); ?>

				<?php else : ?>

					<?php get_template_part( 'content', 'none' ); ?>

				<?php endif; ?>
<?php
blueline_page_wrapper_end( $has_sidebar );

get_footer();
