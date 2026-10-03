<?php
/**
 * SportsPress integration: body classes, the primary-nav current-item
 * fix, and the option filters that blank or reshape SportsPress's own
 * league-menu and header/footer sponsor settings on the front end.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

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

	$hub_path = blueline_utility_normalize_path( $hub_url );

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
		return blueline_resolve_link( 'page_schedule' );
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

	blueline_prime_team_caches( $stored );

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
 * Load the posts, meta and crest attachments for a list of team IDs in a few
 * batched queries, so the per-team get_post_status()/get_permalink()/
 * get_the_post_thumbnail() calls in the flyout and footer directory read
 * from cache instead of querying once per team (PERF-09).
 *
 * @param array $team_ids Team IDs (scalars; anything else is ignored).
 * @return void
 */
function blueline_prime_team_caches( array $team_ids ): void {
	if ( ! function_exists( '_prime_post_caches' ) ) {
		return;
	}

	$ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $team_ids, 'is_scalar' ) ) ) ) );

	if ( ! $ids ) {
		return;
	}

	_prime_post_caches( $ids, false, true );

	$thumbnail_ids = array_values( array_filter( array_map( 'absint', array_map( 'get_post_thumbnail_id', $ids ) ) ) );

	if ( $thumbnail_ids ) {
		_prime_post_caches( $thumbnail_ids, false, true );
	}
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
