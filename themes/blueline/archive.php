<?php
/**
 * Template for displaying archive pages (category, tag, date, author...).
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
			</div>
			<?php if ( $has_sidebar ) : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
