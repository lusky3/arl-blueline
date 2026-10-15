<?php
/**
 * 404 (not found) template.
 *
 * A friendly empty state using the blue-leaf mark, pointing visitors at
 * Schedule, Standings and Register rather than leaving them stranded.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="bl-main" tabindex="-1">
	<div class="bl-container">
		<section class="bl-empty-state bl-empty-state--404">
			<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>

			<h1 class="bl-empty-state__title"><?php esc_html_e( 'That page took a bad bounce', 'blueline' ); ?></h1>
			<p class="bl-empty-state__text">
				<?php esc_html_e( 'We couldn’t find the page you were looking for. Here are a few places to start instead.', 'blueline' ); ?>
			</p>

			<?php blueline_empty_state_links(); ?>

			<?php get_search_form(); ?>
		</section>
	</div>
</main>
<?php
get_footer();
