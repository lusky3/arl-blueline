<?php
/**
 * Template for displaying search results.
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
						<h1 class="bl-archive-header__title">
							<?php
							/* translators: %s: search query, already escaped by get_search_query(). */
							printf( esc_html__( 'Search results for: %s', 'blueline' ), '<span>' . get_search_query() . '</span>' );
							?>
						</h1>
					</header>

					<?php
					/*
					 * The empty-results branch below (content-none.php, via
					 * get_template_part( 'content', 'none' )) already prints a
					 * search form -- this branch, when there ARE results, had
					 * none at all: no way to refine or repeat a search without
					 * navigating away first. Confirmed live, 2026-09-04 UX
					 * audit.
					 */
					get_search_form();
					?>

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
