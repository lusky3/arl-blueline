<?php
/**
 * Homepage: the standings_snippet module. The active season's division
 * tables, their sort and labels, and the tab strip.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The sp_season term the standings snippet shows: the current SportsPress
 * season, or its Playoffs term while the season's playoff games are the
 * ones on the schedule (blueline_current_season_phase_term_id(),
 * inc/current-season.php). Independent of which hero/registration season is
 * being sold, since the games being played can be a different season (e.g.
 * a running summer league while next winter's registration is open).
 *
 * @return int|null
 */
function blueline_homepage_active_event_season_term_id() {
	$term_id = blueline_current_season_phase_term_id();

	return $term_id > 0 ? $term_id : null;
}

/**
 * The most division tabs blueline_homepage_module_standings_snippet() will
 * ever render. Must match assets/src/css/homepage.css's own hand-enumerated
 * :nth-of-type(1) through :nth-of-type(12) pairing exactly -- CSS has no
 * way to express "match the Nth of each type, for any N" without knowing N
 * in advance, so raising this constant means adding more pairs there too.
 * Well above this site's own historical maximum (7 lettered groups, one
 * winter season).
 */
const BLUELINE_HOMEPAGE_STANDINGS_MAX_TABS = 12;

/**
 * Every sp_table post id + short tab label for the active season's
 * division/group standings (sp_table titles on this site follow "Division
 * N | <Season>", e.g. "Division 1 | S2026", or "Division N | Playoffs
 * <Season>" -- older seasons in the archive instead use "Group A".."Group
 * G"). Returned in natural-sort order by the extracted label ("1".."9",
 * then "10".."12", "A".."Z"), not the query's own title-string order --
 * plain string order breaks past 9 divisions ("Division 10" would sort
 * before "Division 2").
 *
 * Playoffs-ness must be read off the resolved sp_season TERM's own name,
 * not $state alone: the term can already say "Playoffs" (e.g. "S2026
 * Playoffs") while $state has moved on to 'registration_open' for the next
 * season, since the two track different things.
 *
 * Used to be blueline_homepage_current_standings_table_id() (singular),
 * returning only the lowest-numbered division -- see this function's
 * caller for why showing just one division stopped making sense once
 * there are several.
 *
 * @param string $state Season state.
 * @return array<int, array{id:int, label:string, short:string, full:string}> `label` sorts;
 *         `short`/`full` are the tab's text (blueline_homepage_standings_tab_names()).
 */
function blueline_homepage_current_standings_tables( string $state ): array {
	if ( ! post_type_exists( 'sp_table' ) || ! taxonomy_exists( 'sp_season' ) ) {
		return array();
	}

	$season_term_id = blueline_homepage_active_event_season_term_id();

	if ( ! $season_term_id ) {
		return array();
	}

	$season_term = get_term( $season_term_id, 'sp_season' );

	if ( ! $season_term || is_wp_error( $season_term ) ) {
		return array();
	}

	// Rebuild the needle in title word order ("Playoffs S2026"), which is
	// the reverse of how the term name stores it ("S2026 Playoffs").
	$term_says_playoffs = (bool) preg_match( '/\bplayoffs\b/i', $season_term->name );
	$is_playoffs        = $term_says_playoffs || ( 'playoffs' === $state );
	$season_label       = trim( (string) preg_replace( '/\s*\bplayoffs\b\s*/i', ' ', $season_term->name ) );
	$needle             = $is_playoffs ? 'Playoffs ' . $season_label : $season_label;

	$candidates = get_posts(
		array(
			'post_type'      => 'sp_table',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			's'              => $needle,
		)
	);

	$tables = array();

	foreach ( $candidates as $candidate_id ) {
		$title = get_the_title( $candidate_id );

		if ( false === strpos( $title, $needle ) ) {
			continue; // The 's' search can match loosely; confirm the exact token is present.
		}

		if ( ! $is_playoffs && false !== stripos( $title, 'playoffs' ) ) {
			continue; // Exclude playoff tables outside the playoffs state.
		}

		$tables[] = array(
			'id'    => (int) $candidate_id,
			'label' => blueline_homepage_standings_table_label( $title ),
		) + blueline_homepage_standings_tab_names( $title );
	}

	return blueline_homepage_standings_tables_sort( $tables );
}

/**
 * Sort a set of division tables by their own extracted label, not the
 * query's title-string order: "Division 1".."Division 9" happen to sort
 * correctly as plain strings, but "Division 10" would sort BEFORE
 * "Division 2" that way. strnatcasecmp() compares embedded digit runs
 * numerically ("2" < "10") while still handling non-numeric labels ("A" <
 * "B", "4B" < "10") -- exactly what a mixed Division-N/Group-X/lettered-
 * subdivision archive needs.
 *
 * Pure array-in, array-out function, split out purely so it's directly
 * unit-testable -- matching blueline_homepage_standings_table_label()'s own
 * trade-off just above.
 *
 * @param array<int, array{id:int, label:string}> $tables Unsorted tables.
 * @return array<int, array{id:int, label:string}> The same entries, sorted.
 */
