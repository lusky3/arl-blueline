<?php
/**
 * SportsPress integration: body classes, sidebar logic, the header sponsors
 * filter, the horizontal-scroll wrapper for SP's own data tables, the
 * future-status fix for venue archives, and the small hero/teaser renderers
 * consumed by sportspress/*.php.
 *
 * Every SportsPress touchpoint here is guarded with function_exists() /
 * class_exists() / post_type_exists() / taxonomy_exists() so the theme never
 * fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A post's display title, with SportsPress's own number/role badge removed
 * for exactly the two post types that get one.
 *
 * SportsPress hooks 'the_title' for sp_player/sp_staff only, to prepend a
 * "<strong class=\"sp-player-number\">27</strong> " / "<strong
 * class=\"sp-staff-role\">Referee</strong> " badge (confirmed live:
 * get_the_title() on a player named "Matthew Mascola" returns that literal
 * markup plus text) -- this theme's own hero markup already shows that same
 * number/role in its own badge, so the prefix would both duplicate it and,
 * if merely HTML-escaped, print the literal tags as visible text.
 *
 * Review finding: an earlier version of this function used
 * get_post_field( 'post_title', $id, 'raw' ) for every post type, which
 * bypasses the ENTIRE 'the_title' filter chain -- not just SportsPress's
 * badge, but also wptexturize()/convert_chars() (smart quotes, em-dashes)
 * that every other post type's title still needs. Narrowed to only the two
 * post types with the actual badge problem; sp_event/sp_team (and anything
 * else) go through the normal, fully-filtered get_the_title().
 *
 * @param int $post_id Post ID.
 * @return string Plain title, ready for esc_html().
 */
function blueline_sp_title( $post_id ) {
	if ( in_array( get_post_type( $post_id ), array( 'sp_player', 'sp_staff' ), true ) ) {
		return (string) get_post_field( 'post_title', $post_id, 'raw' );
	}

	return get_the_title( $post_id );
}

/**
 * Whether SportsPress templates should reserve a sidebar column. Mirrors the
 * is_active_sidebar('sidebar-1') check page.php/archive.php already use,
 * promoted to a named, filterable function because all seven sportspress/
 * templates need the same decision (sidebar-1 historically carries SP-
 * relevant widgets -- countdown, recent posts -- so showing it here is
 * intentional, not an oversight).
 *
 * @return bool
 */
function blueline_sp_has_sidebar(): bool {
	return (bool) apply_filters( 'blueline_sp_has_sidebar', is_active_sidebar( 'sidebar-1' ) );
}

add_filter( 'body_class', 'blueline_sp_body_class' );
/**
 * Add bl-sp / bl-sp-{post_type-or-taxonomy} body classes on SportsPress
 * singular and taxonomy views, per the Task 8 interface contract.
 *
 * @param string[] $classes Existing body classes.
 * @return string[]
 */
function blueline_sp_body_class( $classes ) {
	if ( ! function_exists( 'sp_post_types' ) ) {
		return $classes;
	}

	if ( is_singular( sp_post_types() ) ) {
		$classes[] = 'bl-sp';
		$classes[] = 'bl-sp-' . get_post_type();
		return $classes;
	}

	if ( function_exists( 'sp_taxonomies' ) && is_tax( sp_taxonomies() ) ) {
		$term      = get_queried_object();
		$classes[] = 'bl-sp';
		if ( $term instanceof WP_Term ) {
			$classes[] = 'bl-sp-' . $term->taxonomy;
		}
	}

	return $classes;
}

add_filter( 'sportspress_header_sponsors_selector', 'blueline_header_sponsors_selector' );
/**
 * Tell SportsPress which element the header sponsors should be inserted into.
 *
 * @param string $selector Default selector.
 * @return string
 */
function blueline_header_sponsors_selector( $selector ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the filter's own signature; this theme always returns one fixed selector regardless of the default passed in.
	return '.bl-header__sponsors';
}

