<?php
/**
 * Fallback template.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

// null: this template has no sidebar concept at all -- see
// blueline_page_wrapper_start()'s own docblock for why that renders just
// <main><div class="bl-container">, with no .bl-content-layout wrapper.
blueline_page_wrapper_start( null );
?>
			<?php
			/*
			 * The one heading this fallback was missing: when a static Page
			 * is configured as the site's blog index (Settings > Reading >
			 * "Posts page"), WordPress serves it through home.php, or -- as
			 * here, since this theme has none -- this file, NOT archive.php,
			 * so archive.php's own the_archive_title() never ran for it.
			 * Confirmed live, 2026-09-04 UX audit: /news rendered with no
			 * <h1> anywhere on the page. single_post_title() is the
			 * standard WP idiom for this exact case (also is_front_page()'s
			 * own theme's news landing, if ever repointed there, already
			 * prints its own h1 elsewhere, so this is scoped OFF that case
			 * to avoid a second, duplicate one).
			 */
			if ( is_home() && ! is_front_page() ) :
				?>
				<header class="bl-archive-header">
					<h1 class="bl-archive-header__title"><?php single_post_title(); ?></h1>
				</header>
				<?php
			endif;
			?>
			<?php if ( have_posts() ) : ?>

				<div class="bl-post-list">
					<?php
					while ( have_posts() ) {
						the_post();
						get_template_part( 'content', get_post_format() );
					}
					?>
				</div>

				<?php blueline_pagination(); ?>

			<?php else : ?>

				<?php get_template_part( 'content', 'none' ); ?>

			<?php endif; ?>
<?php
blueline_page_wrapper_end( null );

get_footer();