function blueline_homepage_standings_tables_sort( array $tables ): array {
	usort(
		$tables,
		static function ( array $a, array $b ): int {
			return strnatcasecmp( $a['label'], $b['label'] );
		}
	);

	return $tables;
}

/**
 * The sort key for one sp_table title: everything before the "|" that
 * separates division from season (e.g. "Division 1 | S2026" -> "Division
 * 1"), with a leading "Division "/"Group " dropped so it reads "1"/"A" and
 * sorts naturally. The tab's own text comes from
 * blueline_homepage_standings_tab_names(). A title matching neither convention (e.g. the
 * archive's older "Open Division | S2019") falls back to the text before
 * "|" unchanged, so a tab is never silently blank.
 *
 * Pure string function, split out purely so it's unit-testable without a
 * WordPress install, matching blueline_resolve_team_events_season()'s own
 * trade-off (inc/sportspress/core.php) for the same reason.
 *
 * @param string $title Full sp_table post title.
 * @return string
 */
function blueline_homepage_standings_table_label( string $title ): string {
	$label = trim( explode( '|', $title, 2 )[0] );

	if ( preg_match( '/^(?:Division|Group)\s+(.+)$/i', $label, $matches ) ) {
		return $matches[1];
	}

	return $label;
}

/**
 * A division tab's visible text and its full name for assistive tech: "Div 3"
 * and "Division 3" for a "Division 3 | <Season>" table, so five chips stay
 * compact on a phone while each radio is still announced as a division. Any
 * other convention ("Group A", "Open Division") uses its own name for both.
 *
 * @param string $title Full sp_table post title.
 * @return array{short:string, full:string}
 */
function blueline_homepage_standings_tab_names( string $title ): array {
	$full = trim( explode( '|', $title, 2 )[0] );

	if ( preg_match( '/^Division\s+(.+)$/i', $full, $matches ) ) {
		return array(
			/* translators: %s: division number or letter, e.g. "3". Short form for a small tab. */
			'short' => sprintf( __( 'Div %s', 'blueline' ), $matches[1] ),
			/* translators: %s: division number or letter, e.g. "3". */
			'full'  => sprintf( __( 'Division %s', 'blueline' ), $matches[1] ),
		);
	}

	return array(
		'short' => $full,
		'full'  => $full,
	);
}

/**
 * The standings_snippet module: every current-season division's table,
 * rendered through SportsPress's own [league_table] shortcode (not a
 * custom entity template). A single
 * division renders directly, as it always has; more than one gets a tab
 * strip (blueline_homepage_standings_tabs()) so a visitor whose team is in
 * Division 3 isn't left looking at Division 1's table with no way to reach
 * their own from the homepage.
 *
 * Capped at BLUELINE_HOMEPAGE_STANDINGS_MAX_TABS: assets/src/css/
 * homepage.css's tab/panel pairing is a hand-enumerated, finite set of
 * :nth-of-type rules (there is no way to express "match the Nth of each
 * type, for any N" in CSS without knowing N in advance), so a season with
 * more divisions than that has nothing to render extra tabs against --
 * sliced BEFORE do_shortcode() runs, so a division that will never get a
 * tab never pays for one either.
 */
