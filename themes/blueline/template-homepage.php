<?php
/**
 * Template Name: Homepage
 *
 * Season-aware homepage. One hero variant and one module order is selected
 * from blueline_season_state() (Task 6) -- nobody edits this page twice a
 * year for that to happen. Assign this template to the static front page
 * under Settings > Reading.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$blueline_state = function_exists( 'blueline_season_state' ) ? blueline_season_state() : 'offseason';
?>
<main id="main" class="bl-main bl-main--homepage">
	<?php
	blueline_render_hero( $blueline_state );

	foreach ( blueline_homepage_module_order( $blueline_state ) as $blueline_module ) {
		blueline_render_module( $blueline_module );
	}
	?>
</main>
<?php
get_footer();
