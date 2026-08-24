<?php
/**
 * Content with a title but no featured image.
 *
 * Kept under this exact filename because SportsPress entity pages resolve
 * to it by convention (see template-fullwidth.php); Task 8 builds on it.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'bl-entry' ); ?>>

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
