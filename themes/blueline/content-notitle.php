<?php
/**
 * Content with a featured image but no title.
 *
 * Kept under this exact filename because SportsPress entity pages resolve
 * to it by convention; Task 8 builds on it.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'bl-entry' ); ?>>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="bl-entry__thumbnail">
			<?php if ( ! is_single() ) : ?>
				<a href="<?php the_permalink(); ?>">
					<?php the_post_thumbnail( 'large' ); ?>
				</a>
			<?php else : ?>
				<?php the_post_thumbnail( 'large' ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="entry-content bl-entry__content">
		<?php
		the_content();

		blueline_page_links();
		?>
	</div>
</article>
