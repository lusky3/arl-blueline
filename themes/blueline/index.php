<?php
/**
 * Fallback template.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="bl-main">
	<div class="bl-container">
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
	</div>
</main>
<?php
get_footer();
