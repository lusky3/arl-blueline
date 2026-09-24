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
 * markup plus text), and this theme's own hero markup already shows that same
 * number/role in its own badge, so the prefix would both duplicate it and,
 * if merely HTML-escaped, print the literal tags as visible text.
 *
 * Review finding: an earlier version of this function used
 * get_post_field( 'post_title', $id, 'raw' ) for every post type, which
 * bypasses the ENTIRE 'the_title' filter chain, not just SportsPress's
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
 * relevant widgets, e.g. countdown, recent posts, so showing it here is
 * intentional, not an oversight).
 *
 * @return bool
 */
function blueline_sp_has_sidebar(): bool {
	return (bool) apply_filters( 'blueline_sp_has_sidebar', is_active_sidebar( 'sidebar-1' ) );
}

/**
 * The shared skeleton behind sportspress/single-event.php, single-player.php,
 * single-staff.php, and single-team.php: those four templates were
 * byte-identical (get_header() -> the sidebar-active check above -> the
 * entity's own hero -> `.bl-content-layout` wrapper -> the_content() ->
 * comments -> sidebar -> get_footer()) except for which hero function runs
 * and, on single-team.php alone, one extra colour-related attribute on the
 * `<main>` tag. This is that shared body; each of the four templates is now
 * just get_header(), one call here, and get_footer().
 *
 * @param callable      $hero_callback     The entity's own hero renderer (e.g.
 *                                          'blueline_sp_event_hero'), called with the
 *                                          current post's ID at the point the hero used
 *                                          to render inline. All four hero functions are
 *                                          declared unconditionally in this same file, so
 *                                          they are always callable here.
 * @param string        $extra_main_attr   Extra, already-escaped markup echoed into
 *                                          `<main>`'s opening tag: single-team.php's own
 *                                          team-colour custom properties
 *                                          (blueline_team_color_style_attr()). Empty for
 *                                          the other three templates.
 * @param callable|null $before_content_cb Optional, called with no arguments
 *                                          immediately before the_content() (and
 *                                          therefore above every SportsPress
 *                                          auto-injected section, including the
 *                                          roster) -- single-team.php's own claim
 *                                          nudge (blueline_render_claim_nudge()).
 *                                          Null (the default) for the other three
 *                                          templates, which need no such content.
 */
function blueline_render_sp_single( callable $hero_callback, string $extra_main_attr = '', ?callable $before_content_cb = null ): void {
	$has_sidebar = blueline_sp_has_sidebar();
	?>
	<main id="main" class="bl-main bl-main--sp bl-main--sp-hero" tabindex="-1"<?php echo $extra_main_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-supplied markup is already escaped at its own source; see single-team.php. ?>>
		<?php
		while ( have_posts() ) :
			the_post();

			$hero_callback( get_the_ID() );
			?>
			<div class="bl-container">
				<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
					<div class="bl-content-layout__primary">
						<?php if ( $before_content_cb ) : ?>
							<?php $before_content_cb(); ?>
						<?php endif; ?>
						<div class="entry-content bl-entry__content">
							<?php the_content(); ?>
						</div>
						<?php
						if ( comments_open() || get_comments_number() ) :
							comments_template();
						endif;
						?>
					</div>
					<?php if ( $has_sidebar ) : ?>
						<?php get_sidebar(); ?>
					<?php endif; ?>
				</div>
			</div>
			<?php
		endwhile;
		?>
	</main>
	<?php
}

/**
 * The heading level SportsPress's own auto-injected table/list captions
 * ("Division 1 | S2026", "Upcoming Games", a roster list's own title, a
 * player's per-league stats caption -- sportspress/league-table.php,
 * event-list.php, team-lists.php and player-statistics-league.php) should
 * render at.
 *
 * A11y finding: these four templates are shared across two structurally
 * different contexts, and neither can be known when the markup is written
 * -- only at request time:
 *
 *   - On a SportsPress singular view (sp_team/sp_player/sp_staff/sp_event),
 *     the theme's own hero prints the page's ONE <h1> (blueline_sp_title(),
 *     e.g. sportspress/single-team.php) and then the_content() drops
 *     SportsPress's auto-injected sections straight in below it with
 *     NOTHING else in between -- confirmed live: a team page's outline
 *     went h1 -> [this caption], not h1 -> h2 -> [this caption]. The
 *     correct next level there is h2.
 *   - Everywhere else these four templates are used today (the homepage's
 *     standings_snippet module, via [league_table] --
 *     blueline_homepage_module_standings_snippet()), an h2 section title
 *     ("Standings") already precedes them, so the correct next level is
 *     h3. A page whose own content places one of these shortcodes at some
 *     other depth (e.g. /standings' own [team_standings], sitting directly
 *     in page-editor content the theme does not control) is a content
 *     concern, not this function's -- h3 is the right default because it
 *     is correct for every case this theme itself renders.
 *
 * Hardcoding h4 regardless of context (the bug this fixes) skipped a level
 * in BOTH cases: h2 -> h4 on the homepage, and h1 -> h4 -- skipping two
 * levels at once -- on every SportsPress singular page.
 *
 * @return int Either 2 or 3.
 */
function blueline_sp_caption_heading_level(): int {
	if ( function_exists( 'sp_post_types' ) && is_singular( sp_post_types() ) ) {
		return 2;
	}

	return 3;
}

/**
 * The roster table's stat columns, in display order -- the single source
 * both the <thead> column labels (sportspress/team-lists.php) and each
 * row's cells (blueline_render_roster_stats(), just below) read from, so
 * the two can never drift out of sync.
 *
 * @return array<string,string> Stat key => column label.
 */
function blueline_roster_stat_labels(): array {
	return array(
		'gp'  => __( 'GP', 'blueline' ),
		'g'   => __( 'G', 'blueline' ),
		'a'   => __( 'A', 'blueline' ),
		'pts' => __( 'PTS', 'blueline' ),
		'pim' => __( 'PIM', 'blueline' ),
	);
}

/**
 * Print one player's current-season stat cells (GP / G / A / PTS / PIM,
 * blueline_roster_stat_labels()' order) for a team roster row
 * (sportspress/team-lists.php, both the curated-list and the
 * blueline_get_team_roster() fallback loop) -- a real <table> so
 * SportsPress's own already-loaded sp-sortable-table/DataTables behaviour
 * (see league-table.php, same classes, same page) makes every column
 * click-to-sort with no new JS.
 *
 * Reads through blueline_get_player_season_stats() -- SP_Player::data(),
 * memoised, correctly season- and league-scoped (see that function's own
 * docblock, inc/account/player-data.php) -- rather than the columns already
 * available on $row from SP_Player_List::data(). That data only carries
 * whatever columns an admin configured on THIS PARTICULAR sp_list post
 * (SP_Player_List's own constructor default is just number/team/position,
 * no stats at all), curated inconsistently across a decade of team pages;
 * reading through the account dashboard's own stats reader instead means
 * every roster shows the same real numbers regardless of that per-list
 * configuration, the same way the account dashboard's own "My season"
 * module already does for a single signed-in player.
 *
 * PTS is goals + assists, computed here rather than stored: SportsPress
 * data carries no separate points figure for a skater, and this is the
 * standard hockey box-score derivation, not a guess.
 *
 * @param int $player_id sp_player post ID.
 * @return void
 */
