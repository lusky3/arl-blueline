<?php
/**
 * Theme override of SportsPress's per-league player statistics table
 * (player-statistics.php calls sp_get_template('player-statistics-league.php',
 * $args) once per league the player belongs to, and once more for "Career
 * Total" when sportspress_player_show_career_total is enabled -- $data,
 * $caption, $scrollable, $league_id and $hide_teams all arrive the same way,
 * via sp_get_template()'s own extract()).
 *
 * When a player has no recorded statistics at all, SportsPress's
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
 * Overrides SportsPress templates/player-statistics-league.php, core template version 2.5 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 * @version 2.5 (copied from SportsPress Pro 2.7.29: sportspress-pro/includes/sportspress/templates/player-statistics-league.php)
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

// Stat columns that are blank or dashes in every row are just as empty.
// Inside a player's whole stats section the line is held back, so a player
// with nothing anywhere gets one empty state, not one per table
// (inc/sportspress/player-stats.php).
if ( empty( $stat_keys ) || ! blueline_sp_stat_rows_have_values( $data, $stat_keys ) ) {
	blueline_sp_player_stats_print( blueline_sp_player_stats_collect( 'empty', (string) ( $caption ?? '' ) ) );
	return;
}

blueline_sp_player_stats_print( blueline_sp_player_stats_collect( 'table' ) );

// Heading level depends on where this template is actually rendered -- see
// blueline_sp_caption_heading_level()'s own docblock
// (inc/sportspress/core.php) for why (a hardcoded h4 would skip heading
// levels -- on a single-player page it would follow the hero's own h1 with
// nothing else in between).
$bl_caption_level = blueline_sp_caption_heading_level();

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
