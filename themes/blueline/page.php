<?php
/**
 * Template for displaying pages.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = is_active_sidebar( 'sidebar-1' );
?>
<main id="main" class="bl-main" tabindex="-1">
	<div class="bl-container">
		<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
			<div class="bl-content-layout__primary">
				<?php
				while ( have_posts() ) :
					the_post();

					get_template_part( 'content', 'page' );

					if ( comments_open() || get_comments_number() ) :
						comments_template();
					endif;
				endwhile;
				?>
			</div>
			<?php if ( $has_sidebar ) : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
