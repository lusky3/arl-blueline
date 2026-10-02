<?php
/**
 * Theme override of SportsPress's league table template
 * ([team_standings]/[league_table], and the auto-injected mini standings on
 * every sp_team page via team-tables.php's own `highlight` call).
 *
 * Enhancement (finding 14): /standings is the site's most-visited page and
 * shows all thirteen SportsPress columns (Pos GP W L Tie OT PTS GF GA Diff
 * L10 Strk) for a beginner league -- the exact "every pro-sports site shows
 * all thirteen" category reflex this theme otherwise fights everywhere
 * else. This override renders every column SportsPress would (so nothing is
 * lost and the underlying data/highlight/limit logic is untouched -- this is
 * a copy of the stock template with two additions, not a redesign), adds a
 * synthesised "Record" column (W-L-T[-OT], from the same row data), and
 * marks the non-essential raw columns (GP, W, L, Tie, OT, GF, GA, Diff, L10,
 * Strk) with a class a pure-CSS checkbox toggle can hide -- Pos / Team /
 * Record / Points show by default; a "Show full stats" control (no
 * JavaScript required) reveals the rest and hides the now-redundant Record
 * column in its place.
 *
 * Overrides SportsPress templates/league-table.php, core template version 2.7.23 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

$defaults = array(
	'id'                   => get_the_ID(),
	'number'               => -1,
	'columns'              => null,
	'highlight'            => null,
	'show_full_table_link' => false,
	'title'                => false,
	'show_title'           => get_option( 'sportspress_table_show_title', 'yes' ) === 'yes',
	'show_team_logo'       => get_option( 'sportspress_table_show_logos', 'yes' ) === 'yes',
	'link_posts'           => null,
	'responsive'           => get_option( 'sportspress_enable_responsive_tables', 'no' ) === 'yes',
	'sortable'             => get_option( 'sportspress_enable_sortable_tables', 'yes' ) === 'yes',
	'scrollable'           => get_option( 'sportspress_enable_scrollable_tables', 'yes' ) === 'yes',
	'paginated'            => get_option( 'sportspress_table_paginated', 'yes' ) === 'yes',
	'rows'                 => get_option( 'sportspress_table_rows', 10 ),
);

extract( $defaults, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors sp_get_template()'s own extract() convention for the args this template receives; EXTR_SKIP never overwrites an already-set variable.

if ( ! isset( $link_posts ) ) {
	$link_posts = ( 'player' === sp_get_post_mode( $id ) )
		? get_option( 'sportspress_link_players', 'yes' ) === 'yes'
		: get_option( 'sportspress_link_teams', 'no' ) === 'yes';
}

if ( ! isset( $highlight ) ) {
	$highlight = get_post_meta( $id, 'sp_highlight', true );
}

$table = new SP_League_Table( $id );

if ( $show_title && false === $title && $id ) {
	$caption = $table->caption;
	$title   = $caption ? $caption : get_the_title( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is this template's documented extract()-provided argument name (mirrors the stock league-table.php this overrides), not a WordPress global.
}

if ( isset( $show_published_events ) ) {
	$table->show_published_events = $show_published_events;
}

if ( isset( $show_future_events ) ) {
	$table->show_future_events = $show_future_events;
}

$identifier = uniqid( 'table_' );

// PERF-03: the computed table (no per-viewer markup) is cached; highlighting below stays per request.
$bl_table_cache_key = ( $id && function_exists( 'blueline_sp_cache_key' ) && ! is_preview() )
	? blueline_sp_cache_key( 'table', array( (int) $id, $table->show_published_events ?? '', $table->show_future_events ?? '' ) )
	: '';
$data               = $bl_table_cache_key ? get_transient( $bl_table_cache_key ) : false;

if ( ! is_array( $data ) ) {
	$data = $table->data();

	if ( $bl_table_cache_key && is_array( $data ) && isset( $data[0] ) ) {
		set_transient( $bl_table_cache_key, $data, BLUELINE_SP_CACHE_TTL );
	}
}

$labels = $data[0];
unset( $data[0] );

if ( null === $columns ) {
	$columns = get_post_meta( $id, 'sp_columns', true );
}

if ( null !== $columns && ! is_array( $columns ) ) {
	$columns = explode( ',', $columns );
}

// Columns this override folds into a single synthesised "Record" cell,
// shown instead of them in the default (non-"full stats") view. Only keys
// that actually exist in this table's own $labels are used -- a league
// configured without, say, an OT column just gets a shorter W-L-T.
$bl_record_source = array( 'w', 'l', 'tie', 'ot' );
$bl_record_keys   = array();
foreach ( $bl_record_source as $bl_key ) {
	if ( isset( $labels[ $bl_key ] ) && ( ! is_array( $columns ) || in_array( $bl_key, $columns, true ) ) ) {
		$bl_record_keys[] = $bl_key;
	}
}
$bl_has_record = count( $bl_record_keys ) >= 2; // Need at least W and L for "Record" to mean anything.

// Every other non-pos/non-name column (gp, gf, ga, diff, lten, strk, plus
// w/l/tie/ot themselves once Record exists) is "extra" -- present, but
// hidden until the full-stats toggle is checked.
$bl_extra_keys = $bl_has_record ? $bl_record_keys : array();
foreach ( $labels as $bl_key => $bl_label ) {
	if ( in_array( $bl_key, array( 'pos', 'name', 'pts' ), true ) ) {
		continue;
	}
	if ( ! in_array( $bl_key, $bl_extra_keys, true ) ) {
		$bl_extra_keys[] = $bl_key;
	}
}
$bl_show_toggle = $bl_has_record && ! empty( $bl_extra_keys );

/*
 * Finding 7 (live review): a division whose season/playoff round hasn't
 * started yet renders every team at position 0 with a 0-0-0-0 record and
 * no explanatory text at all -- confirmed live on "Division 5 | Playoffs
 * S2026" -- which reads as a broken table, not as "nothing has happened
 * here yet." Detected from the same computed $data this template already
 * renders (SP_League_Table::data(), not a second query): every listed
 * team's games-played figure is zero. Prefers the table's own 'gp' column
 * when present (the direct, unambiguous signal); falls back to every
 * record component (W/L/T/OT) being zero for a league configured without a
 * GP column at all. The actual decision is a pure function
 * (blueline_sp_zero_games_note(), inc/sportspress.php, unit tested) so it
 * is testable without a real SP_League_Table.
 */
