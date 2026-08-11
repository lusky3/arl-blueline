<?php
/**
 * Single sp_player: a custom masthead (number, position from the taxonomy,
 * current team -- see blueline_sp_player_hero() in inc/sportspress.php),
 * then SportsPress's own the_content()-injected sections (player details,
 * season stats table, past teams -- whichever are enabled).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = function_exists( 'blueline_sp_has_sidebar' ) && blueline_sp_has_sidebar();
?>
<main id="main" class="bl-main bl-main--sp bl-main--sp-hero">
	<?php
	while ( have_posts() ) :
		the_post();

		if ( function_exists( 'blueline_sp_player_hero' ) ) {
			blueline_sp_player_hero( get_the_ID() );
		}
		?>
		<div class="bl-container">
			<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
				<div class="bl-content-layout__primary">
					<div class="entry-content bl-entry__content">
						<?php the_content(); ?>
					</div>
					<?php
					if ( comments_open() || get_comments_number() ) :
						comments_template();
					endif;
					?>
				</div>
				<?php if ( $has_sidebar ) : ?>
					<?php get_sidebar(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
