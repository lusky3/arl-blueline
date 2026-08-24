<?php
/**
 * Content for a static Page.
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
	</header>

	<div class="entry-content bl-entry__content">
		<?php
		the_content();

		blueline_page_links();
		?>
	</div>
</article>
