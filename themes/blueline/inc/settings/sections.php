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
 * Every toggleable section: option key => { label, group }. The single
 * source of truth blueline_settings_schema() and blueline_settings_defaults()
 * (inc/settings/defaults.php) both generate their 'sections'-tab entries
 * from, so a new section needs no second edit in either of those functions.
 *
 * @return array<string,array{label:string,group:string}>
 */
function blueline_section_definitions(): array {
	return array(
		'module_next_games'        => array(
			'label' => 'Next games',
			'group' => 'Homepage',
		),
		'module_standings_snippet' => array(
			'label' => 'Standings',
			'group' => 'Homepage',
		),
		'module_new_here'          => array(
			'label' => 'Never played? Perfect.',
			'group' => 'Homepage',
		),
		'module_latest_news'       => array(
			'label' => 'Latest news',
			'group' => 'Homepage',
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
