<?php
/**
 * Theme override of SportsPress's per-league player statistics table
 * (player-statistics.php calls sp_get_template('player-statistics-league.php',
 * $args) once per league the player belongs to, and once more for "Career
 * Total" when sportspress_player_show_career_total is enabled -- $data,
 * $caption, $scrollable, $league_id and $hide_teams all arrive the same way,
 * via sp_get_template()'s own extract()).
 *
 * Finding 7: when a player has no recorded statistics at all, SportsPress's
 * own template still renders a table -- confirmed live, /player/alec-lehto's
 * "Career Total" is a full-width `.sp-highlight` bar containing the single
 * word "Total" and nothing else, because $data's own label row ($data[0])
 * only ever contained a 'name' (Season) column: no stat keys were computed
 * for this player at all, so `empty($data)` after removing that label row is
 * still false (one degenerate row survives) and the stock template's own
 * `if (empty($data)) return;` guard never fires. This override adds the
 * missing check -- a table with no stat columns beyond name/team gets the
 * documented empty state (leaf mark + one line) instead of a table that is
 * visually a full-width colour bar around one word.
 *
 * Every league/career call that DOES have real stat columns renders through
 * the exact same markup SportsPress's own template would produce -- this is
 * additive, not a redesign.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $data ) || ! is_array( $data ) || empty( $data[0] ) ) {
	return;
}

$labels = $data[0];
unset( $data[0] );

if ( empty( $data ) ) {
	return;
}

// Real stat columns are anything other than 'name' (the season/career-row
// label) and 'team' (hidden anyway when $hide_teams is set) -- a table with
// none of those has nothing to show but a season label, which is exactly
// the degenerate case this override exists to catch.
$stat_keys = array_diff( array_keys( $labels ), array( 'name', 'team' ) );

if ( empty( $stat_keys ) ) {
	?>
	<div class="bl-sp-empty">
		<?php
		if ( function_exists( 'blueline_leaf_mark' ) ) {
			blueline_leaf_mark( 'bl-sp-empty__mark' );
		}
		?>
		<p class="bl-sp-empty__text">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: table caption, e.g. "Career Total" or a league name. */
					__( '%s not recorded yet.', 'blueline' ),
					wp_strip_all_tags( (string) ( $caption ?? '' ) )
				)
			);
			?>
		</p>
	</div>
	<?php
	return;
}

// Heading level depends on where this template is actually rendered -- see
// blueline_sp_caption_heading_level()'s own docblock (inc/sportspress.php)
// for the accessibility finding this fixes (this caption used to be a
// hardcoded, level-skipping h4 everywhere -- on a single-player page it
// followed the hero's own h1 with nothing else in between).
$bl_caption_level = function_exists( 'blueline_sp_caption_heading_level' ) ? blueline_sp_caption_heading_level() : 3;

$output = '<h' . $bl_caption_level . ' class="sp-table-caption">' . $caption . '</h' . $bl_caption_level . '>' .
	'<div class="sp-table-wrapper">' .
	'<table class="sp-player-statistics sp-data-table' . ( $scrollable ? ' sp-scrollable-table' : '' ) . '"><thead><tr>';

foreach ( $labels as $key => $label ) :
	if ( isset( $hide_teams ) && 'team' === $key ) {
		continue;
	}
	$output .= '<th class="data-' . $key . '">' . $label . '</th>';
endforeach;

$output .= '</tr></thead><tbody>';

$i = 0;

foreach ( $data as $season_id => $row ) :

	$output .= '<tr class="' . ( 0 === $i % 2 ? 'odd' : 'even' ) . '">';

	foreach ( $labels as $key => $value ) :
		if ( isset( $hide_teams ) && 'team' === $key ) {
			continue;
		}
		$output .= '<td class="data-' . $key . ( -1 === $season_id ? ' sp-highlight' : '' ) . '">' . sp_array_value( $row, $key, '' ) . '</td>';
	endforeach;

	$output .= '</tr>';

	++$i;

endforeach;

$output .= '</tbody></table></div>';
?>
<div class="sp-template sp-template-player-statistics">
	<?php echo wp_kses_post( $output ); ?>
</div>