$bl_has_progress_signal = isset( $labels['gp'] ) || ! empty( $bl_record_keys );
$bl_progress_figures    = array();

foreach ( $data as $bl_probe_row ) {
	if ( isset( $labels['gp'] ) ) {
		$bl_progress_figures[] = sp_array_value( $bl_probe_row, 'gp', 0 );
		continue;
	}

	foreach ( $bl_record_keys as $bl_probe_key ) {
		$bl_progress_figures[] = sp_array_value( $bl_probe_row, $bl_probe_key, 0 );
	}
}

$bl_zero_games_note = function_exists( 'blueline_sp_zero_games_note' )
	? blueline_sp_zero_games_note(
		$bl_has_progress_signal,
		$bl_progress_figures,
		! empty( $data ),
		(bool) $title && false !== stripos( (string) $title, 'playoff' )
	)
	: '';

// The viewer's own current team(s) -- possibly more than one, since a
// player can be rostered onto several current teams across divisions
// (blueline_current_user_team_ids()'s own docblock). Read ONCE, outside the
// row loop, not once per row: it is the same answer for every row this
// template renders.
$bl_my_team_ids = function_exists( 'blueline_current_user_team_ids' ) ? blueline_current_user_team_ids() : array();

// wp_kses_post()'s rules plus img srcset/sizes/decoding, so the logo's responsive sources survive.
$bl_kses_allowed = function_exists( 'blueline_sp_kses_allowed_html' ) ? blueline_sp_kses_allowed_html() : wp_kses_allowed_html( 'post' );

$output  = '<th class="data-rank">' . esc_attr__( 'Pos', 'sportspress' ) . '</th>';
$output .= '<th class="data-name">' . esc_html( $labels['name'] ) . '</th>';

if ( $bl_has_record ) {
	$output .= '<th class="data-record bl-sp-col-record">' . esc_html__( 'Record', 'blueline' ) . '</th>';
}

foreach ( $labels as $key => $label ) :
	if ( in_array( $key, array( 'pos', 'name' ), true ) ) {
		continue;
	}
	if ( is_array( $columns ) && ! in_array( $key, $columns, true ) ) {
		continue;
	}
	$bl_extra_class = blueline_sp_extra_class( $key, $bl_extra_keys );
	$output        .= '<th class="data-' . esc_attr( $key ) . esc_attr( $bl_extra_class ) . '">' . esc_html( $label ) . '</th>';