function blueline_render_roster_stats( int $player_id ) {
	if ( ! function_exists( 'blueline_get_player_season_stats' ) ) {
		return;
	}

	$stats        = blueline_get_player_season_stats( $player_id );
	$stats['pts'] = $stats['g'] + $stats['a'];

	foreach ( array_keys( blueline_roster_stat_labels() ) as $bl_key ) {
		printf( '<td class="bl-sp-roster__stat">%s</td>', esc_html( (string) $stats[ $bl_key ] ) );
	}
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

add_filter( 'wp_nav_menu_objects', 'blueline_sp_primary_nav_current_item', 10, 2 );
/**
 * Live-review finding: on any SportsPress singular view (a team, player, or
 * staff page) or a schedule-related event page, EVERY top-level primary-menu
 * item renders identically -- no "you are here" at all, on either the
 * desktop bar or the mobile drawer, even though both already have working
 * CSS for it (see .bl-nav__link[aria-current="page"] in nav.css) and even
 * though a ordinary Page (e.g. /schedule itself, or /news) highlights
 * correctly on both.
 *
 * The gap is not the CSS -- it never fires. WordPress's own
 * wp_nav_menu_objects "current item" detection (wp-includes'
 * _wp_menu_item_classes_by_context()) only ever recognises a menu item as
 * current by comparing the queried object against Pages/Posts/standard
 * taxonomies it linked to; it has no notion that a singular sp_team page and
 * the "Rosters / Stats" Page are related, or that a singular sp_event page
 * and the "Schedule" Page are -- both pairs are structurally unrelated
 * posts as far as WordPress's page hierarchy is concerned. Confirmed live:
 * visiting /team/royals or a single /event/{id} page left every top-level
 * item without 'current-menu-item'/'current_page_item' (Blueline_Nav_Walker
 * only ever sets aria-current="page" from those two classes -- see its own
 * start_el() docblock).
 *
 * This adds those two classes to the one top-level item that legitimately
 * "owns" the current SportsPress view, resolved by
 * blueline_sp_current_page_hub_url() -- so the same aria-current CSS that
 * already works for ordinary Pages now also lights up for the SportsPress
 * views nested under them. Scoped to the 'primary' location only: the
 * 'utility' account menu (see blueline_utility_nav_auth_state()) has no
 * SportsPress-adjacent items to mark.
 *
 * @param WP_Post[]|object[] $items Nav menu items resolved for this call.
 * @param stdClass           $args  wp_nav_menu() args object.
 * @return WP_Post[]|object[]
 */
function blueline_sp_primary_nav_current_item( $items, $args ) {
	if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) {
		return $items;
	}

	$hub_url = blueline_sp_current_page_hub_url();

	if ( '' === $hub_url ) {
		return $items;
	}

	$hub_path = function_exists( 'blueline_utility_normalize_path' ) ? blueline_utility_normalize_path( $hub_url ) : '';

	if ( '' === $hub_path ) {
		return $items;
	}

	foreach ( $items as $item ) {
		if ( empty( $item->url ) || blueline_utility_normalize_path( $item->url ) !== $hub_path ) {
			continue;
		}

		$classes = empty( $item->classes ) ? array() : (array) $item->classes;

		foreach ( array( 'current-menu-item', 'current_page_item' ) as $flag ) {
			if ( ! in_array( $flag, $classes, true ) ) {
				$classes[] = $flag;
			}
		}

		$item->classes = $classes;
	}

	return $items;
}

/**
 * The top-level primary-menu "hub" Page the CURRENT request belongs under,
 * for the SportsPress singular views WordPress's own current-menu-item
 * detection cannot place (see blueline_sp_primary_nav_current_item()) --
 * '' on an ordinary page/post, where WordPress's own detection already
 * works and this must stay out of the way.
 *
 * The sp_league/sp_season taxonomy archives are deliberately NOT handled here:
 * this site's own "Standings"/"Playoffs" pages (confirmed live) are hand-
 * built Pages embedding [league_table] shortcodes per division, not
 * SportsPress's native taxonomy-archive template, so there is no such
 * archive view actually reachable on this site to resolve a hub for.
 *
 * @return string Absolute hub URL, or '' if none applies.
 */
function blueline_sp_current_page_hub_url(): string {
	if ( ! post_type_exists( 'sp_team' ) ) {
		return '';
	}

	if ( is_singular( array( 'sp_team', 'sp_player', 'sp_staff' ) ) ) {
		return blueline_sp_primary_nav_title_url( 'Rosters' );
	}

	if ( is_singular( 'sp_event' ) ) {
		return function_exists( 'blueline_resolve_link' ) ? blueline_resolve_link( 'page_schedule' ) : '';
	}

	return '';
}

/**
 * Resolves a top-level primary-menu item's URL by matching its title, for
 * the one hub ("Rosters / Stats") the control panel's Links tab has no
 * configured page for (unlike page_schedule/page_standings/page_register,
 * inc/settings/defaults.php). Mirrors blueline_utility_nav_auth_state()'s
 * own title-substring fallback (inc/template-tags.php) for the same reason:
 * there is no other stable handle available. Matches the FIRST published,
 * top-level (depth-0) 'primary' menu item whose title contains $needle,
 * case-insensitively.
 *
 * @param string $needle Case-insensitive substring to match against each
 *                        top-level item's stripped title (e.g. "Rosters").
 * @return string Absolute URL, or '' if no primary menu, or no matching item.
 */
function blueline_sp_primary_nav_title_url( string $needle ): string {
	$locations = get_nav_menu_locations();

	if ( empty( $locations['primary'] ) ) {
		return '';
	}

	$items = wp_get_nav_menu_items( $locations['primary'] );

	if ( ! $items ) {
		return '';
	}

	foreach ( $items as $item ) {
		if ( ! empty( $item->menu_item_parent ) || empty( $item->url ) ) {
			continue; // Top-level items only -- a submenu item cannot be a hub.
		}

		$title = wp_strip_all_tags( (string) $item->title );

		if ( false !== stripos( $title, $needle ) ) {
			return (string) $item->url;
		}
	}

	return '';
}

add_filter( 'option_sportspress_league_menu_teams', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_league_menu_title', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_league_menu_logo', 'blueline_sp_blank_frontend_option' );
/**
 * P0 finding 1: SportsPress Pro's "League Menu" sub-module
 * (includes/sportspress-league-menu/sportspress-league-menu.php) is a
 * separate always-loaded plugin feature, not something this theme's own
 * header renders. Its own JS, confirmed in the installed plugin at
 * includes/sportspress/assets/js/sportspress.js, unconditionally prepends
 * `<div class="sp-header sp-header-loaded">` to <body> on EVERY front-end
 * page, before .bl-site and before this theme's own skip link; a small
 * always-loaded core stylesheet (sportspress.css, not the
 * sportspress_enable_frontend_css-gated one) gives that div
 * `position: relative; z-index: 10000;`. SportsPress_League_Menu::menu()
 * then fills it with one team-logo link per team configured in
 * `sportspress_league_menu_teams` (22 on this site) UNLESS that option
 * (and the title/logo options that would otherwise still render an empty
 * bar) evaluate empty, in which case menu() returns before printing
 * anything and the wrapper div stays empty. An empty div has no focusable
 * content, so it cannot cover the mobile nav (confirmed: at 360px with the
 * drawer open, elementFromPoint(180,150) previously returned the league
 * menu's own IMG.sp-team-logo, ahead of Home/News/League Info/
 * Rosters-Stats/Schedule) and cannot push 22 links ahead of the skip link
 * in tab order.
 *
 * Blanking the three options (same front-end-only pattern as
 * blueline_sp_blank_frontend_option()'s other callers below, so the
 * League Menu *settings screen* still shows/edits the real saved values)
 * is the theme-side, non-destructive way to disable this: it does not
 * touch the plugin, does not delete the site's saved configuration, and
 * survives a SportsPress update. The theme's own header already carries
 * the brand mark, and DESIGN.md's whole point is a light, unintimidating
 * first impression, not a wall of competitive crests above the fold.
 */

/**
 * The team ids the league menu is configured with, as the admin actually saved
 * them.
 *
 * The three option_* filters above blank these on the front end to stop
 * SportsPress' own League Menu module prepending 22-plus crest links to <body>
 * ahead of the skip link. That blanking is what this theme wants for the
 * plugin's renderer, and NOT what it wants for its own footer directory, so
 * this reads the stored value with the filter lifted for exactly one call and
 * puts it straight back.
 *
 * Note the filter is is_admin()-scoped, which means WP-CLI (where is_admin()
 * is false) sees the blanked value too: `wp option get
 * sportspress_league_menu_teams` returns '' on this site, and that is the filter
 * working, not a missing option.
 *
 * @return int[] Published sp_team post ids, in the order the admin arranged
 *               them; empty when the league menu is unconfigured.
 */
