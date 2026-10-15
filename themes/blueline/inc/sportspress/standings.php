<?php
/**
 * SportsPress integration: standings-table helpers for
 * sportspress/league-table.php -- the viewer's own team row, header
 * abbreviations, and the Record legend.
 *
 * The viewer's own row. league-table.php marks it while it renders, which is
 * right for the theme's own pages: their cache holds table data only. The
 * SportsPress Events Manager plugin's [arl_standings] instead caches the
 * finished HTML and serves that one copy to every visitor. While it renders
 * that copy it turns 'spem_standings_shared_render' on, and the template then
 * prints only viewer-neutral markup: a data-team-id on every row and a hidden
 * "(your team)" label in every team cell. The plugin runs
 * 'spem_standings_html' on every request after its cache read (page load and
 * the season/type switch alike), and blueline_sp_standings_viewer_html()
 * marks the viewer's rows there, so nothing per-viewer is ever cached. With
 * the plugin inactive neither hook fires and the template behaves as before.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'spem_standings_html', 'blueline_sp_standings_viewer_html', 10, 3 );

/**
 * Whether the table is being rendered for a copy shared between viewers.
 * False unless the SportsPress Events Manager plugin says otherwise.
 *
 * @return bool
 */
function blueline_sp_standings_shared_render(): bool {
	return (bool) apply_filters( 'spem_standings_shared_render', false );
}

/**
 * The "(your team)" label for a team cell, so the viewer's row is announced,
 * not only tinted. In a shared copy every row carries it hidden, and
 * blueline_sp_standings_mark_viewer_rows() reveals the viewer's.
 *
 * @param bool $is_mine Whether this row is the current viewer's team.
 * @param bool $shared  Whether the table is rendered for a shared copy.
 * @return string Markup, or '' when there is nothing to print.
 */
function blueline_sp_standings_mine_label( bool $is_mine, bool $shared ): string {
	if ( $is_mine ) {
		return ' <span class="bl-sp-mine-label screen-reader-text">' . esc_html__( '(your team)', 'blueline' ) . '</span>';
	}

	return $shared ? ' <span class="bl-sp-mine-label" hidden>' . esc_html__( '(your team)', 'blueline' ) . '</span>' : '';
}

/**
 * 'spem_standings_html' callback: mark the current viewer's own team rows in
 * the plugin's shared standings copy, per request.
 *
 * @param string $html   Viewer-neutral standings HTML.
 * @param string $season Base season slug (unused).
 * @param string $type   'regular' or 'playoff' (unused).
 * @return string
 */
function blueline_sp_standings_viewer_html( $html, $season = '', $type = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the plugin passes all three; the rows carry their own team ids.
	$team_ids = function_exists( 'blueline_current_user_team_ids' ) ? blueline_current_user_team_ids() : array();

	return $team_ids ? blueline_sp_standings_mark_viewer_rows( (string) $html, $team_ids ) : $html;
}

/**
 * Add the "your team" class and team colours to every <tr data-team-id> whose
 * team is one of $team_ids, and reveal the hidden label inside those rows.
 *
 * @param string $html     Standings HTML.
 * @param int[]  $team_ids The viewer's current team ids.
 * @return string
 */
function blueline_sp_standings_mark_viewer_rows( string $html, array $team_ids ): string {
	$team_ids = array_filter( array_map( 'absint', $team_ids ) );

	if ( ! $team_ids || false === strpos( $html, 'data-team-id' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$tags    = new WP_HTML_Tag_Processor( $html );
	$in_mine = false;
	$changed = false;

	while ( $tags->next_tag() ) {
		if ( 'TR' === $tags->get_tag() ) {
			$team_id = $tags->get_attribute( 'data-team-id' );
			$team_id = is_string( $team_id ) ? absint( $team_id ) : 0;
			$in_mine = $team_id && in_array( $team_id, $team_ids, true );

			if ( $in_mine ) {
				$tags->add_class( 'bl-sp-row--mine' );
				$style = blueline_team_color_style_value( $team_id );

				if ( '' !== $style ) {
					$tags->set_attribute( 'style', $style );
				}
				$changed = true;
			}
			continue;
		}

		if ( $in_mine && 'SPAN' === $tags->get_tag() && $tags->has_class( 'bl-sp-mine-label' ) ) {
			$tags->remove_attribute( 'hidden' );
			$tags->add_class( 'screen-reader-text' );
		}
	}

	return $changed ? $tags->get_updated_html() : $html;
}

/**
 * What each SportsPress standings column key stands for, for the abbreviated
 * labels the league table prints (GP, PTS, L10...).
 *
 * @return array<string,string> Column key => expansion.
 */
function blueline_sp_standings_column_names(): array {
	return array(
		'pos'  => __( 'Position', 'blueline' ),
		'gp'   => __( 'Games played', 'blueline' ),
		'w'    => __( 'Wins', 'blueline' ),
		'l'    => __( 'Losses', 'blueline' ),
		'ot'   => __( 'Overtime losses', 'blueline' ),
		'pts'  => __( 'Points', 'blueline' ),
		'gf'   => __( 'Goals for', 'blueline' ),
		'ga'   => __( 'Goals against', 'blueline' ),
		'diff' => __( 'Goal difference', 'blueline' ),
		'lten' => __( 'Last 10 games', 'blueline' ),
		'strk' => __( 'Streak', 'blueline' ),
	);
}

/**
 * Header cell content for one standings column: the label in an <abbr> with
 * its expansion, plus the expansion as visually hidden text. The text form
 * matters because SportsPress's sortable tables (DataTables) rebuild each
 * header's aria-label from its text and drop the title.
 *
 * @param string $key   Column key.
 * @param string $label Column label as SportsPress configured it.
 * @return string Escaped markup.
 */
function blueline_sp_standings_header_html( string $key, string $label ): string {
	$names = blueline_sp_standings_column_names();
	$name  = $names[ $key ] ?? '';

	if ( '' === $name || 0 === strcasecmp( $name, $label ) ) {
		return esc_html( $label );
	}

	return '<abbr title="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</abbr>'
		. '<span class="screen-reader-text"> (' . esc_html( strtolower( $name ) ) . ')</span>';
}

/**
 * One-line legend for the synthesised Record column ("2-0-0-1"), naming the
 * columns it joins, in order.
 *
 * @param string[] $record_keys Keys the Record cell joins (w, l, tie, ot).
 * @return string Plain text.
 */
function blueline_sp_standings_record_legend( array $record_keys ): string {
	$parts = array(
		'w'   => array( __( 'W', 'blueline' ), __( 'wins', 'blueline' ) ),
		'l'   => array( __( 'L', 'blueline' ), __( 'losses', 'blueline' ) ),
		'tie' => array( __( 'T', 'blueline' ), __( 'ties', 'blueline' ) ),
		'ot'  => array( __( 'OTL', 'blueline' ), __( 'overtime losses', 'blueline' ) ),
	);

	$letters = array();
	$words   = array();

	foreach ( $record_keys as $key ) {
		if ( isset( $parts[ $key ] ) ) {
			$letters[] = $parts[ $key ][0];
			$words[]   = $parts[ $key ][1];
		}
	}

	/* translators: 1: record letters, e.g. "W-L-T-OTL", 2: what they stand for, e.g. "wins, losses, ties, overtime losses". */
	return sprintf( __( 'Record is %1$s: %2$s.', 'blueline' ), implode( '-', $letters ), implode( ', ', $words ) );
}