endforeach;

$output = '<thead><tr>' . $output . '</tr></thead><tbody>';

$i     = 0;
$start = 0;

if ( intval( $number ) > 0 ) :
	$limit = $number;

	if ( $highlight && count( $data ) > $limit && array_key_exists( $highlight, $data ) ) :
		$size  = count( $data );
		$key   = array_search( $highlight, array_keys( $data ), true );
		$start = $key - ceil( $limit / 2 ) + 1;
		if ( $start < 0 ) {
			$start = 0;
		}

		$trimmed = array_slice( $data, $start, $limit, true );

		if ( count( $trimmed ) < $limit && count( $trimmed ) < $size ) :
			$offset = $limit - count( $trimmed );
			$start -= $offset;
			if ( $start < 0 ) {
				$start = 0;
			}
			$trimmed = array_slice( $data, $start, $limit, true );
		endif;

		$data = $trimmed;
	endif;
endif;

foreach ( $data as $team_id => $row ) :

	if ( isset( $limit ) && $i >= $limit ) {
		continue;
	}

	$name = sp_array_value( $row, 'name', null );
	if ( ! $name ) {
		continue;
	}

	$tr_class = '';
	$td_class = '';
	if ( $highlight === $team_id ) :
		$tr_class = ' highlighted';
		$td_class = ' sp-highlight';
	endif;

	/*
	 * A distinct, per-VIEWER "this is your team" cue -- deliberately its own
	 * class, never folded into .sp-highlight/.highlighted above: those mark
	 * an admin-chosen "featured team" (sp_highlight post meta), a completely
	 * different, editorial concept that must keep meaning exactly that. The
	 * two can coexist -- an admin's featured team and the viewer's own team
	 * can be the same row, or different ones, with no conflict, since this
	 * adds a class rather than replacing $tr_class.
	 *
	 * blueline_team_color_style_attr() (inc/team-colors.php) is scoped to
	 * THIS ROW only -- not printed on <main> or any page-wide ancestor the
	 * way single-team.php does it -- because a league table can (and on
	 * /standings, does) list many teams on one page; only the viewer's own
	 * row may carry their team's colour.
	 */
	$bl_mine_attr = '';
	if ( in_array( $team_id, $bl_my_team_ids, true ) ) :
		$tr_class    .= ' bl-sp-row--mine';
		$bl_mine_attr = function_exists( 'blueline_team_color_style_attr' ) ? blueline_team_color_style_attr( $team_id ) : '';
	endif;

	$output .= '<tr class="' . ( 0 === $i % 2 ? 'odd' : 'even' ) . $tr_class . ' sp-row-no-' . (int) $i . '"' . $bl_mine_attr . '>';

	$output .= '<td class="data-rank' . $td_class . '" data-label="' . esc_attr( $labels['pos'] ) . '">' . esc_html( (string) sp_array_value( $row, 'pos' ) ) . '</td>';

	$name_class = '';

	/*
	 * On a team's own page, this table's highlighted row IS that team --
	 * its crest is already the page's hero image (blueline_sp_team_hero(),
	 * inc/sportspress.php), so showing it again here duplicated the logo.
	 * Every other team's logo in this same mini-standings table (and every
	 * logo on /standings, where is_singular('sp_team') is false) is
	 * unaffected -- only the one row that mirrors the page it's on.
	 */
	$bl_is_own_team_page = is_singular( 'sp_team' ) && (int) get_queried_object_id() === (int) $team_id;

	if ( $show_team_logo && ! $bl_is_own_team_page && has_post_thumbnail( $team_id ) ) :
		// PERF-06: drawn at 24px (sportspress.css), so sizes lets the browser take the 32w source at 1x.
		$logo        = get_the_post_thumbnail(
			$team_id,
			'sportspress-fit-icon',
			array(
				'sizes'    => '24px',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);
		$name        = '<span class="team-logo">' . $logo . '</span>' . $name;
		$name_class .= ' has-logo';
	endif;

	if ( $link_posts ) :
		$name = '<a href="' . esc_url( get_post_permalink( $team_id ) ) . '">' . $name . '</a>';
	endif;

	$output .= '<td class="data-name' . $name_class . $td_class . '" data-label="' . esc_attr( $labels['name'] ) . '">' . wp_kses( $name, $bl_kses_allowed ) . '</td>';

	if ( $bl_has_record ) :
		$bl_record_parts = array();
		foreach ( $bl_record_keys as $bl_key ) {
			$bl_record_parts[] = (string) sp_array_value( $row, $bl_key, '0' );
		}
		$output .= '<td class="data-record bl-sp-col-record' . $td_class . '" data-label="' . esc_attr__( 'Record', 'blueline' ) . '">' . esc_html( implode( '-', $bl_record_parts ) ) . '</td>';
	endif;

	foreach ( $labels as $key => $value ) :
		if ( in_array( $key, array( 'pos', 'name' ), true ) ) {
			continue;
		}
		if ( is_array( $columns ) && ! in_array( $key, $columns, true ) ) {
			continue;
		}
		$bl_extra_class = blueline_sp_extra_class( $key, $bl_extra_keys );
		$output        .= '<td class="data-' . esc_attr( $key ) . esc_attr( $bl_extra_class ) . $td_class . '" data-label="' . esc_attr( $labels[ $key ] ) . '">' . wp_kses_post( (string) sp_array_value( $row, $key, '&mdash;' ) ) . '</td>';
	endforeach;

	$output .= '</tr>';

	++$i;
	++$start;

endforeach;

$output .= '</tbody>';
?>
<div class="sp-template sp-template-league-table">
	<?php if ( $title ) : ?>
		<?php
		// Heading level depends on where this shortcode/template is
		// actually rendered -- see blueline_sp_caption_heading_level()'s
		// own docblock (inc/sportspress.php) for the accessibility finding
		// this fixes (this caption used to be a hardcoded, level-skipping
		// h4 everywhere).
		$bl_caption_level = function_exists( 'blueline_sp_caption_heading_level' ) ? blueline_sp_caption_heading_level() : 3;
		printf( '<h%1$d class="sp-table-caption">%2$s</h%1$d>', $bl_caption_level, wp_kses_post( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bl_caption_level is always the int 2 or 3 blueline_sp_caption_heading_level() returns, never user input; $title is already escaped via wp_kses_post().
		?>
	<?php endif; ?>
	<?php if ( $bl_zero_games_note ) : ?>
		<p class="bl-sp-table-empty-note"><?php echo esc_html( $bl_zero_games_note ); ?></p>
	<?php endif; ?>
	<?php if ( $bl_show_toggle ) : ?>
		<?php
		/*
		 * The disclosure's INITIAL state, from the panel's
		 * `standings_extra_stats_default` setting (Appearance tab). Both
		 * states stay reachable by the reader regardless -- the extra
		 * columns are rendered either way and the label below toggles them
		 * with no JavaScript -- which is exactly why that setting is a
		 * plain `bool` and not one of inc/settings/sections.php's presence
		 * toggles; see blueline_settings_schema()'s own comment on the key.
		 */
		?>
		<input type="checkbox" id="bl-sp-full-<?php echo esc_attr( $identifier ); ?>" class="bl-sp-standings-toggle-input"<?php checked( blueline_settings( 'standings_extra_stats_default' ) ); ?>>
		<label class="bl-sp-standings-toggle-label" for="bl-sp-full-<?php echo esc_attr( $identifier ); ?>">
			<?php esc_html_e( 'Show full stats', 'blueline' ); ?>
		</label>
	<?php endif; ?>
	<div class="sp-table-wrapper">
		<table class="sp-league-table sp-league-table-<?php echo esc_attr( $id ); ?> sp-data-table<?php echo $sortable ? ' sp-sortable-table' : ''; ?><?php echo $responsive ? ' sp-responsive-table ' . esc_attr( $identifier ) : ''; ?><?php echo $scrollable ? ' sp-scrollable-table' : ''; ?><?php echo $paginated ? ' sp-paginated-table' : ''; ?>" data-sp-rows="<?php echo esc_attr( $rows ); ?>">
			<?php echo wp_kses( $output, $bl_kses_allowed ); ?>
		</table>
	</div>
	<?php if ( $show_full_table_link ) : ?>
		<div class="sp-league-table-link sp-view-all-link"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php esc_html_e( 'View full table', 'sportspress' ); ?></a></div>
	<?php endif; ?>
</div>