function blueline_league_menu_team_ids(): array {
	remove_filter( 'option_sportspress_league_menu_teams', 'blueline_sp_blank_frontend_option' );
	$stored = get_option( 'sportspress_league_menu_teams' );
	add_filter( 'option_sportspress_league_menu_teams', 'blueline_sp_blank_frontend_option' );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	$ids = array();

	foreach ( $stored as $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			continue;
		}

		$id = absint( $value );

		// De-duplicated because the option is a hand-arranged list and nothing
		// in SportsPress' own UI stops the same team being added twice.
		if ( $id > 0 && ! in_array( $id, $ids, true ) && 'publish' === get_post_status( $id ) ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * The team directory's resolved position, clamped to a real choice.
 *
 * `blueline_settings()` does no validation -- it merges defaults and
 * returns whatever is stored, which could be a value written by a raw `wp
 * db import`, a hand-edited row, or a plugin filtering
 * `option_blueline_settings`, bypassing the admin panel's own save-time
 * `choices` guard entirely. Without this clamp, an unrecognized value
 * would make BOTH blueline_footer_team_directory() and
 * blueline_render_team_flyout() return early (one checks `'footer' !==
 * ...`, the other `'flyout' !== ...`) -- the team directory silently
 * vanishing sitewide while the admin panel still shows the master toggle
 * on. Same clamp-on-read idiom as blueline_announcement_severity()
 * (inc/announcement.php) and blueline_season_state_override()
 * (inc/season-state.php).
 *
 * @return string 'footer' or 'flyout'.
 */
function blueline_team_directory_position(): string {
	$stored = (string) blueline_settings( 'chrome_team_directory_position' );

	return in_array( $stored, array( 'footer', 'flyout' ), true ) ? $stored : 'footer';
}

add_filter( 'option_sportspress_header_sponsors_limit', 'blueline_sp_header_sponsors_limit' );
/**
 * The real gate for `chrome_sponsors`: force the option SportsPress checks
 * before printing anything at all, front end only, to 0 when the toggle is
 * off.
 *
 * Confirmed in the installed plugin (SportsPress_Sponsors::header(),
 * includes/sportspress-sponsors/sportspress-sponsors.php, hooked on
 * `wp_footer`):
 *
 *     $limit = get_option( 'sportspress_header_sponsors_limit', 0 );
 *     if ( $limit ) {
 *         ... echoes <div class="sp-header-sponsors">[sponsors ...]</div>
 *         ... AND the inline <script> that reads
 *             sportspress_header_sponsors_selector and prepends that div
 *             into it ...
 *     }
 *
 * The entire method (both the sponsor markup and the relocation script)
 * is inside that one `if ( $limit )`. An earlier version of this fix only
 * changed `sportspress_header_sponsors_selector` to something unmatchable,
 * on the theory that SportsPress would then have nowhere to prepend the
 * block. That was wrong: with $limit still truthy, `header()` still prints
 * `.sp-header-sponsors` and still runs the prepend script; prepending an
 * empty jQuery selection is simply a no-op, so the sponsor markup just
 * stays wherever `wp_footer` put it (stranded at the end of the page, not
 * hidden) instead of never being printed. Forcing $limit itself to 0 is
 * what actually stops `header()` from printing anything.
 *
 * Scoped to the front end only (is_admin() passes the real value straight
 * through), matching blueline_sp_blank_frontend_option()'s own convention
 * below, so the Sponsors settings screen keeps showing/editing the site's
 * actual saved limit; turning the control-panel toggle off must not look
 * like the admin's own SportsPress setting silently changed.
 *
 * @param mixed $value Stored option value.
 * @return mixed
 */
function blueline_sp_header_sponsors_limit( $value ) {
	if ( is_admin() ) {
		return $value;
	}

	return blueline_section_enabled( 'chrome_sponsors' ) ? $value : 0;
}

add_filter( 'sportspress_header_sponsors_selector', 'blueline_header_sponsors_selector' );
/**
 * Tell SportsPress which element the header sponsors should be inserted into.
 *
 * The function above, blueline_sp_header_sponsors_limit(), is what actually
 * stops SportsPress printing anything while `chrome_sponsors` is off; see
 * its own docblock for why forcing the limit to 0, not this selector, is
 * the real gate. While that toggle is off, `SportsPress_Sponsors::header()`
 * never reaches the line that reads this filter at all, so this branch is
 * never actually exercised on a real request in that state.
 *
 * `:not(*)` is returned anyway, as defence in depth: a selector that
 * matches nothing, in both CSS and jQuery, by construction, unlike
 * `.bl-header__sponsors--disabled`, an earlier version of this fix's
 * choice, which is a BEM modifier of a class (`.bl-header__sponsors`) this
 * theme really emits elsewhere, and so the single edit most likely to be
 * made to that div in the future (adding a state class to it) would have
 * silently re-enabled injection. `:not(*)` cannot collide with any class
 * this theme, or any future edit to it, ever adds. If some future caller
 * (a different plugin, a SportsPress update that stops respecting the
 * limit) ever does reach this filter while the toggle is off, the result
 * has nowhere to land instead of stranding sponsor markup wherever it was
 * printed.
 *
 * @param string $selector Default selector.
 * @return string
 */
function blueline_header_sponsors_selector( $selector ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the filter's own signature; this theme always returns one fixed selector regardless of the default passed in.
	if ( ! blueline_section_enabled( 'chrome_sponsors' ) ) {
		return ':not(*)';
	}

	return '.bl-header__sponsors';
}

add_filter( 'option_sportspress_header_sponsors_top', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_header_sponsors_right', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_footer_sponsors_css_background', 'blueline_sp_blank_frontend_option' );
add_filter( 'option_sportspress_footer_sponsors_css_text', 'blueline_sp_blank_frontend_option' );
/**
 * Blank one of SportsPress Sponsors' own decorative option values on the
 * front end only, so this theme's own token-based CSS is the only styling
 * that reaches the browser for these two surfaces, consistent with
 * DESIGN.md's stated constraint that the theme supplies the only styling
 * SportsPress surfaces get.
 *
 * SportsPress_Sponsors::header() prints
 * `style="margin-top: {$top}px; margin-right: {$right}px;"` directly on
 * `.sp-header-sponsors` as an inline HTML attribute, so no CSS selector,
 * however specific, can ever override that from an external stylesheet.
 * Blanking the two option values makes it print `style="margin-top: px;
 * margin-right: px;"`; a value-less declaration like `margin-top: px;` is
 * invalid CSS and is simply dropped by the browser, which is equivalent to
 * 0 for this purpose. The offset it would otherwise apply exists to nudge
 * a sponsor logo away from a generic theme's own header edge:
 * `.bl-header__sponsors`/`.sp-sponsors-loader` (header.css/sportspress.css)
 * already reserve and center that exact space, so the extra offset only
 * pushes the logo outside the box those rules size to.
 *
 * SportsPress_Sponsors::footer() prints a real `<style>` block containing
 * `.sp-footer-sponsors { background: ...; color: ...; }` directly into the
 * page (not gated by sportspress_enable_frontend_css, which this site
 * otherwise leaves off), so an external stylesheet rule of matching
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
 * inside a scroll container or gets one of its own, because "the page body must
 * never scroll horizontally" is a site-wide hard invariant, not a
 * today's-markup-shaped one, so this must not depend on SportsPress's exact
 * current class names.
 *
 * Re-scoped after review: this must touch SportsPress's own output only,
 * never an ordinary block-editor table in a blog post or page (those
 * already have a working .wp-block-table wrapper of their own, and forcing
 * display:block onto them via bl-table-self-scroll would be an untested,
 * out-of-scope behaviour change). The scoping problem is that SP tables can
 * legitimately appear on an ordinary Page too: /standings embeds
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
 *    `<div class="sp-table-wrapper">`, confirmed by reading every
 *    occurrence in the installed plugin. A cheap string check/replace
 *    handles this, the overwhelming common case, without any parsing.
 * 2. A cheap `strpos( $content, 'sp-' )` pre-check, then
 *    blueline_sp_ensure_tables_scroll() walks every remaining <table> via
 *    DOMDocument and gives any that (a) is SportsPress's own (carries an
 *    sp- prefixed class) and (b) still has no scroll-capable ancestor a
 *    self-contained scroll class directly. This is what actually closes
 *    the gap: event-venue.php is the one SP template today that renders
 *    its <table> with no wrapper at all (its embedded Leaflet map
 *    overflowed the page body at mobile widths until this was added (see
 *    the Task 8 fix report), and a future SportsPress update changing
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
	// SportsPress table and the expensive DOM pass is skipped entirely. An
	// ordinary blog post with a block-editor table (<table
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
 * sp-player-list, sp-player-statistics, sp-event-details, sp-event-venue,
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
 * is left completely untouched; see
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
		// Malformed fragment: leave content untouched rather than risk
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
			continue; // Not SportsPress's own markup (e.g. an ordinary block-editor table). Leave it untouched.
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
 * with a given prefix (e.g. "sp-"). Token-exact prefix matching: a class
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
 * archive page, which is the exact under-counting bug this project has already
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
 * Live-review finding: "The Next Puck Drop" countdown widget (SportsPress's
 * own Countdown widget, sportspress/countdown.php below overrides its
 * template) got permanently stuck on a game from over a week in the past
 * whose result was never entered, showing 00 Days 00 Hrs 00 Mins 00 Secs
 * forever instead of advancing to the real next dated game -- confirmed
 * live on staging while real future games existed the same week. Whatever
 * event the stock widget/template resolves (a pinned "Event" setting, a
 * specific Calendar, or SportsPress's own "next" pick) can end up being a
 * stale one; the template's own countdown math (date_diff() with $interval
 * ->invert zeroed rather than checked) then silently renders zeros for any
 * past date rather than refusing to.
 *
 * Whether a resolved event is "stale" -- pure, so the actual decision
 * blueline_sp_countdown_event() below makes is directly unit testable
 * without a WP_Post.
 *
 * @param string $post_date Event's post_date ('Y-m-d H:i:s', site-local),
 *                           or '' for no event at all.
 * @param int    $now_ts    Unix timestamp to evaluate "past" against.
 * @return bool
 */
function blueline_sp_event_date_is_past( string $post_date, int $now_ts ): bool {
	if ( '' === $post_date ) {
		return false;
	}

	return strtotime( $post_date ) < $now_ts;
}

/**
 * Pure decision: given the event the countdown widget/template's own
 * resolution already picked (id/calendar/next -- see
 * blueline_sp_event_date_is_past()'s docblock) and, separately, the nearest
 * genuinely future-dated event this theme's own lookup found in the same
 * scope (team/league/season), decide which one the countdown should
 * actually show, and whether that is a live countdown or an
 * already-played fallback with no clock to run.
 *
 * - The picked event is not stale (future, or none picked at all): use it
 *   as-is: it is either already correct, or there is nothing to show.
 * - The picked event IS stale, and a real future event was found in scope:
 *   switch to that one -- never show a stale pick when a better answer
 *   exists.
 * - The picked event is stale AND nothing future exists anywhere in scope:
 *   the stale pick survives, but 'is_future' is false, which the template
 *   (never this function) is responsible for rendering as "already played"
 *   copy rather than a countdown claiming to be live.
 *
 * @param array{ID:int, post_date:string}|null $picked      Whatever the stock resolution chose, or null.
 * @param array{ID:int, post_date:string}|null $next_future Nearest future-dated event in scope, or null.
 * @param int                                  $now_ts      Unix timestamp to evaluate "past" against.
 * @return array{event: array{ID:int, post_date:string}|null, is_future: bool}
 */
function blueline_sp_decide_countdown_event( ?array $picked, ?array $next_future, int $now_ts ): array {
	$picked_is_past = null !== $picked && blueline_sp_event_date_is_past( $picked['post_date'] ?? '', $now_ts );

	if ( ! $picked_is_past ) {
		return array(
			'event'     => $picked,
			'is_future' => null !== $picked,
		);
	}

	if ( null !== $next_future ) {
		return array(
			'event'     => $next_future,
			'is_future' => true,
		);
	}

	return array(
		'event'     => $picked,
		'is_future' => false,
	);
}

/**
 * The nearest sp_event, in the same team/league/season scope $scope_args
 * narrows (same meta_query/tax_query shape SportsPress's own countdown.php
 * template builds), whose date is still ahead of $now.
 *
 * Untested at this layer for the same reason blueline_season_state_data()'s
 * own WP_Query calls are (see tests/SeasonStateOverrideTest.php's
 * test_the_query_moment_is_built_from_the_injected_timestamp() docblock): a
 * from-memory WP_Query/date_query stub is exactly how this project's
 * option-lifecycle stubs previously shipped assumptions nobody could check
 * against core's real behaviour. blueline_sp_decide_countdown_event() above
 * carries the actual decision this exists to serve, and IS unit tested.
 *
 * @param array    $scope_args Extra meta_query/tax_query args narrowing the scope.
 * @param int|null $now        Unix timestamp to evaluate against; null for the current time.
 * @return array{ID:int, post_date:string}|null
 */
function blueline_sp_next_dated_event( array $scope_args = array(), $now = null ) {
	if ( ! post_type_exists( 'sp_event' ) || ! function_exists( 'blueline_season_state_moment' ) ) {
		return null;
	}

	list( $now_mysql, ) = blueline_season_state_moment( $now );

	$query = new WP_Query(
		array_merge(
			$scope_args,
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'future' ),
				'no_found_rows'  => true,
				'date_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- bounded to a single posts_per_page=1 lookup, not an unbounded query.
					array(
						'column' => 'post_date',
						'after'  => $now_mysql,
					),
				),
			)
		)
	);

	if ( empty( $query->posts ) ) {
		return null;
	}

	$post = $query->posts[0];

	return array(
		'ID'        => (int) $post->ID,
		'post_date' => (string) $post->post_date,
	);
}

