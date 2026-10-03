<?php
/**
 * Section toggles for the My Account endpoints the Blueline Core plugin routes.
 *
 * The plugin owns the endpoints and menu (account-endpoints module) and never reads
 * `blueline_settings`; this file answers its `blueline_core_account_endpoint_enabled`
 * filter from the theme's section toggles.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint slug => the `account_*` section key that must be enabled for
 * that endpoint's own menu entry to appear.
 *
 * Only 'my-team' and 'my-schedule' have a matching dashboard card that can
 * go dark (Task 3's blueline_account_render_my_team()/_next_game() early
 * returns): every other endpoint here is in the billing group, which has no
 * toggle at all -- see blueline_section_definitions()'s own "deliberately
 * NOT here" note. Switching a card's toggle off without also removing its
 * endpoint's menu entry would leave a linked player a nav link to a page
 * that now renders nothing.
 *
 * @return array<string,string>
 */
function blueline_account_endpoint_section_keys(): array {
	return array(
		'my-team'     => 'account_my_team',
		'my-schedule' => 'account_next_game',
	);
}

add_filter( 'blueline_core_account_endpoint_enabled', 'blueline_account_endpoint_enabled_by_section', 10, 2 );
/**
 * Hide a league endpoint's menu entry when its dashboard card's toggle is off.
 *
 * @param bool   $enabled Whether the endpoint is enabled so far.
 * @param string $slug    Endpoint slug, e.g. 'my-team'.
 * @return bool
 */
function blueline_account_endpoint_enabled_by_section( $enabled, $slug ): bool {
	$section_key = blueline_account_endpoint_section_keys()[ (string) $slug ] ?? null;

	if ( ! $enabled || null === $section_key ) {
		return (bool) $enabled;
	}

	return blueline_section_enabled( $section_key );
}