add_filter( 'option_sportspress_header_sponsors_top', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_header_sponsors_right', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_footer_sponsors_css_background', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_footer_sponsors_css_text', 'blueline_sp_blank_frontend_option' );
/**
 * Blank one of SportsPress Sponsors' own decorative option values on the
 * front end only, so this theme's own token-based CSS is the only styling
 * that reaches the browser for these two surfaces -- consistent with
 * DESIGN.md's stated constraint that the theme supplies the only styling
 * SportsPress surfaces get.
 *
 * SportsPress_Sponsors::header() prints
 * `style="margin-top: {$top}px; margin-right: {$right}px;"` directly on
 * `.sp-header-sponsors` as an inline HTML attribute -- no CSS selector,
 * however specific, can ever override that from an external stylesheet.
 * Blanking the two option values makes it print `style="margin-top: px;
 * margin-right: px;"`; a value-less declaration like `margin-top: px;` is
 * invalid CSS and is simply dropped by the browser, which is equivalent to
 * 0 for this purpose. The offset it would otherwise apply exists to nudge
 * a sponsor logo away from a generic theme's own header edge --
 * `.bl-header__sponsors`/`.sp-sponsors-loader` (header.css/sportspress.css)
 * already reserve and center that exact space, so the extra offset only
 * pushes the logo outside the box those rules size to.
 *
 * SportsPress_Sponsors::footer() prints a real `<style>` block containing
 * `.sp-footer-sponsors { background: ...; color: ...; }` directly into the
 * page (not gated by sportspress_enable_frontend_css, which this site
 * otherwise leaves off) -- an external stylesheet rule of matching
 * specificity loses to it purely because that block appears later in the
 * document. Blanking the two colour option values makes those two
 * declarations value-less and equally droppable, leaving
 * assets/src/css/sportspress.css's own `.sp-footer-sponsors` rule as the
 * only one that applies.
 *
 * Scoped to the front end only (is_admin() passes the real value straight
 * through) so the Sponsors settings screen's own colour pickers and
 * position fields keep showing/editing the site's actual saved values.
 *
 * @param mixed $value Stored option value.
 * @return mixed
 */
function blueline_sp_blank_frontend_option( $value ) {
	return is_admin() ? $value : '';
}

add_filter( 'the_content', 'blueline_sp_wrap_tables_for_scroll', 20 );
/**
 * Guarantee every SportsPress <table> in rendered content either sits
 * inside a scroll container or gets one of its own -- "the page body must
 * never scroll horizontally" is a site-wide hard invariant, not a
 * today's-markup-shaped one, so this must not depend on SportsPress's exact
 * current class names.
 *
 * Re-scoped after review: this must touch SportsPress's own output only,
 * never an ordinary block-editor table in a blog post or page (those
 * already have a working .wp-block-table wrapper of their own, and forcing
 * display:block onto them via bl-table-self-scroll would be an untested,
 * out-of-scope behaviour change). The scoping problem is that SP tables can
 * legitimately appear on an ordinary Page too -- /standings embeds
 * [team_standings] shortcodes directly in page content, so a page-type
 * check (is_singular(sp_post_types())/is_tax(sp_taxonomies())) would
 * incorrectly skip it. What every SportsPress table genuinely has in
 * common, regardless of post type or template, is SportsPress's own `sp-`
 * class-name convention: every table this plugin renders carries at least
 * one class starting with "sp-" directly on the <table> itself (confirmed
 * by reading every table-producing template in the installed plugin), and
 * an ordinary wp-block-table never does. Detecting that convention, not one
 * specific class, is what keeps this both scoped to SportsPress AND still
 * resilient to a future SportsPress markup change (see
 * blueline_dom_table_is_sportspress()).
 *
 * Three layers, in order:
 * 1. SportsPress's own templates (league-table.php, event-blocks.php,
 *    player-statistics-league.php, event-details.php, event-list.php,
 *    player-list.php, event-officials-table.php, event-logos-block.php) all
 *    wrap their <table> in an identical, class-only
 *    `<div class="sp-table-wrapper">` -- confirmed by reading every
 *    occurrence in the installed plugin. A cheap string check/replace
 *    handles this, the overwhelming common case, without any parsing.
 * 2. A cheap `strpos( $content, 'sp-' )` pre-check, then
 *    blueline_sp_ensure_tables_scroll() walks every remaining <table> via
 *    DOMDocument and gives any that (a) is SportsPress's own (carries an
 *    sp- prefixed class) and (b) still has no scroll-capable ancestor a
 *    self-contained scroll class directly. This is what actually closes
 *    the gap: event-venue.php is the one SP template today that renders
 *    its <table> with no wrapper at all (its embedded Leaflet map
 *    overflowed the page body at mobile widths until this was added -- see
 *    the Task 8 fix report) -- and a future SportsPress update changing
 *    any table's class list, or adding a new unwrapped one, is still
 *    caught by this pass as long as it keeps SP's own sp- prefix
 *    convention, which every SP class already does today. Runs after
 *    shortcodes have already expanded (priority 20, after SP's own
 *    the_content hooks and do_shortcode's default priority 11).
 *
 * base.css's `html { overflow-x: clip; }` is the third, independent layer:
 * even if a table somehow reaches the page without either mechanism above
 * catching it, the page body still cannot scroll horizontally.
 *
 * @param string $content Post content, already shortcode-expanded.
 * @return string
 */
function blueline_sp_wrap_tables_for_scroll( $content ) {
	if ( false === strpos( $content, '<table' ) ) {
		return $content;
	}

	if ( false !== strpos( $content, 'class="sp-table-wrapper"' ) ) {
		$content = str_replace( 'class="sp-table-wrapper"', 'class="sp-table-wrapper bl-table-scroll"', $content );
	}

	// Cheap pre-check before the DOMDocument parse below: nothing
	// SportsPress renders is ever without an sp- prefixed class somewhere,
	// so content with no "sp-" substring at all cannot contain a
	// SportsPress table and the expensive DOM pass is skipped entirely --
	// an ordinary blog post with a block-editor table (<table
	// class="wp-block-table">, no "sp-" anywhere) never reaches
	// DOMDocument at all.
	if ( false === strpos( $content, 'sp-' ) ) {
		return $content;
	}

	if ( ! class_exists( 'DOMDocument' ) ) {
		// ext-dom unavailable: degrade to the string-only pass above. The
		// html{overflow-x:clip} CSS backstop still protects the invariant.
		return $content;
	}

	return blueline_sp_ensure_tables_scroll( $content );
}

/**
 * Whether a <table> is SportsPress's own output: does it (or an ancestor)
 * carry a class starting with "sp-"? SportsPress's class-name convention is
 * universal across every table-producing template in the installed plugin
 * (sp-data-table, sp-league-table, sp-event-blocks, sp-event-calendar,
 * sp-player-list, sp-player-statistics, sp-event-details, sp-event-venue --
 * always directly on the <table> element itself), so this is a durable
 * signal that survives a future SportsPress markup change, unlike matching
 * one specific class. An ordinary WordPress block-editor table
 * (<table class="wp-block-table">) never has one, at any ancestor depth.
 *
 * @param DOMElement $table Table element to check.
 * @return bool
 */
function blueline_dom_table_is_sportspress( DOMElement $table ) {
	if ( blueline_dom_has_class_prefix( $table, 'sp-' ) ) {
		return true;
	}

	$node = $table->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.

	while ( $node instanceof DOMElement ) {
		if ( blueline_dom_has_class_prefix( $node, 'sp-' ) ) {
			return true;
		}
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
	}

	return false;
}

/**
 * Walk every <table> in $content and make sure every SportsPress one (see
 * blueline_dom_table_is_sportspress()) has a scroll-capable ancestor:
 * either it's already inside something carrying bl-table-scroll (added
 * above, or by any future mechanism) or bl-table-self-scroll, or it gets
 * bl-table-self-scroll added directly to itself. A table that is NOT
 * SportsPress's own output (an ordinary block-editor table, for instance)
 * is left completely untouched -- see
 * blueline_sp_wrap_tables_for_scroll()'s docblock for why this exists.
 *
 * @param string $content Post content.
 * @return string
 */
function blueline_sp_ensure_tables_scroll( $content ) {
	$libxml_state = libxml_use_internal_errors( true );

	$dom    = new DOMDocument();
	$loaded = $dom->loadHTML(
		'<?xml encoding="utf-8" ?><div id="blueline-scroll-root">' . $content . '</div>',
		LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	libxml_clear_errors();
	libxml_use_internal_errors( $libxml_state );

	if ( ! $loaded ) {
		// Malformed fragment -- leave content untouched rather than risk
		// corrupting it; the CSS backstop still applies.
		return $content;
	}

	$xpath  = new DOMXPath( $dom );
	$tables = $xpath->query( '//table' );

	if ( 0 === $tables->length ) {
		return $content;
	}

	$changed = false;

	foreach ( $tables as $table ) {
		if ( ! $table instanceof DOMElement ) {
			continue;
		}

		if ( ! blueline_dom_table_is_sportspress( $table ) ) {
			continue; // Not SportsPress's own markup -- e.g. an ordinary block-editor table. Leave it untouched.
		}

		if ( blueline_dom_find_class_ancestor( $table, 'bl-table-scroll' )
			|| blueline_dom_find_class_ancestor( $table, 'bl-table-self-scroll' ) ) {
			continue; // Already inside a scroll container.
		}

		blueline_dom_add_class( $table, 'bl-table-self-scroll' );
		$changed = true;
	}

	if ( ! $changed ) {
		return $content;
	}

	$root = $xpath->query( '//div[@id="blueline-scroll-root"]' )->item( 0 );

	if ( ! $root ) {
		return $content;
	}

	$html = '';
	foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
		$html .= $dom->saveHTML( $child );
	}

	return $html;
}

/**
 * Find the nearest ancestor element carrying a given class, or null.
 *
 * @param DOMNode $node       Node to search upward from (its own classes are not checked).
 * @param string  $class_name Class name to look for.
 * @return DOMElement|null
 */
function blueline_dom_find_class_ancestor( $node, $class_name ) {
	$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.

	while ( $node instanceof DOMElement ) {
		if ( blueline_dom_has_class( $node, $class_name ) ) {
			return $node;
		}
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
	}

	return null;
}

/**
 * Whether a DOM element's class attribute contains a given class token.
 *
 * @param DOMElement $element    Element to check.
 * @param string     $class_name Class name to look for.
 * @return bool
 */
function blueline_dom_has_class( DOMElement $element, $class_name ) {
	$classes = preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

	return in_array( $class_name, $classes, true );
}

/**
 * Whether a DOM element's class attribute contains any class token starting
 * with a given prefix (e.g. "sp-"). Token-exact prefix matching -- a class
 * like "responsive-table" does NOT match prefix "sp-" just because that
 * substring appears mid-word ("re-sp-onsive"); only a class that itself
 * starts with "sp-" counts.
 *
 * @param DOMElement $element Element to check.
 * @param string     $prefix  Prefix to look for.
 * @return bool
 */
function blueline_dom_has_class_prefix( DOMElement $element, $prefix ) {
	$classes = preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

	foreach ( $classes as $class_name ) {
		if ( 0 === strpos( $class_name, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Add a class token to a DOM element, without duplicating it if present.
 *
 * @param DOMElement $element    Element to modify.
 * @param string     $class_name Class name to add.
 */
function blueline_dom_add_class( DOMElement $element, $class_name ) {
	if ( blueline_dom_has_class( $element, $class_name ) ) {
		return;
	}

	$existing = trim( (string) $element->getAttribute( 'class' ) );
	$element->setAttribute( 'class', ( '' !== $existing ? $existing . ' ' : '' ) . $class_name );
}

add_action( 'pre_get_posts', 'blueline_sp_venue_archive_include_future' );
/**
 * WordPress's default main-query post_status is 'publish' only, which would
 * silently hide every upcoming (future-status) game from a venue's own
 * archive page -- the exact under-counting bug this project has already
 * shipped twice in custom queries (Tasks 6/7). This is the equivalent fix
 * for the one query Task 8 does not build itself: the taxonomy-venue.php
 * main loop, which WordPress core populates before the template ever runs.
 *
 * @param WP_Query $query The main query, passed by reference.
 */
function blueline_sp_venue_archive_include_future( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_tax( 'sp_venue' ) ) {
		$query->set( 'post_status', array( 'publish', 'future' ) );
	}
}

/**
 * GMT unix timestamp an sp_event starts at, or false if it cannot be
 * determined. Used only to build the add-to-calendar link; never used to
 * decide pre-game vs post-game (that is sp_get_status()'s job).
 *
 * @param int $event_id sp_event post ID.
 * @return int|false
 */
function blueline_sp_event_start_timestamp( $event_id ) {
	$local = get_the_time( 'Y-m-d H:i:s', $event_id );

	if ( ! $local ) {
		return false;
	}

	$gmt = get_gmt_from_date( $local );

	return $gmt ? strtotime( $gmt . ' +0000' ) : false;
}

/**
 * A Google Calendar "add event" link for an upcoming sp_event. No JS, no
 * external dependency beyond the calendar.google.com URL scheme -- a plain
 * <a href> that works with or without a Google account.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready URL, or '' if the start time is unknown.
 */
function blueline_sp_event_calendar_url( $event_id ) {
	$start_ts = blueline_sp_event_start_timestamp( $event_id );

	if ( ! $start_ts ) {
		return '';
	}

	$minutes = 90;
	if ( class_exists( 'SP_Event' ) ) {
		$event         = new SP_Event( $event_id );
		$event_minutes = (int) $event->minutes();
		if ( $event_minutes > 0 ) {
			$minutes = $event_minutes;
		}
	}

	$end_ts = $start_ts + ( $minutes * MINUTE_IN_SECONDS );

	$venue_names = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue', array( 'fields' => 'names' ) ) : array();
	$location    = ( ! is_wp_error( $venue_names ) && ! empty( $venue_names ) ) ? $venue_names[0] : '';

	$args = array(
		'action'   => 'TEMPLATE',
		'text'     => blueline_sp_title( $event_id ),
		'dates'    => gmdate( 'Ymd\THis\Z', $start_ts ) . '/' . gmdate( 'Ymd\THis\Z', $end_ts ),
		'location' => $location,
	);

	return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
}

/**
 * One team's own scoreline for a played sp_event, looked up by team ID --
 * never by position in a shared results array.
 *
 * Review finding: SP_Event::main_results() (SportsPress core) returns a
 * plain, re-indexed array built by looping $teams and doing
 * `$output[] = $team_result` only `if ( null != $team_result )` -- so a
 * team with no result recorded for this event (a bye, an incomplete score
 * entry, etc.) is skipped entirely, shifting every following team's score
 * left by one slot. Zipping that array against this theme's own
 * separately-fetched $teams array by index (the original implementation)
 * would silently attribute one team's score to a different team the moment
 * either team is missing a value -- home/away meta-row order is meaningful
 * on this site but not guaranteed to line up with a differently-derived,
 * gap-compacted results array. This function re-derives one specific
 * team's own result directly from the sp_results meta, keyed by that
 * team's own ID, mirroring SP_Event::main_results()'s own per-team logic
 * (primary result column if configured and non-empty, else the last
 * non-empty, non-outcome column) without going through its shared,
 * position-based output array at all.
 *
 * @param int $event_id sp_event post ID.
 * @param int $team_id  sp_team post ID, expected to be one of this event's own sp_team meta values.
 * @return string|int|null The team's own scoreline, or null if none is recorded.
 */
function blueline_sp_team_result( $event_id, $team_id ) {
	$results = get_post_meta( $event_id, 'sp_results', true );

	if ( ! is_array( $results ) || empty( $results[ $team_id ] ) || ! is_array( $results[ $team_id ] ) ) {
		return null;
	}

	$team_results = $results[ $team_id ];
	$primary_key  = get_option( 'sportspress_primary_result', '' );

	if ( $primary_key && isset( $team_results[ $primary_key ] ) && '' !== $team_results[ $primary_key ] ) {
		return $team_results[ $primary_key ];
	}

	unset( $team_results['outcome'] );

	$team_results = array_filter(
		$team_results,
		static function ( $value ) {
			return '' !== $value && null !== $value;
		}
	);

	if ( empty( $team_results ) ) {
		return null;
	}

	return end( $team_results );
}

/**
 * The event masthead: teams, "vs", venue (and pad, since the venue term
 * name IS the pad here -- e.g. term 14 is literally named "Red", term 13
 * "Black", both sharing one street address), and either an add-to-calendar
 * link (pre-game) or the final score (post-game, i.e. sp_get_status()
 * returns 'results'). Purely additive to what the_content() renders below
 * it -- SportsPress's own event-logos/event-details/event-venue sections
 * still appear and are styled via sportspress.css.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_hero( $event_id ) {
	if ( ! function_exists( 'sp_get_status' ) ) {
		return;
	}

	$teams = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );

	$status    = sp_get_status( $event_id );
	$is_played = ( 'results' === $status );

	$venue_terms = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue' ) : array();
	$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0]->name : '';

	$calendar_url = $is_played ? '' : blueline_sp_event_calendar_url( $event_id );
	?>
	<header class="sp-scoreboard">
		<?php
		if ( function_exists( 'blueline_render_faceoff_rings' ) ) {
			blueline_render_faceoff_rings();
		}
		?>
		<div class="bl-container sp-scoreboard__inner">
			<p class="sp-scoreboard__status">
				<span class="bl-skew"><span><?php echo esc_html( $is_played ? __( 'Final', 'blueline' ) : __( 'Preview', 'blueline' ) ); ?></span></span>
			</p>

			<div class="sp-scoreboard__matchup">
				<?php foreach ( array( 0, 1 ) as $slot ) : ?>
					<?php
					$team_id = $teams[ $slot ] ?? 0;
					$score   = ( $is_played && $team_id ) ? blueline_sp_team_result( $event_id, $team_id ) : null;
					?>
					<div class="sp-scoreboard__team">
						<?php if ( $team_id && has_post_thumbnail( $team_id ) ) : ?>
							<span class="sp-scoreboard__logo"><?php echo get_the_post_thumbnail( $team_id, 'thumbnail' ); ?></span>
						<?php endif; ?>
						<span class="sp-scoreboard__team-name">
							<?php echo esc_html( $team_id ? blueline_sp_title( $team_id ) : __( 'TBD', 'blueline' ) ); ?>
						</span>
						<?php if ( $is_played && null !== $score ) : ?>
							<span class="sp-scoreboard__score"><?php echo esc_html( $score ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( 0 === $slot ) : ?>
						<span class="sp-scoreboard__vs" aria-hidden="true"><?php esc_html_e( 'vs', 'blueline' ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<p class="sp-scoreboard__meta">
				<time class="sp-scoreboard__time" datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $event_id ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: event date, 2: event time. */
							__( '%1$s · %2$s', 'blueline' ),
							get_the_date( 'D, M j', $event_id ),
							get_the_time( get_option( 'time_format' ), $event_id )
						)
					);
					?>
				</time>
				<?php if ( $venue_name ) : ?>
					<span class="sp-scoreboard__venue"><?php echo esc_html( $venue_name ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( $calendar_url ) : ?>
				<a class="bl-btn bl-btn--secondary sp-scoreboard__calendar" href="<?php echo esc_url( $calendar_url ); ?>">
					<span class="bl-skew"><span><?php esc_html_e( 'Add to calendar', 'blueline' ); ?></span></span>
				</a>
			<?php endif; ?>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * The player masthead: number, position (read from the sp_position
 * taxonomy -- never a meta field), current team, and nationality (escaped
 * for the attribute context it is used in, per the Task 8 brief).
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_sp_player_hero( $player_id ) {
	$number = get_post_meta( $player_id, 'sp_number', true );

	$position_names = array();
	if ( taxonomy_exists( 'sp_position' ) ) {
		$position_terms = wp_get_post_terms( $player_id, 'sp_position' );
		if ( ! is_wp_error( $position_terms ) ) {
			foreach ( $position_terms as $term ) {
				$position_names[] = $term->name;
			}
		}
	}

	$current_team_id = 0;
	if ( class_exists( 'SP_Player' ) ) {
		$sp_player     = new SP_Player( $player_id );
		$current_teams = array_filter( array_map( 'absint', (array) $sp_player->current_teams() ) );
		if ( ! empty( $current_teams ) ) {
			$current_team_id = (int) reset( $current_teams );
		}
	}

	$nationalities = array_filter( (array) get_post_meta( $player_id, 'sp_nationality', false ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--player">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( '' !== $number && null !== $number ) : ?>
				<span class="bl-sp-hero__number" aria-hidden="true"><?php echo esc_html( $number ); ?></span>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $player_id ) ); ?></h1>
				<p class="bl-sp-hero__meta">
					<?php if ( $position_names ) : ?>
						<span class="bl-sp-hero__position"><?php echo esc_html( implode( ', ', $position_names ) ); ?></span>
					<?php endif; ?>
					<?php foreach ( $nationalities as $code ) : ?>
						<?php
						$code = (string) $code;
						if ( '' === $code ) {
							continue;
						}
						?>
						<span class="bl-sp-hero__flag" title="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( strtoupper( $code ) ); ?></span>
					<?php endforeach; ?>
				</p>
			</div>

			<?php if ( $current_team_id && 'publish' === get_post_status( $current_team_id ) ) : ?>
				<a class="bl-sp-hero__team" href="<?php echo esc_url( get_permalink( $current_team_id ) ); ?>">
					<?php if ( has_post_thumbnail( $current_team_id ) ) : ?>
						<?php echo get_the_post_thumbnail( $current_team_id, 'thumbnail' ); ?>
					<?php endif; ?>
					<span><?php echo esc_html( blueline_sp_title( $current_team_id ) ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * The team masthead: crest, division(s). Record is deliberately not
 * recomputed here -- SportsPress's own standings table (auto-rendered by
 * the_content() below, via team-tables.php) already highlights this team's
 * row with .sp-highlight, which is the correct, already-computed source for
 * W/L/T/PTS rather than a second, hand-rolled aggregation.
 *
 * @param int $team_id sp_team post ID.
 */
function blueline_sp_team_hero( $team_id ) {
	$division_names = array();
	if ( taxonomy_exists( 'sp_league' ) ) {
		$league_terms = wp_get_post_terms( $team_id, 'sp_league' );
		if ( ! is_wp_error( $league_terms ) ) {
			foreach ( $league_terms as $term ) {
				$division_names[] = $term->name;
			}
		}
	}

	/*
	 * The team's own colour, as scoped custom properties. Returns '' for a
	 * team with no usable `sp_colors`, in which case every rule below falls
	 * back to its theme token and the hero renders exactly as it always has.
	 * See inc/team-colors.php for why the stored palette is not used as-is.
	 */
	$team_color_attr = function_exists( 'blueline_team_color_style_attr' )
		? blueline_team_color_style_attr( $team_id )
		: '';
	?>
	<header class="bl-sp-hero bl-sp-hero--team"<?php echo $team_color_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blueline_team_color_style_attr() returns a complete, esc_attr()'d style attribute built only from hex values it validated itself. ?>>
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $team_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $team_id, 'medium' ); ?></div>
			<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--fallback">
					<?php blueline_leaf_mark( 'bl-sp-hero__crest-mark' ); ?>
				</div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></h1>
				<?php if ( $division_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $division_names ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<?php
}

/**
 * The staff masthead: role(s) and the team(s) they're attached to.
 *
 * @param int $staff_id sp_staff post ID.
 */
function blueline_sp_staff_hero( $staff_id ) {
	$role_names = array();
	if ( taxonomy_exists( 'sp_role' ) ) {
		$role_terms = wp_get_post_terms( $staff_id, 'sp_role' );
		if ( ! is_wp_error( $role_terms ) ) {
			foreach ( $role_terms as $term ) {
				$role_names[] = $term->name;
			}
		}
	}

	$team_ids = array_filter( array_map( 'absint', (array) get_post_meta( $staff_id, 'sp_team', false ) ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--staff">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $staff_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $staff_id, 'thumbnail' ); ?></div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $staff_id ) ); ?></h1>
				<?php if ( $role_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $role_names ) ); ?></p>
				<?php endif; ?>
				<?php if ( $team_ids ) : ?>
					<ul class="bl-sp-hero__teams">
						<?php
						foreach ( $team_ids as $team_id ) :
							if ( 'publish' !== get_post_status( $team_id ) ) {
								continue;
							}
							?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $team_id ) ); ?>"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<?php
}

/**
 * A compact single-line teaser for one sp_event -- used in archive/taxonomy
 * listings (taxonomy-venue.php) where showing every event's full,
 * the_content()-rendered detail would be far too heavy per row.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_teaser( $event_id ) {
	$status    = function_exists( 'sp_get_status' ) ? sp_get_status( $event_id ) : get_post_status( $event_id );
	$is_played = ( 'results' === $status );

	// Keyed by team ID (blueline_sp_team_result()), not positionally zipped
	// against a shared results array -- see that function's own docblock
	// for why a positional pairing can silently attribute one team's score
	// to the other.
	$scores = array();
	if ( $is_played ) {
		$teams = array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) );
		foreach ( $teams as $team_id ) {
			$score = blueline_sp_team_result( $event_id, $team_id );
			if ( null !== $score && '' !== $score ) {
				$scores[] = $score;
			}
		}
	}
	?>
	<a class="bl-sp-event-teaser" href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
		<span class="bl-sp-event-teaser__date"><?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event_id ) ); ?></span>
		<span class="bl-sp-event-teaser__title"><?php echo esc_html( blueline_sp_title( $event_id ) ); ?></span>
		<?php if ( $is_played && $scores ) : ?>
			<span class="bl-sp-event-teaser__score"><?php echo esc_html( implode( ' - ', $scores ) ); ?></span>
		<?php else : ?>
			<span class="bl-sp-event-teaser__status"><?php esc_html_e( 'Preview', 'blueline' ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}
