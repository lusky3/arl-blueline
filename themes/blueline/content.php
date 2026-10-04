<?php
/**
 * Post teaser used by the blog index, archives and search results.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

$bl_post_type = (string) get_post_type();
$bl_sp_label  = blueline_sp_post_card_label( $bl_post_type, 'sp_event' === $bl_post_type ? get_the_date() : '' );
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
			<?php if ( $bl_sp_label ) : ?>
				<div class="bl-entry-meta"><span class="bl-entry-meta__byline"><?php echo esc_html( $bl_sp_label ); ?></span></div>
			<?php endif; ?>
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

		<?php if ( blueline_post_card_shows_excerpt( $bl_post_type, has_excerpt() ) ) : ?>
			<div class="bl-post-card__excerpt">
				<?php the_excerpt(); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $bl_sp_label || 'sp_event' === $bl_post_type ) : ?>
			<a class="bl-post-card__more" href="<?php the_permalink(); ?>">
				<?php
				printf(
					/* translators: %s: post title, visually hidden for screen readers. */
					esc_html__( 'Continue reading %s', 'blueline' ),
					'<span class="screen-reader-text">' . esc_html( get_the_title() ) . '</span>'
				);
				?>
			</a>
		<?php endif; ?>
	</div>
</article>