/**
 * Pure decision: explanatory copy for a league table (sportspress/
 * league-table.php) where no listed team has played any games yet, or ''
 * when at least one has -- or when there is simply no way to tell.
 *
 * Live-review finding: a division whose season/playoff round hasn't
 * started yet rendered every team at position 0 with a 0-0-0-0 record and
 * NO explanatory text at all -- confirmed live on "Division 5 | Playoffs
 * S2026" -- reading as a broken table rather than "nothing has happened
 * here yet."
 *
 * Kept free of SportsPress/WordPress calls -- the caller alone knows its
 * own table's column shape (whether it has a 'gp' column, or must fall
 * back to W/L/T/OT) and extracts the flat list of per-team figures this
 * function only ever compares to zero -- so the actual "is this table
 * empty" decision is directly unit testable without a real
 * SP_League_Table.
 *
 * @param bool  $has_progress_signal Whether the caller found ANY column
 *                                    (gp, or at least one record component)
 *                                    it could read a progress figure from
 *                                    at all. False means "cannot tell",
 *                                    which must never be treated the same
 *                                    as "confirmed zero".
 * @param array $progress_figures    Every listed team's own progress figure
 *                                    (its 'gp' value, or each of its
 *                                    W/L/T/OT record components) --
 *                                    whichever the caller used to decide
 *                                    $has_progress_signal.
 * @param bool  $has_teams           Whether the table lists any teams at all.
 * @param bool  $is_playoffs         Whether the table's own caption/title
 *                                    names a playoff round.
 * @return string
 */
function blueline_sp_zero_games_note( bool $has_progress_signal, array $progress_figures, bool $has_teams, bool $is_playoffs ): string {
	if ( ! $has_teams || ! $has_progress_signal ) {
		return '';
	}

	foreach ( $progress_figures as $figure ) {
		if ( (int) $figure > 0 ) {
			return '';
		}
	}

	return $is_playoffs
		? __( 'Playoffs have not started yet.', 'blueline' )
		: __( 'No games have been played yet this season.', 'blueline' );
}

/**
 * The effective display state of an sp_event, independent of whether a
 * score has been entered.
 *
 * P0 finding 2: blueline_sp_event_hero() used to decide pre/post-game
 * purely from `sp_get_status() === 'results'`, which SportsPress only ever
 * returns once a result row exists in `sp_results`. Confirmed live:
 * eleven published events in the last 90 days have no `sp_results` row at
 * all, so an event dated well in the past still advertised "Preview" and
 * offered a live "Add to calendar" link. This function is pure (no
 * SportsPress/WordPress calls of its own) so it can be unit tested
 * directly: given whether a result exists and when the event actually
 * starts, it returns exactly one of three states, gated on the clock
 * rather than on data entry:
 *
 *   'final'   : a result has been recorded. Always wins regardless of time
 *                (a game can be scored before its listed start passes, e.g.
 *                a corrected/backdated entry).
 *   'pending' : no result yet, but the start time has already passed.
 *                "Result pending", never "Preview", and never a calendar
 *                link for a game that has already happened.
 *   'preview' : no result, and the start time has not passed yet. The
 *                only state that gets a calendar link.
 *
 * @param bool      $has_results     Whether sp_get_status() returned 'results'.
 * @param int|false $start_timestamp GMT unix timestamp the event starts, or
 *                                    false if unknown (treated as not yet
 *                                    started, i.e. never 'pending').
 * @param int|null  $now_timestamp   Current GMT unix timestamp; defaults to
 *                                    time(). Exposed as a parameter purely so
 *                                    tests can pass a fixed clock.
 * @return string 'final'|'pending'|'preview'.
 */
function blueline_sp_event_state( $has_results, $start_timestamp, $now_timestamp = null ) {
	if ( $has_results ) {
		return 'final';
	}

	if ( null === $now_timestamp ) {
		$now_timestamp = time();
	}

	if ( $start_timestamp && $start_timestamp <= $now_timestamp ) {
		return 'pending';
	}

	return 'preview';
}

/**
 * Subscribe URLs for a team's whole SportsPress calendar.
 *
 * The league already publishes one iCal feed per team: an sp_calendar post
 * carrying `sp_team` = the team's id, whose permalink serves iCal when asked
 * with `?feed=sp-ical`. Each team's own page links it by hand in its post
 * content; this resolves the same feed from a team id so the account dashboard
 * can offer it without anyone maintaining a second copy of the URL.
 *
 * Returns BOTH forms because no single one works everywhere. `webcal://` is
 * what iOS and macOS hand to Calendar, and what Outlook takes on Windows;
 * Android generally does nothing with it, and wants Google's own add-by-URL
 * screen instead. Choosing between them is a presentation decision, made in
 * the template and refined by assets/src/js/calendar-links.js, not here.
 *
 * A SUBSCRIPTION, not an export: the reader's calendar re-reads the feed, so a
 * rescheduled game corrects itself instead of leaving a stale entry behind, and
 * one action covers the whole season rather than one game.
 *
 * @param int $team_id sp_team post ID.
 * @return array{webcal:string, google:string, calendar_id:int}|null
 *         Null when the team has no published calendar.
 */
