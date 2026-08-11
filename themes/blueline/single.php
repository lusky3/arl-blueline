<?php
/**
 * Template for displaying a single post.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = is_active_sidebar( 'sidebar-1' );
?>
<main id="main" class="bl-main">
	<div class="bl-container">
		<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
			<div class="bl-content-layout__primary">
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
			</div>
			<?php if ( $has_sidebar ) : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
