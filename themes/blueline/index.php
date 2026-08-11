<?php
/**
 * Fallback template.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="bl-main" role="main">
	<?php
	if ( have_posts() ) {
		while ( have_posts() ) {
			the_post();
			get_template_part( 'content', get_post_format() );
		}
	} else {
		get_template_part( 'content', 'none' );
	}
	?>
</main>
<?php
get_footer();
