<?php
/**
 * Section presence toggles: which parts of the site render at all.
 *
 * PRESENCE ONLY, NEVER ORDER. blueline_homepage_module_order() (a later
 * task) encodes the registration_open-and-playing fix, and a drag-list
 * inviting an admin to reorder sections would invite re-breaking it -- this
 * tab only ever offers "show" or "hide", never "where".
 *
 * Every consumer (the homepage, account cards, site chrome -- later tasks)
 * reads through blueline_section_enabled() rather than calling
 * blueline_settings() directly, so a floor or an unknown-key guard added
 * here cannot be bypassed by a caller that forgot it exists.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every toggleable section: option key => { label, group, help? }. The
 * single source of truth blueline_settings_schema() and
 * blueline_settings_defaults() (inc/settings/defaults.php) both generate
 * their 'sections'-tab entries from, so a new section needs no second edit
 * in either of those functions.
 *
 * The four `module_*` entries carry a `help` string the other groups
 * don't: blueline_homepage_module_order() (inc/homepage-modules.php) never
 * lets unticking every one of them empty the homepage -- it keeps the
 * first module of that state's order regardless (the floor; WCAG 2.4.5
 * needs two ways to find content). That is genuinely surprising if an
 * admin unticks the last box and a module still renders anyway, so the
 * help text says so at the moment they're doing it. `chrome_*` and
 * `account_*` have no such floor -- unticking every one of those really
 * does hide everything in that group -- so they carry no `help` string.
 *
 * @return array<string,array{label:string,group:string,help?:string}>
 */
function blueline_section_definitions(): array {
	$module_help = 'At least one homepage module always shows, even if every box here is unticked -- an empty homepage isn\'t an option.';

	return array(
		'module_next_games'        => array(
			'label' => 'Next games',
			'group' => 'Homepage',
			'help'  => $module_help,
		),
		'module_standings_snippet' => array(
			'label' => 'Standings',
			'group' => 'Homepage',
			'help'  => $module_help,
		),
		'module_new_here'          => array(
			'label' => 'Never played? Perfect.',
			'group' => 'Homepage',
			'help'  => $module_help,
		),
		'module_latest_news'       => array(
			'label' => 'Latest news',
			'group' => 'Homepage',
			'help'  => $module_help,
		),
		'chrome_sponsors'          => array(
			'label' => 'Header sponsor slot',
			'group' => 'Site chrome',
		),
		'chrome_utility_nav'       => array(
			'label' => 'Account links in the header',
			'group' => 'Site chrome',
		),
		'chrome_footer_trust'      => array(
			'label' => 'Footer contact block',
			'group' => 'Site chrome',
		),
		'chrome_footer_teams'      => array(
			'label' => 'Footer team directory',
			'group' => 'Site chrome',
		),
		// Fix round (Task 5): these four gate the four REAL footer-1..footer-4
		// WordPress widget areas (register_sidebar() calls in inc/setup.php's
		// blueline_widgets_init()) -- each ANDed with the area's own
		// is_active_sidebar() check in blueline_site_footer()
		// (inc/template-tags.php). `chrome_footer_trust` (above) does NOT
		// gate any of these; it only gates the separate, hardcoded trust
		// column next to them -- a distinction a fix round of this plan got
		// wrong once already, which is why these four exist as their own
		// entries rather than being folded into chrome_footer_trust.
		'chrome_footer_widgets_1'  => array(
			'label' => 'Footer widget area 1',
			'group' => 'Site chrome',
		),
		'chrome_footer_widgets_2'  => array(
			'label' => 'Footer widget area 2',
			'group' => 'Site chrome',
		),
		'chrome_footer_widgets_3'  => array(
			'label' => 'Footer widget area 3',
			'group' => 'Site chrome',
		),
		'chrome_footer_widgets_4'  => array(
			'label' => 'Footer widget area 4',
			'group' => 'Site chrome',
		),
		'account_next_game'        => array(
			'label' => 'My next game',
			'group' => 'My Account',
		),
		'account_my_team'          => array(
			'label' => 'My team',
			'group' => 'My Account',
		),
		'account_season_stats'     => array(
			'label' => 'My season',
			'group' => 'My Account',
		),
		'account_registration'     => array(
			'label' => 'Registration status',
			'group' => 'My Account',
		),
		// Deliberately NOT here: the claim-a-profile card and the billing
		// group. Each is the only route to something a user needs (linking
		// their player profile; managing payment details), so hiding either
		// would strand a user with no other way to reach it -- these two
		// stay permanently on, with no toggle at all.
	);
}