function blueline_homepage_module_standings_snippet() {
	$state  = blueline_season_state();
	$tables = array_slice( blueline_homepage_current_standings_tables( $state ), 0, BLUELINE_HOMEPAGE_STANDINGS_MAX_TABS );
	$panels = array();

	foreach ( $tables as $table ) {
		$table_html = shortcode_exists( 'league_table' )
			? do_shortcode( '[league_table id="' . absint( $table['id'] ) . '"]' )
			: '';

		if ( '' === trim( wp_strip_all_tags( $table_html ) ) ) {
			continue;
		}

		// inc/sportspress/table-scroll.php's blueline_sp_wrap_tables_for_scroll()
		// only ever hooks 'the_content'; do_shortcode() above bypasses that
		// filter entirely, so without this call the table reached the page with
		// .sp-table-wrapper computing overflow-x: visible, and at 360px a 786px
		// table was silently cut off by the html{overflow-x:clip} backstop:
		// only Pos/Team/GP stayed reachable; W/L/Tie/PTS/GF/GA/Diff/L10/Strk
		// were not. Running the shortcode's own output through the SAME wrap
		// function /standings gets via the_content (called here, not
		// duplicated) keeps every one of these tables
		// scrollable exactly like every other SportsPress table on the site.
		$table_html = blueline_sp_wrap_tables_for_scroll( $table_html );

		$panels[] = array(
			'id'    => $table['id'],
			'short' => $table['short'],
			'full'  => $table['full'],
			'html'  => $table_html,
		);
	}

	blueline_homepage_module_start( 'standings_snippet', __( 'Standings', 'blueline' ), blueline_resolve_link( 'page_standings' ), __( 'Full standings', 'blueline' ) );

	if ( empty( $panels ) ) {
		blueline_homepage_module_empty_state( __( 'Standings aren’t posted yet.', 'blueline' ) );
	} elseif ( 1 === count( $panels ) ) {
		// Deliberately NOT wp_kses_post(). That filter allows no `data-*`
		// attribute of any kind, so running SportsPress's own table through it
		// stripped `data-sp-rows` (the hook SP's own pagination script reads)
		// and every `data-label` (the labels its responsive CSS shows in place
		// of column headers at narrow widths), quietly degrading the module
		// while the very same [league_table], rendered through the_content on
		// /standings, kept them (inc/sportspress/core.php applies no kses there).
		// One plugin's first-party, already-escaped output must not be subject
		// to two different sanitisation policies depending on which template
		// renders it; this is the /standings policy, applied here too.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SportsPress's own shortcode output, escaped by SP itself, and treated exactly as the_content treats it on /standings; see comment above.
		echo $panels[0]['html'];
	} else {
		blueline_homepage_standings_tabs( $panels );
	}

	blueline_homepage_module_end();
}

/**
 * The standings module's tab strip: one radio + label per division, one
 * panel per division, CSS alone deciding which panel shows -- the same
 * hidden-radio + sibling-selector mechanism sportspress/league-table.php's
 * own "Show full stats" checkbox already uses (assets/src/css/
 * sportspress.css's .bl-sp-standings-toggle-input), extended from a single
 * on/off toggle to a mutually-exclusive set. Every panel is server-rendered
 * regardless of which is showing, so this works with no JavaScript at all;
 * assets/src/js/standings-tabs.js layers a progressive enhancement on top
 * (remembers the division a visitor last picked) that this markup does not
 * depend on.
 *
 * The radios and their labels sit in a <fieldset> whose legend names the
 * group; the panels are siblings of that fieldset, not inside it, so the
 * announced group holds only the controls. assets/src/css/homepage.css pairs
 * radio N with panel N by position (:nth-of-type, since sp_table post ids
 * are not known in advance), labels with `~` inside the fieldset and panels
 * with :has() on the wrapper. Each radio's accessible name is the full
 * "Division N" while its chip shows "Div N" (blueline_homepage_standings_tab_names()).
 *
 * @param array<int, array{id:int, short:string, full:string, html:string}> $panels At least 2 entries.
 */
function blueline_homepage_standings_tabs( array $panels ) {
	?>
	<div class="bl-standings-tabs">
		<fieldset class="bl-standings-tabs__group">
			<legend class="screen-reader-text"><?php esc_html_e( 'Choose a division', 'blueline' ); ?></legend>
			<?php foreach ( $panels as $index => $panel ) : ?>
				<input
					type="radio"
					name="bl-standings-tab"
					id="bl-standings-tab-<?php echo esc_attr( $panel['id'] ); ?>"
					class="bl-standings-tabs__input"
					value="<?php echo esc_attr( $panel['id'] ); ?>"
					<?php checked( 0 === $index ); ?>
				>
				<label class="bl-standings-tabs__label" for="bl-standings-tab-<?php echo esc_attr( $panel['id'] ); ?>">
					<span class="bl-skew"><span><?php blueline_homepage_standings_tab_text( $panel['short'], $panel['full'] ); ?></span></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php foreach ( $panels as $panel ) : ?>
			<div class="bl-standings-tabs__panel">
				<?php echo $panel['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SportsPress's own shortcode output; see blueline_homepage_module_standings_snippet()'s own comment on why this is not wp_kses_post()'d. ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Print a division tab's text: the short form on screen and the full name
 * for assistive tech, or the name alone when the two are the same.
 *
 * @param string $short Visible text, e.g. "Div 3".
 * @param string $full  Accessible name, e.g. "Division 3".
 */
function blueline_homepage_standings_tab_text( string $short, string $full ) {
	if ( $short === $full ) {
		echo esc_html( $full );
		return;
	}

	echo '<span aria-hidden="true">' . esc_html( $short ) . '</span><span class="screen-reader-text">' . esc_html( $full ) . '</span>';
}
