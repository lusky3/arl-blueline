<?php
/**
 * Full article body for a single post.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'bl-entry' ); ?>>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="bl-entry__thumbnail">
			<?php the_post_thumbnail( 'large' ); ?>
		</div>
	<?php endif; ?>

	<header class="bl-entry__header">
		<?php the_title( '<h1 class="bl-entry__title">', '</h1>' ); ?>
		<?php blueline_entry_meta(); ?>
	</header>

	<div class="entry-content bl-entry__content">
		<?php
		the_content();

		blueline_page_links();
		?>
	</div>

	<?php $tags_list = get_the_tag_list( '', ', ' ); ?>
	<?php if ( $tags_list ) : ?>
		<footer class="bl-entry__footer">
			<div class="bl-entry__tags">
				<span class="screen-reader-text"><?php esc_html_e( 'Tagged:', 'blueline' ); ?></span>
				<?php echo wp_kses_post( $tags_list ); ?>
			</div>
		</footer>
	<?php endif; ?>
</article>
