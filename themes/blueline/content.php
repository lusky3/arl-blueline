<?php
/**
 * Post teaser used by the blog index, archives and search results.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'bl-post-card' ); ?>>

	<?php if ( has_post_thumbnail() ) : ?>
		<a class="bl-post-card__thumb" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
			<?php the_post_thumbnail( 'medium_large' ); ?>
		</a>
	<?php endif; ?>

	<div class="bl-post-card__body">
		<header class="bl-post-card__header">
			<?php blueline_entry_meta(); ?>
			<?php
			the_title(
				sprintf(
					'<h2 class="bl-post-card__title"><a href="%s" rel="bookmark">',
					esc_url( get_permalink() )
				),
				'</a></h2>'
			);
			?>
		</header>

		<div class="bl-post-card__excerpt">
			<?php the_excerpt(); ?>
		</div>

		<a class="bl-post-card__more" href="<?php the_permalink(); ?>">
			<?php
			printf(
				/* translators: %s: post title, visually hidden for screen readers. */
				esc_html__( 'Continue reading %s', 'blueline' ),
				'<span class="screen-reader-text">' . esc_html( get_the_title() ) . '</span>'
			);
			?>
		</a>
	</div>
</article>
