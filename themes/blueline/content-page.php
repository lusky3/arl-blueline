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

	<?php
	// The claim nudge is scoped to the site's configured standings page
	// only -- every other static Page uses this same template, so the
	// gate lives here, at the one call site, rather than inside
	// blueline_render_claim_nudge() itself (which has no page context of
	// its own to check).
	if ( function_exists( 'blueline_is_standings_page' ) && blueline_is_standings_page() && function_exists( 'blueline_render_claim_nudge' ) ) {
		blueline_render_claim_nudge();
	}
	?>

	<div class="entry-content bl-entry__content">
		<?php
		the_content();

		blueline_page_links();
		?>
	</div>
</article>