function blueline_team_calendar_urls( $team_id ) {
	$team_id = absint( $team_id );

	if ( ! $team_id || ! post_type_exists( 'sp_calendar' ) ) {
		return null;
	}

	$calendars = get_posts(
		array(
			'post_type'      => 'sp_calendar',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'sp_team', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-row lookup of one team's calendar, not a listing query.
			'meta_value'     => (string) $team_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'        => 'ID',
			'order'          => 'DESC',
		)
	);

	if ( ! $calendars ) {
		return null;
	}

	$calendar_id = (int) $calendars[0];
	$permalink   = get_permalink( $calendar_id );

	if ( ! $permalink ) {
		return null;
	}

	$feed = add_query_arg( 'feed', 'sp-ical', $permalink );

	// webcal:// is the same URL under a scheme that tells the OS "subscribe"
	// rather than "download once". Replacing only the leading scheme, so a
	// host or path that happens to contain "http" is untouched.
	$webcal = preg_replace( '#^https?://#', 'webcal://', $feed );

	return array(
		'calendar_id' => $calendar_id,
		'webcal'      => $webcal,

		/*
		 * Encoded HERE, deliberately. add_query_arg() does NOT encode values:
		 * it builds through build_query(), which calls
		 * _http_build_query( $data, null, '&', '', false ); that last `false`
		 * is $urlencode (wp-includes/functions.php, verified against the
		 * installed core rather than assumed). Without this the cid would
		 * carry a literal "://" and a second "?", and Google truncates the
		 * feed URL at that "?".
		 */
		'google'      => add_query_arg(
			'cid',
			rawurlencode( $webcal ),
			'https://calendar.google.com/calendar/render'
		),
	);
}

/**
 * Print a team's "subscribe to this season" calendar links -- Apple/Outlook
 * (webcal) and Google -- or nothing at all when the team has no published
 * calendar (blueline_team_calendar_urls() itself returns null).
 *
 * Every team's page carried this by hand until now: the same two links,
 * the same "Take your schedule with you." lead-in, and (per a live content
 * audit) the same 2016-era GCal.png/iCal.png image attachments and a
 * hardcoded, environment-specific webcal:// URL -- one team at a time,
 * copy-pasted, unable to follow a domain change or a URL scheme fix. This
 * renders the identical two destinations from the one already-tested
 * source (blueline_team_calendar_urls(), used unchanged by the account
 * dashboard's "My next game" module) instead.
 *
 * data-calendar-links / data-calendar="apple|google" is the same contract
 * assets/src/js/calendar-links.js already reads sitewide (it is imported
 * once, globally, in assets/src/js/index.js) -- it reorders the two links
 * so the reader's likely platform comes first, with no new JS needed here.
 *
 * Hooked to sportspress_after_single_team (below), not called from inside
 * sportspress/team-events.php: that partial only ever renders inside the
 * "Games" tab of the Division Table/Games sp-tab-group SP_Template_Loader
 * builds around the team's tables + events (SP_Template_Loader::add_content(),
 * SportsPress core, registers both as TAB templates, not stacked
 * sections) -- printing the calendar links there put them inside
 * <div class="sp-tab-content-events" style="display:none">, invisible
 * until that tab is clicked. Reported live as "I don't see the calendar."
 * sportspress_after_single_team fires once, unconditionally, after every
 * stacked section but BEFORE that tab group is appended to the page (see
 * SP_Template_Loader::add_content() in sportspress-pro), which is exactly
 * "introduces the Division Table/Games tabs" -- always visible, and
 * directly attached to the schedule it was reported as floating away from.
 *
 * @param int $team_id sp_team post ID.
 * @return void
 */
function blueline_render_team_calendar_links( $team_id ) {
	$team_calendar = function_exists( 'blueline_team_calendar_urls' ) ? blueline_team_calendar_urls( $team_id ) : null;

	if ( ! $team_calendar ) {
		return;
	}
	?>
	<div class="bl-sp-team-calendar" data-calendar-links>
		<span class="bl-sp-team-calendar__label"><?php esc_html_e( 'Take your schedule with you:', 'blueline' ); ?></span>
		<a class="bl-btn bl-btn--secondary" data-calendar="apple" href="<?php echo esc_url( $team_calendar['webcal'], array( 'webcal', 'http', 'https' ) ); ?>">
			<span class="bl-skew"><span><?php esc_html_e( 'Apple / Outlook', 'blueline' ); ?></span></span>
		</a>
		<a class="bl-btn bl-btn--secondary" data-calendar="google" href="<?php echo esc_url( $team_calendar['google'] ); ?>">
			<span class="bl-skew"><span><?php esc_html_e( 'Google', 'blueline' ); ?></span></span>
		</a>
	</div>
	<?php
}

/**
 * Print a team's own "Upcoming Games" table -- the same [event_list]
 * shortcode /schedule uses (id pointing at a saved event-list
 * configuration), reading the team's own sp_calendar post as that
 * configuration, via sportspress/event-list.php's own theme override
 * (grouped date headings, muted venue text -- see that file's own
 * docblock).
 *
 * Every team's page carried this by hand until now, as a literal
 * `[event_list id="..." title="Upcoming Games" ...]` shortcode pasted into
 * the team's Description -- one PER-TEAM sp_calendar post id, unique to
 * that team, hand-copied from wherever the shortcode was first generated.
 * A live content audit clearing that pasted boilerplate (see
 * blueline_render_team_calendar_links()'s own docblock for the matching
 * calendar-links half of the same cleanup) removed the shortcode text but
 * left the underlying sp_calendar posts themselves untouched -- this
 * renders the identical table from that same post, auto-resolved via
 * blueline_team_calendar_urls()'s own calendar_id lookup, instead of
 * requiring the shortcode to be pasted back in by hand.
 *
 * This is NOT the same thing as sportspress/team-events.php's own
 * "Fixtures"/"Results" cards (the "Games" tab, sportspress_after_single_team
 * teammate below) -- reported live as two visibly different features:
 * this is a plain grouped table of the next 5 upcoming games (matching
 * /schedule's own layout exactly), that is a card-based fixtures/results
 * breakdown. Both have coexisted on a team's own page before this
 * session's changes (confirmed against team-events.php's own docblock,
 * which already described a "Results" card sitting directly beneath an
 * "Upcoming Games" table from the pasted shortcode) -- restoring this
 * table does not replace or duplicate that section's job, it restores
 * the piece that went missing when the pasted shortcode was cleared.
 *
 * @param int $team_id sp_team post ID.
 * @return void
 */
function blueline_render_team_schedule_table( $team_id ) {
	$team_calendar = function_exists( 'blueline_team_calendar_urls' ) ? blueline_team_calendar_urls( $team_id ) : null;

	if ( ! $team_calendar || empty( $team_calendar['calendar_id'] ) || ! function_exists( 'sp_get_template' ) ) {
		return;
	}

	sp_get_template(
		'event-list.php',
		array(
			'id'                   => $team_calendar['calendar_id'],
			'title'                => __( 'Upcoming Games', 'blueline' ),
			'status'               => 'future',
			'number'               => 5,
			'order'                => 'default',
			'columns'              => array( 'event', 'teams', 'time', 'venue' ),
			'show_all_events_link' => true,

			/*
			 * Reversed live, per explicit request: an earlier round of
			 * feedback ("too busy") turned this off for this one table --
			 * this site's own sportspress_event_list_show_logos option is
			 * "yes" (default "no"), so /schedule and any other [event_list]
			 * shortcode already show these crests regardless of this
			 * override. Re-enabled now that the table's own column widths
			 * (see .sp-event-list td.data-home/.data-away below) are also
			 * being fixed -- the two changes were requested together, on
			 * the reasoning that a tighter, better-proportioned table
			 * would no longer feel cluttered with the crests back.
			 */
			'show_team_logo'       => true,
		)
	);
}

add_action( 'sportspress_after_single_team', 'blueline_render_team_calendar_links_hook' );
/**
 * Callback for sportspress_after_single_team -- see
 * blueline_render_team_calendar_links()'s and
 * blueline_render_team_schedule_table()'s own docblocks for why this hook
 * and not a direct call from a template partial.
 *
 * @return void
 */
function blueline_render_team_calendar_links_hook() {
	$team_id = get_the_ID();
	blueline_render_team_calendar_links( $team_id );
	blueline_render_team_schedule_table( $team_id );
}

/**
 * Shared start/end timestamps and venue label for a single sp_event's
 * calendar links (Google and Apple/Outlook, blueline_sp_event_calendar_url()
 * and blueline_sp_event_ics_url() below) -- kept in one place so the two
 * links can never silently disagree on when the game starts/ends or where
 * it is.
 *
 * @param int $event_id sp_event post ID.
 * @return array{start:int, end:int, location:string}|null Null when the
 *         start time is unknown.
 */
