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

$blueline_state      = blueline_season_state();
$blueline_state_data = blueline_season_state_data();
?>
<main id="main" class="bl-main bl-main--homepage" tabindex="-1">
	<?php
	// blueline_render_hero() can render a different EFFECTIVE state than the
	// one requested (e.g. registration_open falls back to preseason/offseason
	// when the product fails live re-verification) -- module order is chosen
	// from that same effective state, not the raw one, so the module stack
	// never disagrees with the hero actually shown above it. $blueline_state_data
	// is passed through too so the module order can also see is_playing --
	// registration_open and "games are being played" are independent facts
	// (P1 finding 4) the effective state alone cannot carry.
	$blueline_effective_state = blueline_render_hero( $blueline_state );

	foreach ( blueline_homepage_module_order( $blueline_effective_state, $blueline_state_data ) as $blueline_module ) {
		blueline_render_module( $blueline_module );
	}
	?>
</main>
<?php
get_footer();
