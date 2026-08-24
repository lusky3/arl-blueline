<?php
/**
 * Root archive/singular fallback for SportsPress.
 *
 * SP_Template_Loader appends 'sportspress.php' to every candidate list it
 * builds, so this file is reached whenever a more specific override is
 * missing: an SP taxonomy archive without its own taxonomy-*.php (e.g.
 * sp_league, sp_season, sp_position, sp_role -- only sp_venue gets a
 * dedicated template), or a singular view of an SP post type that has no
 * sportspress/single-*.php override (sp_table, sp_list, sp_calendar --
 * sp_event/sp_player/sp_team/sp_staff always resolve to their own
 * dedicated templates first and never reach this file).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = function_exists( 'blueline_sp_has_sidebar' ) && blueline_sp_has_sidebar();

blueline_page_wrapper_start( $has_sidebar, 'bl-main--sp' );
?>
				<?php if ( is_archive() ) : ?>

					<header class="bl-archive-header">
						<?php
						the_archive_title( '<h1 class="bl-archive-header__title">', '</h1>' );
						the_archive_description( '<div class="bl-archive-header__description">', '</div>' );
						?>
					</header>

					<?php if ( have_posts() ) : ?>
						<ul class="bl-sp-archive-list">
							<?php
							while ( have_posts() ) :
								the_post();
								?>
								<li class="bl-sp-archive-list__item">
									<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
								</li>
								<?php
							endwhile;
							?>
						</ul>
						<?php blueline_pagination(); ?>
					<?php else : ?>
						<?php
						// This template already rendered its own H1 above
						// (the_archive_title(), in the header just above),
						// so content-none.php's own heading must not be a
						// second H1 on the page.
						get_template_part( 'content', 'none', array( 'heading_level' => 'h2' ) );
						?>
					<?php endif; ?>

				<?php elseif ( have_posts() ) : ?>

					<?php
					while ( have_posts() ) :
						the_post();
						get_template_part( 'content', 'nothumb' );

						if ( comments_open() || get_comments_number() ) :
							comments_template();
						endif;
					endwhile;
					?>

				<?php endif; ?>
<?php
blueline_page_wrapper_end( $has_sidebar );

get_footer();
