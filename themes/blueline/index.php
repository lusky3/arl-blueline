<?php
/**
 * Fallback template.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

// null: this template has no sidebar concept at all -- see
// blueline_page_wrapper_start()'s own docblock for why that renders just
// <main><div class="bl-container">, with no .bl-content-layout wrapper.
blueline_page_wrapper_start( null );
?>
			<?php if ( have_posts() ) : ?>

				<div class="bl-post-list">
					<?php
					while ( have_posts() ) {
						the_post();
						get_template_part( 'content', get_post_format() );
					}
					?>
				</div>

				<?php blueline_pagination(); ?>

			<?php else : ?>

				<?php get_template_part( 'content', 'none' ); ?>

			<?php endif; ?>
<?php
blueline_page_wrapper_end( null );

get_footer();