function blueline_sp_event_calendar_bounds( $event_id ) {
	$start_ts = blueline_sp_event_start_timestamp( $event_id );

	if ( ! $start_ts ) {
		return null;
	}

	$minutes = 90;
	if ( class_exists( 'SP_Event' ) ) {
		$event         = new SP_Event( $event_id );
		$event_minutes = (int) $event->minutes();
		if ( $event_minutes > 0 ) {
			$minutes = $event_minutes;
		}
	}

	$venue_names = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue', array( 'fields' => 'names' ) ) : array();

	return array(
		'start'    => $start_ts,
		'end'      => $start_ts + ( $minutes * MINUTE_IN_SECONDS ),
		'location' => ( ! is_wp_error( $venue_names ) && ! empty( $venue_names ) ) ? $venue_names[0] : '',
	);
}

/**
 * A Google Calendar "add event" link for a single upcoming sp_event. No JS, no
 * external dependency beyond the calendar.google.com URL scheme: a plain
 * <a href> that works with or without a Google account.
 *
 * Kept for one-off use; the account dashboard offers the team's whole season
 * through blueline_team_calendar_urls() instead, since a subscription both
 * covers every game and corrects itself when one is rescheduled.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready URL, or '' if the start time is unknown.
 */
function blueline_sp_event_calendar_url( $event_id ) {
	$bounds = blueline_sp_event_calendar_bounds( $event_id );

	if ( ! $bounds ) {
		return '';
	}

	$args = array(
		'action'   => 'TEMPLATE',
		'text'     => blueline_sp_title( $event_id ),
		'dates'    => gmdate( 'Ymd\THis\Z', $bounds['start'] ) . '/' . gmdate( 'Ymd\THis\Z', $bounds['end'] ),
		'location' => $bounds['location'],
	);

	return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
}

/**
 * An Apple/Outlook "add event" link for a single upcoming sp_event.
 *
 * Reported live: the event page's "Add to calendar" only ever offered
 * Google -- the one-off single-event equivalent of the gap
 * blueline_render_team_calendar_links() already closed for a team's whole
 * season (that function's own docblock covers why a subscription needs
 * BOTH forms; the same "no single link works everywhere" reasoning applies
 * here). A subscription feed is the wrong shape for a one-off add, though
 * (RFC 5545 requires webcal:// to serve reachable HTTP with the calendar
 * app then polling it forever), so this builds a self-contained VCALENDAR/
 * VEVENT as a `data:text/calendar` URI instead of a URL -- no server
 * endpoint needed, the same "no external dependency" property
 * blueline_sp_event_calendar_url() already has for Google. Opening it
 * downloads a one-shot .ics that iOS/macOS Calendar and Windows Outlook
 * all already know how to import directly.
 *
 * @param int $event_id sp_event post ID.
 * @return string Escaped-ready data: URI, or '' if the start time is unknown.
 */
function blueline_sp_event_ics_url( $event_id ) {
	$bounds = blueline_sp_event_calendar_bounds( $event_id );

	if ( ! $bounds ) {
		return '';
	}

	// RFC 5545 TEXT escaping: backslash first, so the characters this adds
	// are never themselves re-escaped by the following replacements.
	$escape = static function ( $text ) {
		$text = str_replace( '\\', '\\\\', (string) $text );
		return str_replace( array( ',', ';', "\n" ), array( '\,', '\;', '\n' ), $text );
	};

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//' . $host . '//Event//EN',
		'BEGIN:VEVENT',
		'UID:sp-event-' . $event_id . '@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . gmdate( 'Ymd\THis\Z', $bounds['start'] ),
		'DTEND:' . gmdate( 'Ymd\THis\Z', $bounds['end'] ),
		'SUMMARY:' . $escape( blueline_sp_title( $event_id ) ),
	);

	if ( $bounds['location'] ) {
		$lines[] = 'LOCATION:' . $escape( $bounds['location'] );
	}

	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';

	// CRLF line endings are RFC 5545, not a style choice -- some calendar
	// clients reject a feed that uses bare \n instead.
	$ics = implode( "\r\n", $lines ) . "\r\n";

	return 'data:text/calendar;charset=utf8,' . rawurlencode( $ics );
}

/**
 * One team's own scoreline for a played sp_event, looked up by team ID,
 * never by position in a shared results array.
 *
 * Review finding: SP_Event::main_results() (SportsPress core) returns a
 * plain, re-indexed array built by looping $teams and doing
 * `$output[] = $team_result` only `if ( null != $team_result )`, so a
 * team with no result recorded for this event (a bye, an incomplete score
 * entry, etc.) is skipped entirely, shifting every following team's score
 * left by one slot. Zipping that array against this theme's own
 * separately-fetched $teams array by index (the original implementation)
 * would silently attribute one team's score to a different team the moment
 * either team is missing a value: home/away meta-row order is meaningful
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
 * The team ids attached to an sp_event's `sp_team` meta, absint()'d and
 * de-duplicated of anything that doesn't resolve to a real (positive) id:
 * the exact get_post_meta()/array_map()/array_filter() shape
 * blueline_sp_event_hero(), blueline_sp_event_teaser(),
 * blueline_social_meta_data_for_event() (inc/social-meta.php), and
 * blueline_sports_event_schema() (inc/social-meta.php) each used to repeat
 * verbatim. Callers that split this into a "team A"/"team B" pair (most of
 * them) still do that split themselves; this only resolves the raw id
 * list.
 *
 * @param int $event_id sp_event post ID.
 * @return int[] Team ids, re-indexed from 0, in the order SportsPress stored them.
 */
function blueline_sp_event_team_ids( int $event_id ): array {
	return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
}

