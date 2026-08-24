<?php
/**
 * Shown when a query (blog index, archive or search) has no posts.
 *
 * Most callers (archive.php, search.php, index.php) reach this template only
 * from a branch where they rendered no heading of their own, so this
 * template's heading is the page's only H1 by default. A caller that has
 * ALREADY rendered its own H1 before falling back to this template --
 * sportspress/taxonomy-venue.php renders `the_archive_title()` unconditionally,
 * then calls this template only when the venue has zero events -- must pass
 * `array( 'heading_level' => 'h2' )` as get_template_part()'s third argument
 * to avoid a second H1 on that page.
 *
 * @package blueline
 *
 * @param string $heading_level HTML tag for this template's own heading.
 *                               'h1' (default) when this is the page's only
 *                               heading, 'h2' when a caller already rendered
 *                               an H1 before reaching this template.
 */

defined( 'ABSPATH' ) || exit;

$heading_level = isset( $args['heading_level'] ) ? (string) $args['heading_level'] : 'h1';
?>
<section class="bl-empty-state">
	<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>

	<?php if ( is_search() ) : ?>

		<<?php echo tag_escape( $heading_level ); ?> class="bl-empty-state__title"><?php esc_html_e( 'No results found', 'blueline' ); ?></<?php echo tag_escape( $heading_level ); ?>>
		<p class="bl-empty-state__text">
			<?php esc_html_e( 'We couldn’t find anything matching your search. Try a different term below.', 'blueline' ); ?>
		</p>
		<?php get_search_form(); ?>

	<?php elseif ( is_home() && current_user_can( 'publish_posts' ) ) : ?>

		<<?php echo tag_escape( $heading_level ); ?> class="bl-empty-state__title"><?php esc_html_e( 'Nothing posted yet', 'blueline' ); ?></<?php echo tag_escape( $heading_level ); ?>>
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

		<<?php echo tag_escape( $heading_level ); ?> class="bl-empty-state__title"><?php esc_html_e( 'Nothing here yet', 'blueline' ); ?></<?php echo tag_escape( $heading_level ); ?>>
		<p class="bl-empty-state__text">
			<?php esc_html_e( 'We couldn’t find anything to show. Try searching instead.', 'blueline' ); ?>
		</p>
		<?php get_search_form(); ?>

	<?php endif; ?>
</section>
