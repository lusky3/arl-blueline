<?php
/**
 * Shown when a query (blog index, archive or search) has no posts.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="bl-empty-state">
	<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>

	<?php if ( is_search() ) : ?>

		<h1 class="bl-empty-state__title"><?php esc_html_e( 'No results found', 'blueline' ); ?></h1>
		<p class="bl-empty-state__text">
			<?php esc_html_e( 'We couldn’t find anything matching your search. Try a different term below.', 'blueline' ); ?>
		</p>
		<?php get_search_form(); ?>

	<?php elseif ( is_home() && current_user_can( 'publish_posts' ) ) : ?>

		<h1 class="bl-empty-state__title"><?php esc_html_e( 'Nothing posted yet', 'blueline' ); ?></h1>
		<p class="bl-empty-state__text">
			<?php
			printf(
				wp_kses(
					/* translators: %s: URL of the new-post screen. */
					__( 'Ready to publish your first post? <a href="%s">Get started here</a>.', 'blueline' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( admin_url( 'post-new.php' ) )
			);
			?>
		</p>

	<?php else : ?>

		<h1 class="bl-empty-state__title"><?php esc_html_e( 'Nothing here yet', 'blueline' ); ?></h1>
		<p class="bl-empty-state__text">
			<?php esc_html_e( 'We couldn’t find anything to show. Try searching instead.', 'blueline' ); ?>
		</p>
		<?php get_search_form(); ?>

	<?php endif; ?>
</section>