/**
 * The player-facing venue label for a single sp_event's own `sp_venue`
 * term: "{Arena name} — {Pad name}" via blueline_venue_label() (P0 finding
 * 10) when that function exists, else the term's own name; '' when the
 * taxonomy doesn't exist, the event has no venue term at all, or the term
 * lookup errors.
 *
 * The exact wp_get_post_terms()/is_wp_error()/blueline_venue_label()-or-
 * fallback shape blueline_sp_event_hero(), blueline_sp_event_teaser(),
 * blueline_social_meta_data_for_event() (inc/social-meta.php), and
 * blueline_sports_event_schema() (inc/social-meta.php) each used to repeat
 * verbatim.
 * blueline_homepage_next_event_line() (inc/homepage-modules.php) used a
 * wp_get_object_terms() variant of the same lookup (functionally
 * identical for a single post's own terms), and now calls this one
 * canonical version instead.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_sp_event_venue_label( int $event_id ): string {
	if ( ! taxonomy_exists( 'sp_venue' ) ) {
		return '';
	}

	$venue_terms = wp_get_post_terms( $event_id, 'sp_venue' );

	if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
		return '';
	}

	return function_exists( 'blueline_venue_label' )
		? blueline_venue_label( $venue_terms[0]->term_id )
		: $venue_terms[0]->name;
}

/**
 * The event masthead: teams, "vs", venue (via blueline_venue_label(),
 * P0 finding 10, so this reads "Mr. Lube and Tires Arena — Red" rather
 * than just the pad name), and either an add-to-calendar link (game has not
 * started), "Result pending" (game has started but no score is in yet), or
 * the final score (a result has been recorded). Purely additive to what
 * the_content() renders below it: SportsPress's own event-logos/
 * event-details/event-venue sections still appear and are styled via
 * sportspress.css.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_hero( $event_id ) {
	if ( ! function_exists( 'sp_get_status' ) ) {
		return;
	}

	$teams = blueline_sp_event_team_ids( $event_id );

	$has_results = ( 'results' === sp_get_status( $event_id ) );
	$start_ts    = blueline_sp_event_start_timestamp( $event_id );
	$state       = blueline_sp_event_state( $has_results, $start_ts );
	$is_played   = ( 'final' === $state );

	$status_labels = array(
		'final'   => __( 'Final', 'blueline' ),
		'pending' => __( 'Result pending', 'blueline' ),
		'preview' => __( 'Preview', 'blueline' ),
	);

	$venue_name = blueline_sp_event_venue_label( $event_id );

	$calendar_url = ( 'preview' === $state ) ? blueline_sp_event_calendar_url( $event_id ) : '';
	$ics_url      = ( 'preview' === $state ) ? blueline_sp_event_ics_url( $event_id ) : '';
	?>
	<header class="sp-scoreboard">
		<?php
		if ( function_exists( 'blueline_render_faceoff_rings' ) ) {
			blueline_render_faceoff_rings();
		}
		?>
		<div class="bl-container sp-scoreboard__inner">
			<p class="sp-scoreboard__status">
				<span class="bl-skew"><span><?php echo esc_html( $status_labels[ $state ] ); ?></span></span>
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

			<?php if ( $calendar_url || $ics_url ) : ?>
				<div class="sp-scoreboard__calendar" data-calendar-links>
					<?php if ( $ics_url ) : ?>
						<?php
						/*
						 * esc_attr(), not esc_url(): confirmed live, esc_url()
						 * strips every %0d/%0a it finds (wp-includes/
						 * formatting.php's own anti-header-injection pass) --
						 * exactly the CRLF sequences RFC 5545 requires between
						 * every line of the encoded .ics payload below, so the
						 * calendar app received one unbroken, unparseable blob
						 * instead of a valid VEVENT. This data: URI is built
						 * entirely from this function's own escaped/encoded
						 * output (blueline_sp_event_ics_url(), never raw user
						 * input), so esc_attr()'s attribute-context escaping --
						 * which does not touch %0d/%0a -- is what this needed,
						 * the same way a plain <a href> to a same-origin,
						 * already-safe URL only ever needs esc_attr() rather
						 * than the fuller esc_url() sanitizer.
						 */
						?>
						<a class="bl-btn bl-btn--secondary" data-calendar="apple" href="<?php echo esc_attr( $ics_url ); ?>" download="<?php echo esc_attr( sanitize_file_name( blueline_sp_title( $event_id ) ) . '.ics' ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Apple / Outlook', 'blueline' ); ?></span></span>
						</a>
					<?php endif; ?>
					<?php if ( $calendar_url ) : ?>
						<a class="bl-btn bl-btn--secondary" data-calendar="google" href="<?php echo esc_url( $calendar_url ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Google', 'blueline' ); ?></span></span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * The player masthead: number, position (read from the sp_position
 * taxonomy, never a meta field), current team, and nationality (escaped
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
			<?php if ( has_post_thumbnail( $player_id ) ) : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--player"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></div>
			<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--fallback">
					<?php blueline_leaf_mark( 'bl-sp-hero__crest-mark' ); ?>
				</div>
			<?php endif; ?>

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
 * Pure decision: which division name(s) blueline_sp_team_hero() should
 * render, given the CURRENT season's division name
 * blueline_player_division_name() resolved (inc/account/player-data.php;
 * '' if none) for this team.
 *
 * Always prefers that season-scoped single answer over the team's own raw,
 * unscoped sp_league terms -- an empty season-scoped answer is honest ("no
 * current-season assignment yet"), never a reason to fall back to whatever
 * historical tags the team happens to carry. Kept as its own pure function,
 * separate from the WordPress-touching wrapper below, so this policy
 * decision is directly unit testable.
 *
 * @param string $current_season_division blueline_player_division_name()'s result, or ''.
 * @return string[]
 */
function blueline_sp_team_hero_decide_division_names( string $current_season_division ): array {
	return '' !== $current_season_division ? array( $current_season_division ) : array();
}

/**
 * Division name(s) blueline_sp_team_hero() should show for $team_id -- the
 * CURRENT season's division only, resolved through
 * blueline_player_division_name() (inc/account/player-data.php), never the
 * team's own raw sp_league terms directly.
 *
 * Live-review finding: team pages showed 5-7 stale/historical division
 * tags, most not matching any of the site's five actually-current
 * divisions -- this used to render EVERY sp_league term ever attached to
 * the team, comma-joined, with no "current" filter at all.
 * blueline_player_division_name()'s own docblock already documents exactly
 * this: "an earlier version of this function picked the team's own HIGHEST
 * term_id sp_league term... [that] claim was wrong: blueline_sp_team_hero()
 * renders EVERY sp_league term... so the two pages could disagree about the
 * same team's division," and "confirmed live: team 14955 alone carries 7
 * different Division terms spanning several seasons." This reuses that
 * function's season-scoped resolution (via the team's current-season
 * sp_table -- see blueline_team_current_table_id()) instead of inventing a
 * second way to determine "current," per that same docblock's own
 * direction, keeping this page and the My Account dashboard's division
 * line in agreement.
 *
 * Falls back to the team's own raw sp_league terms only when
 * inc/account/player-data.php's resolver isn't loaded at all (defensive --
 * every real request loads it, per functions.php's require order) rather
 * than when it resolves to "no current season," which is a real, honest
 * answer this function must not paper over.
 *
 * @param int $team_id sp_team post ID.
 * @return string[]
 */
function blueline_sp_team_hero_division_names( int $team_id ): array {
	if ( function_exists( 'blueline_player_division_name' ) ) {
		return blueline_sp_team_hero_decide_division_names( blueline_player_division_name( $team_id ) );
	}

	if ( ! taxonomy_exists( 'sp_league' ) ) {
		return array();
	}

	$terms = wp_get_post_terms( $team_id, 'sp_league' );

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$names = array();
	foreach ( $terms as $term ) {
		$names[] = $term->name;
	}

	return $names;
}

/**
 * The team masthead: crest, division(s). Record is deliberately not
 * recomputed here: SportsPress's own standings table (auto-rendered by
 * the_content() below, via team-tables.php) already highlights this team's
 * row with .sp-highlight, which is the correct, already-computed source for
 * W/L/T/PTS rather than a second, hand-rolled aggregation.
 *
 * Finding 13: the hero itself is now a coloured band (fill = team primary,
 * text = the derived on-primary foreground) with a paired blue-line band
 * (Device #2) underneath in team primary + ink, in place of the old
 * border-top/box-shadow approximation. See single-team.php for the second
 * half of finding 13: it prints this same style attribute again on
 * <main>, promoting the custom properties to a scope the_content()'s own
 * league table (rendered as a SIBLING of this <header>, not a descendant)
 * can also see, which is what lets .sp-highlight (sportspress.css) pick up
 * the team's own colour there instead of the theme's generic ice fill.
 *
 * @param int $team_id sp_team post ID.
 */
function blueline_sp_team_hero( $team_id ) {
	$division_names = blueline_sp_team_hero_division_names( $team_id );

	/*
	 * The team's own colour, as scoped custom properties. Returns '' for a
	 * team with no usable `sp_colors`, in which case every rule below falls
	 * back to its theme token and the hero renders exactly as it always has.
	 * See inc/team-colors.php for why the stored palette is not used as-is:
	 * that derivation guard (the fills-vs-boundaries split and the
	 * achromatic withholding) is unchanged by this finding.
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

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
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
 * A compact single-line teaser for one sp_event, used in archive/taxonomy
 * listings (taxonomy-venue.php) where showing every event's full,
 * the_content()-rendered detail would be far too heavy per row.
 *
 * Same P0 finding 2/10 fixes as blueline_sp_event_hero(): "Preview" is
 * gated on the clock via blueline_sp_event_state(), not on whether a score
 * has been entered, and the venue (when the venue archive itself does not
 * already say it via the page title) reads through blueline_venue_label()
 * so a cross-pad "Also plays at this arena" link elsewhere on the page has
 * something concrete to distinguish from.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_teaser( $event_id ) {
	$has_results = function_exists( 'sp_get_status' ) && ( 'results' === sp_get_status( $event_id ) );
	$start_ts    = function_exists( 'blueline_sp_event_start_timestamp' ) ? blueline_sp_event_start_timestamp( $event_id ) : false;
	$state       = function_exists( 'blueline_sp_event_state' ) ? blueline_sp_event_state( $has_results, $start_ts ) : ( $has_results ? 'final' : 'preview' );
	$is_played   = ( 'final' === $state );

	$status_labels = array(
		'pending' => __( 'Result pending', 'blueline' ),
		'preview' => __( 'Preview', 'blueline' ),
	);

	// Keyed by team ID (blueline_sp_team_result()), not positionally zipped
	// against a shared results array. See that function's own docblock
	// for why a positional pairing can silently attribute one team's score
	// to the other.
	$scores = array();
	if ( $is_played ) {
		$teams = blueline_sp_event_team_ids( $event_id );
		foreach ( $teams as $team_id ) {
			$score = blueline_sp_team_result( $event_id, $team_id );
			if ( null !== $score && '' !== $score ) {
				$scores[] = $score;
			}
		}
	}

	$venue_name = blueline_sp_event_venue_label( $event_id );
	?>
	<a class="bl-sp-event-teaser" href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
		<span class="bl-sp-event-teaser__date">
			<?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event_id ) ); ?>
			<?php if ( $venue_name ) : ?>
				<span class="bl-sp-event-teaser__venue"> · <?php echo esc_html( $venue_name ); ?></span>
			<?php endif; ?>
		</span>
		<span class="bl-sp-event-teaser__title"><?php echo esc_html( blueline_sp_title( $event_id ) ); ?></span>
		<?php if ( $is_played && $scores ) : ?>
			<span class="bl-sp-event-teaser__score"><?php echo esc_html( implode( ' - ', $scores ) ); ?></span>
		<?php else : ?>
			<span class="bl-sp-event-teaser__status"><?php echo esc_html( $status_labels[ $state ] ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * The known street-address -> arena-name map, filterable so a site owner
 * can correct or extend it without a code deploy. P0 finding 10: SportsPress
 * venue *terms* only ever carry a pad name ("Red", "Black") plus a street
 * address (`sp_address`, in `get_option( 'taxonomy_' . $term_id )`); there
 * is no structured "arena name" field anywhere in the taxonomy. The arena
 * itself was also just renamed: /register (post 11113, live copy) now reads
 * "Mr. Lube and Tires Arena (formerly known as the Wave Twin Rinks)", but
 * the venue terms' own `description` fields (site content, not theme code)
 * still say only "Wave Twin Rinks"; confirmed live for terms 13/14/151/152.
 *
 * This intentionally does NOT try to parse that description text for a name:
 * reading every sp_venue term on this site showed the description's
 * opening line is sometimes a clean arena name ("Wave Twin Rinks", stripped:
 * "APPLEBY ICE CENTRE"), sometimes a rink-specific heading that would
 * duplicate the pad name if reused ("Mainway Recreation Centre - Rink A"),
 * sometimes a full sentence, and sometimes blank/decorative markup
 * (`&nbsp;`); there is no reliable convention to parse, so guessing would
 * ship wrong names as confidently as right ones. A small address-keyed map
 * is auditable and correct for the one rename this task has confirmed;
 * everywhere else this returns '' and blueline_venue_label() falls back to
 * the term's own name exactly as before.
 *
 * The real fix is a content one: give sp_venue terms an actual "arena name"
 * field (or at minimum update the description's opening line) so this map
 * can shrink to nothing. Recorded in this task's report as a content
 * follow-up for the site owner, not fixed here.
 *
 * @param string $address Raw `sp_address` value.
 * @return string Arena name, or '' when this address is not confidently known.
 */
function blueline_venue_arena_name_for_address( $address ) {
	$address = trim( (string) $address );

	if ( '' === $address ) {
		return '';
	}

	$known = apply_filters(
		'blueline_venue_arena_names',
		array(
			// Both forms seen live: Red/Black (term 14/13) store the address
			// without a postal code, StoneRidge Red/Wave Twin Rinks Blue
			// (term 151/152) store it with one; same building, two strings.
			'1179 northside rd, burlington, on l7m, canada'      => 'Mr. Lube and Tires Arena',
			'1179 northside rd, burlington, on l7m 1h5, canada'  => 'Mr. Lube and Tires Arena',
		)
	);

	$key = strtolower( $address );

	return isset( $known[ $key ] ) ? (string) $known[ $key ] : '';
}

/**
 * The arena name for a given sp_venue term, or '' when not confidently known.
 *
 * @param int $term_id sp_venue term ID.
 * @return string
 */
function blueline_venue_arena_name( $term_id ) {
	$term_id = absint( $term_id );

	if ( ! $term_id ) {
		return '';
	}

	$venue_meta = get_option( 'taxonomy_' . $term_id );
	$address    = ( is_array( $venue_meta ) && ! empty( $venue_meta['sp_address'] ) ) ? (string) $venue_meta['sp_address'] : '';

	return blueline_venue_arena_name_for_address( $address );
}

/**
 * The player-facing venue label: "{Arena name} — {Pad name}", per P0 finding
 * 10, PRODUCT.md principle 4 ("the pad, not just the arena"). Falls back to
 * the term's own name alone (today's behaviour, unchanged) whenever no
 * confidently-known arena name exists for that venue's address, or when the
 * arena name and the pad name are the same string (a single-pad venue whose
 * own term name already IS the full arena name, e.g. "Central Arena").
 *
 * Used by the scoreboard and event teaser (both in this file), the venue
 * archive (sportspress/taxonomy-venue.php, via the get_the_archive_title
 * filter below), and the schedule table's Arena column
 * (sportspress/event-list.php). Package 2 can call this directly for the
 * homepage.
 *
 * @param int $term_id sp_venue term ID.
 * @return string
 */
function blueline_venue_label( $term_id ) {
	$term_id = absint( $term_id );

	if ( ! $term_id || ! taxonomy_exists( 'sp_venue' ) ) {
		return '';
	}

	$term = get_term( $term_id, 'sp_venue' );

	if ( ! ( $term instanceof WP_Term ) ) {
		return '';
	}

	$pad_name   = $term->name;
	$arena_name = blueline_venue_arena_name( $term_id );

	if ( '' === $arena_name || 0 === strcasecmp( $arena_name, $pad_name ) ) {
		return $pad_name;
	}

	return sprintf(
		/* translators: 1: arena name, 2: pad/sheet name. */
		__( '%1$s — %2$s', 'blueline' ),
		$arena_name,
		$pad_name
	);
}

add_filter( 'get_the_archive_title', 'blueline_sp_venue_archive_title' );
/**
 * The venue archive's own page title, via blueline_venue_label(), per P0
 * finding 10. Scoped strictly to the sp_venue taxonomy archive so every
 * other archive/page title on the site is untouched.
 *
 * @param string $title Default archive title.
 * @return string
 */
function blueline_sp_venue_archive_title( $title ) {
	if ( ! is_tax( 'sp_venue' ) ) {
		return $title;
	}

	$term = get_queried_object();

	if ( ! ( $term instanceof WP_Term ) ) {
		return $title;
	}

	$label = blueline_venue_label( $term->term_id );

	return '' !== $label ? esc_html( $label ) : $title;
}

/**
 * The `bl-sp-col-extra` class fragment for one league-table column, shared by
 * sportspress/league-table.php's header-building and row-building loops;
 * both used to repeat `in_array( $key, $bl_extra_keys, true ) ? '
 * bl-sp-col-extra' : ''` verbatim.
 *
 * @param string   $key         The column key being rendered.
 * @param string[] $extra_keys  Column keys marked "extra" (hidden until the
 *                               full-stats toggle is checked).
 * @return string ' bl-sp-col-extra' when $key is one of $extra_keys, '' otherwise.
 */
function blueline_sp_extra_class( string $key, array $extra_keys ): string {
	return in_array( $key, $extra_keys, true ) ? ' bl-sp-col-extra' : '';
}

/**
 * Pure decision behind blueline_team_events_current_season_id(): given
 * SportsPress' own filter value ($season -- its plugin default of 0, or
 * whatever an earlier-priority `sp_team_events_season` callback already
 * set) and this theme's own resolved "current season" answer, decide which
 * one actually wins.
 *
 * Split out purely so this decision is unit-testable without going through
 * blueline_current_sp_season_term_id()'s own get_posts()/transient-backed
 * resolution path (inc/account/player-data.php) -- that function's own
 * WordPress dependencies stay entirely outside this one's test surface.
 *
 * @param int      $season           SportsPress' own filter value.
 * @param int|null $resolved_current blueline_current_sp_season_term_id()'s answer.
 * @return int
 */
function blueline_resolve_team_events_season( int $season, ?int $resolved_current ): int {
	if ( $season > 0 ) {
		// Something upstream already chose a season (a future filter running
		// at a later priority, or a caller passing one explicitly) -- never
		// override a real value with our own guess.
		return $season;
	}

	return $resolved_current ? $resolved_current : $season;
}

add_filter( 'sp_team_events_season', 'blueline_team_events_current_season_id' );
/**
 * Scope a team's public "Upcoming Games"/"Results" cards
 * (sportspress/team-events.php, the theme's own override of SportsPress'
 * team-events.php) to the season currently driving the schedule.
 *
 * Live-site review: a team's Results section showed games from years
 * earlier (e.g. 2017) directly beneath Upcoming Games, with nothing
 * distinguishing them from the current season. Root cause, confirmed
 * against the SportsPress plugin's own team-events.php/
 * event-fixtures-results.php: the 'blocks' display format (this site's own
 * configured `sportspress_team_events_format`) calls
 * `sp_get_template( 'event-fixtures-results.php', array( 'team' => $id ) )`
 * with NO season argument at all, so SP_Calendar falls back to every
 * sp_event the team has EVER played. The 'list' format already exposes an
 * `sp_team_events_season` filter for exactly this purpose (unused on this
 * site, but honoured here too, for free, since both formats now read the
 * same filter); the theme's own team-events.php override adds the
 * equivalent call for the 'blocks' format, whose plugin default takes no
 * filter input at all.
 *
 * Reuses blueline_current_sp_season_term_id() (inc/account/player-data.php)
 * -- the same season-resolution the account dashboard's own My Season/My
 * Next Game readers already use -- rather than inventing a second rule for
 * "which season is current."
 *
 * @param int $season SportsPress' own filter value (its plugin default of 0
 *                     unless another filter already set one).
 * @return int
 */
function blueline_team_events_current_season_id( $season = 0 ) {
	$resolved = function_exists( 'blueline_current_sp_season_term_id' ) ? blueline_current_sp_season_term_id() : null;

	return blueline_resolve_team_events_season( (int) $season, $resolved );
}