/**
 * Whether $key renders. Unknown keys are never enabled: a typo in a
 * consumer's own key must fail closed rather than silently render something
 * nothing controls.
 *
 * @param string $key A key from blueline_section_definitions().
 * @return bool
 */
function blueline_section_enabled( string $key ): bool {
	if ( ! isset( blueline_section_definitions()[ $key ] ) ) {
		return false;
	}

	$value = function_exists( 'blueline_settings' ) ? blueline_settings( $key ) : null;

	// Unset means enabled: every section shipped visible, and an install
	// that has never opened this tab must look exactly as it did before.
	return null === $value ? true : (bool) $value;
}

/**
 * A warning naming the live widget count behind a section, or '' when there
 * is nothing to warn about (the section has no widget-area mapping, or the
 * mapped area is empty).
 *
 * Switching a section off (blueline_section_enabled()) hides it; it never
 * touches the widget store -- widgets an admin already placed in the area
 * stay exactly where they are, ready to reappear the moment the section is
 * switched back on. Saying so, with the count, is what stops an admin
 * assuming their widgets were deleted and rebuilding them from scratch.
 *
 * The wording depends on the toggle's CURRENT value, not only on the area
 * being populated. Before this took the toggle into account, an admin who
 * had already switched an area off still read "Switching it off hides
 * them" beside an unticked box -- future tense about a step they had
 * already taken, and silent on the one thing they would actually want
 * confirmed at that moment, which is that the widgets they can no longer
 * see still exist. Both phrasings carry the count and both say nothing was
 * deleted; only the tense and the reassurance differ.
 *
 * Each of the four `chrome_footer_widgets_N` keys maps to its own real
 * widget area (`footer-N`) -- and, unlike an earlier fix round's
 * `chrome_footer_trust` -> `footer-2` mapping, each genuinely IS the toggle
 * that gates its area: blueline_site_footer() (inc/template-tags.php) gates
 * each of the four footer widget columns on both that column's own section
 * toggle and that area's own is_active_sidebar() check, so this warning's
 * claim about hiding is true for every key below. Those four gates are
 * written out as four separate blocks, each naming its key as a literal
 * rather than assembling one from a loop counter; blueline_site_footer()'s
 * own docblock explains why that shape is required rather than merely
 * preferred, and this docblock deliberately does not restate the code.
 *
 * No other section key gets a mapping, since no other widget area holds
 * live content to warn about (only `footer-2` does today, on this site's
 * real configuration -- the other three currently have nothing assigned,
 * so their own toggle warns as soon as something is).
 *
 * @param string $key A blueline_section_definitions() key.
 * @return string
 */
function blueline_section_widget_warning( string $key ): string {
	$areas = array(
		'chrome_footer_widgets_1' => 'footer-1',
		'chrome_footer_widgets_2' => 'footer-2',
		'chrome_footer_widgets_3' => 'footer-3',
		'chrome_footer_widgets_4' => 'footer-4',
	);

	if ( ! isset( $areas[ $key ] ) || ! is_active_sidebar( $areas[ $key ] ) ) {
		return '';
	}

	$count = blueline_active_widget_count( $areas[ $key ] );

	if ( ! blueline_section_enabled( $key ) ) {
		return sprintf(
			/* translators: %d: number of widgets in the area. */
			__( 'This area holds %d widget(s), currently hidden because this box is unticked. Nothing was deleted -- tick it to show them again.', 'blueline' ),
			$count
		);
	}

	return sprintf(
		/* translators: %d: number of widgets in the area. */
		__( 'This area holds %d widget(s). Switching it off hides them; nothing is deleted.', 'blueline' ),
		$count
	);
}

/**
 * The capability-then-nonce guard every one of this theme's `admin-post`
 * handlers opens with (the settings panel's export, import, restore,
 * delete-all-data and cache-purge-notice-dismiss handlers today), extracted
 * once rather than repeated near-verbatim at each site.
 *
 * Lives here, in inc/settings/sections.php, rather than in any one of those
 * handlers' own files: it has no connection to this file's actual topic
 * (section presence toggles), but this file is already require_once'd
 * ahead of every one of theirs (see functions.php) and by every test that
 * exercises them, which is what a genuinely cross-cutting helper like this
 * one actually needs from wherever it lives.
 *
 * Capability first, then nonce, in that order deliberately: telling an
 * unprivileged caller their nonce was wrong first would concede that a
 * valid one exists for this action at all.
 *
 * @param string $nonce_action The check_admin_referer() action this
 *                              request's nonce is checked against.
 * @return void
 */
function blueline_settings_require_manage_options_and_nonce( string $nonce_action ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'blueline' ), 403 );
	}

	check_admin_referer( $nonce_action );
}
