<?php
/**
 * Single sp_team: a custom masthead (crest, division -- see
 * blueline_sp_team_hero() in inc/sportspress.php), then SportsPress's own
 * the_content()-injected sections: roster (via this theme's own
 * sportspress/team-lists.php override), standings (the team's row
 * highlighted), and schedule (fixtures and results, both status-correct --
 * see event-fixtures-results.php's own 'future'-status fixtures query).
 *
 * Finding 13: the team's colour custom properties are printed a second time
 * here, on <main> itself, so they are visible to the_content()'s own
 * league-table markup too -- a SIBLING of the hero <header>, not one of its
 * descendants, so a property declared only on the header could never reach
 * it. blueline_team_color_style_attr() itself (inc/team-colors.php) is
 * untouched -- same derivation, same guard, just read from a wider scope.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar     = function_exists( 'blueline_sp_has_sidebar' ) && blueline_sp_has_sidebar();
$team_color_attr = function_exists( 'blueline_team_color_style_attr' )
	? blueline_team_color_style_attr( get_queried_object_id() )
	: '';
?>
<main id="main" class="bl-main bl-main--sp bl-main--sp-hero" tabindex="-1"<?php echo $team_color_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blueline_team_color_style_attr() returns a complete, esc_attr()'d style attribute built only from hex values it validated itself. ?>>
	<?php
	while ( have_posts() ) :
		the_post();

		if ( function_exists( 'blueline_sp_team_hero' ) ) {
			blueline_sp_team_hero( get_the_ID() );
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
